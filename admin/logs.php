<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$titulo = "Logs del Sistema";

// ── Filtros ──────────────────────────────────────────────────
$filtro_nivel  = $_GET['nivel']   ?? '';
$filtro_modulo = $_GET['modulo']  ?? '';
$filtro_user   = trim($_GET['usuario'] ?? '');
$filtro_fecha  = $_GET['fecha']   ?? '';
$pagina        = max(1, (int)($_GET['pagina'] ?? 1));
$por_pagina    = 50;
$offset        = ($pagina - 1) * $por_pagina;

// ── Construir query con filtros ──────────────────────────────
$where  = ['1=1'];
$params = [];

if ($filtro_nivel) {
    $where[]  = 'nivel = ?';
    $params[] = $filtro_nivel;
}
if ($filtro_modulo) {
    $where[]  = 'modulo = ?';
    $params[] = $filtro_modulo;
}
if ($filtro_user) {
    $where[]  = 'username LIKE ?';
    $params[] = "%{$filtro_user}%";
}
if ($filtro_fecha) {
    $where[]  = 'DATE(created_at) = ?';
    $params[] = $filtro_fecha;
}

$whereSQL = implode(' AND ', $where);

// Total para paginación
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM sistema_logs WHERE $whereSQL");
$stmtCount->execute($params);
$total_logs  = (int)$stmtCount->fetchColumn();
$total_pages = max(1, ceil($total_logs / $por_pagina));

// Logs paginados
$stmtLogs = $pdo->prepare("
    SELECT * FROM sistema_logs 
    WHERE $whereSQL 
    ORDER BY created_at DESC 
    LIMIT $por_pagina OFFSET $offset
");
$stmtLogs->execute($params);
$logs = $stmtLogs->fetchAll();

// ── Estadísticas rápidas (últimas 24h) ──────────────────────
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(nivel = 'error')   as errores,
        SUM(nivel = 'warning') as warnings,
        SUM(nivel = 'success') as exitos,
        SUM(accion LIKE 'login%') as logins,
        COUNT(DISTINCT username) as usuarios_activos
    FROM sistema_logs
    WHERE created_at >= NOW() - INTERVAL 24 HOUR
")->fetch();

// ── Módulos disponibles para filtro ─────────────────────────
$modulos = $pdo->query("
    SELECT DISTINCT modulo FROM sistema_logs ORDER BY modulo
")->fetchAll(PDO::FETCH_COLUMN);

// Config visual
$nivel_config = [
    'info'    => ['color' => 'info',      'icon' => 'fa-info-circle'],
    'success' => ['color' => 'success',   'icon' => 'fa-check-circle'],
    'warning' => ['color' => 'warning',   'icon' => 'fa-exclamation-triangle'],
    'error'   => ['color' => 'danger',    'icon' => 'fa-times-circle'],
];

// ── Acción: limpiar logs manualmente ────────────────────────
if ($_POST && ($_POST['post_accion'] ?? '') === 'limpiar_logs') {
    $dias = (int)($_POST['dias'] ?? 30);
    $dias = max(1, min(365, $dias)); // entre 1 y 365
    $pdo->prepare("DELETE FROM sistema_logs 
                   WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)")
        ->execute([$dias]);
    $eliminados = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
    registrarLog($pdo, 'logs_limpiados',
        "Eliminados logs con más de {$dias} días ({$eliminados} registros)",
        'sistema', 'warning');
    header("Location: logs.php?limpiado=1");
    exit();
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-file-alt"></i> <?php echo $titulo; ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="dashboard.php">Dashboard</a>
                    </li>
                    <li class="breadcrumb-item active">Logs</li>
                </ol>
            </nav>
        </div>
        <!-- Limpiar logs -->
        <button class="btn btn-outline-danger btn-sm"
                data-bs-toggle="modal" data-bs-target="#modalLimpiar">
            <i class="fas fa-trash-alt"></i> Limpiar Logs
        </button>
    </div>
</div>

<?php if (isset($_GET['limpiado'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> Logs limpiados correctamente.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Estadísticas 24h -->
<div class="row mb-4">
    <div class="col-6 col-md-3 mb-2">
        <div class="card border-0 bg-light text-center py-2">
            <div class="card-body p-2">
                <h4 class="mb-0 text-dark"><?php echo $stats['total']; ?></h4>
                <small class="text-muted">Eventos (24h)</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-2">
        <div class="card border-0 bg-danger bg-opacity-10 text-center py-2">
            <div class="card-body p-2">
                <h4 class="mb-0 text-danger"><?php echo $stats['errores']; ?></h4>
                <small class="text-muted">Errores (24h)</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-2">
        <div class="card border-0 bg-warning bg-opacity-10 text-center py-2">
            <div class="card-body p-2">
                <h4 class="mb-0 text-warning"><?php echo $stats['warnings']; ?></h4>
                <small class="text-muted">Warnings (24h)</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-2">
        <div class="card border-0 bg-success bg-opacity-10 text-center py-2">
            <div class="card-body p-2">
                <h4 class="mb-0 text-success"><?php echo $stats['usuarios_activos']; ?></h4>
                <small class="text-muted">Usuarios activos (24h)</small>
            </div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <!-- Nivel -->
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Nivel</label>
                <select name="nivel" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($nivel_config as $nv => $nc): ?>
                    <option value="<?php echo $nv; ?>"
                        <?php echo $filtro_nivel === $nv ? 'selected' : ''; ?>>
                        <?php echo ucfirst($nv); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Módulo -->
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Módulo</label>
                <select name="modulo" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($modulos as $m): ?>
                    <option value="<?php echo $m; ?>"
                        <?php echo $filtro_modulo === $m ? 'selected' : ''; ?>>
                        <?php echo ucfirst($m); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Usuario -->
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">Usuario</label>
                <input type="text" name="usuario" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($filtro_user); ?>"
                       placeholder="Buscar username...">
            </div>
            <!-- Fecha -->
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Fecha</label>
                <input type="date" name="fecha" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($filtro_fecha); ?>">
            </div>
            <!-- Botones -->
            <div class="col-12 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                    <i class="fas fa-search"></i> Filtrar
                </button>
                <a href="logs.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Tabla de logs -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="fas fa-list"></i>
            <strong><?php echo number_format($total_logs); ?></strong> registros encontrados
        </span>
        <small class="text-muted">Página <?php echo $pagina; ?> de <?php echo $total_pages; ?></small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th style="width:140px">Fecha/Hora</th>
                        <th style="width:80px">Nivel</th>
                        <th style="width:100px">Módulo</th>
                        <th style="width:120px">Usuario</th>
                        <th>Acción</th>
                        <th>Descripción</th>
                        <th style="width:110px">IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                        No hay logs con los filtros aplicados
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($logs as $log):
                    $nc = $nivel_config[$log['nivel']] ?? ['color'=>'secondary','icon'=>'fa-circle'];
                ?>
                <tr>
                    <td class="small text-muted text-nowrap">
                        <?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?>
                    </td>
                    <td>
                        <span class="badge bg-<?php echo $nc['color']; ?>">
                            <i class="fas <?php echo $nc['icon']; ?>"></i>
                            <?php echo $log['nivel']; ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge bg-secondary bg-opacity-75">
                            <?php echo htmlspecialchars($log['modulo']); ?>
                        </span>
                    </td>
                    <td class="small">
                        <strong><?php echo htmlspecialchars($log['username']); ?></strong>
                        <?php if ($log['rol']): ?>
                        <br><span class="text-muted" style="font-size:10px;">
                            <?php echo htmlspecialchars($log['rol']); ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <code class="text-dark">
                            <?php echo htmlspecialchars($log['accion']); ?>
                        </code>
                    </td>
                    <td class="small text-muted">
                        <?php echo htmlspecialchars($log['descripcion'] ?? ''); ?>
                    </td>
                    <td class="small text-muted text-nowrap">
                        <i class="fas fa-network-wired" style="font-size:10px;"></i>
                        <?php echo htmlspecialchars($log['ip']); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Paginación -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer">
        <nav>
            <ul class="pagination pagination-sm mb-0 justify-content-center flex-wrap">
                <!-- Anterior -->
                <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])); ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>
                <!-- Páginas -->
                <?php
                $rango_inicio = max(1, $pagina - 2);
                $rango_fin    = min($total_pages, $pagina + 2);
                if ($rango_inicio > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>">1</a>
                    </li>
                    <?php if ($rango_inicio > 2): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $rango_inicio; $p <= $rango_fin; $p++): ?>
                <li class="page-item <?php echo $p === $pagina ? 'active' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $p])); ?>">
                        <?php echo $p; ?>
                    </a>
                </li>
                <?php endfor; ?>

                <?php if ($rango_fin < $total_pages): ?>
                    <?php if ($rango_fin < $total_pages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_pages])); ?>">
                            <?php echo $total_pages; ?>
                        </a>
                    </li>
                <?php endif; ?>

                <!-- Siguiente -->
                <li class="page-item <?php echo $pagina >= $total_pages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])); ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Modal limpiar logs -->
<div class="modal fade" id="modalLimpiar" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-trash-alt"></i> Limpiar Logs
                </h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="post_accion" value="limpiar_logs">
                <div class="modal-body">
                    <p class="small text-muted">Eliminar logs más antiguos que:</p>
                    <div class="input-group">
                        <input type="number" class="form-control" name="dias"
                               value="30" min="1" max="365">
                        <span class="input-group-text">días</span>
                    </div>
                    <small class="text-danger mt-2 d-block">
                        <i class="fas fa-exclamation-triangle"></i>
                        Esta acción no se puede deshacer
                    </small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm"
                            data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-trash"></i> Eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>