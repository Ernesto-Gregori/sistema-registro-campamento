<?php
// equipo/configurar_costos.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
verificarMantenimiento($pdo);

if (!esEquipoOAdmin()) {
    header('Location: ../default.php');
    exit();
}

$year = obtenerAnioCampamento();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mensaje = '';
$error   = '';

// Contar cuantas semanas hay en el ano
$totalSemanasAno = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM semanas_campamento WHERE year_campamento = ?");
    $stmt->execute([$year]);
    $totalSemanasAno = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Token de seguridad invalido.';
    } else {
        try {
            // Guardar costo por cada cantidad de semanas (1 hasta totalSemanasAno)
            for ($i = 1; $i <= $totalSemanasAno; $i++) {
                $clave = 'equipo_costo_' . $i . '_semana' . ($i > 1 ? 's' : '');
                $valor = (float)($_POST['costo_' . $i] ?? 0);
                $desc = 'Costo equipante: ' . $i . ' semana' . ($i > 1 ? 's' : '');

                $stmt = $pdo->prepare("INSERT INTO configuracion (clave, valor, descripcion, tipo) VALUES (?, ?, ?, 'numero') ON DUPLICATE KEY UPDATE valor = ?, descripcion = ?");
                $stmt->execute([$clave, $valor, $desc, $valor, $desc]);
            }

            // Guardar temporada completa
            $costoTemp = (float)($_POST['costo_temporada'] ?? 0);
            $stmt = $pdo->prepare("INSERT INTO configuracion (clave, valor, descripcion, tipo) VALUES ('equipo_costo_temporada', ?, 'Costo equipante: temporada completa', 'numero') ON DUPLICATE KEY UPDATE valor = ?");
            $stmt->execute([$costoTemp, $costoTemp]);

            $mensaje = 'Costos actualizados correctamente.';
            header('Location: configurar_costos.php?message=' . urlencode($mensaje));
            exit();
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// Cargar costos actuales
$costosGuardados = [];
$costoTemp = 0;
try {
    $stmt = $pdo->prepare("SELECT clave, valor FROM configuracion WHERE clave LIKE 'equipo_costo_%'");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        if ($row['clave'] === 'equipo_costo_temporada') {
            $costoTemp = (float)$row['valor'];
        } else {
            // Extraer el numero de la clave (ej: equipo_costo_3_semanas -> 3)
            if (preg_match('/equipo_costo_(\d+)_/', $row['clave'], $m)) {
                $costosGuardados[(int)$m[1]] = (float)$row['valor'];
            }
        }
    }
} catch (Exception $e) {}

include '../includes/header.php';
?>

<div class="container-fluid py-3">

<?php if (!empty($_GET['message'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0"><i class="fas fa-dollar-sign text-primary"></i> Configurar Costos de Equipantes</h1>
            <small class="text-muted">Define el costo por cantidad de semanas. Campamento <?php echo $year; ?>.</small>
        </div>
        <a href="pagos.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Volver a Pagos
        </a>
    </div>

    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        El pago se cobra por <strong>cantidad de semanas</strong>, no por semana especifica.
        Actualmente tienes <strong><?php echo $totalSemanasAno; ?> semana(s)</strong> registradas en el sistema.
        Para agregar mas opciones de pago, crea mas semanas en el panel de administracion.
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="fas fa-table"></i> Costos escalonados</h6>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <table class="table table-hover align-middle mb-3">
                    <thead class="table-dark">
                        <tr>
                            <th>Cantidad de semanas</th>
                            <th style="width:200px">Costo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 1; $i <= $totalSemanasAno; $i++): ?>
                        <tr>
                            <td><strong><?php echo $i; ?> semana<?php echo $i > 1 ? 's' : ''; ?></strong></td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="costo_<?php echo $i; ?>" class="form-control" step="0.01" min="0"
                                           value="<?php echo number_format($costosGuardados[$i] ?? 0, 2, '.', ''); ?>">
                                </div>
                            </td>
                        </tr>
                        <?php endfor; ?>
                        <tr>
                            <td><strong>Temporada completa</strong><br><small class="text-muted">Para equipantes que asisten todas las semanas</small></td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="costo_temporada" class="form-control" step="0.01" min="0"
                                           value="<?php echo number_format($costoTemp, 2, '.', ''); ?>">
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Guardar costos
                </button>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>