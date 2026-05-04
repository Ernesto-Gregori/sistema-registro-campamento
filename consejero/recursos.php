<?php  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
if (!esConsejero()) {  
    header('Location: ../admin/dashboard.php');  
    exit();  
}  
  
$titulo = "Recursos Disponibles";  
$year = $_GET['year'] ?? obtenerAnioCampamento();  
$tipo_filter = $_GET['tipo'] ?? '';  
$search = $_GET['search'] ?? '';  
  
try {  
    // Obtener recursos activos con filtros  
    $sql = "SELECT * FROM recursos WHERE activo = 1 AND year_campamento = ?";  
    $params = [$year];  
      
    if ($tipo_filter) {  
        $sql .= " AND tipo = ?";  
        $params[] = $tipo_filter;  
    }  
      
    if ($search) {  
        $sql .= " AND (titulo LIKE ? OR descripcion LIKE ?)";  
        $params[] = "%$search%";  
        $params[] = "%$search%";  
    }  
      
    $sql .= " ORDER BY tipo, titulo";  
      
    $stmt = $pdo->prepare($sql);  
    $stmt->execute($params);  
    $recursos = $stmt->fetchAll();  
      
    // Agrupar recursos por tipo  
    $recursosPorTipo = [];  
    foreach ($recursos as $recurso) {  
        $recursosPorTipo[$recurso['tipo']][] = $recurso;  
    }  
      
    // Contar recursos por tipo  
    $stmt = $pdo->prepare("SELECT tipo, COUNT(*) as total FROM recursos   
                          WHERE activo = 1 AND year_campamento = ?   
                          GROUP BY tipo");  
    $stmt->execute([$year]);  
    $conteoTipos = [];  
    while ($row = $stmt->fetch()) {  
        $conteoTipos[$row['tipo']] = $row['total'];  
    }  
      
    // Años disponibles  
    $stmt = $pdo->query("SELECT DISTINCT year_campamento FROM recursos WHERE activo = 1 ORDER BY year_campamento DESC");  
    $yearsDisponibles = $stmt->fetchAll();  
  
} catch (Exception $e) {  
    $error = "Error al cargar recursos: " . $e->getMessage();  
}  
  
include '../includes/header.php';  
?>  
  
<div class="row mb-4">  
    <div class="col-12">  
        <h1><i class="fas fa-download"></i> <?php echo $titulo; ?></h1>  
        <p class="text-muted">Campamento <?php echo $year; ?> - Material de apoyo para consejeros</p>  
        <nav aria-label="breadcrumb">  
            <ol class="breadcrumb">  
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>  
                <li class="breadcrumb-item active">Recursos</li>  
            </ol>  
        </nav>  
    </div>  
</div>  
  
<?php if (isset($error)): ?>  
    <div class="alert alert-danger">  
        <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>  
    </div>  
<?php endif; ?>  
  
<!-- Filtros y estadísticas -->  
<div class="row mb-4">  
    <div class="col-md-8">  
        <div class="card">  
            <div class="card-header">  
                <h6><i class="fas fa-filter"></i> Filtros de Búsqueda</h6>  
            </div>  
            <div class="card-body">  
                <form method="GET" class="row">  
                    <div class="col-md-3">  
                        <select name="year" class="form-select" onchange="this.form.submit()">  
                            <?php foreach ($yearsDisponibles as $yearItem): ?>  
                            <option value="<?php echo $yearItem['year_campamento']; ?>"  
                                    <?php echo $year == $yearItem['year_campamento'] ? 'selected' : ''; ?>>  
                                Campamento <?php echo $yearItem['year_campamento']; ?>  
                            </option>  
                            <?php endforeach; ?>  
                        </select>  
                    </div>  
                    <div class="col-md-3">  
                        <select name="tipo" class="form-select">  
                            <option value="">Todos los tipos</option>  
                            <option value="devocional" <?php echo $tipo_filter == 'devocional' ? 'selected' : ''; ?>>  
                                📖 Devocionales (<?php echo $conteoTipos['devocional'] ?? 0; ?>)  
                            </option>  
                            <option value="hora_silenciosa" <?php echo $tipo_filter == 'hora_silenciosa' ? 'selected' : ''; ?>>  
                                🙏 Hora Silenciosa (<?php echo $conteoTipos['hora_silenciosa'] ?? 0; ?>)  
                            </option>  
                            <option value="apoyo_consejeria" <?php echo $tipo_filter == 'apoyo_consejeria' ? 'selected' : ''; ?>>  
                                💬 Apoyo Consejería (<?php echo $conteoTipos['apoyo_consejeria'] ?? 0; ?>)  
                            </option>  
                        </select>  
                    </div>  
                    <div class="col-md-4">  
                        <input type="text" class="form-control" name="search"   
                               placeholder="Buscar recursos..."   
                               value="<?php echo htmlspecialchars($search); ?>">  
                    </div>  
                    <div class="col-md-2">  
                        <button type="submit" class="btn btn-primary w-100">  
                            <i class="fas fa-search"></i>  
                        </button>  
                    </div>  
                </form>  
            </div>  
        </div>  
    </div>  
      
    <div class="col-md-4">  
        <div class="card bg-primary text-white">  
            <div class="card-body text-center">  
                <h4><?php echo count($recursos); ?></h4>  
                <p class="mb-0">Recursos Disponibles</p>  
                <small>Total para Campamento <?php echo $year; ?></small>  
            </div>  
        </div>  
    </div>  
</div>  
  
<!-- Recursos agrupados por tipo -->  
<?php if (empty($recursos)): ?>  
    <div class="card">  
        <div class="card-body text-center py-5">  
            <i class="fas fa-folder-open fa-5x text-muted mb-3"></i>  
            <h4 class="text-muted">No hay recursos disponibles</h4>  
            <p class="text-muted">No se encontraron recursos para los filtros seleccionados</p>  
            <a href="recursos.php" class="btn btn-primary">Ver todos los recursos</a>  
        </div>  
    </div>  
<?php else: ?>  
  
    <!-- Devocionales -->  
    <?php if (isset($recursosPorTipo['devocional'])): ?>  
    <div class="card mb-4">  
        <div class="card-header bg-primary text-white">  
            <h5 class="mb-0">  
                <i class="fas fa-book-open"></i> Devocionales   
                <span class="badge bg-light text-primary"><?php echo count($recursosPorTipo['devocional']); ?></span>  
            </h5>  
        </div>  
        <div class="card-body">  
            <p class="text-muted">Material para reflexión y estudio personal o grupal</p>  
            <div class="row">  
                <?php foreach ($recursosPorTipo['devocional'] as $recurso): ?>  
                <div class="col-md-6 col-lg-4 mb-3">  
                    <div class="card h-100 border-primary">  
                        <div class="card-body">  
                            <div class="d-flex justify-content-between align-items-start mb-2">  
                                <h6 class="card-title"><?php echo htmlspecialchars($recurso['titulo']); ?></h6>  
                                <span class="badge bg-primary">  
                                    <i class="fas fa-<?php   
                                        echo $recurso['formato'] == 'pdf' ? 'file-pdf' :   
                                            ($recurso['formato'] == 'video' ? 'video' : 'file-alt');   
                                    ?>"></i>  
                                </span>  
                            </div>  
                              
                            <?php if ($recurso['descripcion']): ?>  
                            <p class="card-text small text-muted">  
                                <?php echo htmlspecialchars(substr($recurso['descripcion'], 0, 100)); ?>  
                                <?php echo strlen($recurso['descripcion']) > 100 ? '...' : ''; ?>  
                            </p>  
                            <?php endif; ?>  
                              
                            <div class="d-flex justify-content-between align-items-center">  
                                <small class="text-muted">  
                                    v<?php echo htmlspecialchars($recurso['version']); ?> •   
                                    <?php echo date('d/m/Y', strtotime($recurso['fecha_subida'])); ?>  
                                </small>  
                                <?php if ($recurso['ruta_archivo']): ?>  
                                <a href="../<?php echo $recurso['ruta_archivo']; ?>"   
                                   class="btn btn-sm btn-primary" target="_blank">  
                                    <i class="fas fa-download"></i> Ver  
                                </a>  
                                <?php endif; ?>  
                            </div>  
                        </div>  
                    </div>  
                </div>  
                <?php endforeach; ?>  
            </div>  
        </div>  
    </div>  
    <?php endif; ?>  
  
    <!-- Hora Silenciosa -->  
    <?php if (isset($recursosPorTipo['hora_silenciosa'])): ?>  
    <div class="card mb-4">  
        <div class="card-header bg-info text-white">  
            <h5 class="mb-0">  
                <i class="fas fa-praying-hands"></i> Hora Silenciosa   
                <span class="badge bg-light text-info"><?php echo count($recursosPorTipo['hora_silenciosa']); ?></span>  
            </h5>  
        </div>  
        <div class="card-body">  
            <p class="text-muted">Guías para tiempo personal de oración y reflexión</p>  
            <div class="row">  
                <?php foreach ($recursosPorTipo['hora_silenciosa'] as $recurso): ?>  
                <div class="col-md-6 col-lg-4 mb-3">  
                    <div class="card h-100 border-info">  
                        <div class="card-body">  
                            <div class="d-flex justify-content-between align-items-start mb-2">  
                                <h6 class="card-title"><?php echo htmlspecialchars($recurso['titulo']); ?></h6>  
                                <span class="badge bg-info">  
                                    <i class="fas fa-<?php   
                                        echo $recurso['formato'] == 'pdf' ? 'file-pdf' :   
                                            ($recurso['formato'] == 'video' ? 'video' : 'file-alt');   
                                    ?>"></i>  
                                </span>  
                            </div>  
                              
                            <?php if ($recurso['descripcion']): ?>  
                            <p class="card-text small text-muted">  
                                <?php echo htmlspecialchars(substr($recurso['descripcion'], 0, 100)); ?>  
                                <?php echo strlen($recurso['descripcion']) > 100 ? '...' : ''; ?>  
                            </p>  
                            <?php endif; ?>  
                              
                            <div class="d-flex justify-content-between align-items-center">  
                                <small class="text-muted">  
                                    v<?php echo htmlspecialchars($recurso['version']); ?> •   
                                    <?php echo date('d/m/Y', strtotime($recurso['fecha_subida'])); ?>  
                                </small>  
                                <?php if ($recurso['ruta_archivo']): ?>  
                                <a href="../<?php echo $recurso['ruta_archivo']; ?>"   
                                   class="btn btn-sm btn-info" target="_blank">  
                                    <i class="fas fa-download"></i> Ver  
                                </a>  
                                <?php endif; ?>  
                            </div>  
                        </div>  
                    </div>  
                </div>  
                <?php endforeach; ?>  
            </div>  
        </div>  
    </div>  
    <?php endif; ?>  
  
    <!-- Apoyo Consejería -->  
    <?php if (isset($recursosPorTipo['apoyo_consejeria'])): ?>  
    <div class="card mb-4">  
        <div class="card-header bg-success text-white">  
            <h5 class="mb-0">  
                <i class="fas fa-hands-helping"></i> Apoyo para Consejería   
                <span class="badge bg-light text-success"><?php echo count($recursosPorTipo['apoyo_consejeria']); ?></span>  
            </h5>  
        </div>  
        <div class="card-body">  
            <p class="text-muted">Material de apoyo y guías para sesiones de consejería</p>  
            <div class="row">  
                <?php foreach ($recursosPorTipo['apoyo_consejeria'] as $recurso): ?>  
                <div class="col-md-6 col-lg-4 mb-3">  
                    <div class="card h-100 border-success">  
                        <div class="card-body">  
                            <div class="d-flex justify-content-between align-items-start mb-2">  
                                <h6 class="card-title"><?php echo htmlspecialchars($recurso['titulo']); ?></h6>  
                                <span class="badge bg-success">  
                                    <i class="fas fa-<?php   
                                        echo $recurso['formato'] == 'pdf' ? 'file-pdf' :   
                                            ($recurso['formato'] == 'video' ? 'video' : 'file-alt');   
                                    ?>"></i>  
                                </span>  
                            </div>  
                              
                            <?php if ($recurso['descripcion']): ?>  
                            <p class="card-text small text-muted">  
                                <?php echo htmlspecialchars(substr($recurso['descripcion'], 0, 100)); ?>  
                                <?php echo strlen($recurso['descripcion']) > 100 ? '...' : ''; ?>  
                            </p>  
                            <?php endif; ?>  
                              
                            <div class="d-flex justify-content-between align-items-center">  
                                <small class="text-muted">  
                                    v<?php echo htmlspecialchars($recurso['version']); ?> •   
                                    <?php echo date('d/m/Y', strtotime($recurso['fecha_subida'])); ?>  
                                </small>  
                                <?php if ($recurso['ruta_archivo']): ?>  
                                <a href="../<?php echo $recurso['ruta_archivo']; ?>"   
                                   class="btn btn-sm btn-success" target="_blank">  
                                    <i class="fas fa-download"></i> Ver  
                                </a>  
                                <?php endif; ?>  
                            </div>  
                        </div>  
                    </div>  
                </div>  
                <?php endforeach; ?>  
            </div>  
        </div>  
    </div>  
    <?php endif; ?>  
  
<?php endif; ?>  
  
<!-- Información adicional -->  
<div class="row mt-4">  
    <div class="col-md-8">  
        <div class="alert alert-info">  
            <h6><i class="fas fa-info-circle"></i> Información sobre los recursos</h6>  
            <ul class="mb-0">  
                <li><strong>📖 Devocionales:</strong> Material para reflexión diaria y estudio bíblico</li>  
                <li><strong>🙏 Hora Silenciosa:</strong> Guías para tiempo personal de oración</li>  
                <li><strong>💬 Apoyo Consejería:</strong> Herramientas y material de apoyo para sesiones</li>  
                <li><strong>Formatos:</strong> PDF (documentos), Video (multimedia), Texto (documentos editables)</li>  
            </ul>  
        </div>  
    </div>  
      
    <div class="col-md-4">  
        <div class="card bg-light">  
            <div class="card-body">  
                <h6><i class="fas fa-lightbulb"></i> Consejos de uso</h6>  
                <ul class="small mb-0">  
                    <li>Descarga los recursos antes del campamento</li>  
                    <li>Revisa el material antes de usarlo</li>  
                    <li>Adapta el contenido a tu grupo</li>  
                    <li>Combina diferentes tipos de recursos</li>  
                </ul>  
            </div>  
        </div>  
    </div>  
</div>  
  
<?php include '../includes/footer.php'; ?>  