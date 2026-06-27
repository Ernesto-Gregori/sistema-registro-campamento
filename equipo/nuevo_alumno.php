<?php
// equipo/nuevo_alumno.php
// Formulario simplificado para alumnos, misioneros, invitados y cocina
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
verificarMantenimiento($pdo);

if (!esEquipoOAdmin()) {
    header('Location: ../default.php');
    exit();
}

$year   = obtenerAnioCampamento();
$action = $_GET['action'] ?? 'add';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Tipo de persona (viene por URL: ?tipo=misionero o ?tipo=alumno)
$tiposValidos = ['alumno','misionero','invitado','cocina'];
$tipoSel = $_GET['tipo'] ?? 'alumno';
if (!in_array($tipoSel, $tiposValidos, true)) {
    $tipoSel = 'alumno';
}

$labelsTipo = [
    'alumno'    => 'Alumno',
    'misionero' => 'Misionero',
    'invitado'  => 'Invitado',
    'cocina'    => 'Personal de Cocina',
];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mensaje = '';
$error   = '';

// ---------------------------------------------------------------------
// GUARDAR
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'], true)) {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Token de seguridad invalido.';
    } else {
        try {
            $nombre      = trim($_POST['nombre'] ?? '');
            $edad        = !empty($_POST['edad']) ? (int)$_POST['edad'] : null;
            $sexo        = in_array($_POST['sexo'] ?? '', ['masculino','femenino'], true) ? $_POST['sexo'] : null;
            $direccion   = trim($_POST['direccion'] ?? '');
            $correo      = trim($_POST['correo'] ?? '');
            $telefono    = trim($_POST['telefono_whatsapp'] ?? '');
            $talla       = trim($_POST['talla'] ?? '');
            $iglesia     = trim($_POST['iglesia'] ?? '');
            $observaciones = trim($_POST['observaciones'] ?? '');
            $tipo_persona = in_array($_POST['tipo_persona'] ?? 'alumno', $tiposValidos, true) ? $_POST['tipo_persona'] : 'alumno';

            // Semanas (checkboxes con IDs)
            $semanas_disp = is_array($_POST['semanas_disponibles'] ?? null)
                            ? implode(',', array_map('intval', $_POST['semanas_disponibles']))
                            : '';

            // Campos de familia
            $es_familia    = isset($_POST['es_familia']) ? 1 : 0;
            $menores_12    = (int)($_POST['familiares_menores_12'] ?? 0);
            $mayores_12    = (int)($_POST['familiares_mayores_12'] ?? 0);
            $mayores_18    = (int)($_POST['familiares_mayores_18'] ?? 0);
            $come_comedor  = isset($_POST['come_comedor']) ? 1 : 0;

            // Estado: los alumnos/misioneros/invitados/cocina se aceptan directamente
            $estado = 'aceptado';

            if ($nombre === '') {
                throw new Exception('El nombre es obligatorio.');
            }

            $userId = $_SESSION['user_id'] ?? 0;

            if ($action === 'add') {
                $stmt = $pdo->prepare("
                    INSERT INTO equipantes (
                        nombre, edad, sexo, direccion, correo, telefono_whatsapp, talla,
                        semanas_disponibles, iglesia, observaciones,
                        tipo_persona, es_familia, familiares_menores_12, familiares_mayores_12,
                        familiares_mayores_18, come_comedor,
                        estado, year_campamento, registrado_por
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?
                    )
                ");
                $stmt->execute([
                    $nombre, $edad, $sexo, $direccion, $correo, $telefono, $talla,
                    $semanas_disp, $iglesia, $observaciones,
                    $tipo_persona, $es_familia, $menores_12, $mayores_12,
                    $mayores_18, $come_comedor,
                    $estado, $year, $userId
                ]);
                
                $nuevoId = (int)$pdo->lastInsertId();
                $mensaje = $labelsTipo[$tipo_persona] . ' registrado correctamente.';

                // Si es personal de cocina, auto-asignar al area "Cocina" en todas las semanas
                if ($tipo_persona === 'cocina') {
                    // Buscar el area "Cocina" (usar LIKE para evitar problemas de codificacion)
                    $stmtArea = $pdo->prepare("SELECT id FROM areas_servicio WHERE nombre LIKE '%ocina%' AND activa = 1 LIMIT 1");
                    $stmtArea->execute();
                    $areaCocinaId = $stmtArea->fetchColumn();

                    if ($areaCocinaId) {
                        // Asignar a todas las semanas activas del ano
                        $stmtSem = $pdo->prepare("SELECT id FROM semanas_campamento WHERE year_campamento = ? AND activa = 1");
                        $stmtSem->execute([$year]);
                        $semanas = $stmtSem->fetchAll();

                        foreach ($semanas as $sem) {
                            $stmtDist = $pdo->prepare("
                                INSERT IGNORE INTO distribucion_equipantes (equipante_id, semana_id, area_id, asignado_por)
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmtDist->execute([$nuevoId, $sem['id'], $areaCocinaId, $userId]);
                        }
                        $mensaje .= ' Asignado automaticamente al area de Cocina.';
                    }
                }
            } else {
                $stmt = $pdo->prepare("
                    UPDATE equipantes SET
                        nombre = ?, edad = ?, sexo = ?, direccion = ?,
                        correo = ?, telefono_whatsapp = ?, talla = ?,
                        semanas_disponibles = ?, iglesia = ?, observaciones = ?,
                        tipo_persona = ?, es_familia = ?, familiares_menores_12 = ?,
                        familiares_mayores_12 = ?, familiares_mayores_18 = ?, come_comedor = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $nombre, $edad, $sexo, $direccion,
                    $correo, $telefono, $talla,
                    $semanas_disp, $iglesia, $observaciones,
                    $tipo_persona, $es_familia, $menores_12,
                    $mayores_12, $mayores_18, $come_comedor,
                    $id
                ]);
                $mensaje = $labelsTipo[$tipo_persona] . ' actualizado correctamente.';
            }

            header('Location: reclutamiento.php?tipo_filtro=' . $tipo_persona . '&message=' . urlencode($mensaje));
            exit();

        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------------------
// Cargar datos para edicion
// ---------------------------------------------------------------------
$persona = null;
if ($action === 'edit' && $id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM equipantes WHERE id = ?");
        $stmt->execute([$id]);
        $persona = $stmt->fetch();
        if (!$persona) {
            $error = 'Registro no encontrado.';
            $action = 'add';
        } else {
            $tipoSel = $persona['tipo_persona'] ?? 'alumno';
            if (!in_array($tipoSel, $tiposValidos, true)) $tipoSel = 'alumno';
        }
    } catch (Exception $e) {
        $error = 'Error al cargar: ' . $e->getMessage();
        $action = 'add';
    }
}

// Semanas para los checkboxes
$semanasForm = [];
try {
    $stmtSem = $pdo->prepare("SELECT id, nombre, fecha_inicio, fecha_fin FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio ASC");
    $stmtSem->execute([$year]);
    $semanasForm = $stmtSem->fetchAll();
} catch (Exception $e) {}

$semanasSel = [];
if (!empty($persona['semanas_disponibles'])) {
    $semanasSel = array_map('intval', array_filter(explode(',', $persona['semanas_disponibles'])));
}

include '../includes/header.php';
?>

<div class="container-fluid py-3">

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h1 class="h3 mb-0">
            <i class="fas fa-user-plus text-primary"></i>
            <?php echo $action === 'add' ? 'Nuevo ' . $labelsTipo[$tipoSel] : 'Editar ' . $labelsTipo[$tipoSel]; ?>
        </h1>
        <div class="d-flex gap-2">
            <?php if ($action === 'add'): ?>
            <div class="btn-group btn-group-sm">
                <a href="?tipo=alumno" class="btn <?php echo $tipoSel==='alumno'?'btn-primary':'btn-outline-primary'; ?>">Alumno</a>
                <a href="?tipo=misionero" class="btn <?php echo $tipoSel==='misionero'?'btn-primary':'btn-outline-primary'; ?>">Misionero</a>
                <a href="?tipo=invitado" class="btn <?php echo $tipoSel==='invitado'?'btn-primary':'btn-outline-primary'; ?>">Invitado</a>
                <a href="?tipo=cocina" class="btn <?php echo $tipoSel==='cocina'?'btn-primary':'btn-outline-primary'; ?>">Cocina</a>
            </div>
            <?php endif; ?>
            <a href="reclutamiento.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>

    <form method="POST" action="?action=<?php echo $action; ?>&tipo=<?php echo $tipoSel; ?><?php echo $id ? '&id=' . $id : ''; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="tipo_persona" value="<?php echo $tipoSel; ?>">

        <div class="row g-3">
            <!-- Datos personales -->
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-id-card"></i> Datos personales</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nombre y apellidos <span class="text-danger">*</span></label>
                                <input type="text" name="nombre" class="form-control" required
                                       value="<?php echo htmlspecialchars($persona['nombre'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Edad</label>
                                <input type="number" name="edad" class="form-control" min="1" max="99"
                                       value="<?php echo htmlspecialchars($persona['edad'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Sexo</label>
                                <select name="sexo" class="form-select">
                                    <option value="">--</option>
                                    <option value="femenino" <?php echo ($persona['sexo'] ?? '')==='femenino'?'selected':''; ?>>Femenino</option>
                                    <option value="masculino" <?php echo ($persona['sexo'] ?? '')==='masculino'?'selected':''; ?>>Masculino</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Talla camiseta</label>
                                <select name="talla" class="form-select">
                                    <?php foreach (['XS','S','M','G','XL','XXL'] as $t): ?>
                                        <option value="<?php echo $t; ?>" <?php echo ($persona['talla'] ?? '')===$t?'selected':''; ?>><?php echo $t; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Direccion</label>
                                <input type="text" name="direccion" class="form-control"
                                       value="<?php echo htmlspecialchars($persona['direccion'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Correo electronico</label>
                                <input type="email" name="correo" class="form-control"
                                       value="<?php echo htmlspecialchars($persona['correo'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">WhatsApp / Telefono</label>
                                <input type="text" name="telefono_whatsapp" class="form-control"
                                       value="<?php echo htmlspecialchars($persona['telefono_whatsapp'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Iglesia / Institucion</label>
                                <input type="text" name="iglesia" class="form-control"
                                       value="<?php echo htmlspecialchars($persona['iglesia'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Semanas que considera asistir -->
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-calendar-alt"></i> Semanas que considera asistir</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($semanasForm)): ?>
                            <p class="text-muted small mb-0">No hay semanas registradas.</p>
                        <?php else: ?>
                            <div class="row g-2">
                                <?php foreach ($semanasForm as $sf): ?>
                                <div class="col-12 col-md-6">
                                    <div class="form-check">
                                        <input type="checkbox" name="semanas_disponibles[]" value="<?php echo $sf['id']; ?>"
                                               class="form-check-input" id="sem_<?php echo $sf['id']; ?>"
                                               <?php echo in_array((int)$sf['id'], $semanasSel, true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="sem_<?php echo $sf['id']; ?>">
                                            <?php echo htmlspecialchars($sf['nombre']); ?>
                                            <small class="text-muted d-block">
                                                <?php echo date('d/m/Y', strtotime($sf['fecha_inicio'])); ?> -
                                                <?php echo date('d/m/Y', strtotime($sf['fecha_fin'])); ?>
                                            </small>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Familia y comedor -->
            <div class="col-12">
                <div class="card shadow-sm border-warning">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-home"></i> Familia y comedor</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input type="checkbox" name="es_familia" value="1" class="form-check-input" id="es_fam"
                                           <?php echo !empty($persona['es_familia'])?'checked':''; ?>>
                                    <label class="form-check-label" for="es_fam">Es una familia</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Menores de 12 anos</label>
                                <input type="number" name="familiares_menores_12" class="form-control form-control-sm" min="0" max="20"
                                       value="<?php echo (int)($persona['familiares_menores_12'] ?? 0); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Mayores de 12 anos</label>
                                <input type="number" name="familiares_mayores_12" class="form-control form-control-sm" min="0" max="20"
                                       value="<?php echo (int)($persona['familiares_mayores_12'] ?? 0); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Mayores de 18 anos</label>
                                <input type="number" name="familiares_mayores_18" class="form-control form-control-sm" min="0" max="20"
                                       value="<?php echo (int)($persona['familiares_mayores_18'] ?? 0); ?>">
                            </div>
                            <div class="col-md-12 pt-2 border-top">
                                <div class="form-check">
                                    <input type="checkbox" name="come_comedor" value="1" class="form-check-input" id="come"
                                           <?php echo isset($persona['come_comedor']) ? ($persona['come_comedor'] ? 'checked' : '') : 'checked'; ?>>
                                    <label class="form-check-label" for="come">Van a comer en el comedor</label>
                                </div>
                            </div>
                        </div>
                        <small class="text-muted d-block mt-2">
                            <i class="fas fa-info-circle"></i>
                            Si es una familia, indica cuantos familiares vienen por rango de edad. Esto se suma automaticamente a los totales para cocina.
                        </small>
                    </div>
                </div>
            </div>

            <!-- Observaciones -->
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-sticky-note"></i> Observaciones</h6>
                    </div>
                    <div class="card-body">
                        <textarea name="observaciones" class="form-control" rows="2" placeholder="Notas internas..."><?php echo htmlspecialchars($persona['observaciones'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Botones -->
            <div class="col-12">
                <div class="d-flex justify-content-end gap-2">
                    <a href="reclutamiento.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i>
                        <?php echo $action === 'add' ? 'Registrar' : 'Guardar cambios'; ?>
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>