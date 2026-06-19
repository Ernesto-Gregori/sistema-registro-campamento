<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministrador()) {
    header('Location: dashboard.php');
    exit();
}

$titulo     = "Mantenimiento del Sistema";
$message    = '';
$error      = '';
$resultados = [];

// ── Todas las tablas del sistema ──────────────────────────────
$TABLAS_SISTEMA = [
    'acampantes', 'cabanas', 'semanas_campamento',
    'sesiones_consejeria', 'usuarios', 'temas_consejeria',
    'consejeros_semana', 'cabana_semana_config',
    'grupos_campamento', 'sistema_logs',
];

// ── Acciones de mantenimiento ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    try {
        switch ($accion) {

            // 1. Verificar integridad
            case 'verificar_integridad':

                // Acampantes sin cabaña
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM acampantes
                                     WHERE cabana_id IS NULL AND estado = 'activo'");
                $n = $stmt->fetch()['total'];
                $resultados[] = [
                    'tipo' => $n > 0 ? 'warning' : 'success',
                    'msg'  => "Acampantes activos sin cabaña asignada: $n",
                ];

                // Acampantes sin semana
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM acampantes
                                     WHERE semana_id IS NULL AND estado = 'activo'");
                $n = $stmt->fetch()['total'];
                $resultados[] = [
                    'tipo' => $n > 0 ? 'warning' : 'success',
                    'msg'  => "Acampantes activos sin semana asignada: $n",
                ];

                // Semanas activas (debe ser exactamente 1)
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM semanas_campamento WHERE activa = 1");
                $activas = $stmt->fetch()['total'];
                $resultados[] = [
                    'tipo' => $activas == 1 ? 'success' : 'warning',
                    'msg'  => "Semanas activas: $activas" . ($activas == 1 ? ' ✓' : ' — debería ser solo 1'),
                ];

                // Cabañas sin equipo
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM cabanas
                                     WHERE activa = 1 AND (equipo IS NULL OR equipo = '')");
                $n = $stmt->fetch()['total'];
                $resultados[] = [
                    'tipo' => $n > 0 ? 'warning' : 'success',
                    'msg'  => "Cabañas activas sin equipo asignado: $n",
                ];

                // Usuarios sin rol (rol vacío)
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios
                                     WHERE rol = '' OR rol IS NULL");
                $n = $stmt->fetch()['total'];
                $resultados[] = [
                    'tipo' => $n > 0 ? 'warning' : 'success',
                    'msg'  => "Usuarios sin rol asignado: $n",
                ];

                // Usuarios inactivos
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE activo = 0");
                $n = $stmt->fetch()['total'];
                $resultados[] = [
                    'tipo' => 'info',
                    'msg'  => "Usuarios inactivos: $n",
                ];

                // Fotos huérfanas (referencia en BD pero archivo no existe)
                $stmt = $pdo->query("SELECT foto FROM acampantes
                                     WHERE foto IS NOT NULL AND foto != ''");
                $fotos     = $stmt->fetchAll();
                $huerfanas = 0;
                foreach ($fotos as $f) {
                    if (!file_exists('../' . $f['foto'])) $huerfanas++;
                }
                $resultados[] = [
                    'tipo' => $huerfanas > 0 ? 'warning' : 'success',
                    'msg'  => "Referencias de fotos sin archivo físico: $huerfanas",
                ];

                // Consistencia consejeros_semana: registros sin cabaña activa
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM consejeros_semana cs
                                     LEFT JOIN cabanas c ON cs.cabana_id = c.id
                                     WHERE c.id IS NULL OR c.activa = 0");
                $n = $stmt->fetch()['total'];
                $resultados[] = [
                    'tipo' => $n > 0 ? 'warning' : 'success',
                    'msg'  => "Asignaciones de consejeros con cabaña inexistente o inactiva: $n",
                ];

                $message = "Verificación de integridad completada";
                registrarLog($pdo, 'verificar_integridad',
                    'Verificación de integridad ejecutada', 'mantenimiento', 'info');
                break;

            // 2. Limpiar sesiones PHP antiguas
            case 'limpiar_sesiones':
                $path      = session_save_path() ?: sys_get_temp_dir();
                $archivos  = glob($path . '/sess_*') ?: [];
                $eliminados = 0;
                foreach ($archivos as $archivo) {
                    if (filemtime($archivo) < time() - 86400) {
                        unlink($archivo);
                        $eliminados++;
                    }
                }
                $message = "Sesiones antiguas eliminadas: $eliminados";
                $resultados[] = ['tipo' => 'success', 'msg' => $message];
                registrarLog($pdo, 'sesiones_limpiadas',
                    "Eliminadas {$eliminados} sesiones antiguas", 'mantenimiento', 'info');
                break;

            // 3. Optimizar tablas
            case 'optimizar_tablas':
                foreach ($TABLAS_SISTEMA as $tabla) {
                    try {
                        $pdo->query("OPTIMIZE TABLE `$tabla`");
                        $resultados[] = ['tipo' => 'success', 'msg' => "Tabla '$tabla' optimizada ✓"];
                    } catch (Exception $e) {
                        $resultados[] = ['tipo' => 'warning', 'msg' => "Tabla '$tabla': " . $e->getMessage()];
                    }
                }
                $message = "Optimización completada en " . count($TABLAS_SISTEMA) . " tablas";
                registrarLog($pdo, 'tablas_optimizadas',
                    "OPTIMIZE ejecutado en " . count($TABLAS_SISTEMA) . " tablas",
                    'mantenimiento', 'info');
                break;

            // 4. Backup JSON de datos principales
            case 'backup_datos':
                $backupDir = '../backups/';
                if (!file_exists($backupDir)) mkdir($backupDir, 0755, true);

                $tablas_backup = [
                    'semanas_campamento', 'cabanas', 'acampantes',
                    'usuarios', 'consejeros_semana', 'cabana_semana_config',
                    'grupos_campamento', 'temas_consejeria',
                ];

                $datos = [];
                foreach ($tablas_backup as $tabla) {
                    $stmt = $pdo->query("SELECT * FROM `$tabla`");
                    $datos[$tabla] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                // Quitar contraseñas del backup de usuarios por seguridad
                if (isset($datos['usuarios'])) {
                    $datos['usuarios'] = array_map(function ($u) {
                        unset($u['password']);
                        return $u;
                    }, $datos['usuarios']);
                }

                $archivo  = $backupDir . 'backup_' . date('Y-m-d_H-i-s') . '.json';
                file_put_contents($archivo, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $tamano   = round(filesize($archivo) / 1024, 1);
                $message  = "Backup generado: " . basename($archivo) . " ({$tamano} KB)";
                $resultados[] = ['tipo' => 'success', 'msg' => $message];
                registrarLog($pdo, 'backup_generado',
                    "Backup: " . basename($archivo) . " ({$tamano} KB)",
                    'mantenimiento', 'success');
                break;

            // 5. Descargar backup
            case 'descargar_backup':
                $nombre  = basename($_POST['archivo'] ?? '');
                $ruta    = '../backups/' . $nombre;
                if ($nombre && file_exists($ruta) && pathinfo($ruta, PATHINFO_EXTENSION) === 'json') {
                    header('Content-Type: application/json');
                    header('Content-Disposition: attachment; filename="' . $nombre . '"');
                    header('Content-Length: ' . filesize($ruta));
                    readfile($ruta);
                    exit();
                }
                $error = "Archivo no encontrado o no válido.";
                break;

            // 6. Eliminar backup
            case 'eliminar_backup':
                $nombre = basename($_POST['archivo'] ?? '');
                $ruta   = '../backups/' . $nombre;
                if ($nombre && file_exists($ruta) && pathinfo($ruta, PATHINFO_EXTENSION) === 'json') {
                    unlink($ruta);
                    $message = "Backup eliminado: $nombre";
                    $resultados[] = ['tipo' => 'success', 'msg' => $message];
                    registrarLog($pdo, 'backup_eliminado',
                        "Backup eliminado: $nombre", 'mantenimiento', 'warning');
                } else {
                    $error = "Archivo no encontrado.";
                }
                break;

            // 7. Limpiar logs antiguos
            case 'limpiar_logs':
                $modo = $_POST['modo_logs'] ?? 'antiguos';
                $dias = max(1, (int)($_POST['dias_logs'] ?? 90));
            
                if ($modo === 'todos') {
                    // Limpiar todos los logs sin excepción
                    $stmt = $pdo->query("DELETE FROM sistema_logs");
                    $eliminados = $stmt->rowCount();
                    $message = "Todos los logs eliminados: $eliminados registros";
                } else {
                    // Limpiar solo los más antiguos que $dias días
                    $stmt = $pdo->prepare("DELETE FROM sistema_logs
                                           WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
                    $stmt->execute([$dias]);
                    $eliminados = $stmt->rowCount();
                    $message = $eliminados > 0
                        ? "Logs eliminados (más de $dias días): $eliminados registros"
                        : "No hay logs con más de $dias días. Usa 'Limpiar todos' si quieres vaciar la tabla.";
                }
            
                $resultados[] = ['tipo' => $eliminados > 0 ? 'success' : 'info', 'msg' => $message];
            
                // El log de esta acción se registra DESPUÉS del borrado
                registrarLog($pdo, 'logs_limpiados',
                    "Eliminados {$eliminados} logs (modo: {$modo})",
                    'mantenimiento', 'info');
                break;

            // 8. Estadísticas del sistema
            case 'estadisticas':
                foreach ($TABLAS_SISTEMA as $tabla) {
                    $stmt  = $pdo->query("SELECT COUNT(*) as total FROM `$tabla`");
                    $total = $stmt->fetch()['total'];
                    $resultados[] = ['tipo' => 'info', 'msg' => "Tabla '$tabla': $total registros"];
                }

                // Fotos
                $uploadsDir    = '../assets/uploads/fotos_acampantes/';
                $tamanoUploads = 0;
                $numFotos      = 0;
                if (is_dir($uploadsDir)) {
                    foreach (glob($uploadsDir . '*') as $file) {
                        $tamanoUploads += filesize($file);
                        $numFotos++;
                    }
                }
                $resultados[] = ['tipo' => 'info',
                    'msg' => "Fotos almacenadas: $numFotos ("
                             . round($tamanoUploads / 1024 / 1024, 2) . " MB)"];

                // Backups
                $backups_count = count(glob('../backups/*.json') ?: []);
                $resultados[]  = ['tipo' => 'info', 'msg' => "Backups disponibles: $backups_count"];

                // Logs hoy
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM sistema_logs
                                     WHERE DATE(created_at) = CURDATE()");
                $resultados[] = ['tipo' => 'info',
                    'msg' => "Logs registrados hoy: " . $stmt->fetch()['total']];

                $message = "Estadísticas generadas";
                break;
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Listar backups disponibles
$backups = array_reverse(glob('../backups/*.json') ?: []);

// Conteo de logs para mostrar en tarjeta
$logs_total = 0;
$logs_hoy   = 0;
try {
    $logs_total = $pdo->query("SELECT COUNT(*) FROM sistema_logs")->fetchColumn();
    $logs_hoy   = $pdo->query("SELECT COUNT(*) FROM sistema_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
} catch (Exception $e) {}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-tools"></i> <?= $titulo ?></h1>
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
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!empty($resultados)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-list-check"></i> Resultados</h5>
    </div>
    <div class="card-body p-0">
        <ul class="list-group list-group-flush">
            <?php foreach ($resultados as $r): ?>
            <li class="list-group-item">
                <?php
                $icon = match($r['tipo']) {
                    'success' => 'check-circle text-success',
                    'warning' => 'exclamation-triangle text-warning',
                    'error'   => 'times-circle text-danger',
                    default   => 'info-circle text-info',
                };
                ?>
                <i class="fas fa-<?= $icon ?>"></i>
                <?= htmlspecialchars($r['msg']) ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<div class="row">

    <!-- ── Acciones de mantenimiento ── -->
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-wrench"></i> Acciones de Mantenimiento</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">

                    <!-- Verificar integridad -->
                    <div class="col-md-6">
                        <div class="card border-info h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-stethoscope text-info"></i> Verificar Integridad</h6>
                                <p class="small text-muted mb-3">
                                    Detecta acampantes sin cabaña o semana, semanas mal configuradas,
                                    usuarios sin rol, cabañas sin equipo y fotos huérfanas.
                                </p>
                                <form method="POST">
                                    <input type="hidden" name="accion" value="verificar_integridad">
                                    <button type="submit" class="btn btn-info btn-sm w-100">
                                        <i class="fas fa-play"></i> Ejecutar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Optimizar tablas -->
                    <div class="col-md-6">
                        <div class="card border-success h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-database text-success"></i> Optimizar Tablas</h6>
                                <p class="small text-muted mb-3">
                                    Ejecuta OPTIMIZE TABLE en las <?= count($TABLAS_SISTEMA) ?> tablas
                                    del sistema para mejorar rendimiento.
                                </p>
                                <form method="POST">
                                    <input type="hidden" name="accion" value="optimizar_tablas">
                                    <button type="submit" class="btn btn-success btn-sm w-100">
                                        <i class="fas fa-play"></i> Ejecutar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Limpiar sesiones -->
                    <div class="col-md-6">
                        <div class="card border-warning h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-broom text-warning"></i> Limpiar Sesiones</h6>
                                <p class="small text-muted mb-3">
                                    Elimina archivos de sesión PHP con más de 24 horas de antigüedad.
                                </p>
                                <form method="POST">
                                    <input type="hidden" name="accion" value="limpiar_sesiones">
                                    <button type="submit" class="btn btn-warning btn-sm w-100">
                                        <i class="fas fa-play"></i> Ejecutar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Backup de datos -->
                    <div class="col-md-6">
                        <div class="card border-primary h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-download text-primary"></i> Backup de Datos</h6>
                                <p class="small text-muted mb-3">
                                    Genera un JSON con todas las tablas principales.
                                    Las contraseñas se excluyen automáticamente.
                                </p>
                                <form method="POST">
                                    <input type="hidden" name="accion" value="backup_datos">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        <i class="fas fa-play"></i> Generar Backup
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Limpiar logs -->
                    <div class="col-md-6">
                        <div class="card border-secondary h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-trash-alt text-secondary"></i> Limpiar Logs</h6>
                                <p class="small text-muted mb-2">
                                    Actualmente: <strong><?= number_format($logs_total) ?></strong> registros
                                    (<?= $logs_hoy ?> hoy).
                                </p>
                    
                                <!-- Limpiar por antigüedad -->
                                <form method="POST" class="d-flex gap-2 align-items-center mb-2">
                                    <input type="hidden" name="accion"    value="limpiar_logs">
                                    <input type="hidden" name="modo_logs" value="antiguos">
                                    <input type="number" name="dias_logs" value="90" min="1" max="365"
                                           class="form-control form-control-sm" style="width:75px;">
                                    <label class="small text-muted mb-0 text-nowrap">días de antigüedad</label>
                                    <button type="submit" class="btn btn-secondary btn-sm flex-grow-1">
                                        <i class="fas fa-clock"></i> Limpiar antiguos
                                    </button>
                                </form>
                    
                                <!-- Limpiar todos -->
                                <form method="POST"
                                      onsubmit="return confirm('¿Eliminar TODOS los logs del sistema? Esta acción no se puede deshacer.');">
                                    <input type="hidden" name="accion"    value="limpiar_logs">
                                    <input type="hidden" name="modo_logs" value="todos">
                                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                        <i class="fas fa-trash"></i> Limpiar todos los logs
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Estadísticas -->
                    <div class="col-md-6">
                        <div class="card border-dark h-100">
                            <div class="card-body">
                                <h6><i class="fas fa-chart-bar text-dark"></i> Estadísticas del Sistema</h6>
                                <p class="small text-muted mb-3">
                                    Conteo de registros por tabla, uso de almacenamiento de fotos
                                    y backups disponibles.
                                </p>
                                <form method="POST">
                                    <input type="hidden" name="accion" value="estadisticas">
                                    <button type="submit" class="btn btn-dark btn-sm w-100">
                                        <i class="fas fa-play"></i> Ver Estadísticas
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- CRON info -->
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-clock"></i> CRON Jobs sugeridos</h6>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    Configura en Hostinger los siguientes CRON para automatizar tareas:
                </p>
                <div class="mb-2">
                    <small class="text-muted d-block mb-1">Reporte mensual (día 1 de cada mes, 8:00 AM):</small>
                    <code class="small d-block bg-light p-2 rounded">
                        0 8 1 * * php <?= realpath('../reportes/generar_reporte_mensual.php') ?>
                    </code>
                </div>
                <div>
                    <small class="text-muted d-block mb-1">Backup automático (todos los días, 2:00 AM):</small>
                    <code class="small d-block bg-light p-2 rounded">
                        0 2 * * * php <?= realpath('../admin/mantenimiento.php') ?>?cron_backup=1
                    </code>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Panel lateral: backups + logs recientes ── -->
    <div class="col-md-4">

        <!-- Backups disponibles -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-archive"></i> Backups</h5>
                <span class="badge bg-secondary"><?= count($backups) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($backups)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-archive fa-2x mb-2 opacity-50"></i>
                    <p class="mb-0 small">No hay backups aún</p>
                </div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach (array_slice($backups, 0, 8) as $backup): ?>
                    <li class="list-group-item px-3 py-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="me-2" style="min-width:0;">
                                <small class="fw-bold d-block text-truncate" style="max-width:160px;"
                                       title="<?= basename($backup) ?>">
                                    <?= basename($backup) ?>
                                </small>
                                <small class="text-muted">
                                    <?= round(filesize($backup) / 1024, 1) ?> KB —
                                    <?= date('d/m/Y H:i', filemtime($backup)) ?>
                                </small>
                            </div>
                            <div class="d-flex gap-1 flex-shrink-0">
                                <!-- Descargar -->
                                <form method="POST">
                                    <input type="hidden" name="accion"  value="descargar_backup">
                                    <input type="hidden" name="archivo" value="<?= basename($backup) ?>">
                                    <button type="submit" class="btn btn-outline-primary btn-sm py-0 px-1"
                                            title="Descargar">
                                        <i class="fas fa-download" style="font-size:11px;"></i>
                                    </button>
                                </form>
                                <!-- Eliminar -->
                                <form method="POST"
                                      onsubmit="return confirm('¿Eliminar este backup?');">
                                    <input type="hidden" name="accion"  value="eliminar_backup">
                                    <input type="hidden" name="archivo" value="<?= basename($backup) ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1"
                                            title="Eliminar">
                                        <i class="fas fa-trash" style="font-size:11px;"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($backups) > 8): ?>
                <div class="text-center py-2">
                    <small class="text-muted">... y <?= count($backups) - 8 ?> más</small>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Últimos logs del sistema -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-clipboard-list"></i> Logs Recientes</h6>
                <small class="text-muted"><?= $logs_hoy ?> hoy</small>
            </div>
            <div class="card-body p-0">
                <?php
                try {
                    $stmt_logs = $pdo->query("
                        SELECT username, accion, modulo, nivel, created_at
                        FROM sistema_logs
                        ORDER BY created_at DESC
                        LIMIT 10
                    ");
                    $logs_recientes = $stmt_logs->fetchAll();
                } catch (Exception $e) {
                    $logs_recientes = [];
                }
                ?>
                <?php if (empty($logs_recientes)): ?>
                <div class="text-center text-muted py-3">
                    <small>Sin logs registrados</small>
                </div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($logs_recientes as $log):
                        $badge = match($log['nivel']) {
                            'success' => 'bg-success',
                            'warning' => 'bg-warning text-dark',
                            'error'   => 'bg-danger',
                            default   => 'bg-secondary',
                        };
                    ?>
                    <li class="list-group-item px-3 py-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <small class="fw-semibold"><?= htmlspecialchars($log['username']) ?></small>
                                <small class="text-muted d-block" style="font-size:10px;">
                                    <?= htmlspecialchars($log['accion']) ?>
                                    <span class="badge <?= $badge ?> ms-1"
                                          style="font-size:9px;">
                                        <?= $log['nivel'] ?>
                                    </span>
                                </small>
                            </div>
                            <small class="text-muted text-nowrap ms-2" style="font-size:10px;">
                                <?= date('d/m H:i', strtotime($log['created_at'])) ?>
                            </small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>