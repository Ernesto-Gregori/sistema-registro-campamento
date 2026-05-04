<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$titulo = "Panel del Administrador";

// ── Estadísticas rápidas ──
try {
    $stats = [];

    // Total usuarios por rol
    $stmt = $pdo->query("SELECT rol, COUNT(*) as total FROM usuarios 
                         WHERE activo = 1 GROUP BY rol ORDER BY rol");
    $stats['usuarios'] = $stmt->fetchAll();

    // Último backup
    $backups = glob('../backups/*.json') ?: [];
    $stats['ultimo_backup'] = !empty($backups)
        ? date('d/m/Y H:i', filemtime(end($backups)))
        : 'Nunca';
    $stats['total_backups'] = count($backups);

    // Semana activa
    $stmt = $pdo->query("SELECT nombre FROM semanas_campamento 
                         WHERE activa = 1 LIMIT 1");
    $stats['semana_activa'] = $stmt->fetchColumn() ?: 'Ninguna';

    // Total acampantes año actual
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM acampantes 
                           WHERE year_campamento = ? AND estado = 'activo'");
    $stmt->execute([date('Y')]);
    $stats['acampantes_anio'] = $stmt->fetchColumn();

    // Logs recientes (si existe la tabla)
    try {
        $stmt = $pdo->query("SELECT * FROM sistema_logs 
                             ORDER BY created_at DESC LIMIT 5");
        $stats['logs'] = $stmt->fetchAll();
    } catch (Exception $e) {
        $stats['logs'] = [];
    }

} catch (Exception $e) {
    $stats = [];
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-shield-alt"></i> <?php echo $titulo; ?></h1>
        <p class="text-muted">
            <i class="fas fa-user-circle"></i>
            Bienvenido, <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></strong>
            — Administrador del Sistema
        </p>
    </div>
</div>

<!-- Tarjetas rápidas -->
<div class="row mb-4">

    <div class="col-md-3 mb-3">
        <div class="card border-primary h-100">
            <div class="card-body text-center">
                <i class="fas fa-users fa-2x text-primary mb-2"></i>
                <h4 class="text-primary">
                    <?php echo array_sum(array_column($stats['usuarios'] ?? [], 'total')); ?>
                </h4>
                <p class="mb-0 small">Usuarios Activos</p>
                <hr class="my-2">
                <?php foreach ($stats['usuarios'] ?? [] as $u): ?>
                <small class="d-block text-muted">
                    <?php echo ucfirst(str_replace('_', ' ', $u['rol'])); ?>: 
                    <strong><?php echo $u['total']; ?></strong>
                </small>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-md-3 mb-3">
        <div class="card border-success h-100">
            <div class="card-body text-center">
                <i class="fas fa-database fa-2x text-success mb-2"></i>
                <h4 class="text-success"><?php echo $stats['total_backups']; ?></h4>
                <p class="mb-0 small">Backups Disponibles</p>
                <hr class="my-2">
                <small class="text-muted">
                    Último: <strong><?php echo $stats['ultimo_backup']; ?></strong>
                </small>
            </div>
        </div>
    </div>

    <div class="col-md-3 mb-3">
        <div class="card border-info h-100">
            <div class="card-body text-center">
                <i class="fas fa-broadcast-tower fa-2x text-info mb-2"></i>
                <h5 class="text-info mt-2"><?php echo htmlspecialchars($stats['semana_activa']); ?></h5>
                <p class="mb-0 small">Semana Activa</p>
            </div>
        </div>
    </div>

    <div class="col-md-3 mb-3">
        <div class="card border-warning h-100">
            <div class="card-body text-center">
                <i class="fas fa-user-plus fa-2x text-warning mb-2"></i>
                <h4 class="text-warning"><?php echo $stats['acampantes_anio']; ?></h4>
                <p class="mb-0 small">Acampantes <?php echo date('Y'); ?></p>
            </div>
        </div>
    </div>

</div>

<!-- Módulos del admin -->
<div class="row">

    <!-- Gestión de Sistema -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-cogs"></i> Gestión del Sistema</h5>
            </div>
            <div class="card-body">
                <div class="list-group list-group-flush">

                    <a href="mantenimiento.php" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-info bg-opacity-10 p-2">
                            <i class="fas fa-tools text-info"></i>
                        </div>
                        <div>
                            <strong>Mantenimiento</strong>
                            <small class="d-block text-muted">Optimizar BD, limpiar sesiones, verificar integridad</small>
                        </div>
                        <i class="fas fa-chevron-right ms-auto text-muted"></i>
                    </a>

                    <a href="backup_manager.php" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-success bg-opacity-10 p-2">
                            <i class="fas fa-database text-success"></i>
                        </div>
                        <div>
                            <strong>Gestor de Backups</strong>
                            <small class="d-block text-muted">Generar, descargar y eliminar backups</small>
                        </div>
                        <i class="fas fa-chevron-right ms-auto text-muted"></i>
                    </a>

                    <a href="logs.php" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-warning bg-opacity-10 p-2">
                            <i class="fas fa-file-alt text-warning"></i>
                        </div>
                        <div>
                            <strong>Logs del Sistema</strong>
                            <small class="d-block text-muted">Accesos, errores y acciones registradas</small>
                        </div>
                        <i class="fas fa-chevron-right ms-auto text-muted"></i>
                    </a>

                    <a href="configuracion.php" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-secondary bg-opacity-10 p-2">
                            <i class="fas fa-sliders-h text-secondary"></i>
                        </div>
                        <div>
                            <strong>Configuración Global</strong>
                            <small class="d-block text-muted">Nombre del campamento, país, año activo</small>
                        </div>
                        <i class="fas fa-chevron-right ms-auto text-muted"></i>
                    </a>

                </div>
            </div>
        </div>
    </div>

    <!-- Usuarios y Reportes -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-users-cog"></i> Usuarios y Reportes</h5>
            </div>
            <div class="card-body">
                <div class="list-group list-group-flush">

                    <a href="gestionar_usuarios.php" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-2">
                            <i class="fas fa-users text-primary"></i>
                        </div>
                        <div>
                            <strong>Gestionar Usuarios</strong>
                            <small class="d-block text-muted">Crear, editar, desactivar todos los roles</small>
                        </div>
                        <i class="fas fa-chevron-right ms-auto text-muted"></i>
                    </a>

                    <a href="cambiar_password.php" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-danger bg-opacity-10 p-2">
                            <i class="fas fa-key text-danger"></i>
                        </div>
                        <div>
                            <strong>Gestionar Contraseñas</strong>
                            <small class="d-block text-muted">Restablecer contraseñas de cualquier usuario</small>
                        </div>
                        <i class="fas fa-chevron-right ms-auto text-muted"></i>
                    </a>

                    <a href="reporte_anual.php" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-info bg-opacity-10 p-2">
                            <i class="fas fa-chart-line text-info"></i>
                        </div>
                        <div>
                            <strong>Reporte Anual</strong>
                            <small class="d-block text-muted">Consolidado de todo el año por semanas y meses</small>
                        </div>
                        <i class="fas fa-chevron-right ms-auto text-muted"></i>
                    </a>

                    <a href="../reportes/generar_reporte_mensual.php" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-warning bg-opacity-10 p-2">
                            <i class="fas fa-calendar-alt text-warning"></i>
                        </div>
                        <div>
                            <strong>Reporte Mensual</strong>
                            <small class="d-block text-muted">Estadísticas del mes seleccionado</small>
                        </div>
                        <i class="fas fa-chevron-right ms-auto text-muted"></i>
                    </a>

                </div>
            </div>
        </div>
    </div>

</div>

<?php include '../includes/footer.php'; ?>