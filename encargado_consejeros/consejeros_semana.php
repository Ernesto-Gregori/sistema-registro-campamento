<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esEncargadoConsejeros()) {
    header('Location: ../consejero/dashboard.php');
    exit();
}

$titulo  = "Consejeros por Semana";
$message = '';
$error   = '';

// ── Semanas ────────────────────────────────────────────────────
$stmt_semanas = $pdo->query("SELECT * FROM semanas_campamento ORDER BY fecha_inicio DESC");
$semanas      = $stmt_semanas->fetchAll();

$semana_sel_id = $_GET['semana_id'] ?? null;
if (!$semana_sel_id) {
    foreach ($semanas as $s) {
        if ($s['activa']) { $semana_sel_id = $s['id']; break; }
    }
}
$semana_sel_id = $semana_sel_id ? (int)$semana_sel_id : null;

$semana_sel = null;
foreach ($semanas as $s) {
    if ($s['id'] == $semana_sel_id) { $semana_sel = $s; break; }
}

// ── Cabañas activas ────────────────────────────────────────────
$stmt_cab = $pdo->query("SELECT * FROM cabanas WHERE activa = 1 ORDER BY equipo, nombre_cabana");
$cabanas  = $stmt_cab->fetchAll();

$cabanas_por_equipo = [];
foreach ($cabanas as $cab) {
    $eq = $cab['equipo'] ?? 'sin_equipo';
    $cabanas_por_equipo[$eq][] = $cab;
}

// ── Guardar ────────────────────────────────────────────────────
if ($_POST && isset($_POST['guardar']) && $semana_sel_id) {
    try {
        $pdo->beginTransaction();

        // Borrar todo lo anterior de esta semana
        $stmt_del = $pdo->prepare("DELETE FROM consejeros_semana WHERE semana_id = ?");
        $stmt_del->execute([$semana_sel_id]);

        $stmt_ins = $pdo->prepare("INSERT INTO consejeros_semana
                                   (semana_id, cabana_id, nombre_consejero, rol)
                                   VALUES (?, ?, ?, ?)");

        $filas = 0;

        // Capitanes marcados: [equipo] => cabana_id del principal marcado
        $capitan_cabana_id  = $_POST['capitan_masc']  ?? [];  // [equipo => cabana_id]
        $capitana_cabana_id = $_POST['capitan_fem']   ?? [];  // [equipo => cabana_id]

        foreach ($_POST['consejeros'] ?? [] as $cabana_id => $roles) {
            $cabana_id = (int)$cabana_id;
        
            // Consejero principal
            $principal = trim($roles['principal'] ?? '');
            if ($principal !== '') {
                $stmt_ins->execute([$semana_sel_id, $cabana_id, $principal, 'principal']);
                $filas++;
            }
        
            // Asistentes — puede ser uno o varios
            $asistentes = $roles['asistente'] ?? [];
            // Compatibilidad: si viene como string (campo simple), convertir a array
            if (is_string($asistentes)) {
                $asistentes = [$asistentes];
            }
            foreach ($asistentes as $asistente) {
                $asistente = trim($asistente);
                if ($asistente !== '') {
                    $stmt_ins->execute([$semana_sel_id, $cabana_id, $asistente, 'asistente']);
                    $filas++;
                }
            }
        }

        // Guardar capitanes como rol especial vinculado a la cabaña del marcado
        foreach ($capitan_cabana_id as $equipo => $cab_id) {
            $cab_id = (int)$cab_id;
            if (!$cab_id) continue;
            // Obtener el nombre del consejero principal de esa cabaña
            $nombre_cap = trim($_POST['consejeros'][$cab_id]['principal'] ?? '');
            if ($nombre_cap !== '') {
                $stmt_ins->execute([$semana_sel_id, $cab_id, $nombre_cap, 'capitan']);
                $filas++;
            }
        }

        foreach ($capitana_cabana_id as $equipo => $cab_id) {
            $cab_id = (int)$cab_id;
            if (!$cab_id) continue;
            $nombre_cap = trim($_POST['consejeros'][$cab_id]['principal'] ?? '');
            if ($nombre_cap !== '') {
                $stmt_ins->execute([$semana_sel_id, $cab_id, $nombre_cap, 'capitana']);
                $filas++;
            }
        }

        $pdo->commit();
        $message = "Consejeros guardados correctamente ($filas registros)";
        header("Location: consejeros_semana.php?semana_id=$semana_sel_id&message=" . urlencode($message));
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error al guardar: " . $e->getMessage();
    }
}

// ── Cargar asignaciones existentes ────────────────────────────
$asignaciones = []; // [cabana_id]['principal'] = nombre, [cabana_id]['asistentes'] = [...]
$capitan_marcado  = []; // [equipo] = cabana_id del capitán
$capitana_marcada = []; // [equipo] = cabana_id de la capitana

if ($semana_sel_id) {
    $stmt_as = $pdo->prepare("SELECT cs.*, c.equipo, c.genero
                               FROM consejeros_semana cs
                               JOIN cabanas c ON cs.cabana_id = c.id
                               WHERE cs.semana_id = ?");
    $stmt_as->execute([$semana_sel_id]);
    foreach ($stmt_as->fetchAll() as $row) {
        if ($row['rol'] === 'capitan') {
            $capitan_marcado[$row['equipo']] = $row['cabana_id'];
        } elseif ($row['rol'] === 'capitana') {
            $capitana_marcada[$row['equipo']] = $row['cabana_id'];
        } elseif ($row['rol'] === 'principal') {
            $asignaciones[$row['cabana_id']]['principal'] = $row['nombre_consejero'];
        } elseif ($row['rol'] === 'asistente') {
            $asignaciones[$row['cabana_id']]['asistentes'][] = $row['nombre_consejero'];
        }
    }
}

if (isset($_GET['message'])) $message = $_GET['message'];

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-users-cog"></i> <?php echo $titulo; ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="cabanas.php">Cabañas</a></li>
                    <li class="breadcrumb-item active">Consejeros por Semana</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Selector de semana -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="d-flex align-items-center gap-3 flex-wrap">
            <label class="fw-bold mb-0">
                <i class="fas fa-calendar-week"></i> Semana:
            </label>
            <select name="semana_id" class="form-select w-auto" onchange="this.form.submit()">
                <option value="">-- Seleccionar semana --</option>
                <?php foreach ($semanas as $sem): ?>
                <option value="<?php echo $sem['id']; ?>"
                        <?php echo $sem['id'] == $semana_sel_id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($sem['nombre']); ?>
                    <?php echo $sem['activa'] ? '✓ ACTIVA' : ''; ?>
                    (<?php echo date('d/m/Y', strtotime($sem['fecha_inicio'])); ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($semana_sel): ?>
            <span class="badge bg-<?php echo $semana_sel['activa'] ? 'success' : 'secondary'; ?> fs-6">
                <?php echo $semana_sel['activa'] ? '🟢 Activa' : '⚫ Inactiva'; ?>
            </span>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (!$semana_sel_id): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    Selecciona una semana para asignar consejeros.
</div>
<?php else: ?>

<!-- Leyenda -->
<div class="alert alert-info py-2 mb-3">
    <i class="fas fa-info-circle"></i>
    Escribe el nombre de cada consejero y marca
    <span class="badge bg-warning text-dark"><i class="fas fa-star"></i> Capitán</span> o
    <span class="badge bg-warning text-dark"><i class="fas fa-star"></i> Capitana</span>
    en la fila del consejero principal que lidera el equipo.
    Solo puede haber <strong>uno por equipo</strong>.
</div>

<form method="POST">
    <input type="hidden" name="guardar" value="1">

    <?php foreach ($cabanas_por_equipo as $equipo => $cabs_equipo):
        $colorEq = $equipo === 'verde' ? 'success' : ($equipo === 'azul' ? 'primary' : 'secondary');
        $emojiEq = $equipo === 'verde' ? '🟢' : ($equipo === 'azul' ? '🔵' : '⚪');
        $labelEq = $equipo === 'sin_equipo' ? 'Sin equipo' : 'Equipo ' . ucfirst($equipo);
        $masc    = array_filter($cabs_equipo, fn($c) => $c['genero'] === 'masculino');
        $fem     = array_filter($cabs_equipo, fn($c) => $c['genero'] === 'femenino');
    ?>

    <div class="card mb-4 border-<?php echo $colorEq; ?>">
        <div class="card-header bg-<?php echo $colorEq; ?> text-white">
            <h5 class="mb-0"><?php echo $emojiEq; ?> <?php echo $labelEq; ?></h5>
        </div>
        <div class="card-body">
            <div class="row g-4">

                <!-- ── Cabañas Masculinas ── -->
                <?php if (!empty($masc)): ?>
                <div class="<?php echo !empty($fem) ? 'col-md-6' : 'col-12'; ?>">
                    <h6 class="text-primary mb-2">
                        <i class="fas fa-mars"></i> Cabañas Masculinas
                    </h6>
                    <?php if ($equipo !== 'sin_equipo'): ?>
                    <div class="alert alert-warning py-1 small mb-2">
                        <i class="fas fa-star"></i>
                        Marca <strong>Capitán</strong> en el consejero principal que lidera este equipo
                    </div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle mb-0">
                            <thead class="table-primary">
                                <tr>
                                    <th style="width:30%">Cabaña</th>
                                    <th>
                                        <i class="fas fa-user"></i> Consejero Principal
                                    </th>
                                    <th>
                                        <i class="fas fa-user-friends"></i> Asistente
                                    </th>
                                    <?php if ($equipo !== 'sin_equipo'): ?>
                                    <th class="text-center" style="width:80px;">
                                        <i class="fas fa-star text-warning"></i><br>
                                        <small>Capitán</small>
                                    </th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($masc as $cab):
                                    $esCapitan = isset($capitan_marcado[$equipo])
                                        && $capitan_marcado[$equipo] == $cab['id'];
                                ?>
                                <tr class="<?php echo $esCapitan ? 'table-warning' : ''; ?>"
                                    id="fila-masc-<?php echo $cab['id']; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($cab['nombre_cabana']); ?></strong>
                                        <br>
                                        <small class="text-muted">Cap. <?php echo $cab['capacidad_maxima']; ?></small>
                                        <?php if ($esCapitan): ?>
                                        <br>
                                        <span class="badge bg-warning text-dark">
                                            <i class="fas fa-star"></i> Capitán
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="text"
                                               class="form-control form-control-sm"
                                               name="consejeros[<?php echo $cab['id']; ?>][principal]"
                                               value="<?php echo htmlspecialchars($asignaciones[$cab['id']]['principal'] ?? ''); ?>"
                                               placeholder="Nombre del consejero..."
                                               id="principal-<?php echo $cab['id']; ?>">
                                    </td>
                                    <td>
                                        <div id="asistentes-<?php echo $cab['id']; ?>">
                                            <?php
                                            $lista_asistentes = $asignaciones[$cab['id']]['asistentes'] ?? [''];
                                            // Asegurar al menos un campo vacío
                                            if (empty($lista_asistentes)) $lista_asistentes = [''];
                                            foreach ($lista_asistentes as $idx => $nombre_as):
                                            ?>
                                            <div class="input-group input-group-sm mb-1 asistente-row">
                                                <input type="text"
                                                       class="form-control form-control-sm"
                                                       name="consejeros[<?php echo $cab['id']; ?>][asistente][]"
                                                       value="<?php echo htmlspecialchars($nombre_as); ?>"
                                                       placeholder="Nombre asistente...">
                                                <?php if ($idx > 0): ?>
                                                <button type="button"
                                                        class="btn btn-outline-danger btn-sm btn-quitar-asistente"
                                                        title="Quitar">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button"
                                                class="btn btn-outline-primary btn-sm w-100 mt-1 btn-agregar-asistente"
                                                data-cabana="<?php echo $cab['id']; ?>"
                                                title="Agregar otro asistente">
                                            <i class="fas fa-plus"></i> Asistente
                                        </button>
                                    </td>
                                    <?php if ($equipo !== 'sin_equipo'): ?>
                                    <td class="text-center">
                                        <div class="form-check d-flex justify-content-center">
                                            <input class="form-check-input capitan-radio-masc"
                                                   type="radio"
                                                   name="capitan_masc[<?php echo $equipo; ?>]"
                                                   value="<?php echo $cab['id']; ?>"
                                                   id="cap-masc-<?php echo $cab['id']; ?>"
                                                   <?php echo $esCapitan ? 'checked' : ''; ?>
                                                   data-cabana="<?php echo $cab['id']; ?>"
                                                   data-equipo="<?php echo $equipo; ?>"
                                                   data-genero="masc"
                                                   style="width:1.3em; height:1.3em; cursor:pointer;"
                                                   title="Marcar como Capitán del equipo <?php echo ucfirst($equipo); ?>">
                                        </div>
                                        <small class="text-muted d-block" style="font-size:10px;">Capitán</small>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Botón deseleccionar capitán -->
                    <?php if ($equipo !== 'sin_equipo'): ?>
                    <div class="mt-1 text-end">
                        <button type="button"
                                class="btn btn-link btn-sm text-muted p-0 btn-deselect"
                                data-equipo="<?php echo $equipo; ?>"
                                data-genero="masc">
                            <i class="fas fa-times-circle"></i> Quitar capitán
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- ── Cabañas Femeninas ── -->
                <?php if (!empty($fem)): ?>
                <div class="<?php echo !empty($masc) ? 'col-md-6' : 'col-12'; ?>">
                    <h6 class="text-danger mb-2">
                        <i class="fas fa-venus"></i> Cabañas Femeninas
                    </h6>
                    <?php if ($equipo !== 'sin_equipo'): ?>
                    <div class="alert alert-warning py-1 small mb-2">
                        <i class="fas fa-star"></i>
                        Marca <strong>Capitana</strong> en la consejera principal que lidera este equipo
                    </div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle mb-0">
                            <thead class="table-danger">
                                <tr>
                                    <th style="width:30%">Cabaña</th>
                                    <th>
                                        <i class="fas fa-user"></i> Consejera Principal
                                    </th>
                                    <th>
                                        <i class="fas fa-user-friends"></i> Asistente
                                    </th>
                                    <?php if ($equipo !== 'sin_equipo'): ?>
                                    <th class="text-center" style="width:80px;">
                                        <i class="fas fa-star text-warning"></i><br>
                                        <small>Capitana</small>
                                    </th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fem as $cab):
                                    $esCapitana = isset($capitana_marcada[$equipo])
                                        && $capitana_marcada[$equipo] == $cab['id'];
                                ?>
                                <tr class="<?php echo $esCapitana ? 'table-warning' : ''; ?>"
                                    id="fila-fem-<?php echo $cab['id']; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($cab['nombre_cabana']); ?></strong>
                                        <br>
                                        <small class="text-muted">Cap. <?php echo $cab['capacidad_maxima']; ?></small>
                                        <?php if ($esCapitana): ?>
                                        <br>
                                        <span class="badge bg-warning text-dark">
                                            <i class="fas fa-star"></i> Capitana
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="text"
                                               class="form-control form-control-sm"
                                               name="consejeros[<?php echo $cab['id']; ?>][principal]"
                                               value="<?php echo htmlspecialchars($asignaciones[$cab['id']]['principal'] ?? ''); ?>"
                                               placeholder="Nombre de la consejera..."
                                               id="principal-<?php echo $cab['id']; ?>">
                                    </td>
                                    <td>
                                        <div id="asistentes-<?php echo $cab['id']; ?>">
                                            <?php
                                            $lista_asistentes = $asignaciones[$cab['id']]['asistentes'] ?? [''];
                                            // Asegurar al menos un campo vacío
                                            if (empty($lista_asistentes)) $lista_asistentes = [''];
                                            foreach ($lista_asistentes as $idx => $nombre_as):
                                            ?>
                                            <div class="input-group input-group-sm mb-1 asistente-row">
                                                <input type="text"
                                                       class="form-control form-control-sm"
                                                       name="consejeros[<?php echo $cab['id']; ?>][asistente][]"
                                                       value="<?php echo htmlspecialchars($nombre_as); ?>"
                                                       placeholder="Nombre asistente...">
                                                <?php if ($idx > 0): ?>
                                                <button type="button"
                                                        class="btn btn-outline-danger btn-sm btn-quitar-asistente"
                                                        title="Quitar">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button"
                                                class="btn btn-outline-primary btn-sm w-100 mt-1 btn-agregar-asistente"
                                                data-cabana="<?php echo $cab['id']; ?>"
                                                title="Agregar otro asistente">
                                            <i class="fas fa-plus"></i> Asistente
                                        </button>
                                    </td>
                                    <?php if ($equipo !== 'sin_equipo'): ?>
                                    <td class="text-center">
                                        <div class="form-check d-flex justify-content-center">
                                            <input class="form-check-input capitan-radio-fem"
                                                   type="radio"
                                                   name="capitan_fem[<?php echo $equipo; ?>]"
                                                   value="<?php echo $cab['id']; ?>"
                                                   id="cap-fem-<?php echo $cab['id']; ?>"
                                                   <?php echo $esCapitana ? 'checked' : ''; ?>
                                                   data-cabana="<?php echo $cab['id']; ?>"
                                                   data-equipo="<?php echo $equipo; ?>"
                                                   data-genero="fem"
                                                   style="width:1.3em; height:1.3em; cursor:pointer;"
                                                   title="Marcar como Capitana del equipo <?php echo ucfirst($equipo); ?>">
                                        </div>
                                        <small class="text-muted d-block" style="font-size:10px;">Capitana</small>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Botón deseleccionar capitana -->
                    <?php if ($equipo !== 'sin_equipo'): ?>
                    <div class="mt-1 text-end">
                        <button type="button"
                                class="btn btn-link btn-sm text-muted p-0 btn-deselect"
                                data-equipo="<?php echo $equipo; ?>"
                                data-genero="fem">
                            <i class="fas fa-times-circle"></i> Quitar capitana
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div><!-- /row -->
        </div><!-- /card-body -->
    </div><!-- /card equipo -->
    <?php endforeach; ?>

    <div class="d-flex justify-content-between mb-5">
        <a href="cabanas.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver a Cabañas
        </a>
        <button type="submit" class="btn btn-success btn-lg">
            <i class="fas fa-save"></i> Guardar Consejeros
        </button>
    </div>
</form>

<!-- ── Vista resumen ── -->
<?php if (!empty($asignaciones) || !empty($capitan_marcado) || !empty($capitana_marcada)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-table"></i> Resumen —
            <?php echo htmlspecialchars($semana_sel['nombre']); ?>
        </h5>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-print"></i> Imprimir
        </button>
    </div>
    <div class="card-body">
        <?php foreach ($cabanas_por_equipo as $equipo => $cabs_equipo):
            $colorEq = $equipo === 'verde' ? 'success' : ($equipo === 'azul' ? 'primary' : 'secondary');
            $emojiEq = $equipo === 'verde' ? '🟢' : ($equipo === 'azul' ? '🔵' : '⚪');
            $labelEq = $equipo === 'sin_equipo' ? 'Sin equipo' : 'Equipo ' . ucfirst($equipo);
            $masc    = array_filter($cabs_equipo, fn($c) => $c['genero'] === 'masculino');
            $fem     = array_filter($cabs_equipo, fn($c) => $c['genero'] === 'femenino');

            // Obtener nombre del capitán/a desde asignaciones
            $nombre_capitan  = null;
            $nombre_capitana = null;
            if (isset($capitan_marcado[$equipo])) {
                $nombre_capitan = $asignaciones[$capitan_marcado[$equipo]]['principal'] ?? null;
            }
            if (isset($capitana_marcada[$equipo])) {
                $nombre_capitana = $asignaciones[$capitana_marcada[$equipo]]['principal'] ?? null;
            }
        ?>
        <div class="mb-4">
            <h6 class="bg-<?php echo $colorEq; ?> text-white px-3 py-2 rounded mb-2 d-flex flex-wrap align-items-center gap-2">
                <?php echo $emojiEq; ?> <?php echo $labelEq; ?>
                <?php if ($nombre_capitan): ?>
                <span class="badge bg-warning text-dark">
                    <i class="fas fa-star"></i> Capitán: <?php echo htmlspecialchars($nombre_capitan); ?>
                </span>
                <?php endif; ?>
                <?php if ($nombre_capitana): ?>
                <span class="badge bg-warning text-dark">
                    <i class="fas fa-star"></i> Capitana: <?php echo htmlspecialchars($nombre_capitana); ?>
                </span>
                <?php endif; ?>
            </h6>

            <div class="row g-3">
                <?php if (!empty($masc)): ?>
                <div class="<?php echo !empty($fem) ? 'col-md-6' : 'col-12'; ?>">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-primary">
                            <tr>
                                <th><i class="fas fa-mars"></i> Cabaña</th>
                                <th>Principal</th>
                                <th>Asistente</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($masc as $cab):
                                $esCapResumen = isset($capitan_marcado[$equipo])
                                    && $capitan_marcado[$equipo] == $cab['id'];
                            ?>
                            <tr class="<?php echo $esCapResumen ? 'table-warning' : ''; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($cab['nombre_cabana']); ?></strong>
                                    <?php if ($esCapResumen): ?>
                                    <span class="badge bg-warning text-dark ms-1">
                                        <i class="fas fa-star"></i>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($asignaciones[$cab['id']]['principal'] ?? '—'); ?></td>
                                <td>
                                    <?php
                                    $asis_lista = $asignaciones[$cab['id']]['asistentes'] ?? [];
                                    if (!empty($asis_lista)):
                                        foreach ($asis_lista as $an):
                                    ?>
                                        <div><?php echo htmlspecialchars($an); ?></div>
                                    <?php
                                        endforeach;
                                    else:
                                        echo '—';
                                    endif;
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($fem)): ?>
                <div class="<?php echo !empty($masc) ? 'col-md-6' : 'col-12'; ?>">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-danger">
                            <tr>
                                <th><i class="fas fa-venus"></i> Cabaña</th>
                                <th>Principal</th>
                                <th>Asistente</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fem as $cab):
                                $esCapResumen = isset($capitana_marcada[$equipo])
                                    && $capitana_marcada[$equipo] == $cab['id'];
                            ?>
                            <tr class="<?php echo $esCapResumen ? 'table-warning' : ''; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($cab['nombre_cabana']); ?></strong>
                                    <?php if ($esCapResumen): ?>
                                    <span class="badge bg-warning text-dark ms-1">
                                        <i class="fas fa-star"></i>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($asignaciones[$cab['id']]['principal'] ?? '—'); ?></td>
                                <td>
                                    <?php
                                    $asis_lista = $asignaciones[$cab['id']]['asistentes'] ?? [];
                                    if (!empty($asis_lista)):
                                        foreach ($asis_lista as $an):
                                    ?>
                                        <div><?php echo htmlspecialchars($an); ?></div>
                                    <?php
                                        endforeach;
                                    else:
                                        echo '—';
                                    endif;
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<style>
@media print {
    nav, .breadcrumb, form, .btn, .alert, .card-header .btn { display: none !important; }
    .card { border: 1px solid #000 !important; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Resaltar fila al marcar radio ─────────────────────────
    function actualizarFilas(radios, prefijo) {
        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                const equipo  = this.dataset.equipo;
                const genero  = this.dataset.genero;
                const cabana  = this.dataset.cabana;

                // Quitar resaltado de todas las filas del mismo grupo
                document.querySelectorAll(`input[name="capitan_${genero}[${equipo}]"]`)
                    .forEach(function (r) {
                        const fila = document.getElementById(`fila-${genero}-${r.value}`);
                        if (fila) fila.classList.remove('table-warning');
                    });

                // Resaltar la fila seleccionada
                const filaActual = document.getElementById(`fila-${genero}-${cabana}`);
                if (filaActual) filaActual.classList.add('table-warning');
            });
        });
    }

    actualizarFilas(document.querySelectorAll('.capitan-radio-masc'), 'masc');
    actualizarFilas(document.querySelectorAll('.capitan-radio-fem'),  'fem');

    // ── Botones quitar capitán/a ──────────────────────────────
    document.querySelectorAll('.btn-deselect').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const equipo = this.dataset.equipo;
            const genero = this.dataset.genero;

            // Desmarcar todos los radios del grupo
            document.querySelectorAll(`input[name="capitan_${genero}[${equipo}]"]`)
                .forEach(function (r) {
                    r.checked = false;
                    const fila = document.getElementById(`fila-${genero}-${r.value}`);
                    if (fila) fila.classList.remove('table-warning');
                });
        });
    });
    
    // ── Agregar asistente ─────────────────────────────────────
    document.addEventListener('click', function (e) {

        // Botón agregar asistente
        if (e.target.closest('.btn-agregar-asistente')) {
            const btn     = e.target.closest('.btn-agregar-asistente');
            const cabana  = btn.dataset.cabana;
            const cont    = document.getElementById('asistentes-' + cabana);

            const div = document.createElement('div');
            div.className = 'input-group input-group-sm mb-1 asistente-row';
            div.innerHTML = `
                <input type="text"
                       class="form-control form-control-sm"
                       name="consejeros[${cabana}][asistente][]"
                       placeholder="Nombre asistente...">
                <button type="button"
                        class="btn btn-outline-danger btn-sm btn-quitar-asistente"
                        title="Quitar">
                    <i class="fas fa-times"></i>
                </button>`;
            cont.appendChild(div);

            // Foco en el nuevo campo
            div.querySelector('input').focus();
        }

        // Botón quitar asistente
        if (e.target.closest('.btn-quitar-asistente')) {
            const btn = e.target.closest('.btn-quitar-asistente');
            btn.closest('.asistente-row').remove();
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>