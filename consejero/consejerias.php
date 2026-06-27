 <?php   
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
if (!esConsejero()) {  
    header('Location: ../admin/dashboard.php');  
    exit();  
}  
  
$titulo = "Gestión de Consejerías";  
$action = $_GET['action'] ?? 'list';  
$acampante_id = $_GET['acampante_id'] ?? null; 
// Si no viene acampante_id, redirigir a mis_acampantes
if (!$acampante_id && !$_POST) {
    header('Location: mis_acampantes.php');
    exit();
}
$sesion_id = $_GET['sesion_id'] ?? null;  
$message = '';  
$error = '';  
$cabana_id = $_SESSION['cabana_id']; 

// Procesar nueva sesión de consejería  
if ($_POST && ($action === 'add' || !$action)) {  
    try {  
        $acampante_id = (int)$_POST['acampante_id'];  
        $temas_seleccionados = $_POST['temas'] ?? [];  
        $tema_personalizado = limpiarDatos($_POST['tema_personalizado']);  
        $observaciones = limpiarDatos($_POST['observaciones']);  
        $fecha_sesion = $_POST['fecha_sesion'];  
        $hora_sesion = $_POST['hora_sesion'] ?: null;  
          
        // Consejero responsable
        $consejero_responsable = limpiarDatos($_POST['consejero_responsable'] ?? '');
        // DATOS DE EVALUACIÓN ESPIRITUAL  
        $asiste_iglesia = isset($_POST['asiste_iglesia']) ? 1 : 0;  
        $era_creyente_antes = isset($_POST['era_creyente_antes']) ? 1 : 0;  
        $recibio_cristo_semana = isset($_POST['recibio_cristo_semana']) ? 1 : 0;  
        $consagro_vida_fogata = isset($_POST['consagro_vida_fogata']) ? 1 : 0;  
        $decision_tomada = limpiarDatos($_POST['decision_tomada']);  
          
        // Sin validación - permite guardar solo con evaluación espiritual
          
        // Verificar permisos...  
        $stmt = $pdo->prepare("SELECT id FROM acampantes WHERE id = ? AND cabana_id = ? AND year_campamento = ?");  
        $stmt->execute([$acampante_id, $cabana_id, obtenerAnioCampamento()]);  
        if (!$stmt->fetch()) {  
            throw new Exception("No tienes permiso para crear consejerías para este acampante");  
        }  
          
        // Obtener consejero...  
        $consejero_user_id = $_SESSION['user_id'];  
        if (strpos($consejero_user_id, 'cabana_') === 0) {  
            $cabana_numero = str_replace('cabana_', '', $consejero_user_id);  
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cabana_id = ? AND rol = 'consejero'");  
            $stmt->execute([$cabana_numero]);  
            $usuario_existente = $stmt->fetch();  
              
            if ($usuario_existente) {  
                $consejero_user_id = $usuario_existente['id'];  
            } else {  
                $stmt = $pdo->prepare("INSERT INTO usuarios (username, password, rol, cabana_id) VALUES (?, ?, 'consejero', ?)");  
                $username_temp = 'consejero_cabana_' . $cabana_numero;  
                $stmt->execute([$username_temp, hashPassword('temp'), $cabana_numero]);  
                $consejero_user_id = $pdo->lastInsertId();  
            }  
        }  
          
        // Obtener número de sesión...  
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(numero_sesion), 0) + 1 as numero FROM sesiones_consejeria WHERE acampante_id = ?");  
        $stmt->execute([$acampante_id]);  
        $numero_sesion = $stmt->fetch()['numero'];  
          
        $pdo->beginTransaction();  
          
        // ⭐ ACTUALIZAR INFORMACIÓN ESPIRITUAL DEL ACAMPANTE ⭐  
        $stmt = $pdo->prepare("UPDATE acampantes SET   
                              asiste_iglesia = ?,   
                              era_creyente_antes = ?,   
                              recibio_cristo_semana = ?,   
                              consagro_vida_fogata = ?,   
                              decision_tomada = ?,
                              consejero_responsable = ?
                              WHERE id = ?");  
        $stmt->execute([$asiste_iglesia, $era_creyente_antes, $recibio_cristo_semana,   
                       $consagro_vida_fogata, $decision_tomada,
                       $consejero_responsable ?: null,
                       $acampante_id]);  
          
        $sesiones_creadas = 0;    
        $observaciones = limpiarDatos($_POST['observaciones']);  
                    
        // Crear sesiones para temas...    
        if (!empty($temas_seleccionados)) {    
            foreach ($temas_seleccionados as $tema_id) {    
                $stmt = $pdo->prepare("INSERT INTO sesiones_consejeria     
                                      (acampante_id, consejero_id, tema_id, observaciones, fecha_sesion, hora_sesion, numero_sesion)     
                                      VALUES (?, ?, ?, ?, ?, ?, ?)");    
                $stmt->execute([$acampante_id, $consejero_user_id, $tema_id, $observaciones, $fecha_sesion, $hora_sesion, $numero_sesion]);    
                $sesiones_creadas++;    
            }    
        }    
                    
        // Tema personalizado...    
        if ($tema_personalizado) {    
            $stmt = $pdo->prepare("INSERT INTO sesiones_consejeria     
                                  (acampante_id, consejero_id, tema_personalizado, observaciones, fecha_sesion, hora_sesion, numero_sesion)     
                                  VALUES (?, ?, ?, ?, ?, ?, ?)");    
            $stmt->execute([$acampante_id, $consejero_user_id, $tema_personalizado, $observaciones, $fecha_sesion, $hora_sesion, $numero_sesion]);    
            $sesiones_creadas++;    
        }  
          
        // Si hay observaciones (sin tema predefinido ni personalizado)  
        if ($sesiones_creadas === 0 && !empty($observaciones)) {  
            $stmt = $pdo->prepare("INSERT INTO sesiones_consejeria     
                                  (acampante_id, consejero_id, observaciones, fecha_sesion, hora_sesion, numero_sesion)     
                                  VALUES (?, ?, ?, ?, ?, ?)");    
            $stmt->execute([$acampante_id, $consejero_user_id, $observaciones, $fecha_sesion, $hora_sesion, $numero_sesion]);    
            $sesiones_creadas++;  
        }  
          
        // Si solo evaluación espiritual (sin tema ni observaciones)  
        if ($sesiones_creadas === 0) {  
            $stmt = $pdo->prepare("INSERT INTO sesiones_consejeria     
                                  (acampante_id, consejero_id, fecha_sesion, hora_sesion, numero_sesion)     
                                  VALUES (?, ?, ?, ?, ?)");    
            $stmt->execute([$acampante_id, $consejero_user_id, $fecha_sesion, $hora_sesion, $numero_sesion]);    
            $sesiones_creadas++;  
        }  
                    
        if ($sesiones_creadas > 0) {    
            $pdo->commit();    
            $message = "Consejería registrada exitosamente (#$numero_sesion). La información espiritual se ha actualizado y estará disponible en futuras sesiones.";    
            header("Location: consejerias.php?message=" . urlencode($message));    
            exit();    
        } else {    
            $pdo->rollBack();    
            throw new Exception("No se pudo guardar ninguna sesión");    
        }     
          
    } catch (Exception $e) {  
        if ($pdo->inTransaction()) {  
            $pdo->rollBack();  
        }  
        $error = "Error al guardar: " . $e->getMessage();  
    }  
}  
  
// Obtener mis acampantes  
try {  
    $year_actual = obtenerAnioCampamento();  
    // Obtener semana activa
    $stmt_semana = $pdo->query("SELECT id, nombre FROM semanas_campamento WHERE activa = 1 LIMIT 1");
    $semana_activa = $stmt_semana->fetch();
    
    $stmt = $pdo->prepare("SELECT a.*,   
                          DATE_FORMAT(a.fecha_registro, '%d/%m/%Y') as fecha_formateada  
                          FROM acampantes a  
                          WHERE a.cabana_id = ?   
                          AND a.year_campamento = ?
                          AND a.semana_id = ?
                          AND a.estado = 'activo'  
                          ORDER BY a.nombre");  
    $stmt->execute([$cabana_id, $year_actual, $semana_activa['id'] ?? 0]);  
    $misAcampantes = $stmt->fetchAll();  

} catch (Exception $e) {  
    $error = "Error al obtener acampantes: " . $e->getMessage();  
    $misAcampantes = [];  
}  
  
$acampanteSeleccionado = null;  
if ($acampante_id) {  
    try {  
        $stmt = $pdo->prepare("SELECT a.* FROM acampantes a   
                              WHERE a.id = ?   
                              AND a.cabana_id = ?   
                              AND a.year_campamento = ?  
                              AND a.estado = 'activo'");  
        $stmt->execute([$acampante_id, $cabana_id, obtenerAnioCampamento()]);  
        $acampanteSeleccionado = $stmt->fetch();  
          
        if (!$acampanteSeleccionado) {  
            $error = "Acampante no encontrado o no pertenece a tu cabaña";  
            $acampante_id = null;  
        }  
    } catch (Exception $e) {  
        $error = "Error al obtener acampante: " . $e->getMessage();  
        $acampante_id = null;  
    }  
}  
  
// Obtener historial de consejerías del acampante  
$historialConsejerias = [];  
if ($acampanteSeleccionado) {  
    try {  
        $stmt = $pdo->prepare("SELECT sc.*, tc.categoria, tc.tema as tema_predefinido    
                              FROM sesiones_consejeria sc    
                              LEFT JOIN temas_consejeria tc ON sc.tema_id = tc.id    
                              WHERE sc.acampante_id = ?    
                              ORDER BY sc.numero_sesion DESC, sc.fecha_sesion DESC, sc.created_at DESC");  
        $stmt->execute([$acampante_id]);  
        $historialConsejerias = $stmt->fetchAll();  
    } catch (Exception $e) {  
        $historialConsejerias = [];  
    }  
}  
  
// Obtener temas ya tratados  
$temasAnteriores = [];    
if ($acampante_id) {    
    $stmt = $pdo->prepare("SELECT DISTINCT tema_id     
                          FROM sesiones_consejeria     
                          WHERE acampante_id = ? AND tema_id IS NOT NULL");    
    $stmt->execute([$acampante_id]);    
    while ($row = $stmt->fetch()) {    
        $temasAnteriores[] = $row['tema_id'];    
    }    
}    
  
// Obtener SOLO los temas de ESTA sesión  
$temasSesionActual = [];    
$numero_sesion = $_POST['numero_sesion'] ?? $_GET['numero_sesion'] ?? null;    
if ($acampante_id && $numero_sesion) {    
    $stmt = $pdo->prepare("SELECT tema_id     
                          FROM sesiones_consejeria     
                          WHERE acampante_id = ? AND numero_sesion = ? AND tema_id IS NOT NULL");    
    $stmt->execute([$acampante_id, $numero_sesion]);    
    while ($row = $stmt->fetch()) {    
        $temasSesionActual[] = $row['tema_id'];    
    }    
}      
  
// Obtener temas de consejería  
$stmt = $pdo->query("SELECT * FROM temas_consejeria WHERE activo = 1 ORDER BY categoria, tema");    
$temas = $stmt->fetchAll();    
    
$temasPorCategoria = [];    
foreach ($temas as $tema) {    
    $temasPorCategoria[$tema['categoria']][] = $tema;    
}    
  
if (isset($_GET['message'])) {    
    $message = $_GET['message'];    
}

// ── Cargar consejeros asignados a esta cabaña (semana activa) ──
// Excluye capitan/capitana — ya están como principal/asistente en su cabaña
$consejeros_cabana = [];
$consejero_principal_cabana = null;
try {
    if ($semana_activa) {
        $stmt_cons = $pdo->prepare("SELECT nombre_consejero, rol
                                    FROM consejeros_semana
                                    WHERE cabana_id = ? AND semana_id = ?
                                    AND rol IN ('principal','asistente')
                                    ORDER BY 
                                        CASE rol
                                            WHEN 'principal' THEN 1
                                            WHEN 'asistente' THEN 2
                                        END");
        $stmt_cons->execute([$cabana_id, $semana_activa['id']]);
        $consejeros_cabana = $stmt_cons->fetchAll();

        // Guardar el principal para usarlo como default
        foreach ($consejeros_cabana as $c) {
            if ($c['rol'] === 'principal') {
                $consejero_principal_cabana = $c['nombre_consejero'];
                break;
            }
        }
    }
} catch (Exception $e) {
    $consejeros_cabana = [];
}

include '../includes/header.php';  
?>  
  
<div class="row mb-4">  
    <div class="col-12">  
        <h1><i class="fas fa-comments"></i> <?php echo $titulo; ?></h1>  
        <nav aria-label="breadcrumb">  
            <ol class="breadcrumb">  
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>  
                <li class="breadcrumb-item active">Consejerías</li>  
            </ol>  
        </nav>  
    </div>  
</div>  
  
<?php if ($message): ?>  
    <div class="alert alert-success alert-dismissible fade show">  
        <i class="fas fa-check"></i> <?php echo $message; ?>  
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>  
    </div>  
<?php endif; ?>  
  
<?php if ($error): ?>  
    <div class="alert alert-danger alert-dismissible fade show">  
        <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>  
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>  
    </div>  
<?php endif; ?> 

<?php if ($semana_activa): ?>
<div class="alert alert-success">
    <i class="fas fa-broadcast-tower"></i>
    <strong>Semana activa: <?php echo htmlspecialchars($semana_activa['nombre']); ?></strong>
    - Estos son tus acampantes para esta semana.
</div>
<?php else: ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>No hay semana activa.</strong> Espera a que el administrador active la semana para ver tus acampantes.
</div>
<?php endif; ?>
  
<?php if (!$acampanteSeleccionado): ?>  
<!-- Selección de acampante -->  
<div class="card">  
    <div class="card-header">  
        <h5><i class="fas fa-user-plus"></i> Seleccionar Acampante para Consejería</h5>  
    </div>  
    <div class="card-body">  
        <?php if (empty($misAcampantes)): ?>  
            <div class="text-center text-muted py-4">  
                <i class="fas fa-users fa-3x mb-3"></i>  
                <p>No tienes acampantes asignados aún</p>  
                <a href="dashboard.php" class="btn btn-primary">Ir al Dashboard</a>  
            </div>  
        <?php else: ?>  
        <div class="row">  
            <?php foreach ($misAcampantes as $acampante):   
                // Contar sesiones existentes  
                $stmt = $pdo->prepare("SELECT COUNT(DISTINCT numero_sesion) as sesiones FROM sesiones_consejeria WHERE acampante_id = ?");  
                $stmt->execute([$acampante['id']]);  
                $totalSesiones = $stmt->fetch()['sesiones'];  
            ?>  
            <div class="col-md-6 col-lg-4 mb-3">  
                <div class="card h-100">  
                    <div class="card-body">  
                        <h6 class="card-title"><?php echo htmlspecialchars($acampante['nombre']); ?></h6>  
                        <p class="card-text">  
                            <small class="text-muted">  
                                <i class="fas fa-church"></i> <?php echo htmlspecialchars($acampante['iglesia']); ?><br>  
                                <i class="fas fa-home"></i> Cabaña: <?php echo htmlspecialchars($cabana['nombre_cabana']); ?> 
                            </small>  
                        </p>  
                        <div class="mb-2">  
                            <span class="badge <?php echo $totalSesiones >= 3 ? 'bg-success' : ($totalSesiones >= 1 ? 'bg-warning' : 'bg-danger'); ?>">  
                                <?php echo $totalSesiones; ?>/3 Sesiones  
                            </span>  
                        </div>  
                        <a href="consejerias.php?acampante_id=<?php echo $acampante['id']; ?>"   
                           class="btn btn-primary btn-sm w-100">  
                            <i class="fas fa-plus"></i> Nueva Consejería  
                        </a>  
                    </div>  
                </div>  
            </div>  
            <?php endforeach; ?>  
        </div>  
        <?php endif; ?>  
    </div>  
</div>  
  
<?php else: ?>  
<!-- Formulario de nueva consejería -->  
<div class="row">  
    <div class="col-md-8">  
        <div class="card">  
            <div class="card-header d-flex justify-content-between align-items-center">  
                <div>  
                    <h5 class="mb-0">  
                        <i class="fas fa-plus"></i> Nueva Sesión de Consejería  
                        <small class="text-muted">- <?php echo htmlspecialchars($acampanteSeleccionado['nombre']); ?></small>  
                    </h5>  
                </div>  
                <div>  
                    <?php   
                    // Contar sesiones existentes  
                    $total_sesiones_existentes = 0;  
                    if (!empty($historialConsejerias)) {  
                        $sesiones_unicas = array_unique(array_column($historialConsejerias, 'numero_sesion'));  
                        $total_sesiones_existentes = count($sesiones_unicas);  
                    }  
                      
                    if ($total_sesiones_existentes == 0):   
                    ?>  
                    <span class="badge bg-warning fs-6">  
                        <i class="fas fa-star"></i> Primera Consejería  
                    </span>  
                    <?php else: ?>  
                    <span class="badge bg-info fs-6">  
                        <i class="fas fa-comments"></i> Sesión #<?php echo $total_sesiones_existentes + 1; ?>  
                    </span>  
                    <?php endif; ?>  
                </div>  
            </div>
            <?php if ($total_sesiones_existentes == 0): ?>  
            <div class="alert alert-info border-0 mb-4">  
                <div class="d-flex align-items-center">  
                    <i class="fas fa-info-circle fa-2x text-primary me-3"></i>  
                    <div>  
                        <h6 class="alert-heading mb-1">Primera Consejería con este Acampante</h6>  
                        <p class="mb-0">  
                            <strong>Importante:</strong> Completa la evaluación espiritual para conocer su estado inicial.   
                            Esta información se usará en todas las consejerías futuras.  
                        </p>  
                    </div>  
                </div>  
            </div>  
            <?php else: ?>  
            <div class="alert alert-light border-0 mb-4">  
                <small class="text-muted">  
                    <i class="fas fa-history"></i>   
                    Este acampante ya tiene <?php echo $total_sesiones_existentes; ?> consejería(s) previa(s).   
                    Puedes actualizar su información espiritual si hubo cambios.  
                </small>  
            </div>  
            <?php endif; ?>  
            <div class="card-body">  
                <!-- El formulario va aquí -->   
                <form id="formConsejeria"  method="POST" action="consejerias.php?action=add&acampante_id=<?php echo $acampante_id; ?>">  
                    <input type="hidden" name="acampante_id" value="<?php echo $acampante_id; ?>">
                    
                    <!-- 1. CONSEJERO RESPONSABLE (solo lectura, se asigna en mis_acampantes.php) -->
                    <?php
                    $responsable_actual = $acampanteSeleccionado['consejero_responsable'] ?? '';
                    ?>
                    <div class="card border-warning mb-4">
                        <div class="card-header bg-warning bg-opacity-25">
                            <h6 class="mb-0">
                                <i class="fas fa-user-shield"></i> Consejero Responsable
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($responsable_actual)): ?>
                            <p class="mb-2">
                                <strong><?php echo htmlspecialchars($responsable_actual); ?></strong>
                                está a cargo del seguimiento de
                                <strong><?php echo htmlspecialchars($acampanteSeleccionado['nombre']); ?></strong>.
                            </p>
                            <?php else: ?>
                            <p class="mb-2 text-muted">
                                <em>Sin responsable asignado.</em> Asígnalo desde
                                <a href="mis_acampantes.php"><strong>Mis Acampantes</strong></a>.
                            </p>
                            <?php endif; ?>
                    
                            <!-- Hidden para conservar el valor al guardar la consejería -->
                            <input type="hidden" name="consejero_responsable"
                                   value="<?php echo htmlspecialchars($responsable_actual); ?>">
                    
                            <a href="mis_acampantes.php" class="btn btn-outline-warning btn-sm">
                                <i class="fas fa-edit"></i> Cambiar responsable
                            </a>
                        </div>
                    </div>
                    
                    <!-- 2. EVALUACIÓN ESPIRITUAL -->  
                    <div class="card border-primary mb-4">  
                        <div class="card-header bg-primary text-white">  
                            <h6 class="mb-0"><i class="fas fa-cross"></i> Evaluación Espiritual del Acampante</h6>  
                            <small>Completa esta información en la primera consejería para conocer el estado inicial</small>  
                        </div>  
                        <div class="card-body">  
                            <div class="row">  
                                <div class="col-md-6">  
                                    <div class="mb-3">  
                                        <label class="form-label"><strong>Estado Religioso Actual</strong></label>  
                                          
                                        <div class="form-check">  
                                            <input class="form-check-input" type="checkbox" id="asiste_iglesia" name="asiste_iglesia"  
                                                   <?php echo ($acampanteSeleccionado['asiste_iglesia'] ?? false) ? 'checked' : ''; ?>>  
                                            <label class="form-check-label" for="asiste_iglesia">  
                                                <strong>¿Asiste a una iglesia?</strong>  
                                            </label>  
                                        </div>  
                                          
                                        <div class="form-check">  
                                            <input class="form-check-input" type="checkbox" id="era_creyente_antes" name="era_creyente_antes"  
                                                   <?php echo ($acampanteSeleccionado['era_creyente_antes'] ?? false) ? 'checked' : ''; ?>  
                                                   onchange="toggleRecibioCristo()">  
                                            <label class="form-check-label" for="era_creyente_antes">  
                                                <strong>¿Era creyente antes del campamento?</strong>  
                                            </label>  
                                        </div>  
                                    </div>  
                                </div>  
                                  
                                <div class="col-md-6">  
                                    <div class="mb-3">  
                                        <label class="form-label"><strong>Decisiones en el Campamento</strong></label>  
                                          
                                        <div class="form-check">  
                                            <input class="form-check-input" type="checkbox" id="recibio_cristo_semana" name="recibio_cristo_semana"  
                                                   <?php echo ($acampanteSeleccionado['recibio_cristo_semana'] ?? false) ? 'checked' : ''; ?>>  
                                            <label class="form-check-label" for="recibio_cristo_semana">  
                                                <strong>¿Recibió a Cristo esta semana?</strong>  
                                            </label>  
                                            <small class="form-text text-muted d-block">Solo si NO era creyente antes</small>  
                                        </div>  
                                          
                                        <div class="form-check">  
                                            <input class="form-check-input" type="checkbox" id="consagro_vida_fogata" name="consagro_vida_fogata"  
                                                   <?php echo ($acampanteSeleccionado['consagro_vida_fogata'] ?? false) ? 'checked' : ''; ?>>  
                                            <label class="form-check-label" for="consagro_vida_fogata">  
                                                <strong>¿Consagró su vida en la fogata?</strong>  
                                            </label>  
                                        </div>  
                                    </div>  
                                </div>  
                            </div>  
                              
                            <div class="mb-0">  
                                <label for="decision_tomada" class="form-label">  
                                    <strong>¿Qué decisión tomó?</strong>  
                                </label>  
                                <textarea class="form-control" id="decision_tomada" name="decision_tomada" rows="3"  
                                          placeholder="Describe la decisión espiritual tomada por el acampante..."><?php echo htmlspecialchars($acampanteSeleccionado['decision_tomada'] ?? ''); ?></textarea>  
                                <small class="form-text text-muted">  
                                    Describe cualquier compromiso espiritual, decisión de seguir a Cristo, cambios de vida, etc.  
                                </small>  
                            </div>  
                              
                            <!-- Indicador de cambios guardados -->  
                            <?php if ($acampanteSeleccionado && ($acampanteSeleccionado['asiste_iglesia'] || $acampanteSeleccionado['era_creyente_antes'] || $acampanteSeleccionado['recibio_cristo_semana'] || $acampanteSeleccionado['consagro_vida_fogata'] || $acampanteSeleccionado['decision_tomada'])): ?>  
                            <div class="alert alert-success mt-3">  
                                <small><i class="fas fa-check-circle"></i> <strong>Información guardada previamente:</strong> Los datos se cargan automáticamente de sesiones anteriores. Puedes actualizarlos si es necesario.</small>  
                            </div>  
                            <?php endif; ?>  
                        </div>  
                    </div>    
                    
                    <!-- 3. INFORMACIÓN DE LA SESIÓN -->  
                    <div class="row mb-4">  
                        <div class="col-md-6">  
                            <label for="fecha_sesion" class="form-label">Fecha de la Sesión *</label>  
                            <input type="date" class="form-control" id="fecha_sesion" name="fecha_sesion"   
                                   value="<?php echo date('Y-m-d'); ?>" required>  
                        </div>  
                        <div class="col-md-6">  
                            <label for="hora_sesion" class="form-label">Hora de la Sesión</label>  
                            <input type="time" class="form-control" id="hora_sesion" name="hora_sesion"   
                                   value="<?php echo date('H:i'); ?>">  
                        </div>  
                    </div>  
                      
                    <!-- 4. TEMAS TRATADOS -->  
                    <div class="card border-info mb-4">  
                        <div class="card-header bg-light">  
                            <h6 class="mb-0"><i class="fas fa-list-check"></i> Temas Tratados en esta Sesión</h6>  
                            <small>Selecciona los temas que conversaste con el acampante</small>  
                        </div>  
                        <div class="card-body">  
                            <?php if (!empty($temasAnteriores)): ?>  
                            <div class="alert alert-info">  
                                <small>  
                                    <i class="fas fa-info-circle"></i>   
                                    <strong>Temas con badge "historial"</strong> ya fueron tratados en sesiones anteriores.   
                                    Marca SOLO los temas que vas a tratar en ESTA sesión.  
                                </small>  
                            </div>  
                            <?php endif; ?>  
                            <div class="row">  
                                <?php foreach ($temasPorCategoria as $categoria => $temasCategoria): ?>  
                                <div class="col-md-6 mb-3">  
                                    <div class="evaluation-group">  
                                        <h6>  
                                            <?php echo htmlspecialchars($categoria); ?>  
                                            <button type="button" class="btn btn-sm btn-outline-secondary float-end"   
                                                    onclick="toggleCategoria('cat_<?php echo md5($categoria); ?>')" title="Seleccionar todos">  
                                                <i class="fas fa-check-double"></i>  
                                            </button>  
                                        </h6>  
                                        <?php foreach ($temasCategoria as $tema): ?>  
                                    <div class="form-check">  
                                        <input class="form-check-input cat_<?php echo md5($categoria); ?>"   
                                               type="checkbox" name="temas[]"   
                                               value="<?php echo $tema['id']; ?>"   
                                               id="tema_<?php echo $tema['id']; ?>"  
                                               <?php echo in_array($tema['id'], $temasSesionActual) ? 'checked' : ''; ?>>  
                                        <label class="form-check-label" for="tema_<?php echo $tema['id']; ?>">  
                                            <?php echo htmlspecialchars($tema['tema']); ?>  
                                            <?php if (in_array($tema['id'], $temasAnteriores) && !in_array($tema['id'], $temasSesionActual)): ?>  
                                                <span class="badge bg-secondary ms-1" title="Ya tratado en sesiones anteriores">historial</span>  
                                            <?php endif; ?>  
                                        </label>  
                                    </div>  
                                    <?php endforeach; ?>     
                                    </div>  
                                </div>  
                                <?php endforeach; ?>  
                            </div>  
                              
                            <!-- Tema personalizado -->  
                            <div class="mt-3 pt-3 border-top">  
                                <label for="tema_personalizado" class="form-label">  
                                    <i class="fas fa-edit"></i> Tema Personalizado  
                                </label>  
                                <input type="text" class="form-control" id="tema_personalizado" name="tema_personalizado"  
                                       placeholder="Si el tema no está en la lista, escríbelo aquí...">  
                                <small class="form-text text-muted">  
                                    Usa este campo si necesitas agregar un tema que no está en las opciones predefinidas  
                                </small>  
                            </div>  
                        </div>  
                    </div>  
                      
                    <!-- 5. OBSERVACIONES Y NOTAS -->  
                    <div class="card border-success mb-4">  
                        <div class="card-header bg-light">  
                            <h6 class="mb-0"><i class="fas fa-sticky-note"></i> Observaciones y Notas de la Sesión</h6>  
                            <small>Registra los detalles importantes de la conversación</small>  
                        </div>  
                        <div class="card-body">  
                            <textarea class="form-control" id="observaciones" name="observaciones" rows="4"  
                                      placeholder="Describe lo conversado, decisiones tomadas, oración realizada, compromisos del acampante, etc..."  
                                      onkeyup="autoResizeTextarea(this)"></textarea>  
                            <small class="form-text text-muted">  
                                Escribe aquí los detalles importantes: respuestas del acampante, compromisos tomados, oración realizada, etc.  
                            </small>  
                        </div>  
                    </div>  
                      
                    <hr>  
                      
                    <div class="d-flex justify-content-between">  
                        <a href="consejerias.php" class="btn btn-secondary">  
                            <i class="fas fa-arrow-left"></i> Volver  
                        </a>  
                        <button type="submit" class="btn btn-success btn-lg">  
                            <i class="fas fa-save"></i> Registrar Consejería Completa  
                        </button>  
                    </div>  
                </form>    
            </div>  
        </div>  
    </div>  
      
    <!-- Panel lateral con información del acampante e historial -->  
    <div class="col-md-4">  
        <!-- Información del acampante -->  
        <div class="card mb-3">  
            <div class="card-header">  
                <h6><i class="fas fa-user"></i> Información del Acampante</h6>  
            </div>  
            <div class="card-body">  
                <p><strong>Nombre:</strong><br><?php echo htmlspecialchars($acampanteSeleccionado['nombre']); ?></p>  
                <p><strong>Iglesia:</strong><br><?php echo htmlspecialchars($acampanteSeleccionado['iglesia']); ?></p>

                <!-- Responsable -->
                <div class="alert alert-<?php echo !empty($acampanteSeleccionado['consejero_responsable']) ? 'warning' : 'light border'; ?> py-2 mb-2">
                    <small class="fw-bold d-block mb-1">
                        <i class="fas fa-user-shield"></i> Consejero Responsable:
                    </small>
                    <?php if (!empty($acampanteSeleccionado['consejero_responsable'])): ?>
                    <strong><?php echo htmlspecialchars($acampanteSeleccionado['consejero_responsable']); ?></strong>
                    <?php else: ?>
                    <em class="text-muted small">Sin asignar — selecciona abajo en el formulario</em>
                    <?php endif; ?>
                </div> 
                  
                <hr>  
                  
                <h6>Estado Espiritual Actual:</h6>  
                <div class="d-flex flex-column gap-1">  
                    <?php if ($acampanteSeleccionado['asiste_iglesia']): ?>  
                        <span class="badge bg-info">✓ Asiste a iglesia</span>  
                    <?php endif; ?>  
                    <?php if ($acampanteSeleccionado['era_creyente_antes']): ?>  
                        <span class="badge bg-primary">✓ Era creyente antes</span>  
                    <?php endif; ?>  
                    <?php if ($acampanteSeleccionado['recibio_cristo_semana']): ?>  
                        <span class="badge bg-success">✓ Recibió a Cristo esta semana</span>  
                    <?php endif; ?>  
                    <?php if ($acampanteSeleccionado['consagro_vida_fogata']): ?>  
                        <span class="badge bg-warning">✓ Consagró vida en fogata</span>  
                    <?php endif; ?>  
                      
                    <?php if (!$acampanteSeleccionado['asiste_iglesia'] && !$acampanteSeleccionado['era_creyente_antes'] && !$acampanteSeleccionado['recibio_cristo_semana'] && !$acampanteSeleccionado['consagro_vida_fogata']): ?>  
                        <span class="badge bg-secondary">Sin información espiritual registrada</span>  
                    <?php endif; ?>  
                </div>
                <?php if (!empty($temasAnteriores)): ?>  
                <hr>  
                <h6><i class="fas fa-history"></i> Temas Tratados Anteriormente:</h6>  
                <div class="d-flex flex-wrap gap-1">  
                    <?php   
                    $stmt = $pdo->prepare("SELECT DISTINCT t.id, t.tema, COUNT(*) as veces  
                                          FROM sesiones_consejeria sc  
                                          JOIN temas_consejeria t ON sc.tema_id = t.id  
                                          WHERE sc.acampante_id = ? AND sc.tema_id IS NOT NULL  
                                          GROUP BY t.id  
                                          ORDER BY veces DESC");  
                    $stmt->execute([$acampante_id]);  
                    $temasHistorico = $stmt->fetchAll();  
                      
                    foreach ($temasHistorico as $t): ?>  
                        <span class="badge bg-secondary" title="Tratado <?php echo $t['veces']; ?> vez/veces">  
                            <?php echo htmlspecialchars($t['tema']); ?> (<?php echo $t['veces']; ?>)  
                        </span>  
                    <?php endforeach; ?>  
                </div>  
                <small class="text-muted">Total: <?php echo count($temasAnteriores); ?> tema(s)</small>  
                <?php endif; ?>    
                  
                <?php if ($acampanteSeleccionado['decision_tomada']): ?>  
                <hr>  
                <h6>Decisión Registrada:</h6>  
                <p class="small"><?php echo htmlspecialchars($acampanteSeleccionado['decision_tomada']); ?></p>  
                <?php endif; ?>    
            </div>  
        </div>  
          
        <!-- Historial de consejerías -->  
        <div class="card">  
            <div class="card-header">  
                <h6><i class="fas fa-history"></i> Historial de Consejerías</h6>  
            </div>  
            <div class="card-body">  
                <?php if (empty($historialConsejerias)): ?>  
                    <p class="text-muted text-center">No hay consejerías previas</p>  
                <?php else: ?>  
                    <?php   
                    $sesionesAgrupadas = [];  
                    foreach ($historialConsejerias as $consejeria) {  
                        $sesionesAgrupadas[$consejeria['numero_sesion']][] = $consejeria;  
                    }  
                    ?>  
                    <?php foreach ($sesionesAgrupadas as $numSesion => $consejerias): ?>  
                    <div class="mb-3 p-2 border rounded">  
                        <h6 class="text-primary">Sesión #<?php echo $numSesion; ?></h6>  
                        <small class="text-muted">  
                            <?php echo formatearFecha($consejerias[0]['fecha_sesion']); ?>  
                            <?php if ($consejerias[0]['hora_sesion']): ?>  
                                - <?php echo formatearHora($consejerias[0]['hora_sesion']); ?>  
                            <?php endif; ?>  
                        </small>  
                          
                        <div class="mt-2">  
                            <strong>Temas:</strong>  
                            <ul class="small mb-1">  
                                <?php foreach ($consejerias as $consejeria): ?>  
                                <li>  
                                    <?php if ($consejeria['tema_predefinido']): ?>  
                                        <span class="text-primary"><?php echo htmlspecialchars($consejeria['tema_predefinido']); ?></span>  
                                        <small class="text-muted">(<?php echo htmlspecialchars($consejeria['categoria']); ?>)</small>  
                                    <?php else: ?>  
                                        <span class="text-success"><?php echo htmlspecialchars($consejeria['tema_personalizado']); ?></span>  
                                        <small class="text-muted">(Personalizado)</small>  
                                    <?php endif; ?>  
                                </li>  
                                <?php endforeach; ?>  
                            </ul>  
                        </div>  
                          
                        <?php if ($consejerias[0]['observaciones']): ?>  
                        <div class="mt-2">  
                            <strong>Observaciones:</strong>  
                            <p class="small mb-0"><?php echo nl2br(htmlspecialchars($consejerias[0]['observaciones'])); ?></p>  
                        </div>  
                        <?php endif; ?>  
                    </div>  
                    <?php endforeach; ?>  
                <?php endif; ?>  
            </div>  
        </div>  
    </div>  
</div>  
  
<?php endif; ?>  

 
<script>
window._formHasOwnHandler = true;

// ── Seleccionar consejero responsable ─────────────────────────
function seleccionarResponsable(nombre) {
    const campo = document.getElementById('consejero_responsable');
    if (campo) campo.value = nombre;

    document.querySelectorAll('.btn-consejero-resp').forEach(function (btn) {
        if (btn.dataset.nombre === nombre) {
            btn.classList.remove('btn-outline-secondary');
            btn.classList.add('btn-warning');
        } else {
            btn.classList.remove('btn-warning');
            btn.classList.add('btn-outline-secondary');
        }
    });
}

// ── Lógica espiritual: bloquear "Recibió a Cristo" si ya era creyente ──
function toggleRecibioCristo() {
    const eraCreyente   = document.getElementById('era_creyente_antes');
    const recibioCristo = document.getElementById('recibio_cristo_semana');
    if (!eraCreyente || !recibioCristo) return;

    if (eraCreyente.checked) {
        recibioCristo.checked  = false;
        recibioCristo.disabled = true;
        recibioCristo.closest('.form-check').style.opacity = '0.5';
    } else {
        recibioCristo.disabled = false;
        recibioCristo.closest('.form-check').style.opacity = '1';
    }
}

// ── Un solo DOMContentLoaded para todo ────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    // 1. Inicializar estado espiritual (bloqueo de "Recibió a Cristo")
    toggleRecibioCristo();

    // 2. Inicializar botones de consejero responsable
    const campoResponsable = document.getElementById('consejero_responsable');
    if (campoResponsable && campoResponsable.value.trim()) {
        seleccionarResponsable(campoResponsable.value.trim());
    }

    const form = document.getElementById('formConsejeria');
    if (!form) return;

    // ── Submit unificado ──────────────────────────────────────
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();

        // 1. Validar fecha
        const fecha = document.getElementById('fecha_sesion').value;
        if (!fecha) {
            alert('La fecha de la sesión es obligatoria');
            document.getElementById('fecha_sesion').focus();
            return;
        }

        // 2. Confirmar envío
        const temasSeleccionados = document.querySelectorAll('input[name="temas[]"]:checked');
        const temaPersonalizado  = document.getElementById('tema_personalizado').value.trim();
        const totalTemas = temasSeleccionados.length + (temaPersonalizado ? 1 : 0);

        if (!confirm(`¿Guardar consejería con ${totalTemas} tema(s)?`)) return;

        // 3. Spinner
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
        }

        // 4. Limpiar protección beforeunload ANTES del fetch
        if (typeof formChanged !== 'undefined') formChanged = false;
        if (typeof beforeUnloadHandler !== 'undefined') {
            window.removeEventListener('beforeunload', beforeUnloadHandler);
        }

        // 5. Enviar
        try {
            const fetchResponse = await fetch(form.action, {
                method:      'POST',
                body:        new FormData(form),
                credentials: 'include'
            });

            const contentType = fetchResponse.headers.get('content-type') || '';

            if (contentType.includes('application/json')) {
                const data = await fetchResponse.json();

                if (data.offline === true) {
                    console.log('[Form] Guardado offline por SW');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-save"></i> Registrar Consejería Completa';
                    }
                    return;
                }

                if (data.ok === false) {
                    throw new Error(data.error || 'Error del servidor');
                }
            }

            if (fetchResponse.ok || fetchResponse.redirected) {
                if (typeof formChanged !== 'undefined') formChanged = false;
                if (typeof beforeUnloadHandler !== 'undefined') {
                    window.removeEventListener('beforeunload', beforeUnloadHandler);
                }
                window.location.href = fetchResponse.url || form.action;
                return;
            }

            throw new Error(`Error HTTP ${fetchResponse.status}`);

        } catch (err) {
            console.error('[Form] Error en fetch:', err);

            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Registrar Consejería Completa';
            }

            if (navigator.onLine) {
                if (typeof OfflineSync !== 'undefined') {
                    OfflineSync.mostrarToast('Error al guardar. Intenta de nuevo.', 'error');
                }
            }
        }
    }, true);

});
</script>

<?php include '../includes/footer.php'; ?>