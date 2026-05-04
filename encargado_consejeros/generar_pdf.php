<?php  
// Solo funciones - NO HTML  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
if (!esEncargadoConsejeros()) { 
    header('Location: ../consejero/dashboard.php');  
    exit();  
}  
  
function obtenerReporteGeneral($pdo, $year, $semana_id = null) {  
    $data = [];  
      
    // Total acampantes
    if ($semana_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM acampantes WHERE semana_id = ? AND estado = 'activo'");
        $stmt->execute([$semana_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM acampantes WHERE year_campamento = ? AND estado = 'activo'");
        $stmt->execute([$year]);
    }
    $data['total_acampantes'] = $stmt->fetch()['total'];
      
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cabanas WHERE activa = 1");
    $data['total_cabanas'] = $stmt->fetch()['total'];
      
    // Total consejerías
    if ($semana_id) {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT sc.acampante_id, sc.numero_sesion) as total   
                              FROM sesiones_consejeria sc   
                              JOIN acampantes a ON sc.acampante_id = a.id   
                              WHERE a.semana_id = ?");
        $stmt->execute([$semana_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT sc.acampante_id, sc.numero_sesion) as total   
                              FROM sesiones_consejeria sc   
                              JOIN acampantes a ON sc.acampante_id = a.id   
                              WHERE a.year_campamento = ?");
        $stmt->execute([$year]);
    }
    $data['total_consejerias'] = $stmt->fetch()['total'];
      
    // Estadísticas espirituales
    if ($semana_id) {
        $stmt = $pdo->prepare("SELECT   
                              COUNT(CASE WHEN asiste_iglesia = 1 THEN 1 END) as asisten_iglesia,  
                              COUNT(CASE WHEN era_creyente_antes = 1 THEN 1 END) as eran_creyentes,  
                              COUNT(CASE WHEN recibio_cristo_semana = 1 THEN 1 END) as nuevos_creyentes,  
                              COUNT(CASE WHEN consagro_vida_fogata = 1 THEN 1 END) as consagraciones  
                              FROM acampantes WHERE semana_id = ? AND estado = 'activo'");
        $stmt->execute([$semana_id]);
    } else {
        $stmt = $pdo->prepare("SELECT   
                              COUNT(CASE WHEN asiste_iglesia = 1 THEN 1 END) as asisten_iglesia,  
                              COUNT(CASE WHEN era_creyente_antes = 1 THEN 1 END) as eran_creyentes,  
                              COUNT(CASE WHEN recibio_cristo_semana = 1 THEN 1 END) as nuevos_creyentes,  
                              COUNT(CASE WHEN consagro_vida_fogata = 1 THEN 1 END) as consagraciones  
                              FROM acampantes WHERE year_campamento = ? AND estado = 'activo'");
        $stmt->execute([$year]);
    }
    $data['estadisticas_espirituales'] = $stmt->fetch();
      
    return $data;  
}  
  
function obtenerReporteCabanas($pdo, $year, $semana_id = null) {
    if ($semana_id) {
        $stmt = $pdo->prepare("SELECT c.id, c.nombre_cabana, c.consejero_principal, c.capacidad_maxima,  
                              COUNT(a.id) as total_acampantes,  
                              COUNT(CASE WHEN a.recibio_cristo_semana = 1 THEN 1 END) as nuevos_creyentes,  
                              COUNT(CASE WHEN a.consagro_vida_fogata = 1 THEN 1 END) as consagraciones,  
                              COUNT(CASE WHEN a.asiste_iglesia = 1 THEN 1 END) as asisten_iglesia  
                              FROM cabanas c   
                              LEFT JOIN acampantes a ON c.id = a.cabana_id 
                                  AND a.semana_id = ? 
                                  AND a.estado = 'activo'  
                              WHERE c.activa = 1  
                              GROUP BY c.id, c.nombre_cabana, c.consejero_principal, c.capacidad_maxima  
                              ORDER BY c.nombre_cabana");
        $stmt->execute([$semana_id]);
    } else {
        $stmt = $pdo->prepare("SELECT c.id, c.nombre_cabana, c.consejero_principal, c.capacidad_maxima,  
                              COUNT(a.id) as total_acampantes,  
                              COUNT(CASE WHEN a.recibio_cristo_semana = 1 THEN 1 END) as nuevos_creyentes,  
                              COUNT(CASE WHEN a.consagro_vida_fogata = 1 THEN 1 END) as consagraciones,  
                              COUNT(CASE WHEN a.asiste_iglesia = 1 THEN 1 END) as asisten_iglesia  
                              FROM cabanas c   
                              LEFT JOIN acampantes a ON c.id = a.cabana_id 
                                  AND a.year_campamento = ? 
                                  AND a.estado = 'activo'  
                              WHERE c.activa = 1  
                              GROUP BY c.id, c.nombre_cabana, c.consejero_principal, c.capacidad_maxima  
                              ORDER BY c.nombre_cabana");
        $stmt->execute([$year]);
    }
    return $stmt->fetchAll();  
}  
  
function obtenerReporteCabana($pdo, $cabana_id, $year, $semana_id = null) {  
    $data = [];  
      
    $stmt = $pdo->prepare("SELECT * FROM cabanas WHERE id = ?");  
    $stmt->execute([$cabana_id]);  
    $data['cabana'] = $stmt->fetch();  
      
    if ($semana_id) {
        $stmt = $pdo->prepare("SELECT a.*,   
                              COUNT(DISTINCT sc.numero_sesion) as consejerias_realizadas  
                              FROM acampantes a  
                              LEFT JOIN sesiones_consejeria sc ON a.id = sc.acampante_id  
                              WHERE a.cabana_id = ? AND a.semana_id = ? AND a.estado = 'activo'  
                              GROUP BY a.id  
                              ORDER BY a.nombre");
        $stmt->execute([$cabana_id, $semana_id]);
    } else {
        $stmt = $pdo->prepare("SELECT a.*,   
                              COUNT(DISTINCT sc.numero_sesion) as consejerias_realizadas  
                              FROM acampantes a  
                              LEFT JOIN sesiones_consejeria sc ON a.id = sc.acampante_id  
                              WHERE a.cabana_id = ? AND a.year_campamento = ? AND a.estado = 'activo'  
                              GROUP BY a.id  
                              ORDER BY a.nombre");
        $stmt->execute([$cabana_id, $year]);
    }
    $data['acampantes'] = $stmt->fetchAll();  
      
    return $data;  
}  
  
function obtenerReporteCompleto($pdo, $year, $semana_id = null) {  
    $data = [];  
    $data['general'] = obtenerReporteGeneral($pdo, $year, $semana_id);  
    $data['cabanas'] = obtenerReporteCabanas($pdo, $year, $semana_id);  
    return $data;  
}  
?>