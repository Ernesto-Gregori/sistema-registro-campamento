<?php  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin(); 
verificarMantenimiento($pdo);
if (!esConsejero()) {  
    header('Location: ../admin/dashboard.php');  
    exit();  
}  
  
$titulo = "Dashboard Consejero";  
$cabana_id = $_SESSION['cabana_id'];  
  
// Obtener información de la cabaña  
try {  
    $stmt = $pdo->prepare("SELECT * FROM cabanas WHERE id = ?");  
    $stmt->execute([$cabana_id]);  
    $cabana = $stmt->fetch();  
  
    if (!$cabana) {  
        throw new Exception("Cabaña no encontrada");  
    }  
  
    // Obtener semana activa
    $stmt_sem = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
    $semana_activa = $stmt_sem->fetch();
    $semana_id_activa = $semana_activa['id'] ?? null;

    $year_actual = obtenerAnioCampamento();

    // Mis acampantes - solo semana activa
    if ($semana_id_activa) {
        $stmt = $pdo->prepare("SELECT * FROM acampantes   
                              WHERE cabana_id = ? AND semana_id = ? AND estado = 'activo'  
                              ORDER BY nombre");
        $stmt->execute([$cabana_id, $semana_id_activa]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM acampantes   
                              WHERE cabana_id = ? AND year_campamento = ? AND estado = 'activo'  
                              ORDER BY nombre");
        $stmt->execute([$cabana_id, $year_actual]);
    }
    $misAcampantes = $stmt->fetchAll();

    // Consejerías realizadas - solo semana activa
    if ($semana_id_activa) {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT sc.numero_sesion, sc.acampante_id) as total   
                              FROM sesiones_consejeria sc  
                              JOIN acampantes a ON sc.acampante_id = a.id  
                              WHERE a.cabana_id = ? AND a.semana_id = ?");
        $stmt->execute([$cabana_id, $semana_id_activa]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT sc.numero_sesion, sc.acampante_id) as total   
                              FROM sesiones_consejeria sc  
                              JOIN acampantes a ON sc.acampante_id = a.id  
                              WHERE a.cabana_id = ? AND a.year_campamento = ?");
        $stmt->execute([$cabana_id, $year_actual]);
    }
    $totalConsejerias = $stmt->fetch()['total'] ?? 0;

    // Cálculos de progreso  
    $totalAcampantes = count($misAcampantes);  
    $sesionesEsperadas = $totalAcampantes * 3;
    $sesionesPendientes = max(0, $sesionesEsperadas - $totalConsejerias);  

    // Recursos disponibles  
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM recursos   
                          WHERE year_campamento = ? AND activo = 1");  
    $stmt->execute([$year_actual]);  
    $totalRecursos = $stmt->fetch()['total'] ?? 0;  
  
} catch (Exception $e) {  
    $error = "Error al cargar información: " . $e->getMessage();  
}  
  
include '../includes/header.php';  
?>  
  
<div class="row mb-4">  
    <div class="col-12">  
        <h1><i class="fas fa-user-graduate"></i> Dashboard Consejero</h1>  
        <p class="text-muted">  
            Cabaña: <strong><?php echo htmlspecialchars($cabana['nombre_cabana']); ?></strong> |
            <?php if ($semana_activa): ?>
                <span class="badge bg-success">
                    <i class="fas fa-broadcast-tower"></i>
                    <?php echo htmlspecialchars($semana_activa['nombre']); ?>
                </span>
            <?php else: ?>
                <span class="badge bg-warning text-dark">
                    <i class="fas fa-exclamation-triangle"></i> Sin semana activa
                </span>
            <?php endif; ?>
        </p>
        
        <?php if (!$semana_activa): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>No hay semana activa.</strong> El administrador debe activar una semana para que puedas ver tus acampantes.
        </div>
        <?php endif; ?>  
    </div>  
</div>  
  
<?php if (isset($error)): ?>  
    <div class="alert alert-danger">  
        <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>  
    </div>  
<?php endif; ?>  
  
<!-- Tarjetas de estadísticas -->  
<div class="row mb-4">  
    <div class="col-md-3 mb-3">  
        <div class="card card-stat text-center">  
            <div class="card-body">  
                <i class="fas fa-users mb-2"></i>  
                <h3><?php echo $totalAcampantes; ?></h3>  
                <p class="mb-0">Mis Acampantes</p>  
            </div>  
        </div>  
    </div>  
    <div class="col-md-3 mb-3">  
        <div class="card card-stat text-center">  
            <div class="card-body">  
                <i class="fas fa-comments mb-2"></i>  
                <h3><?php echo $totalConsejerias; ?></h3>  
                <p class="mb-0">Consejerías Hechas</p>  
            </div>  
        </div>  
    </div>  
    <div class="col-md-3 mb-3">  
        <div class="card card-stat text-center">  
            <div class="card-body">  
                <i class="fas fa-hourglass-half mb-2"></i>  
                <h3><?php echo $sesionesPendientes; ?></h3>  
                <p class="mb-0">Pendientes</p>  
            </div>  
        </div>  
    </div>  
    <div class="col-md-3 mb-3">  
        <div class="card card-stat text-center">  
            <div class="card-body">  
                <i class="fas fa-file-alt mb-2"></i>  
                <h3><?php echo $totalRecursos; ?></h3>  
                <p class="mb-0">Recursos</p>  
            </div>  
        </div>  
    </div>  
</div>  
  
<div class="row">  
    <!-- Mis acampantes -->  
    <div class="col-md-8 mb-4">  
        <div class="card">  
            <div class="card-header d-flex justify-content-between align-items-center">  
                <h5><i class="fas fa-users"></i> Mis Acampantes</h5>  
                <a href="mis_acampantes.php" class="btn btn-sm btn-primary">Ver detalles</a>  
            </div>  
            <div class="card-body">  
                <?php if (empty($misAcampantes)): ?>  
                    <div class="text-center text-muted py-4">  
                        <i class="fas fa-users fa-3x mb-3"></i>  
                        <p>No tienes acampantes asignados aún</p>  
                        <small>Los administradores deben asignar acampantes a tu cabaña</small>  
                    </div>  
                <?php else: ?>  
                <div class="table-responsive">  
                    <table class="table table-hover">  
                        <thead>  
                            <tr>  
                                <th>Nombre</th>  
                                <th>Iglesia</th>  
                                <th>Estado</th>  
                                <th>Consejerías</th>  
                                <th>Acciones</th>  
                            </tr>  
                        </thead>  
                        <tbody>  
                            <?php foreach ($misAcampantes as $acampante):   
                                // Contar consejerías de este acampante  
                                $stmt = $pdo->prepare("SELECT COUNT(DISTINCT numero_sesion) as total FROM sesiones_consejeria WHERE acampante_id = ?");  
                                $stmt->execute([$acampante['id']]);  
                                $consejerias = $stmt->fetch()['total'] ?? 0;  
                            ?>  
                            <tr>  
                                <td><strong><?php echo htmlspecialchars($acampante['nombre']); ?></strong></td>  
                                <td><?php echo htmlspecialchars($acampante['iglesia']); ?></td>  
                                <td>  
                                    <?php if ($acampante['recibio_cristo_semana']): ?>  
                                        <span class="badge bg-success">Nuevo Creyente</span>  
                                    <?php elseif ($acampante['era_creyente_antes']): ?>  
                                        <span class="badge bg-primary">Creyente</span>  
                                    <?php else: ?>  
                                        <span class="badge bg-warning">En Proceso</span>  
                                    <?php endif; ?>  
                                </td> 
                                <td>  
                                    <span class="badge <?php echo $consejerias >= 3 ? 'bg-success' : ($consejerias >= 1 ? 'bg-warning' : 'bg-danger'); ?>">  
                                        <?php echo $consejerias; ?>/3  
                                    </span>  
                                </td>  
                                <td>  
                                    <div class="btn-group">  
                                        <a href="consejerias.php?acampante_id=<?php echo $acampante['id']; ?>"   
                                           class="btn btn-sm btn-primary" title="Nueva consejería">  
                                            <i class="fas fa-plus"></i>  
                                        </a>  
                                        <a href="mis_acampantes.php?id=<?php echo $acampante['id']; ?>"   
                                           class="btn btn-sm btn-info" title="Ver detalle">  
                                            <i class="fas fa-eye"></i>  
                                        </a>  
                                    </div>  
                                </td>  
                            </tr>  
                            <?php endforeach; ?>  
                        </tbody>  
                    </table>  
                </div>  
                <?php endif; ?>  
            </div>  
        </div>  
    </div>  
  
    <!-- Panel lateral -->  
    <div class="col-md-4 mb-4">  
        <!-- Acciones rápidas -->  
        <div class="card">  
            <div class="card-header">  
                <h5><i class="fas fa-bolt"></i> Acciones Rápidas</h5>  
            </div>  
            <div class="card-body">  
                <div class="d-grid gap-2">  
                    <a href="mis_acampantes.php" class="btn btn-primary">  
                        <i class="fas fa-users"></i> Ver Mis Acampantes  
                    </a>  
                    <a href="consejerias.php" class="btn btn-success">  
                        <i class="fas fa-plus"></i> Nueva Consejería  
                    </a>  
                    <a href="estadisticas.php" class="btn btn-info">  
                        <i class="fas fa-chart-pie"></i> Ver Estadísticas  
                    </a>  
                    <a href="recursos.php" class="btn btn-secondary">  
                        <i class="fas fa-download"></i> Ver Recursos  
                    </a>  
                </div>    
            </div>  
        </div>  
  
        <!-- Progreso general -->  
        <div class="card mt-3">  
            <div class="card-header">  
                <h5><i class="fas fa-chart-pie"></i> Mi Progreso</h5>  
            </div>  
            <div class="card-body">  
                <?php   
                $porcentajeProgreso = $sesionesEsperadas > 0 ? ($totalConsejerias / $sesionesEsperadas) * 100 : 0;  
                ?>  
                <div class="text-center mb-3">  
                    <div class="progress mb-2" style="height: 20px;">  
                        <div class="progress-bar" role="progressbar"   
                             style="width: <?php echo min(100, $porcentajeProgreso); ?>%">  
                            <?php echo round(min(100, $porcentajeProgreso), 1); ?>%  
                        </div>  
                    </div>  
                    <small class="text-muted">  
                        <?php echo $totalConsejerias; ?> de <?php echo $sesionesEsperadas; ?> sesiones esperadas  
                    </small>  
                </div>  
  
                <hr>  
  
                <div class="row text-center">  
                    <div class="col-6">  
                        <h6 class="text-success">  
                            <?php   
                            $completados = 0;  
                            foreach ($misAcampantes as $a) {  
                                $stmt = $pdo->prepare("SELECT COUNT(DISTINCT numero_sesion) as total FROM sesiones_consejeria WHERE acampante_id = ?");  
                                $stmt->execute([$a['id']]);  
                                if ($stmt->fetch()['total'] >= 3) $completados++;  
                            }  
                            echo $completados;  
                            ?>  
                        </h6>  
                        <small class="text-muted">Completados</small>  
                    </div>  
                    <div class="col-6">  
                        <h6 class="text-warning">  
                            <?php   
                            $enProceso = 0;  
                            foreach ($misAcampantes as $a) {  
                                $stmt = $pdo->prepare("SELECT COUNT(DISTINCT numero_sesion) as total FROM sesiones_consejeria WHERE acampante_id = ?");  
                                $stmt->execute([$a['id']]);  
                                $total = $stmt->fetch()['total'];  
                                if ($total > 0 && $total < 3) $enProceso++;  
                            }  
                            echo $enProceso;  
                            ?>  
                        </h6>  
                        <small class="text-muted">En Proceso</small>  
                    </div>  
                </div>  
            </div>  
        </div>  
  
        <!-- Información de la cabaña -->  
        <div class="card mt-3">  
            <div class="card-header">  
                <h6><i class="fas fa-home"></i> Mi Cabaña</h6>  
            </div>  
            <div class="card-body">  
                <p><strong>Capacidad:</strong> <?php echo $cabana['capacidad_maxima']; ?> personas</p>  
                <p><strong>Ocupación:</strong> <?php echo $totalAcampantes; ?>/<?php echo $cabana['capacidad_maxima']; ?></p>  
                  
                <?php if ($cabana['consejero_principal']): ?>  
                <p><strong>Consejero Principal:</strong><br>  
                   <small><?php echo htmlspecialchars($cabana['consejero_principal']); ?></small></p>  
                <?php endif; ?>  
                  
                <?php if ($cabana['consejero_asistente']): ?>  
                <p><strong>Consejero Asistente:</strong><br>  
                   <small><?php echo htmlspecialchars($cabana['consejero_asistente']); ?></small></p>  
                <?php endif; ?>  
            </div>  
        </div>  
    </div>  
</div>  
  
<?php include '../includes/footer.php'; ?>  