<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministrador()) {
    header('Location: dashboard.php');
    exit();
}

$titulo   = "Mantenimiento del Sistema";
$message  = '';
$error    = '';
$resultados = [];

// ── Acciones de mantenimiento ──────────────────────────────────
if ($_POST) {
    $accion = $_POST['accion'] ?? '';

    try {
        switch ($accion) {

            // 1. Verificar integridad
            case 'verificar_integridad':
                // Acampantes sin cabaña
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM acampantes WHERE cabana_id IS NULL AND estado='activo'");
                $resultados[] = ['tipo'=>'info', 'msg' => "Acampantes sin cabaña: " . $stmt->fetch()['total']];

                // Acampantes sin semana
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM acampantes WHERE semana_id IS NULL AND estado='activo'");
                $resultados[] = ['tipo'=>'info', 'msg' => "Acampantes sin semana: " . $stmt->fetch()['total']];

                // Semanas activas (debe ser solo 1)
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM semanas_campamento WHERE activa=1");
                $activas = $stmt->fetch()['total'];
                $resultados[] = [
                    'tipo' => $activas == 1 ? 'success' : 'warning',
                    'msg'  => "Semanas activas: $activas " . ($activas == 1 ? '✓' : '⚠️ Debería ser solo 1')
                ];

                // Fotos huérfanas (archivo no existe)
                $stmt = $pdo->query("SELECT foto FROM acampantes WHERE foto IS NOT NULL AND foto != ''");
                $fotos = $stmt->fetchAll();
                $huerfanas = 0;
                foreach ($fotos as $f) {
                    if (!file_exists('../' . $f['foto'])) $huerfanas++;
                }
                $resultados[] = ['tipo' => $huerfanas > 0 ? 'warning' : 'success',
                    'msg' => "Referencias de fotos sin archivo: $huerfanas"];

                $message = "Verificación completada";
                break;

            // 2. Limpiar sesiones antiguas (PHP sessions)
            case 'limpiar_sesiones':
                $path = session_save_path() ?: sys_get_temp_dir();
                $archivos = glob($path . '/sess_*') ?: [];
                $eliminados = 0;
                foreach ($archivos as $archivo) {
                    if (filemtime($archivo) < time() - 86400) { // > 1 día
                        unlink($archivo);
                        $eliminados++;
                    }
                }
                
                $message = "Sesiones antiguas eliminadas: $eliminados";
                $resultados[] = ['tipo'=>'success', 'msg' => $message];
                registrarLog($pdo, 'sesiones_limpiadas',
                    "Eliminadas {$eliminados} sesiones antiguas",
                    'sistema', 'info');
                break;

            // 3. Optimizar tablas
            case 'optimizar_tablas':
                $tablas = ['acampantes', 'cabanas', 'semanas_campamento',
                           'sesiones_consejeria', 'usuarios', 'temas_consejeria'];
                foreach ($tablas as $tabla) {
                    $pdo->query("OPTIMIZE TABLE $tabla");
                    $resultados[] = ['tipo'=>'success', 'msg' => "✓ Tabla '$tabla' optimizada"];
                }
                
                $message = "Tablas optimizadas correctamente";
                registrarLog($pdo, 'tablas_optimizadas',
                    "OPTIMIZE ejecutado en " . count($tablas) . " tablas",
                    'sistema', 'info');
                break;

            // 4. Backup en JSON (solo datos principales)
            case 'backup_datos':
                $backupDir = '../backups/';
                if (!file_exists($backupDir)) mkdir($backupDir, 0755, true);

                $datos = [];

                $tablas_backup = ['semanas_campamento', 'cabanas', 'acampantes', 'usuarios'];
                foreach ($tablas_backup as $tabla) {
                    $stmt = $pdo->query("SELECT * FROM $tabla");
                    $datos[$tabla] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                $archivo = $backupDir . 'backup_' . date('Y-m-d_H-i-s') . '.json';
                file_put_contents($archivo, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                $tamano = round(filesize($archivo) / 1024, 1);
                $message = "Backup generado: " . basename($archivo) . " ({$tamano} KB)";
                $resultados[] = ['tipo'=>'success', 'msg' => $message];
                registrarLog($pdo, 'backup_generado',
                    "Backup creado: " . basename($archivo) . " ({$tamano} KB)",
                    'sistema', 'success');
                break;

            // 5. Estadísticas del sistema
            case 'estadisticas':
                $tablas = ['acampantes', 'cabanas', 'semanas_campamento',
                           'sesiones_consejeria', 'usuarios'];
                foreach ($tablas as $tabla) {
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM $tabla");
                    $total = $stmt->fetch()['total'];
                    $resultados[] = ['tipo'=>'info', 'msg' => "Tabla '$tabla': $total registros"];
                }

                // Tamaño de uploads
                $uploadsDir = '../assets/uploads/fotos_acampantes/';
                $tamanoUploads = 0;
                $numFotos = 0;
                if (is_dir($uploadsDir)) {
                    foreach (glob($uploadsDir . '*') as $file) {
                        $tamanoUploads += filesize($file);
                        $numFotos++;
                    }
                }
                $resultados[] = ['tipo'=>'info',
                    'msg' => "Fotos almacenadas: $numFotos (" . round($tamanoUploads/1024/1024, 2) . " MB)"];

                // Backups
                $backups = glob('../backups/*.json') ?: [];
                $resultados[] = ['tipo'=>'info', 'msg' => "Backups disponibles: " . count($backups)];

                $message = "Estadísticas generadas";
                break;
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Listar backups disponibles
$backups = array_reverse(glob('../backups/*.json') ?: []);

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-tools"></i> <?php echo $titulo; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Mantenimiento</li>
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

<?php if (!empty($resultados)): ?>
<div class="card mb-4">
    <div class="card-header"><h5><i class="fas fa-list-check"></i> Resultados</h5></div>
    <div class="card-body p-0">
        <ul class="list-group list-group-flush">
            <?php foreach ($resultados as $r): ?>
            <li class="list-group-item">
                <i class="fas fa-<?php echo $r['tipo']==='success' ? 'check-circle text-success' : ($r['tipo']==='warning' ? 'exclamation-triangle text-warning' : 'info-circle text-info'); ?>"></i>
                <?php echo htmlspecialchars($r['msg']); ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <!-- Acciones -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h5><i class="fas fa-wrench"></i> Acciones de Mantenimiento</h5></div>
            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <div class="card border-info h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-stethoscope text-info"></i> Verificar Integridad</h6>
                                <p class="small text-muted">Detecta acampantes sin cabaña, semanas mal configuradas y fotos huérfanas.</p>
                                <form method="POST">
                                    <input type="hidden" name="accion" value="verificar_integridad">
                                    <button type="submit" class="btn btn-info btn-sm w-100">
                                        <i class="fas fa-play"></i> Ejecutar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card border-success h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-database text-success"></i> Optimizar Tablas</h6>
                                <p class="small text-muted">Ejecuta OPTIMIZE TABLE en todas las tablas para mejorar rendimiento.</p>
                                <form method="POST">
                                    <input type="hidden" name="accion" value="optimizar_tablas">
                                    <button type="submit" class="btn btn-success btn-sm w-100">
                                        <i class="fas fa-play"></i> Ejecutar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card border-warning h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-broom text-warning"></i> Limpiar Sesiones</h6>
                                <p class="small text-muted">Elimina archivos de sesión PHP con más de 24 horas de antigüedad.</p>
                                <form method="POST">
                                    <input type="hidden" name="accion" value="limpiar_sesiones">
                                    <button type="submit" class="btn btn-warning btn-sm w-100">
                                        <i class="fas fa-play"></i> Ejecutar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card border-primary h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-download text-primary"></i> Backup de Datos</h6>
                                <p class="small text-muted">Genera un archivo JSON con los datos principales del sistema.</p>
                                <form method="POST">
                                    <input type="hidden" name="accion" value="backup_datos">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        <i class="fas fa-play"></i> Generar Backup
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card border-secondary h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-chart-bar text-secondary"></i> Estadísticas del Sistema</h6>
                                <p class="small text-muted">Muestra conteo de registros por tabla y uso de almacenamiento.</p>
                                <form method="POST">
                                    <input type="hidden" name="accion" value="estadisticas">
                                    <button type="submit" class="btn btn-secondary btn-sm w-100">
                                        <i class="fas fa-play"></i> Ver Stats
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card border-danger h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-file-chart-line text-danger"></i> Reporte Mensual</h6>
                                <p class="small text-muted">Genera el reporte estadístico del mes seleccionado.</p>
                                <a href="../reportes/generar_reporte_mensual.php"
                                   class="btn btn-danger btn-sm w-100">
                                    <i class="fas fa-external-link-alt"></i> Ir al Reporte
                                </a>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Backups disponibles -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><h5><i class="fas fa-archive"></i> Backups Recientes</h5></div>
            <div class="card-body p-0">
                <?php if (empty($backups)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-archive fa-2x mb-2 opacity-50"></i>
                    <p class="mb-0 small">No hay backups aún</p>
                </div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach (array_slice($backups, 0, 8) as $backup): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <small class="fw-bold"><?php echo basename($backup); ?></small><br>
                            <small class="text-muted">
                                <?php echo round(filesize($backup)/1024, 1); ?> KB —
                                <?php echo date('d/m/Y H:i', filemtime($backup)); ?>
                            </small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- CRON info -->
        <div class="card mt-3">
            <div class="card-header"><h6><i class="fas fa-clock"></i> CRON Jobs</h6></div>
            <div class="card-body">
                <p class="small text-muted mb-2">Para automatizar el reporte mensual, agrega en Hostinger → CRON:</p>
                <code class="small d-block bg-light p-2 rounded">
                    0 8 1 * * php <?php echo realpath('../reportes/generar_reporte_mensual.php'); ?>
                </code>
                <small class="text-muted">Se ejecuta el día 1 de cada mes a las 8:00 AM</small>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>