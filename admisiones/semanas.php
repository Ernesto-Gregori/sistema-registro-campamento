<?php
// Agregar al inicio de semanas.php temporalmente
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador() && !esEncargadoConsejeros()) {
    header('Location: ../login.php');
    exit();
}

$titulo  = "Semanas de Campamento";
$action  = $_GET['action'] ?? 'list';
$id      = $_GET['id']     ?? null;
$message = '';
$error   = '';

// ── CREAR / EDITAR ──────────────────────────────────────────────────────────
if ($_POST && in_array($action, ['add', 'edit'])) {
    try {
        $nombre          = trim($_POST['nombre'] ?? '');
        $fecha_inicio    = $_POST['fecha_inicio']    ?? '';
        $fecha_fin       = $_POST['fecha_fin']       ?? '';
        $costo_campamento = (float)($_POST['costo_campamento'] ?? 0);
        $tipo_acampante  = $_POST['tipo_acampante']  ?? 'jovenes';
        $activa          = isset($_POST['activa'])   ? 1 : 0;

        if (empty($nombre))       throw new Exception("El nombre es obligatorio");
        if (empty($fecha_inicio)) throw new Exception("La fecha de inicio es obligatoria");
        if (empty($fecha_fin))    throw new Exception("La fecha de fin es obligatoria");
        if ($fecha_fin < $fecha_inicio) throw new Exception("La fecha de fin no puede ser anterior al inicio");

        // Si se activa esta semana, desactivar las demás
        if ($activa) {
            $pdo->exec("UPDATE semanas_campamento SET activa = 0");
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO semanas_campamento
                    (nombre, fecha_inicio, fecha_fin, costo_campamento, tipo_acampante, activa)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $fecha_inicio, $fecha_fin, $costo_campamento, $tipo_acampante, $activa]);
            $message = "Semana creada exitosamente";
        } else {
            $stmt = $pdo->prepare("
                UPDATE semanas_campamento
                SET nombre = ?, fecha_inicio = ?, fecha_fin = ?,
                    costo_campamento = ?, tipo_acampante = ?, activa = ?
                WHERE id = ?
            ");
            $stmt->execute([$nombre, $fecha_inicio, $fecha_fin, $costo_campamento, $tipo_acampante, $activa, $id]);
            $message = "Semana actualizada exitosamente";
        }

        header("Location: semanas.php?message=" . urlencode($message));
        exit();

    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// ── ACTIVAR / DESACTIVAR rápido ─────────────────────────────────────────────
if ($action === 'activar' && $id) {
    $pdo->exec("UPDATE semanas_campamento SET activa = 0");
    $pdo->prepare("UPDATE semanas_campamento SET activa = 1 WHERE id = ?")->execute([$id]);
    header("Location: semanas.php?message=" . urlencode("Semana activada correctamente"));
    exit();
}

// ── OBTENER SEMANA PARA EDITAR ──────────────────────────────────────────────
$semana = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM semanas_campamento WHERE id = ?");
    $stmt->execute([$id]);
    $semana = $stmt->fetch();
    if (!$semana) {
        $error  = "Semana no encontrada";
        $action = 'list';
    }
}

// ── LISTA DE SEMANAS ────────────────────────────────────────────────────────
$semanas = [];
if ($action === 'list') {
    try {
        $stmt = $pdo->query("
            SELECT s.*,
                   COUNT(DISTINCT a.id)               AS total_acampantes,
                   COALESCE(SUM(a.costo_total), 0)    AS ingresos_brutos,
                   COALESCE(
                       (SELECT SUM(p.monto)
                        FROM pagos_acampante p
                        INNER JOIN acampantes a2 ON p.acampante_id = a2.id
                        WHERE a2.semana_id = s.id AND a2.estado = 'activo')
                   , 0)                                AS ingresos_cobrados
            FROM semanas_campamento s
            LEFT JOIN acampantes a ON a.semana_id = s.id AND a.estado = 'activo'
            GROUP BY s.id
            ORDER BY s.fecha_inicio DESC
        ");
        $semanas = $stmt->fetchAll();
    } catch (Exception $e) {
        $semanas = [];
        $error   = "Error cargando semanas: " . $e->getMessage();
    }
}

if (isset($_GET['message'])) $message = $_GET['message'];

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-calendar-alt"></i> <?php echo $titulo; ?></h1>
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


<?php if ($action === 'list'): ?>
<!-- ══════════════════════════════════════════════════════════════
     LISTA DE SEMANAS
═══════════════════════════════════════════════════════════════ -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-calendar-week"></i> Semanas registradas
            <span class="badge bg-secondary ms-1"><?php echo count($semanas); ?></span>
        </h5>
        <a href="semanas.php?action=add" class="btn btn-success btn-sm">
            <i class="fas fa-plus"></i> Nueva Semana
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Semana</th>
                        <th>Fechas</th>
                        <th>Tipo</th>
                        <th class="text-end">Precio base</th>
                        <th class="text-center">Acampantes</th>
                        <th class="text-end">Cobrado / Total</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center" style="width:130px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($semanas)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="fas fa-calendar-times fa-3x d-block mb-2 opacity-25"></i>
                            No hay semanas registradas
                        </td>
                    </tr>
                <?php else: ?>
                <?php foreach ($semanas as $sem):
                    $pct = $sem['ingresos_brutos'] > 0
                         ? round(($sem['ingresos_cobrados'] / $sem['ingresos_brutos']) * 100)
                         : 0;
                ?>
                <tr class="<?php echo $sem['activa'] ? 'table-success' : ''; ?>">

                    <!-- Nombre -->
                    <td>
                        <div class="fw-bold"><?php echo htmlspecialchars($sem['nombre']); ?></div>
                        <small class="text-muted">ID #<?php echo $sem['id']; ?></small>
                    </td>

                    <!-- Fechas -->
                    <td class="small">
                        <i class="fas fa-calendar text-muted"></i>
                        <?php echo date('d/m/Y', strtotime($sem['fecha_inicio'])); ?>
                        <span class="text-muted">→</span>
                        <?php echo date('d/m/Y', strtotime($sem['fecha_fin'])); ?>
                    </td>

                    <!-- Tipo -->
                    <td>
                        <span class="badge bg-info text-dark">
                            <?php echo ucfirst($sem['tipo_acampante'] ?? 'jovenes'); ?>
                        </span>
                    </td>

                    <!-- Precio base -->
                    <td class="text-end fw-bold">
                        <?php if ($sem['costo_campamento'] > 0): ?>
                            <span class="text-success">
                                $<?php echo number_format($sem['costo_campamento'], 2); ?>
                            </span>
                        <?php else: ?>
                            <span class="text-danger small">Sin precio</span>
                        <?php endif; ?>
                    </td>

                    <!-- Acampantes -->
                    <td class="text-center">
                        <span class="badge bg-primary fs-6">
                            <?php echo $sem['total_acampantes']; ?>
                        </span>
                    </td>

                    <!-- Cobrado / Total -->
                    <td class="text-end small">
                        <div>
                            $<?php echo number_format($sem['ingresos_cobrados'] ?? 0, 2); ?>
                            <span class="text-muted">/</span>
                            $<?php echo number_format($sem['ingresos_brutos'] ?? 0, 2); ?>
                        </div>
                        <div class="progress mt-1" style="height:4px; width:100px; margin-left:auto;">
                            <div class="progress-bar bg-<?php echo $pct >= 80 ? 'success' : ($pct >= 40 ? 'warning' : 'danger'); ?>"
                                 style="width:<?php echo $pct; ?>%"></div>
                        </div>
                    </td>

                    <!-- Estado -->
                    <td class="text-center">
                        <?php if ($sem['activa']): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-broadcast-tower"></i> ACTIVA
                            </span>
                        <?php else: ?>
                            <a href="semanas.php?action=activar&id=<?php echo $sem['id']; ?>"
                               class="badge bg-secondary text-decoration-none"
                               onclick="return confirm('¿Activar esta semana? La semana actual se desactivará.')">
                                Inactiva — activar
                            </a>
                        <?php endif; ?>
                    </td>

                    <!-- Acciones -->
                    <td class="text-center">
                        <a href="semanas.php?action=edit&id=<?php echo $sem['id']; ?>"
                           class="btn btn-sm btn-outline-primary" title="Editar">
                            <i class="fas fa-edit"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<?php elseif ($action === 'add' || $action === 'edit'): ?>
<!-- ══════════════════════════════════════════════════════════════
     FORMULARIO CREAR / EDITAR
═══════════════════════════════════════════════════════════════ -->
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-<?php echo $action === 'add' ? 'plus' : 'edit'; ?>"></i>
            <?php echo $action === 'add' ? 'Nueva' : 'Editar'; ?> Semana de Campamento
        </h5>
    </div>
    <div class="card-body">
        <form method="POST">

            <!-- Nombre -->
            <div class="mb-3">
                <label class="form-label fw-bold">Nombre de la semana *</label>
                <input type="text" class="form-control" name="nombre" required
                       placeholder="Ej: Semana Jóvenes 2026"
                       value="<?php echo htmlspecialchars($semana['nombre'] ?? ''); ?>">
            </div>

            <!-- Fechas -->
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label fw-bold">Fecha de inicio *</label>
                    <input type="date" class="form-control" name="fecha_inicio" required
                           value="<?php echo $semana['fecha_inicio'] ?? ''; ?>">
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label fw-bold">Fecha de fin *</label>
                    <input type="date" class="form-control" name="fecha_fin" required
                           value="<?php echo $semana['fecha_fin'] ?? ''; ?>">
                </div>
            </div>

            <!-- Costo — el campo clave -->
            <div class="mb-3">
                <label class="form-label fw-bold">
                    <i class="fas fa-dollar-sign text-success"></i>
                    Precio base del campamento *
                </label>
                <div class="input-group">
                    <span class="input-group-text">$</span>
                    <input type="number" class="form-control form-control-lg" name="costo_campamento"
                           min="0" step="0.01" required
                           placeholder="0.00"
                           value="<?php echo $semana['costo_campamento'] ?? ''; ?>">
                </div>
                <small class="text-muted">
                    Este precio se asigna automáticamente a los acampantes nuevos y a los
                    que suban por CSV sin costo registrado.
                </small>
            </div>

            <!-- Tipo -->
            <div class="mb-3">
                <label class="form-label fw-bold">Tipo de acampante</label>
                <select class="form-select" name="tipo_acampante">
                    <?php
                    $tipos = [
                        'mayores'      => 'Mayores',
                        'adolescentes' => 'Adolescentes',
                        'ninos'        => 'Niños',
                    ];
                    foreach ($tipos as $val => $label):
                    ?>
                    <option value="<?php echo $val; ?>"
                        <?php echo (($semana['tipo_acampante'] ?? 'jovenes') === $val) ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Activa -->
            <div class="mb-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="activa"
                           name="activa" value="1"
                           <?php echo ($semana['activa'] ?? 0) ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="activa">
                        Marcar como semana activa
                    </label>
                    <div class="text-muted small">
                        La semana activa es la que se usa por defecto en inscripciones,
                        check-in e importación CSV.
                    </div>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="semanas.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Cancelar
                </a>
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fas fa-save"></i>
                    <?php echo $action === 'add' ? 'Crear Semana' : 'Guardar Cambios'; ?>
                </button>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php endif; ?>

<?php include '../includes/footer.php'; ?>