<?php  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
if (!esConsejero()) {  
    header('Location: ../admin/dashboard.php');  
    exit();  
}  
  
$titulo = "Estadísticas de Mi Cabaña";  
$cabana_id = $_SESSION['cabana_id'];  
$year = $_GET['year'] ?? obtenerAnioCampamento();  
  
try {  
    // Información de la cabaña  
    $stmt = $pdo->prepare("SELECT * FROM cabanas WHERE id = ?");  
    $stmt->execute([$cabana_id]);  
    $cabana = $stmt->fetch();

    // Obtener semana activa
    $stmt_sem = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
    $semana_activa = $stmt_sem->fetch();
    $semana_id_activa = $semana_activa['id'] ?? null;

    // Parámetros según semana activa o año
    $filtro_campo = $semana_id_activa ? "a.semana_id = ?" : "a.year_campamento = ?";
    $filtro_valor = $semana_id_activa ?? $year;
    $filtro_campo_simple = $semana_id_activa ? "semana_id = ?" : "year_campamento = ?";

    // Total acampantes
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM acampantes   
                          WHERE cabana_id = ? AND $filtro_campo_simple AND estado = 'activo'");  
    $stmt->execute([$cabana_id, $filtro_valor]);  
    $totalAcampantes = $stmt->fetch()['total'];  

    // Total consejerías
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT sc.acampante_id, sc.numero_sesion) as total   
                          FROM sesiones_consejeria sc   
                          JOIN acampantes a ON sc.acampante_id = a.id   
                          WHERE a.cabana_id = ? AND $filtro_campo");  
    $stmt->execute([$cabana_id, $filtro_valor]);  
    $totalConsejerias = $stmt->fetch()['total'];  

    // Estadísticas espirituales
    $stmt = $pdo->prepare("SELECT   
                          COUNT(CASE WHEN asiste_iglesia = 1 THEN 1 END) as asisten_iglesia,  
                          COUNT(CASE WHEN era_creyente_antes = 1 THEN 1 END) as eran_creyentes,  
                          COUNT(CASE WHEN recibio_cristo_semana = 1 THEN 1 END) as nuevos_creyentes,  
                          COUNT(CASE WHEN consagro_vida_fogata = 1 THEN 1 END) as consagraciones  
                          FROM acampantes   
                          WHERE cabana_id = ? AND $filtro_campo_simple AND estado = 'activo'");  
    $stmt->execute([$cabana_id, $filtro_valor]);  
    $estadisticasEspirituales = $stmt->fetch();  

    // Progreso individual de acampantes
    $stmt = $pdo->prepare("SELECT a.*,   
                          COUNT(DISTINCT sc.numero_sesion) as consejerias_realizadas  
                          FROM acampantes a  
                          LEFT JOIN sesiones_consejeria sc ON a.id = sc.acampante_id  
                          WHERE a.cabana_id = ? AND $filtro_campo AND a.estado = 'activo'  
                          GROUP BY a.id  
                          ORDER BY a.nombre");  
    $stmt->execute([$cabana_id, $filtro_valor]);  
    $progresoAcampantes = $stmt->fetchAll();  

    // Temas más tratados
    $stmt = $pdo->prepare("SELECT tc.categoria, tc.tema, COUNT(*) as veces_tratado  
                          FROM sesiones_consejeria sc  
                          JOIN acampantes a ON sc.acampante_id = a.id  
                          LEFT JOIN temas_consejeria tc ON sc.tema_id = tc.id  
                          WHERE a.cabana_id = ? AND $filtro_campo AND tc.tema IS NOT NULL  
                          GROUP BY tc.id  
                          ORDER BY veces_tratado DESC  
                          LIMIT 8");  
    $stmt->execute([$cabana_id, $filtro_valor]);  
    $temasTratados = $stmt->fetchAll();  

    // Actividad reciente
    $stmt = $pdo->prepare("SELECT DATE(sc.fecha_sesion) as fecha,   
                          COUNT(DISTINCT sc.acampante_id, sc.numero_sesion) as consejerias_dia  
                          FROM sesiones_consejeria sc  
                          JOIN acampantes a ON sc.acampante_id = a.id  
                          WHERE a.cabana_id = ? AND $filtro_campo  
                          GROUP BY DATE(sc.fecha_sesion)  
                          ORDER BY fecha DESC  
                          LIMIT 7");  
    $stmt->execute([$cabana_id, $filtro_valor]);  
    $progresoSemanal = $stmt->fetchAll();  
  
} catch (Exception $e) {  
    $error = "Error al cargar estadísticas: " . $e->getMessage();  
}  
  
include '../includes/header.php';  
?>  
  
<div class="row mb-4">  
    <div class="col-12">  
        <h1><i class="fas fa-chart-pie"></i> <?php echo $titulo; ?></h1>  
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
        <div class="alert alert-warning mb-3">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Sin semana activa.</strong> El administrador debe activar una semana. Mostrando datos del año <?php echo $year; ?>.
        </div>
        <?php endif; ?> 
        <nav aria-label="breadcrumb">  
            <ol class="breadcrumb">  
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>  
                <li class="breadcrumb-item active">Estadísticas</li>  
            </ol>  
        </nav>  
    </div>  
</div>  
  
<?php if (isset($error)): ?>  
    <div class="alert alert-danger">  
        <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>  
    </div>  
<?php endif; ?>  
  
<!-- Tarjetas de estadísticas principales -->  
<div class="row mb-4">  
    <div class="col-md-3 mb-3">  
        <div class="card card-stat text-center">  
            <div class="card-body">  
                <i class="fas fa-users mb-2"></i>  
                <h3><?php echo $totalAcampantes; ?></h3>  
                <p class="mb-0">Mis Acampantes</p>  
                <small class="text-muted-w"><?php echo $cabana['capacidad_maxima']; ?> capacidad</small>  
            </div>  
        </div>  
    </div>  
    <div class="col-md-3 mb-3">  
        <div class="card card-stat text-center">  
            <div class="card-body">  
                <i class="fas fa-comments mb-2"></i>  
                <h3><?php echo $totalConsejerias; ?></h3>  
                <p class="mb-0">Consejerías</p>  
                <small class="text-muted-w">de <?php echo $totalAcampantes * 3; ?> esperadas</small>  
            </div>  
        </div>  
    </div>  
    <div class="col-md-3 mb-3">  
        <div class="card card-stat text-center">  
            <div class="card-body">  
                <i class="fas fa-cross mb-2"></i>  
                <h3><?php echo $estadisticasEspirituales['nuevos_creyentes']; ?></h3>  
                <p class="mb-0">Nuevos Creyentes</p>  
                <small class="text-muted-w">recibieron a Cristo</small>  
            </div>  
        </div>  
    </div>  
    <div class="col-md-3 mb-3">  
        <div class="card card-stat text-center">  
            <div class="card-body">  
                <i class="fas fa-fire mb-2"></i>  
                <h3><?php echo $estadisticasEspirituales['consagraciones']; ?></h3>  
                <p class="mb-0">Consagraciones</p>  
                <small class="text-muted-w">en la fogata</small>  
            </div>  
        </div>  
    </div>  
</div>  
  
<div class="row">  
    <!-- Progreso por Acampante -->  
    <div class="col-md-8">  
        <div class="card">  
            <div class="card-header">  
                <h5><i class="fas fa-user-check"></i> Progreso Individual de Acampantes</h5>  
            </div>  
            <div class="card-body">  
                <?php if (empty($progresoAcampantes)): ?>  
                    <div class="text-center text-muted py-4">  
                        <i class="fas fa-users fa-3x mb-3"></i>  
                        <p>No tienes acampantes asignados</p>  
                    </div>  
                <?php else: ?>  
                <div class="table-responsive">  
                    <table class="table table-hover">  
                        <thead>  
                            <tr>  
                                <th>Acampante</th>  
                                <th>Iglesia</th>  
                                <th>Consejerías</th>  
                                <th>Estado Espiritual</th>  
                                <th>Progreso</th>  
                            </tr>  
                        </thead>  
                        <tbody>  
                            <?php foreach ($progresoAcampantes as $acampante): ?>  
                            <tr>  
                                <td><strong><?php echo htmlspecialchars($acampante['nombre']); ?></strong></td>  
                                <td><?php echo htmlspecialchars($acampante['iglesia']); ?></td>  
                                <td>  
                                    <span class="badge <?php echo $acampante['consejerias_realizadas'] >= 3 ? 'bg-success' : ($acampante['consejerias_realizadas'] >= 1 ? 'bg-warning' : 'bg-danger'); ?>">  
                                        <?php echo $acampante['consejerias_realizadas']; ?>/3  
                                    </span>  
                                </td>  
                                <td>  
                                    <div class="d-flex flex-wrap gap-1">  
                                        <?php if ($acampante['asiste_iglesia']): ?>  
                                            <span class="badge bg-info">Asiste iglesia</span>  
                                        <?php endif; ?>  
                                        <?php if ($acampante['era_creyente_antes']): ?>  
                                            <span class="badge bg-primary">Era creyente</span>  
                                        <?php endif; ?>  
                                        <?php if ($acampante['recibio_cristo_semana']): ?>  
                                            <span class="badge bg-success">Nuevo creyente</span>  
                                        <?php endif; ?>  
                                        <?php if ($acampante['consagro_vida_fogata']): ?>  
                                            <span class="badge bg-warning">Consagró vida</span>  
                                        <?php endif; ?>  
                                    </div>  
                                </td>  
                                <td>  
                                    <?php   
                                    $progreso = ($acampante['consejerias_realizadas'] / 3) * 100;  
                                    $color = $progreso >= 100 ? 'success' : ($progreso >= 33 ? 'warning' : 'danger');  
                                    ?>  
                                    <div class="progress" style="height: 8px;">  
                                        <div class="progress-bar bg-<?php echo $color; ?>"   
                                             style="width: <?php echo min(100, $progreso); ?>%"></div>  
                                    </div>  
                                    <small class="text-muted"><?php echo round($progreso, 1); ?>%</small>  
                                </td>  
                            </tr>  
                            <?php endforeach; ?>  
                        </tbody>  
                    </table>  
                </div>  
                <?php endif; ?>  
            </div>  
        </div>  
          
        <!-- Progreso Semanal -->  
        <div class="card mt-4">  
            <div class="card-header">  
                <h6><i class="fas fa-calendar-week"></i> Actividad Reciente</h6>  
            </div>  
            <div class="card-body">  
                <?php if (empty($progresoSemanal)): ?>  
                    <p class="text-muted text-center">No hay consejerías registradas aún</p>  
                <?php else: ?>  
                    <div class="row">  
                        <?php foreach ($progresoSemanal as $dia): ?>  
                        <div class="col-md-4 mb-2">  
                            <div class="card bg-light">  
                                <div class="card-body text-center py-2">  
                                    <small class="text-muted d-block"><?php echo date('d/m/Y', strtotime($dia['fecha'])); ?></small>  
                                    <strong class="text-primary"><?php echo $dia['consejerias_dia']; ?> consejería(s)</strong>  
                                </div>  
                            </div>  
                        </div>  
                        <?php endforeach; ?>  
                    </div>  
                <?php endif; ?>  
            </div>  
        </div>  
    </div>  
      
    <!-- Panel lateral -->  
    <div class="col-md-4">  
        <!-- Resumen Espiritual -->  
        <div class="card">  
            <div class="card-header">  
                <h6><i class="fas fa-cross"></i> Resumen Espiritual</h6>  
            </div>  
            <div class="card-body">  
                <div class="row text-center">  
                    <div class="col-6 mb-3">  
                        <h4 class="text-info"><?php echo $estadisticasEspirituales['asisten_iglesia']; ?></h4>  
                        <small class="text-muted">Asisten a iglesia</small>  
                    </div>  
                    <div class="col-6 mb-3">  
                        <h4 class="text-primary"><?php echo $estadisticasEspirituales['eran_creyentes']; ?></h4>  
                        <small class="text-muted">Eran creyentes</small>  
                    </div>  
                    <div class="col-6">  
                        <h4 class="text-success"><?php echo $estadisticasEspirituales['nuevos_creyentes']; ?></h4>  
                        <small class="text-muted">Nuevos creyentes</small>  
                    </div>  
                    <div class="col-6">  
                        <h4 class="text-warning"><?php echo $estadisticasEspirituales['consagraciones']; ?></h4>  
                        <small class="text-muted">Consagraciones</small>  
                    </div>  
                </div>  
                  
                <hr>  
                  
                <div class="text-center">  
                    <h6>Progreso General</h6>  
                    <?php   
                    $progresoGeneral = $totalAcampantes > 0 ? ($totalConsejerias / ($totalAcampantes * 3)) * 100 : 0;  
                    ?>  
                    <div class="progress mb-2" style="height: 20px;">  
                        <div class="progress-bar" style="width: <?php echo min(100, $progresoGeneral); ?>%">  
                            <?php echo round($progresoGeneral, 1); ?>%  
                        </div>  
                    </div>  
                    <small class="text-muted">  
                        <?php echo $totalConsejerias; ?> de <?php echo $totalAcampantes * 3; ?> consejerías esperadas  
                    </small>  
                </div>  
            </div>  
        </div>  
          
        <!-- Temas más tratados -->  
        <div class="card mt-3">  
            <div class="card-header">  
                <h6><i class="fas fa-list"></i> Mis Temas Más Tratados</h6>  
            </div>  
            <div class="card-body">  
                <?php if (empty($temasTratados)): ?>  
                    <p class="text-muted text-center">No hay temas registrados aún</p>  
                <?php else: ?>  
                    <?php foreach ($temasTratados as $index => $tema): ?>  
                    <div class="d-flex justify-content-between align-items-center mb-2">  
                        <div>  
                            <strong><?php echo htmlspecialchars($tema['tema']); ?></strong>  
                            <br>  
                            <small class="text-muted"><?php echo htmlspecialchars($tema['categoria']); ?></small>  
                        </div>  
                        <span class="badge bg-primary"><?php echo $tema['veces_tratado']; ?></span>  
                    </div>  
                    <?php if ($index < count($temasTratados) - 1): ?>  
                    <hr class="my-2">  
                    <?php endif; ?>  
                    <?php endforeach; ?>  
                <?php endif; ?>  
            </div>  
        </div>  
          
        <!-- Información de la cabaña -->  
        <div class="card mt-3">  
            <div class="card-header">  
                <h6><i class="fas fa-home"></i> Información de Mi Cabaña</h6>  
            </div>  
            <div class="card-body">  
                <p><strong>Nombre:</strong><br><?php echo htmlspecialchars($cabana['nombre_cabana']); ?></p>  
                <p><strong>Capacidad:</strong><br><?php echo $cabana['capacidad_maxima']; ?> acampantes</p>  
                <p><strong>Ocupación Actual:</strong><br>  
                   <?php echo $totalAcampantes; ?>/<?php echo $cabana['capacidad_maxima']; ?>  
                   (<?php echo round(($totalAcampantes / $cabana['capacidad_maxima']) * 100, 1); ?>%)  
                </p>  
                  
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