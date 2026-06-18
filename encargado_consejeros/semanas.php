<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esEncargadoConsejeros()) {
    header('Location: ../consejero/dashboard.php');
    exit();
}

$titulo = "Gestión de Semanas";
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;
$message = '';
$error = '';

// Procesar formulario
if ($_POST) {
    try {
        if ($action === 'add' || $action === 'edit') {
            $nombre          = limpiarDatos($_POST['nombre']);
            $descripcion     = limpiarDatos($_POST['descripcion']);
            $fecha_inicio    = $_POST['fecha_inicio'];
            $fecha_fin       = $_POST['fecha_fin'];
            $tipo_acampante  = $_POST['tipo_acampante'];
            $year_campamento = (int)$_POST['year_campamento'];
            $edad_min        = $_POST['edad_min'] !== '' ? (int)$_POST['edad_min'] : null;
            $edad_max        = $_POST['edad_max'] !== '' ? (int)$_POST['edad_max'] : null;
            
            if (empty($nombre)) throw new Exception("El nombre es obligatorio");
            if (empty($fecha_inicio)) throw new Exception("La fecha de inicio es obligatoria");
            if (empty($fecha_fin)) throw new Exception("La fecha de fin es obligatoria");
            if ($fecha_fin < $fecha_inicio) throw new Exception("La fecha de fin no puede ser menor a la de inicio");
            if (!in_array($tipo_acampante, ['mayores', 'ninos', 'adolescentes'])) {
                throw new Exception("Tipo de acampante inválido");
            }
            // Validación de edad
            if ($edad_min !== null && $edad_min < 0)
                throw new Exception("La edad mínima no puede ser negativa.");
            if ($edad_max !== null && $edad_max < 0)
                throw new Exception("La edad máxima no puede ser negativa.");
            if ($edad_min !== null && $edad_max !== null && $edad_min > $edad_max)
                throw new Exception("La edad mínima no puede ser mayor que la máxima.");

            if ($action === 'add') {
                $stmt = $pdo->prepare("
                    INSERT INTO semanas_campamento
                        (nombre, descripcion, fecha_inicio, fecha_fin,
                         tipo_acampante, year_campamento, activa, edad_min, edad_max)
                    VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)
                ");
                $stmt->execute([
                    $nombre, $descripcion, $fecha_inicio, $fecha_fin,
                    $tipo_acampante, $year_campamento, $edad_min, $edad_max
                ]);
                $message = "Semana creada exitosamente";
            } else {
                $stmt = $pdo->prepare("
                    UPDATE semanas_campamento
                    SET nombre=?, descripcion=?, fecha_inicio=?, fecha_fin=?,
                        tipo_acampante=?, year_campamento=?, edad_min=?, edad_max=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $nombre, $descripcion, $fecha_inicio, $fecha_fin,
                    $tipo_acampante, $year_campamento, $edad_min, $edad_max, $id
                ]);
                $message = "Semana actualizada exitosamente";
            }

            header("Location: semanas.php?message=" . urlencode($message));
            exit();
        }

        // Activar/Desactivar semana
        if ($action === 'toggle' && $id) {
            $stmt = $pdo->prepare("SELECT activa FROM semanas_campamento WHERE id = ?");
            $stmt->execute([$id]);
            $semana = $stmt->fetch();
            $nuevo_estado = $semana['activa'] ? 0 : 1;

            $stmt = $pdo->prepare("UPDATE semanas_campamento SET activa = ? WHERE id = ?");
            $stmt->execute([$nuevo_estado, $id]);

            $msg = $nuevo_estado ? "Semana activada - Los consejeros ya pueden ver los acampantes" : "Semana desactivada";
            header("Location: semanas.php?message=" . urlencode($msg));
            exit();
        }

    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Toggle desde GET
if ($action === 'toggle' && $id && !$_POST) {
    try {
        $stmt = $pdo->prepare("SELECT activa FROM semanas_campamento WHERE id = ?");
        $stmt->execute([$id]);
        $semana = $stmt->fetch();
        $nuevo_estado = $semana['activa'] ? 0 : 1;

        $stmt = $pdo->prepare("UPDATE semanas_campamento SET activa = ? WHERE id = ?");
        $stmt->execute([$nuevo_estado, $id]);

        $msg = $nuevo_estado ? "✓ Semana ACTIVADA - Consejeros pueden ver acampantes" : "Semana desactivada";
        header("Location: semanas.php?message=" . urlencode($msg));
        exit();
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Obtener datos para editar
$semana = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM semanas_campamento WHERE id = ?");
    $stmt->execute([$id]);
    $semana = $stmt->fetch();
}

// Obtener lista de semanas
$stmt = $pdo->query("SELECT s.*, 
                     COUNT(a.id) as total_acampantes
                     FROM semanas_campamento s
                     LEFT JOIN acampantes a ON a.semana_id = s.id AND a.estado = 'activo'
                     GROUP BY s.id
                     ORDER BY s.year_campamento DESC, s.fecha_inicio ASC");
$semanas = $stmt->fetchAll();

if (isset($_GET['message'])) $message = $_GET['message'];

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-calendar-week"></i> <?php echo $titulo; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Semanas</li>
            </ol>
        </nav>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($action === 'list'): ?>

<!-- Alerta de semana activa -->
<?php
$stmt_activa = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
$semana_activa = $stmt_activa->fetch();
?>
<?php if ($semana_activa): ?>
<div class="alert alert-success border-success">
    <div class="d-flex align-items-center">
        <i class="fas fa-broadcast-tower fa-2x me-3 text-success"></i>
        <div>
            <h5 class="mb-1">✓ Semana Activa: <?php echo htmlspecialchars($semana_activa['nombre']); ?></h5>
            <p class="mb-0">
                <?php echo date('d/m/Y', strtotime($semana_activa['fecha_inicio'])); ?> - 
                <?php echo date('d/m/Y', strtotime($semana_activa['fecha_fin'])); ?> | 
                <span class="badge bg-info"><?php echo ucfirst($semana_activa['tipo_acampante']); ?></span>
                Los consejeros pueden ver sus acampantes asignados a esta semana.
            </p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Sin semana activa.</strong> Los consejeros no pueden ver acampantes. Activa una semana.
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list"></i> Lista de Semanas</h5>
        <a href="semanas.php?action=add" class="btn btn-success">
            <i class="fas fa-plus"></i> Nueva Semana
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($semanas)): ?>
        <div class="text-center text-muted py-4">
            <i class="fas fa-calendar fa-3x mb-3"></i>
            <p>No hay semanas registradas</p>
            <a href="semanas.php?action=add" class="btn btn-success">
                <i class="fas fa-plus"></i> Crear Primera Semana
            </a>
        </div>
        <?php else: ?>
        <div class="row">
            <?php foreach ($semanas as $sem): ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card h-100 border-<?php echo $sem['activa'] ? 'success' : 'secondary'; ?>">
                    <div class="card-header bg-<?php echo $sem['activa'] ? 'success' : 'secondary'; ?> text-white d-flex justify-content-between">
                        <span>
                            <?php if ($sem['activa']): ?>
                                <i class="fas fa-broadcast-tower"></i> ACTIVA
                            <?php else: ?>
                                <i class="fas fa-pause-circle"></i> Inactiva
                            <?php endif; ?>
                        </span>
                        <span class="badge bg-light text-dark"><?php echo $sem['year_campamento']; ?></span>
                    </div>
                    <div class="card-body">
                        <h5 class="card-title"><?php echo htmlspecialchars($sem['nombre']); ?></h5>
                        <?php if ($sem['descripcion']): ?>
                            <p class="text-muted small"><?php echo htmlspecialchars($sem['descripcion']); ?></p>
                        <?php endif; ?>

                        <div class="mb-2">
                            <?php
                            $tipo_colors = ['mayores' => 'primary', 'ninos' => 'info', 'adolescentes' => 'warning'];
                            $tipo_icons = ['mayores' => 'fa-user-tie', 'ninos' => 'fa-child', 'adolescentes' => 'fa-user-graduate'];
                            $color = $tipo_colors[$sem['tipo_acampante']] ?? 'secondary';
                            $icon = $tipo_icons[$sem['tipo_acampante']] ?? 'fa-users';
                            ?>
                            <span class="badge bg-<?php echo $color; ?>">
                                <i class="fas <?php echo $icon; ?>"></i>
                                <?php echo ucfirst($sem['tipo_acampante']); ?>
                            </span>
                        </div>

                        <p class="mb-1">
                            <i class="fas fa-calendar-alt text-muted"></i>
                            <strong>Inicio:</strong> <?php echo date('d/m/Y', strtotime($sem['fecha_inicio'])); ?>
                        </p>
                        <p class="mb-1">
                            <i class="fas fa-calendar-check text-muted"></i>
                            <strong>Fin:</strong> <?php echo date('d/m/Y', strtotime($sem['fecha_fin'])); ?>
                        </p>
                        <p class="mb-0">
                            <i class="fas fa-users text-muted"></i>
                            <strong>Acampantes:</strong>
                            <span class="badge bg-primary"><?php echo $sem['total_acampantes']; ?></span>
                        </p>
                        
                        <?php if ($sem['edad_min'] || $sem['edad_max']): ?>
                        <p class="mb-0 mt-1">
                            <i class="fas fa-id-badge text-warning"></i>
                            <strong>Edades:</strong>
                            <span class="badge bg-warning text-dark">
                                <?php echo ($sem['edad_min'] ?? '—') . ' a ' . ($sem['edad_max'] ?? '—'); ?> años
                            </span>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <div class="btn-group w-100">
                            <a href="semanas.php?action=toggle&id=<?php echo $sem['id']; ?>"
                               class="btn btn-sm btn-<?php echo $sem['activa'] ? 'warning' : 'success'; ?>"
                               onclick="return confirm('<?php echo $sem['activa'] ? '¿Desactivar esta semana?' : '¿Activar esta semana? Los consejeros verán sus acampantes.'; ?>')">
                                <i class="fas fa-<?php echo $sem['activa'] ? 'pause' : 'play'; ?>"></i>
                                <?php echo $sem['activa'] ? 'Desactivar' : 'Activar'; ?>
                            </a>
                            <a href="semanas.php?action=edit&id=<?php echo $sem['id']; ?>"
                               class="btn btn-sm btn-outline-primary" title="Editar semana">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="cabana_edad_config.php?semana_id=<?php echo $sem['id']; ?>"
                               class="btn btn-sm btn-outline-warning" title="Límites de edad por cabaña">
                                <i class="fas fa-id-badge"></i>
                            </a>
                            <a href="acampantes.php?semana_id=<?php echo $sem['id']; ?>"
                               class="btn btn-sm btn-outline-info" title="Ver acampantes">
                                <i class="fas fa-users"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>

<div class="card">
    <div class="card-header">
        <h5><i class="fas fa-<?php echo $action === 'add' ? 'plus' : 'edit'; ?>"></i>
            <?php echo $action === 'add' ? 'Nueva' : 'Editar'; ?> Semana
        </h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label"><strong>Nombre de la Semana *</strong></label>
                        <input type="text" class="form-control" name="nombre" required
                               value="<?php echo htmlspecialchars($semana['nombre'] ?? ''); ?>"
                               placeholder="Ej: Semana 1 - Mayores">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <input type="text" class="form-control" name="descripcion"
                               value="<?php echo htmlspecialchars($semana['descripcion'] ?? ''); ?>"
                               placeholder="Ej: Campamento para mayores de 18 años">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong>Tipo de Acampante *</strong></label>
                        <select class="form-select" name="tipo_acampante" required>
                            <option value="">-- Seleccionar --</option>
                            <option value="mayores" <?php echo (isset($semana['tipo_acampante']) && $semana['tipo_acampante'] === 'mayores') ? 'selected' : ''; ?>>
                                Mayores (18+ años)
                            </option>
                            <option value="ninos" <?php echo (isset($semana['tipo_acampante']) && $semana['tipo_acampante'] === 'ninos') ? 'selected' : ''; ?>>
                                Niños
                            </option>
                            <option value="adolescentes" <?php echo (isset($semana['tipo_acampante']) && $semana['tipo_acampante'] === 'adolescentes') ? 'selected' : ''; ?>>
                                Adolescentes
                            </option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong>Año *</strong></label>
                        <input type="number" class="form-control" name="year_campamento" required
                               value="<?php echo $semana['year_campamento'] ?? date('Y'); ?>"
                               min="2020" max="2030">
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label"><strong>Fecha de Inicio *</strong></label>
                        <input type="date" class="form-control" name="fecha_inicio" required
                               value="<?php echo $semana['fecha_inicio'] ?? ''; ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong>Fecha de Fin *</strong></label>
                        <input type="date" class="form-control" name="fecha_fin" required
                               value="<?php echo $semana['fecha_fin'] ?? ''; ?>">
                    </div>

                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle"></i> ¿Cómo funciona?</h6>
                        <ul class="small mb-0">
                            <li>Crea una semana por cada turno de campamento</li>
                            <li>Asigna acampantes a cada semana</li>
                            <li><strong>Activa la semana</strong> cuando empiece ese turno</li>
                            <li>Los consejeros verán SOLO los acampantes de la semana activa</li>
                            <li>Desactiva al terminar y activa la siguiente</li>
                        </ul>
                    </div>
                </div>
            </div>

             <!-- ── Límites de Edad ────────────────────────────────────────── -->
            <div class="card mb-3 border-warning">
                <div class="card-header bg-warning bg-opacity-10">
                    <h6 class="mb-0">
                        <i class="fas fa-id-badge text-warning"></i>
                        Límites de Edad para esta Semana
                        <small class="text-muted fw-normal ms-2">
                            Opcional — deja en blanco para no aplicar límite
                        </small>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-child text-muted me-1"></i>
                                Edad mínima
                            </label>
                            <div class="input-group">
                                <input type="number"
                                       class="form-control"
                                       name="edad_min"
                                       min="0" max="99"
                                       placeholder="Sin límite"
                                       value="<?php echo $semana['edad_min'] ?? ''; ?>">
                                <span class="input-group-text">años</span>
                            </div>
                            <div class="form-text">
                                Acampantes menores a esta edad no podrán inscribirse.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-user text-muted me-1"></i>
                                Edad máxima
                            </label>
                            <div class="input-group">
                                <input type="number"
                                       class="form-control"
                                       name="edad_max"
                                       min="0" max="99"
                                       placeholder="Sin límite"
                                       value="<?php echo $semana['edad_max'] ?? ''; ?>">
                                <span class="input-group-text">años</span>
                            </div>
                            <div class="form-text">
                                Acampantes mayores a esta edad no podrán inscribirse.
                            </div>
                        </div>
                    </div>

                    <!-- Preview dinámico del rango -->
                    <div id="previewRango" class="alert alert-info mt-3 mb-0 py-2 small d-none">
                        <i class="fas fa-info-circle"></i>
                        Rango configurado:
                        <strong id="textoRango"></strong>
                        &nbsp;·&nbsp;
                        <a href="cabana_edad_config.php?semana_id=<?php echo $semana['id'] ?? ''; ?>"
                           class="alert-link" id="linkCabanas"
                           <?php echo $action === 'add' ? 'style="display:none"' : ''; ?>>
                            <i class="fas fa-home fa-xs"></i>
                            Configurar por cabaña
                        </a>
                    </div>

                    <?php
                    // Si ya tiene rango guardado (modo editar) mostrar badge
                    if (!empty($semana['edad_min']) || !empty($semana['edad_max'])): ?>
                    <div class="mt-2">
                        <span class="badge bg-success">
                            <i class="fas fa-check-circle fa-xs"></i>
                            Rango actual:
                            <?php echo ($semana['edad_min'] ?? '—') . ' a ' . ($semana['edad_max'] ?? '—'); ?> años
                        </span>
                        <?php if ($action === 'edit'): ?>
                        <a href="cabana_edad_config.php?semana_id=<?php echo $semana['id']; ?>"
                           class="btn btn-sm btn-outline-warning ms-2">
                            <i class="fas fa-home"></i>
                            Configurar límite por cabaña
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <hr>
            <div class="d-flex justify-content-between">
                <a href="semanas.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i>
                    <?php echo $action === 'add' ? 'Crear' : 'Actualizar'; ?> Semana
                </button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<script>
const inputMin  = document.querySelector('input[name="edad_min"]');
const inputMax  = document.querySelector('input[name="edad_max"]');
const preview   = document.getElementById('previewRango');
const textoRang = document.getElementById('textoRango');

function actualizarPreview() {
    const emin = inputMin?.value;
    const emax = inputMax?.value;

    if (emin !== '' || emax !== '') {
        const minTxt = emin !== '' ? emin + ' años' : 'sin mínimo';
        const maxTxt = emax !== '' ? emax + ' años' : 'sin máximo';
        textoRang.textContent = minTxt + ' — ' + maxTxt;
        preview.classList.remove('d-none');

        // Validación visual
        if (emin !== '' && emax !== '' && parseInt(emin) > parseInt(emax)) {
            preview.className = 'alert alert-danger mt-3 mb-0 py-2 small';
            textoRang.textContent = '⚠️ La edad mínima no puede superar la máxima';
        } else {
            preview.className = 'alert alert-info mt-3 mb-0 py-2 small';
        }
    } else {
        preview.classList.add('d-none');
    }
}

inputMin?.addEventListener('input', actualizarPreview);
inputMax?.addEventListener('input', actualizarPreview);
actualizarPreview(); // ejecutar al cargar en modo editar
</script>

<?php include '../includes/footer.php'; ?>