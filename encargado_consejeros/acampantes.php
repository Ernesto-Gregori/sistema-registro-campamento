<?php  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
if (!esEncargadoConsejeros()) {
    header('Location: ../consejero/dashboard.php');  
    exit();  
}

// Función para procesar imagen  
function procesarFoto($archivo, $acampante_id) {  
    if (!isset($archivo) || $archivo['error'] !== UPLOAD_ERR_OK) {  
        return null;  
    }  
      
    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));  
      
    // Validar extensión  
    $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'gif'];  
    if (!in_array($extension, $extensionesPermitidas)) {  
        throw new Exception("Tipo de imagen no permitida. Use JPG, PNG o GIF");  
    }  
      
    // Validar tamaño (5MB máximo)  
    if ($archivo['size'] > 5 * 1024 * 1024) {  
        throw new Exception("La imagen es muy grande. Máximo 5MB");  
    }  
      
    // Crear nombre único para la foto  
    $nombreFoto = 'acampante_' . $acampante_id . '_' . time() . '.' . $extension;  
    $rutaDestino = '../assets/uploads/fotos_acampantes/' . $nombreFoto;  
      
    // Crear directorio si no existe  
    if (!file_exists('../assets/uploads/fotos_acampantes/')) {  
        mkdir('../assets/uploads/fotos_acampantes/', 0755, true);  
    }  
      
    // Redimensionar imagen si es necesario  
    $imagen = null;  
      
    if ($extension === 'png') {  
        $imagen = imagecreatefrompng($archivo['tmp_name']);  
    } elseif (in_array($extension, ['jpg', 'jpeg'])) {  
        $imagen = imagecreatefromjpeg($archivo['tmp_name']);  
    } elseif ($extension === 'gif') {  
        $imagen = imagecreatefromgif($archivo['tmp_name']);  
    }  
      
    if ($imagen) {  
        // Redimensionar a 300x400 máximo  
        $ancho = imagesx($imagen);  
        $alto = imagesy($imagen);  
          
        if ($ancho > 300 || $alto > 400) {  
            $nuevoAncho = 300;  
            $nuevoAlto = intval(($alto / $ancho) * 300);  
              
            if ($nuevoAlto > 400) {  
                $nuevoAlto = 400;  
                $nuevoAncho = intval(($ancho / $alto) * 400);  
            }  
              
            $imagenRedimensionada = imagecreatetruecolor($nuevoAncho, $nuevoAlto);  
            imagecopyresampled($imagenRedimensionada, $imagen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);  
              
            if ($extension === 'png') {  
                imagepng($imagenRedimensionada, $rutaDestino, 9);  
            } else {  
                imagejpeg($imagenRedimensionada, $rutaDestino, 90);  
            }  
              
            imagedestroy($imagenRedimensionada);  
        } else {  
            move_uploaded_file($archivo['tmp_name'], $rutaDestino);  
        }  
          
        imagedestroy($imagen);  
    } else {  
        move_uploaded_file($archivo['tmp_name'], $rutaDestino);  
    }  
      
    return 'assets/uploads/fotos_acampantes/' . $nombreFoto;  
}  
  
// Función para eliminar foto anterior  
function eliminarFotoAnterior($rutaFoto) {  
    if ($rutaFoto && file_exists('../' . $rutaFoto)) {  
        unlink('../' . $rutaFoto);  
    }  
}
  
$titulo = "Gestión de Acampantes";  
$action = $_GET['action'] ?? 'list';  
$id = $_GET['id'] ?? null;  
$message = '';  
$error = '';  
$acampante   = null;
$consejerias = [];

// Generar token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
  
// Procesar acciones  
if ($_POST) {  
    try {  
        if ($action === 'add' || $action === 'edit') {  
            $nombre     = limpiarDatos($_POST['nombre']);
            $edad       = !empty($_POST['edad']) ? (int)$_POST['edad'] : null;
            $sexo       = $_POST['sexo'] ?? null;
            $iglesia    = limpiarDatos($_POST['iglesia']);
            $estado_origen                  = limpiarDatos($_POST['estado_origen'] ?? '');
            $contacto                       = limpiarDatos($_POST['contacto'] ?? '');
            $contacto_emergencia_nombre     = limpiarDatos($_POST['contacto_emergencia_nombre'] ?? '');
            $contacto_emergencia_telefono   = limpiarDatos($_POST['contacto_emergencia_telefono'] ?? '');
            $alergias_enfermedades          = limpiarDatos($_POST['alergias_enfermedades'] ?? '');
            $observaciones                  = limpiarDatos($_POST['observaciones'] ?? '');
            $cabana_id  = !empty($_POST['cabana_id']) ? (int)$_POST['cabana_id'] : null;  
              
            // Validar campos obligatorios  
            if (empty($nombre)) {  
                throw new Exception("El nombre es obligatorio");  
            }  
              
            if (empty($iglesia)) {  
                throw new Exception("La iglesia es obligatoria");  
            }  
              
            if (empty($sexo) || !in_array($sexo, ['masculino', 'femenino'])) {  
                throw new Exception("Debes seleccionar el sexo del acampante (masculino o femenino)");  
            }  
              
            // Capturar autorización de edad
            $edad_autorizada = isset($_POST['edad_autorizada']) ? 1 : 0;
            
            // ⭐ OBTENER semana_id del formulario
            $semana_id = !empty($_POST['semana_id']) ? (int)$_POST['semana_id'] : null;

            // Validar que el género del acampante coincida con el de la cabaña  
            if ($cabana_id) {  
                $stmt = $pdo->prepare("SELECT genero FROM cabanas WHERE id = ?");  
                $stmt->execute([$cabana_id]);  
                $cabana_data = $stmt->fetch();  
                  
                if ($cabana_data && $cabana_data['genero'] !== $sexo) {  
                    throw new Exception("No puedes asignar un acampante {$sexo} a una cabaña {$cabana_data['genero']}");  
                }  
            }

            // ── Validar rango de edad por cabaña+semana ─────────────
            if ($cabana_id && $semana_id && !empty($edad)) {
                $stmt = $pdo->prepare("SELECT edad_min, edad_max, descripcion
                                       FROM cabana_rangos_edad
                                       WHERE cabana_id = ? AND semana_id = ?");
                $stmt->execute([$cabana_id, $semana_id]);
                $rango = $stmt->fetch();

                if ($rango) {
                    $fueraDeRango = ($edad < $rango['edad_min'] || $edad > $rango['edad_max']);
                    if ($fueraDeRango && !$edad_autorizada) {
                        $desc = !empty($rango['descripcion'])
                            ? " ({$rango['descripcion']})"
                            : '';
                        throw new Exception(
                            "La edad del acampante ({$edad} años) está fuera del rango configurado " .
                            "para esta cabaña: {$rango['edad_min']}–{$rango['edad_max']} años{$desc}. " .
                            "Si deseas registrarlo de todos modos, marca la casilla de autorización."
                        );
                    }
                }
            } 
              
            // Procesar foto si se subió  
            $foto = null;  
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {  
                $extension = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));  
                $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'gif'];  
                  
                if (in_array($extension, $extensionesPermitidas)) {  
                    // Crear directorio si no existe  
                    $dirFotos = '../assets/uploads/fotos_acampantes/';  
                    if (!file_exists($dirFotos)) {  
                        mkdir($dirFotos, 0755, true);  
                    }  
                      
                    // Si es edición, eliminar foto anterior  
                    if ($action === 'edit' && $id) {  
                        $stmt = $pdo->prepare("SELECT foto FROM acampantes WHERE id = ?");  
                        $stmt->execute([$id]);  
                        $acmpAnterior = $stmt->fetch();  
                        if ($acmpAnterior && $acmpAnterior['foto'] && file_exists('../' . $acmpAnterior['foto'])) {  
                            unlink('../' . $acmpAnterior['foto']);  
                        }  
                    }  
                      
                    // Nombre único para la foto  
                    $tempId = $id ?? time();  
                    $nombreFoto = 'acampante_' . $tempId . '_' . time() . '.' . $extension;  
                    $rutaDestino = $dirFotos . $nombreFoto;  
                      
                    // Mover archivo  
                    if (move_uploaded_file($_FILES['foto']['tmp_name'], $rutaDestino)) {  
                        $foto = 'assets/uploads/fotos_acampantes/' . $nombreFoto;  
                    }  
                }  
            }  
              
            
            if ($action === 'add') {
                if ($foto) {
                    $stmt = $pdo->prepare("INSERT INTO acampantes
                                          (nombre, edad, edad_autorizada, sexo, iglesia, estado_origen,
                                           contacto, contacto_emergencia_nombre, contacto_emergencia_telefono,
                                           alergias_enfermedades, observaciones,
                                           cabana_id, semana_id, year_campamento, foto, estado)
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo')");
                    $stmt->execute([
                        $nombre, $edad, $edad_autorizada, $sexo, $iglesia, $estado_origen,
                        $contacto, $contacto_emergencia_nombre, $contacto_emergencia_telefono,
                        $alergias_enfermedades, $observaciones,
                        $cabana_id, $semana_id, obtenerAnioCampamento(), $foto
                    ]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO acampantes
                                          (nombre, edad, edad_autorizada, sexo, iglesia, estado_origen,
                                           contacto, contacto_emergencia_nombre, contacto_emergencia_telefono,
                                           alergias_enfermedades, observaciones,
                                           cabana_id, semana_id, year_campamento, estado)
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo')");
                    $stmt->execute([
                        $nombre, $edad, $edad_autorizada, $sexo, $iglesia, $estado_origen,
                        $contacto, $contacto_emergencia_nombre, $contacto_emergencia_telefono,
                        $alergias_enfermedades, $observaciones,
                        $cabana_id, $semana_id, obtenerAnioCampamento()
                    ]);
                }
                $message = "Acampante registrado exitosamente";
            
            } else {
                if ($foto) {
                    $stmt = $pdo->prepare("UPDATE acampantes
                                          SET nombre=?, edad=?, edad_autorizada=?, sexo=?, iglesia=?, estado_origen=?,
                                              contacto=?, contacto_emergencia_nombre=?, contacto_emergencia_telefono=?,
                                              alergias_enfermedades=?, observaciones=?,
                                              cabana_id=?, semana_id=?, foto=?
                                          WHERE id=?");
                    $stmt->execute([
                        $nombre, $edad, $edad_autorizada, $sexo, $iglesia, $estado_origen,
                        $contacto, $contacto_emergencia_nombre, $contacto_emergencia_telefono,
                        $alergias_enfermedades, $observaciones,
                        $cabana_id, $semana_id, $foto, $id
                    ]);
                } else {
                    $stmt = $pdo->prepare("UPDATE acampantes
                                          SET nombre=?, edad=?, edad_autorizada=?, sexo=?, iglesia=?, estado_origen=?,
                                              contacto=?, contacto_emergencia_nombre=?, contacto_emergencia_telefono=?,
                                              alergias_enfermedades=?, observaciones=?,
                                              cabana_id=?, semana_id=?
                                          WHERE id=?");
                    $stmt->execute([
                        $nombre, $edad, $edad_autorizada, $sexo, $iglesia, $estado_origen,
                        $contacto, $contacto_emergencia_nombre, $contacto_emergencia_telefono,
                        $alergias_enfermedades, $observaciones,
                        $cabana_id, $semana_id, $id
                    ]);
                }
                $message = "Acampante actualizado exitosamente";
            }
              
            header("Location: acampantes.php?message=" . urlencode($message));  
            exit();  
        }  
    } catch (Exception $e) {  
        $error = "Error: " . $e->getMessage();  
    }  
}  
  
// Eliminar acampante — REQUIERE POST + token CSRF
if ($action === 'delete' && $id) {
    // ⛔ Bloquear DELETE via GET (previene borrado accidental/SW prefetch)
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header("Location: acampantes.php");
        exit();
    }
    // Verificar token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = "Token de seguridad inválido. Intenta de nuevo.";
    } else {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("DELETE FROM sesiones_consejeria WHERE acampante_id = ?");
            $stmt->execute([$id]);
            
            $stmt = $pdo->prepare("DELETE FROM evaluacion_espiritual WHERE acampante_id = ?");
            $stmt->execute([$id]);
            
            $stmt = $pdo->prepare("DELETE FROM acampantes WHERE id = ?");
            $stmt->execute([$id]);
            
            $pdo->commit();
            header("Location: acampantes.php?message=" . urlencode("Acampante eliminado permanentemente"));
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error al eliminar: " . $e->getMessage();
        }
    }
}

// ── Obtener datos del acampante (view / edit) ────────────────────────
$acampante  = null;
$consejerias = [];

if (($action === 'view' || $action === 'edit') && $id) {
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, c.nombre_cabana 
            FROM acampantes a 
            LEFT JOIN cabanas c ON a.cabana_id = c.id
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        $acampante = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$acampante) {
            $error  = "Acampante no encontrado (ID: $id)";
            $action = 'list';
        } elseif ($action === 'view') {
            // Cargar consejerías solo en la vista de detalle
            $stmt = $pdo->prepare("
                SELECT sc.*, tc.categoria, tc.tema AS tema_predefinido, u.username
                FROM sesiones_consejeria sc
                LEFT JOIN temas_consejeria tc ON sc.tema_id = tc.id
                LEFT JOIN usuarios u ON sc.consejero_id = u.id
                WHERE sc.acampante_id = ?
                ORDER BY sc.numero_sesion DESC, sc.fecha_sesion DESC
            ");
            $stmt->execute([$id]);
            $consejerias = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $error  = "Error: " . $e->getMessage();
        $action = 'list';
    }
}   
  
// Obtener cabañas con conteo por semana
$stmt = $pdo->query("SELECT c.*, 
                     (SELECT COUNT(*) FROM acampantes a 
                      WHERE a.cabana_id = c.id AND a.estado = 'activo') as ocupados_total
                     FROM cabanas c 
                     WHERE c.activa = 1 
                     ORDER BY c.nombre_cabana");
$cabanas = $stmt->fetchAll();

// Obtener conteos de cabañas por semana para JavaScript
$stmt = $pdo->query("SELECT cabana_id, semana_id, COUNT(*) as ocupados 
                     FROM acampantes 
                     WHERE estado = 'activo' AND semana_id IS NOT NULL
                     GROUP BY cabana_id, semana_id");
$conteoPorSemana = $stmt->fetchAll();

// Construir array indexado para JSON
$ocupadosPorSemana = [];
foreach ($conteoPorSemana as $row) {
    $ocupadosPorSemana[$row['semana_id']][$row['cabana_id']] = $row['ocupados'];
}  

// Obtener lista de acampantes  
if ($action === 'list') {  
    $search = $_GET['search'] ?? '';  
    $cabana_filter = $_GET['cabana'] ?? '';  
    $semana_filter = $_GET['semana_id'] ?? '';

    // Obtener todas las semanas para el filtro
    $stmt_semanas = $pdo->query("SELECT * FROM semanas_campamento ORDER BY fecha_inicio ASC");
    $semanas_filtro = $stmt_semanas->fetchAll();

    $sql = "SELECT a.*, c.nombre_cabana, c.equipo as cabana_equipo,
               s.nombre as semana_nombre, s.activa as semana_activa
            FROM acampantes a   
            LEFT JOIN cabanas c ON a.cabana_id = c.id
            LEFT JOIN semanas_campamento s ON a.semana_id = s.id
            WHERE a.year_campamento = ? AND a.estado = 'activo'
              AND a.llego = 1";
    $params = [(int)obtenerAnioCampamento()];
    
    if ($semana_filter) {
        $sql .= " AND a.semana_id = ?";
        $params[] = (int)$semana_filter;
    }  
      
    if ($search) {  
        $sql .= " AND (a.nombre LIKE ? OR a.iglesia LIKE ?)";  
        $params[] = "%$search%";  
        $params[] = "%$search%";  
    }  
      
    if ($cabana_filter) {  
        $sql .= " AND a.cabana_id = ?";  
        $params[] = $cabana_filter;  
    }  
      
    $sql .= " ORDER BY a.nombre";  
      
    $stmt = $pdo->prepare($sql);  
    $stmt->execute($params);  
    $acampantes = $stmt->fetchAll();  
}  
  
// Mensajes de URL  
if (isset($_GET['message'])) {  
    $message = $_GET['message'];  
}  

// Config equipos para colores dinámicos
$equipos_config = obtenerEquipos($pdo);

// Rangos de edad por cabaña+semana para JavaScript
$rangosEdadJS = [];
try {
    $stmt_r = $pdo->query("SELECT cabana_id, semana_id, edad_min, edad_max, descripcion
                            FROM cabana_rangos_edad");
    foreach ($stmt_r->fetchAll() as $r) {
        $rangosEdadJS[$r['semana_id']][$r['cabana_id']] = [
            'min'  => $r['edad_min'],
            'max'  => $r['edad_max'],
            'desc' => $r['descripcion'] ?? ''
        ];
    }
} catch (Exception $e) {
    $rangosEdadJS = [];
}

include '../includes/header.php';
?>  
  
<div class="row mb-4">  
    <div class="col-12">  
        <h1><i class="fas fa-users"></i> <?php echo $titulo; ?></h1>  
        <nav aria-label="breadcrumb">  
            <ol class="breadcrumb">  
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>  
                <li class="breadcrumb-item active">Acampantes</li>  
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
  
<?php if ($action === 'list'): ?>  
<!-- Lista de acampantes -->  
<div class="card">  
    <div class="card-header d-flex justify-content-between align-items-center">  
        <h5>
            <i class="fas fa-users"></i> Acampantes con Check-in
            <span class="badge bg-success ms-1"><?php echo count($acampantes); ?></span>
            <small class="text-muted fw-normal fs-6 ms-2">
                <i class="fas fa-map-marker-alt"></i> Solo acampantes que ya llegaron al campamento
            </small>
        </h5> 
        <div class="btn-group">  
            <a href="acampantes.php?action=add" class="btn btn-success">  
                <i class="fas fa-plus"></i> Agregar Individual  
            </a>  
            <a href="importar_acampantes.php" class="btn btn-primary">  
                <i class="fas fa-file-upload"></i> Importar Masivo  
            </a>  
        </div>  
    </div>    
    <div class="card-body">  
        <!-- Filtros -->  
        <form method="GET" class="row mb-3">  
            <input type="hidden" name="action" value="list">  
        
            <div class="col-md-3 mb-2">  
                <input type="text" class="form-control" name="search"   
                       placeholder="Buscar por nombre o iglesia..."   
                       value="<?php echo htmlspecialchars($search ?? ''); ?>">  
            </div>
        
            <div class="col-md-3 mb-2">
                <select name="semana_id" class="form-select">
                    <option value="">Todas las semanas</option>
                    <?php foreach ($semanas_filtro as $sem): ?>
                    <option value="<?php echo $sem['id']; ?>"
                            <?php echo ($semana_filter == $sem['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($sem['nombre']); ?>
                        <?php echo $sem['activa'] ? '✓' : ''; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        
            <div class="col-md-2 mb-2">  
                <select name="cabana" class="form-select">  
                    <option value="">Todas las cabañas</option>  
                    <?php foreach ($cabanas as $cab): ?>  
                    <option value="<?php echo $cab['id']; ?>"   
                            <?php echo ($cabana_filter == $cab['id']) ? 'selected' : ''; ?>>  
                        <?php echo htmlspecialchars($cab['nombre_cabana']); ?>  
                    </option>  
                    <?php endforeach; ?>  
                </select>  
            </div>  
        
            <div class="col-md-2 mb-2">  
                <button type="submit" class="btn btn-primary w-100">  
                    <i class="fas fa-search"></i> Filtrar  
                </button>  
            </div>  
            <div class="col-md-2 mb-2">  
                <a href="acampantes.php" class="btn btn-secondary w-100">  
                    <i class="fas fa-refresh"></i> Limpiar  
                </a>  
            </div>  
        </form>  
  
        <!-- Tabla responsiva -->
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th style="width:70px;">Foto</th>
                        <th>Acampante</th>
                        <th>Contacto Emergencia</th>
                        <th>Cabaña / Equipo</th>
                        <th>Responsable</th>
                        <th>Estado Espiritual</th>
                        <th style="width:110px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($acampantes)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="fas fa-users fa-3x mb-3 d-block opacity-25"></i>
                            No se encontraron acampantes
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($acampantes as $camp):
                        $eqData  = $equipos_config[$camp['cabana_equipo'] ?? ''] ?? null;
                        $hexEq   = $eqData['color_hex'] ?? null;
                        $emojiEq = $eqData['emoji']     ?? null;
                        $tieneAlergia = !empty($camp['alergias_enfermedades']);
                    ?>
                    <tr>

                        <!-- Foto -->
                        <td>
                            <?php if (!empty($camp['foto']) && file_exists('../' . $camp['foto'])): ?>
                                <img src="<?php echo htmlspecialchars('../' . $camp['foto']); ?>"
                                     alt="<?php echo htmlspecialchars($camp['nombre']); ?>"
                                     class="rounded"
                                     style="width:55px; height:70px; object-fit:cover;">
                            <?php else: ?>
                                <div class="rounded bg-light d-flex align-items-center justify-content-center"
                                     style="width:55px; height:70px;">
                                    <i class="fas fa-user-circle fa-2x text-secondary opacity-50"></i>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Nombre + datos básicos -->
                        <td>
                            <div class="fw-bold">
                                <?php echo htmlspecialchars($camp['nombre']); ?>
                            </div>
                            <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                <span class="badge bg-<?php echo $camp['sexo']==='masculino' ? 'primary' : 'danger'; ?>">
                                    <i class="fas fa-<?php echo $camp['sexo']==='masculino' ? 'mars' : 'venus'; ?>"></i>
                                    <?php echo ucfirst($camp['sexo'] ?? ''); ?>
                                </span>
                                <?php if (!empty($camp['edad'])): ?>
                                <span class="text-muted small"><?php echo $camp['edad']; ?> años</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small mt-1">
                                <i class="fas fa-church"></i>
                                <?php echo htmlspecialchars($camp['iglesia'] ?? '—'); ?>
                            </div>
                            <?php if (!empty($camp['semana_nombre'])): ?>
                            <div class="mt-1">
                                <span class="badge bg-<?php echo $camp['semana_activa'] ? 'success' : 'secondary'; ?>"
                                      style="font-size:10px;">
                                    <i class="fas fa-calendar-week"></i>
                                    <?php echo htmlspecialchars($camp['semana_nombre']); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <?php if ($tieneAlergia): ?>
                            <div class="mt-1">
                                <span class="badge bg-danger" style="font-size:10px;"
                                      title="<?php echo htmlspecialchars($camp['alergias_enfermedades']); ?>">
                                    <i class="fas fa-exclamation-triangle"></i> ALERGIA
                                </span>
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Contacto emergencia -->
                        <td>
                            <?php if (!empty($camp['contacto_emergencia_nombre']) || !empty($camp['contacto_emergencia_telefono'])): ?>
                            <div class="small">
                                <?php if (!empty($camp['contacto_emergencia_nombre'])): ?>
                                <div class="fw-bold">
                                    <i class="fas fa-user text-muted"></i>
                                    <?php echo htmlspecialchars($camp['contacto_emergencia_nombre']); ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($camp['contacto_emergencia_telefono'])): ?>
                                <div>
                                    <a href="tel:<?php echo htmlspecialchars($camp['contacto_emergencia_telefono']); ?>"
                                       class="text-decoration-none text-success">
                                        <i class="fas fa-phone"></i>
                                        <?php echo htmlspecialchars($camp['contacto_emergencia_telefono']); ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($camp['contacto'])): ?>
                                <div class="text-muted" style="font-size:11px;">
                                    <i class="fas fa-mobile-alt"></i>
                                    <?php echo htmlspecialchars($camp['contacto']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <em class="text-muted small">—</em>
                            <?php endif; ?>
                        </td>

                        <!-- Cabaña + Equipo -->
                        <td>
                            <?php if (!empty($camp['nombre_cabana'])): ?>
                            <span class="badge bg-dark mb-1 d-block" style="font-size:11px;">
                                <i class="fas fa-home"></i>
                                <?php echo htmlspecialchars($camp['nombre_cabana']); ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($hexEq): ?>
                            <span class="badge" style="background-color:<?php echo $hexEq; ?>; font-size:10px;">
                                <?php echo $emojiEq; ?>
                                <?php echo htmlspecialchars($eqData['nombre']); ?>
                            </span>
                            <?php elseif (empty($camp['nombre_cabana'])): ?>
                            <em class="text-muted small">Sin asignar</em>
                            <?php endif; ?>
                        </td>

                        <!-- Consejero Responsable -->
                        <td>
                            <?php if (!empty($camp['consejero_responsable'])): ?>
                            <span class="badge bg-warning text-dark d-flex align-items-center gap-1"
                                  style="font-size:11px; width:fit-content;">
                                <i class="fas fa-user-shield"></i>
                                <?php echo htmlspecialchars($camp['consejero_responsable']); ?>
                            </span>
                            <?php else: ?>
                            <em class="text-muted small">Sin asignar</em>
                            <?php endif; ?>
                        </td>

                        <!-- Estado espiritual -->
                        <td>
                            <div class="d-flex flex-column gap-1">
                                <?php if ($camp['recibio_cristo_semana']): ?>
                                <span class="badge bg-success" style="font-size:10px;">
                                    ✝️ Recibió a Cristo
                                </span>
                                <?php endif; ?>
                                <?php if ($camp['consagro_vida_fogata']): ?>
                                <span class="badge bg-warning text-dark" style="font-size:10px;">
                                    🙏 Consagró vida
                                </span>
                                <?php endif; ?>
                                <?php if ($camp['era_creyente_antes']): ?>
                                <span class="badge bg-primary" style="font-size:10px;">
                                    📖 Era creyente
                                </span>
                                <?php endif; ?>
                                <?php if ($camp['asiste_iglesia']): ?>
                                <span class="badge bg-info" style="font-size:10px;">
                                    ⛪ Asiste iglesia
                                </span>
                                <?php endif; ?>
                                <?php if (!$camp['recibio_cristo_semana'] && !$camp['consagro_vida_fogata']
                                       && !$camp['era_creyente_antes'] && !$camp['asiste_iglesia']): ?>
                                <span class="badge bg-light text-muted border" style="font-size:10px;">
                                    Sin registro
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- Acciones -->
                        <td>
                            <div class="btn-group-vertical w-100 gap-1">
                                <a href="acampantes.php?action=view&id=<?php echo $camp['id']; ?>"
                                   class="btn btn-sm btn-outline-info" title="Ver detalle">
                                    <i class="fas fa-eye"></i> Ver
                                </a>
                                <a href="acampantes.php?action=edit&id=<?php echo $camp['id']; ?>"
                                   class="btn btn-sm btn-outline-primary" title="Editar">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                <form method="POST" 
                                  action="acampantes.php?action=delete&id=<?php echo $camp['id']; ?>"
                                  onsubmit="return confirmarEliminacion('¿Eliminar permanentemente a <?php echo htmlspecialchars(addslashes($camp['nombre'])); ?>?')"
                                  style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>  
</div>  
  
<?php elseif ($action === 'add' || $action === 'edit'): ?>  
    <?php if ($action === 'edit' && !$acampante): ?>  
        <div class="alert alert-danger">  
            <i class="fas fa-exclamation-triangle"></i> Error: Acampante no encontrado  
        </div>  
        <a href="acampantes.php" class="btn btn-secondary">Volver</a>  
    <?php else: ?>  
<!-- Formulario de agregar/editar -->  
<div class="card">  
    <div class="card-header">  
        <h5>  
            <i class="fas fa-<?php echo $action === 'add' ? 'plus' : 'edit'; ?>"></i>  
            <?php echo $action === 'add' ? 'Nuevo' : 'Editar'; ?> Acampante  
        </h5>  
    </div>  
    <div class="card-body">  
        <?php
        $estados = obtenerEstados();
        $val = fn($campo) => $action === 'edit' && $acampante
            ? htmlspecialchars($acampante[$campo] ?? '')
            : '';
        ?>
        <form method="POST" id="formAcampante" enctype="multipart/form-data">
            <div class="row">
        
                <!-- ── COL 1: Datos personales ── -->
                <div class="col-md-4">
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-user"></i> Datos Personales
                    </h6>
        
                    <div class="mb-3">
                        <label class="form-label">Nombre Completo *</label>
                        <input type="text" class="form-control" name="nombre" required
                               value="<?php echo $val('nombre'); ?>">
                    </div>
        
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Edad</label>
                            <input type="number" class="form-control" name="edad"
                                   id="campo_edad"
                                   min="1" max="100"
                                   value="<?php echo $val('edad'); ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Sexo *</label>
                            <select class="form-select" id="sexo" name="sexo" required>
                                <option value="">-- Seleccionar --</option>
                                <option value="masculino"
                                    <?php echo ($action==='edit' && ($acampante['sexo']??'')==='masculino') ? 'selected' : ''; ?>>
                                    ♂ Masculino
                                </option>
                                <option value="femenino"
                                    <?php echo ($action==='edit' && ($acampante['sexo']??'')==='femenino') ? 'selected' : ''; ?>>
                                    ♀ Femenino
                                </option>
                            </select>
                        </div>
                    </div>
        
                    <div class="mb-3">
                        <label class="form-label">Iglesia *</label>
                        <input type="text" class="form-control" name="iglesia" required
                               value="<?php echo $val('iglesia'); ?>">
                    </div>
        
                    <div class="mb-3">
                        <label class="form-label"><?php echo etiquetaDivision(); ?></label>
                        <select class="form-select" name="estado_origen">
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($estados as $est): ?>
                            <option value="<?php echo $est; ?>"
                                <?php echo ($val('estado_origen') === $est) ? 'selected' : ''; ?>>
                                <?php echo $est; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
        
                    <!-- Alerta de rango + autorización -->
                    <div id="bloque_rango_edad" class="mb-3" style="display:none;">
                        <div id="alerta_rango" class="alert alert-warning py-2 small mb-2">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span id="texto_rango">La edad no está en el rango configurado para esta cabaña.</span>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="edad_autorizada"
                                   name="edad_autorizada" value="1"
                                   <?php echo ($action==='edit' && !empty($acampante['edad_autorizada'])) ? 'checked' : ''; ?>>
                            <label class="form-check-label small fw-bold text-danger" for="edad_autorizada">
                                <i class="fas fa-user-check"></i>
                                Autorizo el ingreso aunque la edad esté fuera del rango
                            </label>
                        </div>
                    </div>

                    <!-- Badge si ya estaba autorizado en edición -->
                    <?php if ($action === 'edit' && !empty($acampante['edad_autorizada'])): ?>
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="fas fa-user-check"></i>
                        <strong>Ingreso autorizado:</strong>
                        Este acampante fue registrado con autorización especial de edad.
                    </div>
                    <?php endif; ?>

                    <?php if ($action === 'edit' && !empty($acampante['foto'])): ?>
                    <div class="mb-3">
                        <label class="form-label text-muted small">
                            <i class="fas fa-camera"></i> Foto registrada por apoyo
                        </label>
                        <div>
                            <img src="<?php echo htmlspecialchars('../' . $acampante['foto']); ?>"
                                 alt="Foto actual" class="img-thumbnail" style="max-width:120px;">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
        
                <!-- ── COL 2: Contacto y salud ── -->
                <div class="col-md-4">
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-phone-alt"></i> Contacto y Salud
                    </h6>
        
                    <div class="mb-3">
                        <label class="form-label">Teléfono / Email del acampante</label>
                        <input type="text" class="form-control" name="contacto"
                               value="<?php echo $val('contacto'); ?>">
                    </div>
        
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-exclamation-triangle text-warning"></i>
                            Nombre del contacto de emergencia
                        </label>
                        <input type="text" class="form-control" name="contacto_emergencia_nombre"
                               placeholder="Nombre de la persona a contactar"
                               value="<?php echo $val('contacto_emergencia_nombre'); ?>">
                    </div>
        
                    <div class="mb-3">
                        <label class="form-label">Teléfono de emergencia</label>
                        <input type="text" class="form-control" name="contacto_emergencia_telefono"
                               placeholder="Número de teléfono"
                               value="<?php echo $val('contacto_emergencia_telefono'); ?>">
                    </div>
        
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-notes-medical text-danger"></i>
                            Alergias o Enfermedades
                        </label>
                        <textarea class="form-control" name="alergias_enfermedades" rows="3"
                                  placeholder="Deja en blanco si no tiene ninguna"><?php echo $val('alergias_enfermedades'); ?></textarea>
                    </div>
        
                    <div class="mb-3">
                        <label class="form-label">Observaciones</label>
                        <textarea class="form-control" name="observaciones" rows="3"
                                  placeholder="Cualquier observación adicional..."><?php echo $val('observaciones'); ?></textarea>
                    </div>
                </div>
        
                <!-- ── COL 3: Asignación ── -->
                <div class="col-md-4">
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-home"></i> Asignación de Cabaña
                    </h6>
        
                    <div class="mb-3">
                        <label class="form-label">Semana de Campamento *</label>
                        <select class="form-select" name="semana_id" id="semana_id" required>
                            <option value="">-- Seleccionar Semana --</option>
                            <?php
                            $stmt_sem = $pdo->query("SELECT * FROM semanas_campamento ORDER BY fecha_inicio ASC");
                            $semanas_lista = $stmt_sem->fetchAll();
                            foreach ($semanas_lista as $sem):
                            ?>
                            <option value="<?php echo $sem['id']; ?>"
                                <?php echo (isset($acampante['semana_id']) && $acampante['semana_id']==$sem['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sem['nombre']); ?>
                                (<?php echo date('d/m/Y', strtotime($sem['fecha_inicio'])); ?>)
                                <?php echo $sem['activa'] ? '✓ ACTIVA' : ''; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Los acampantes se agrupan por semana</small>
                    </div>
        
                    <div class="mb-3">
                        <label class="form-label">Cabaña Asignada *</label>
                        <select class="form-select" id="cabana_id" name="cabana_id" required>
                            <option value="">-- Seleccionar cabaña --</option>
                            <?php foreach ($cabanas as $cab):
                                $icono = $cab['genero'] === 'masculino' ? '♂' : '♀';
                            ?>
                            <option value="<?php echo $cab['id']; ?>"
                                    data-genero="<?php echo $cab['genero']; ?>"
                                    data-capacidad="<?php echo $cab['capacidad_maxima']; ?>"
                                    data-ocupados-total="<?php echo $cab['ocupados_total']; ?>"
                                    data-nombre-base="<?php echo htmlspecialchars($cab['nombre_cabana']); ?>"
                                    <?php echo ($action==='edit' && $acampante && $acampante['cabana_id']==$cab['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cab['nombre_cabana']); ?>
                                <?php echo $icono; ?>
                                - <?php echo $cab['ocupados_total']; ?>/<?php echo $cab['capacidad_maxima']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
        
                        <!-- Barra de ocupación -->
                        <div id="info_cabana" class="mt-2" style="display:none;">
                            <div class="progress mb-1" style="height:8px;">
                                <div id="barra_ocupacion" class="progress-bar" style="width:0%"></div>
                            </div>
                            <small id="texto_ocupacion" class="text-muted"></small>
                        </div>
        
                        <!-- Alerta género -->
                        <div id="alerta_genero" class="alert alert-warning mt-2 py-2" style="display:none;">
                            <i class="fas fa-exclamation-triangle"></i>
                            El sexo del acampante no coincide con el género de esta cabaña.
                        </div>
        
                        <small class="text-muted d-block mt-1">
                            Solo podrás asignar a cabañas del mismo sexo del acampante
                        </small>
                    </div>
        
                    <!-- Info de semana activa -->
                    <?php
                    $sem_activa_form = null;
                    foreach ($semanas_lista as $s) {
                        if ($s['activa']) { $sem_activa_form = $s; break; }
                    }
                    ?>
                    <?php if ($sem_activa_form): ?>
                    <div class="alert alert-success py-2 small">
                        <i class="fas fa-broadcast-tower"></i>
                        <strong>Semana activa:</strong>
                        <?php echo htmlspecialchars($sem_activa_form['nombre']); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        
            <hr>
        
            <div class="d-flex justify-content-between">
                <a href="acampantes.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i>
                    <?php echo $action === 'add' ? 'Registrar' : 'Actualizar'; ?> Acampante
                </button>
            </div>
        </form>  
    </div>  
</div>  
<?php endif; ?>  

<?php elseif ($action === 'view'): ?>
<!-- Vista detallada del acampante -->

<!-- ── Breadcrumb y acciones rápidas ── -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0">
            <i class="fas fa-<?php echo $acampante['sexo']==='masculino' ? 'mars text-primary' : 'venus text-danger'; ?>"></i>
            <?php echo htmlspecialchars($acampante['nombre']); ?>
        </h4>
        <small class="text-muted">
            ID #<?php echo $acampante['id']; ?> &nbsp;|&nbsp;
            Registrado: <?php echo !empty($acampante['fecha_registro']) ? date('d/m/Y H:i', strtotime($acampante['fecha_registro'])) : '—'; ?>
        </small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="acampantes.php?action=edit&id=<?php echo $acampante['id']; ?>"
           class="btn btn-primary">
            <i class="fas fa-edit"></i> Editar
        </a>
        <a href="reportes.php?acampante_id=<?php echo $acampante['id']; ?>"
           class="btn btn-info">
            <i class="fas fa-file-pdf"></i> Reporte
        </a>
        <a href="acampantes.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
</div>

<div class="row g-3">

    <!-- ══ COLUMNA IZQUIERDA: Foto + Asignación + Espiritual ══ -->
    <div class="col-md-3">

        <!-- Foto -->
        <div class="card mb-3 text-center">
            <?php if (!empty($acampante['foto']) && file_exists('../' . $acampante['foto'])): ?>
                <img src="<?php echo htmlspecialchars('../' . $acampante['foto']); ?>"
                     alt="<?php echo htmlspecialchars($acampante['nombre']); ?>"
                     class="card-img-top" style="height:220px; object-fit:cover;">
            <?php else: ?>
                <div class="card-body py-4">
                    <i class="fas fa-user-circle fa-5x text-secondary opacity-50"></i>
                    <p class="text-muted small mt-2 mb-0">Sin foto</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Asignación -->
        <?php
        // Obtener semana del acampante
        $stmt_sem_view = $pdo->prepare("SELECT s.id, s.nombre, s.activa, s.tipo_acampante,
                                        s.fecha_inicio, s.fecha_fin
                                        FROM semanas_campamento s
                                        WHERE s.id = ?");
        $stmt_sem_view->execute([$acampante['semana_id']]);
        $semana_acampante = $stmt_sem_view->fetch();
        ?>
        <div class="card mb-3">
            <div class="card-header bg-success text-white py-2">
                <h6 class="mb-0"><i class="fas fa-home"></i> Asignación</h6>
            </div>
            <div class="card-body py-3">
                <p class="mb-2">
                    <span class="text-muted small d-block">Semana</span>
                    <?php if ($semana_acampante): ?>
                        <span class="badge bg-<?php echo $semana_acampante['activa'] ? 'success' : 'secondary'; ?> text-wrap">
                            <i class="fas fa-calendar-week"></i>
                            <?php echo htmlspecialchars($semana_acampante['nombre']); ?>
                        </span>
                        <br>
                        <small class="text-muted">
                            <?php echo date('d/m/Y', strtotime($semana_acampante['fecha_inicio'])); ?> —
                            <?php echo date('d/m/Y', strtotime($semana_acampante['fecha_fin'])); ?>
                        </small>
                    <?php else: ?>
                        <em class="text-muted small">Sin semana asignada</em>
                    <?php endif; ?>
                </p>
                <p class="mb-0">
                    <span class="text-muted small d-block">Cabaña</span>
                    <?php if (!empty($acampante['nombre_cabana'])): ?>
                        <span class="badge bg-primary fs-6">
                            <i class="fas fa-home"></i>
                            <?php echo htmlspecialchars($acampante['nombre_cabana']); ?>
                        </span>
                    <?php else: ?>
                        <em class="text-muted small">Sin cabaña asignada</em>
                    <?php endif; ?>
                </p>

                <!-- Consejero responsable -->
                <hr class="my-2">
                <p class="mb-0">
                    <span class="text-muted small d-block">
                        <i class="fas fa-user-shield"></i> Consejero Responsable
                    </span>
                    <?php if (!empty($acampante['consejero_responsable'])): ?>
                    <span class="badge bg-warning text-dark fs-6 mt-1">
                        <?php echo htmlspecialchars($acampante['consejero_responsable']); ?>
                    </span>
                    <?php else: ?>
                    <em class="text-muted small">Sin asignar</em>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Estado Espiritual -->
        <div class="card">
            <div class="card-header bg-secondary text-white py-2">
                <h6 class="mb-0"><i class="fas fa-cross"></i> Estado Espiritual</h6>
            </div>
            <div class="card-body py-3">
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <?php if ($acampante['asiste_iglesia']): ?>
                        <span class="badge bg-info">Asiste a iglesia</span>
                    <?php endif; ?>
                    <?php if ($acampante['era_creyente_antes']): ?>
                        <span class="badge bg-primary">Era creyente</span>
                    <?php endif; ?>
                    <?php if ($acampante['recibio_cristo_semana']): ?>
                        <span class="badge bg-success">Recibió a Cristo</span>
                    <?php endif; ?>
                    <?php if ($acampante['consagro_vida_fogata']): ?>
                        <span class="badge bg-warning text-dark">Consagró vida</span>
                    <?php endif; ?>
                    <?php if (!$acampante['asiste_iglesia'] && !$acampante['era_creyente_antes']
                              && !$acampante['recibio_cristo_semana'] && !$acampante['consagro_vida_fogata']): ?>
                        <em class="text-muted small">Sin registro espiritual</em>
                    <?php endif; ?>
                </div>
                <?php if (!empty($acampante['decision_tomada'])): ?>
                <hr class="my-2">
                <p class="small text-muted mb-1 fw-bold">Decisión tomada:</p>
                <p class="small mb-0"><?php echo nl2br(htmlspecialchars($acampante['decision_tomada'])); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Estadísticas consejerías -->
        <div class="card mt-3">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="fas fa-chart-bar"></i> Consejerías</h6>
            </div>
            <div class="card-body text-center py-3">
                <div class="row">
                    <div class="col-6 border-end">
                        <h3 class="text-primary mb-0">
                            <?php echo count(array_unique(array_column($consejerias, 'numero_sesion'))); ?>
                        </h3>
                        <small class="text-muted">Sesiones</small>
                    </div>
                    <div class="col-6">
                        <h3 class="text-info mb-0"><?php echo count($consejerias); ?></h3>
                        <small class="text-muted">Temas</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ COLUMNA DERECHA: Info personal + Contacto + Salud + Consejerías ══ -->
    <div class="col-md-9">

        <!-- Datos personales + Contacto emergencia + Salud -->
        <div class="row g-3 mb-3">

            <!-- Datos personales -->
            <div class="col-md-5">
                <div class="card h-100">
                    <div class="card-header bg-primary text-white py-2">
                        <h6 class="mb-0"><i class="fas fa-user"></i> Datos Personales</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <td class="text-muted" style="width:42%; white-space:nowrap;">Nombre</td>
                                <td><strong><?php echo htmlspecialchars($acampante['nombre']); ?></strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Edad</td>
                                <td>
                                    <?php echo !empty($acampante['edad'])
                                        ? $acampante['edad'] . ' años'
                                        : '<em class="text-muted">—</em>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">Sexo</td>
                                <td>
                                    <span class="badge bg-<?php echo $acampante['sexo']==='masculino' ? 'primary' : 'danger'; ?>">
                                        <i class="fas fa-<?php echo $acampante['sexo']==='masculino' ? 'mars' : 'venus'; ?>"></i>
                                        <?php echo ucfirst($acampante['sexo'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">Iglesia</td>
                                <td><?php echo htmlspecialchars($acampante['iglesia'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted"><?php echo PAIS_DIVISION; ?></td>
                                <td>
                                    <?php echo !empty($acampante['estado_origen'])
                                        ? htmlspecialchars($acampante['estado_origen'])
                                        : '<em class="text-muted">—</em>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">Contacto</td>
                                <td>
                                    <?php echo !empty($acampante['contacto'])
                                        ? htmlspecialchars($acampante['contacto'])
                                        : '<em class="text-muted">—</em>'; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Contacto emergencia + Salud -->
            <div class="col-md-7">
                <div class="row g-3 h-100">

                    <!-- Contacto emergencia -->
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-warning text-dark py-2">
                                <h6 class="mb-0">
                                    <i class="fas fa-phone-alt"></i> Contacto de Emergencia
                                </h6>
                            </div>
                            <div class="card-body py-3">
                                <?php if (!empty($acampante['contacto_emergencia_nombre']) || !empty($acampante['contacto_emergencia_telefono'])): ?>
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted d-block">Persona</small>
                                        <strong><?php echo htmlspecialchars($acampante['contacto_emergencia_nombre'] ?? '—'); ?></strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Teléfono</small>
                                        <?php if (!empty($acampante['contacto_emergencia_telefono'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($acampante['contacto_emergencia_telefono']); ?>"
                                           class="fw-bold text-decoration-none">
                                            <i class="fas fa-phone text-success"></i>
                                            <?php echo htmlspecialchars($acampante['contacto_emergencia_telefono']); ?>
                                        </a>
                                        <?php else: ?>
                                        <em class="text-muted">—</em>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <p class="text-muted small text-center mb-0">
                                    <i class="fas fa-phone-slash"></i> Sin contacto de emergencia registrado
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Salud -->
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-danger text-white py-2">
                                <h6 class="mb-0">
                                    <i class="fas fa-notes-medical"></i> Salud
                                </h6>
                            </div>
                            <div class="card-body py-3">
                                <div class="row">
                                    <div class="col-md-6">
                                        <small class="text-muted fw-bold d-block mb-1">
                                            Alergias / Enfermedades
                                        </small>
                                        <?php if (!empty($acampante['alergias_enfermedades'])): ?>
                                        <div class="alert alert-danger py-2 px-3 mb-0 small">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <?php echo nl2br(htmlspecialchars($acampante['alergias_enfermedades'])); ?>
                                        </div>
                                        <?php else: ?>
                                        <em class="text-muted small">Ninguna registrada</em>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted fw-bold d-block mb-1">Observaciones</small>
                                        <?php if (!empty($acampante['observaciones'])): ?>
                                        <p class="small mb-0">
                                            <?php echo nl2br(htmlspecialchars($acampante['observaciones'])); ?>
                                        </p>
                                        <?php else: ?>
                                        <em class="text-muted small">Sin observaciones</em>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Historial de consejerías -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="fas fa-history"></i>
                    Historial de Consejerías
                    <span class="badge bg-primary ms-1">
                        <?php echo count(array_unique(array_column($consejerias, 'numero_sesion'))); ?>
                    </span>
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($consejerias)): ?>
                    <p class="text-muted text-center py-4 mb-0">
                        <i class="fas fa-comments fa-2x d-block mb-2 opacity-50"></i>
                        No hay consejerías registradas
                    </p>
                <?php else: ?>
                    <?php
                    $sesionesAgrupadas = [];
                    foreach ($consejerias as $consejeria) {
                        $sesionesAgrupadas[$consejeria['numero_sesion']][] = $consejeria;
                    }
                    ?>
                    <div class="row g-3">
                    <?php foreach ($sesionesAgrupadas as $numSesion => $consejerias_sesion): ?>
                    <div class="col-md-6">
                        <div class="card border-primary h-100">
                            <div class="card-header bg-primary bg-opacity-10 py-2 d-flex justify-content-between">
                                <span class="fw-bold text-primary">
                                    <i class="fas fa-comments"></i> Sesión #<?php echo $numSesion; ?>
                                </span>
                                <small class="text-muted">
                                    <?php echo formatearFecha($consejerias_sesion[0]['fecha_sesion']); ?>
                                    <?php if ($consejerias_sesion[0]['hora_sesion']): ?>
                                        — <?php echo formatearHora($consejerias_sesion[0]['hora_sesion']); ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="card-body py-2">
                                <!-- Consejero responsable del acampante -->
                                <?php if (!empty($acampante['consejero_responsable'])): ?>
                                <div class="mb-2">
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-user-shield"></i>
                                        Responsable: <?php echo htmlspecialchars($acampante['consejero_responsable']); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <!-- Consejero que registró la sesión -->
                                <p class="small text-muted mb-2">
                                    <i class="fas fa-pencil-alt"></i>
                                    <?php
                                    $consejero_nombre = $consejerias_sesion[0]['username'] ?? 'Consejero';
                                    if (strpos($consejero_nombre, 'consejero_cabana_') === 0) {
                                        $cabana_num = str_replace('consejero_cabana_', '', $consejero_nombre);
                                        $stmt_cab = $pdo->prepare("SELECT nombre_cabana FROM cabanas WHERE id = ?");
                                        $stmt_cab->execute([$cabana_num]);
                                        $cabana_info = $stmt_cab->fetch();
                                        echo "Registrado por consejero de " . ($cabana_info['nombre_cabana'] ?? "Cabaña $cabana_num");
                                    } else {
                                        echo "Registrado por: " . htmlspecialchars($consejero_nombre);
                                    }
                                    ?>
                                </p>
                                <ul class="small mb-2 ps-3">
                                    <?php foreach ($consejerias_sesion as $cons): ?>
                                    <li>
                                        <?php if ($cons['tema_predefinido']): ?>
                                            <span class="text-primary"><?php echo htmlspecialchars($cons['tema_predefinido']); ?></span>
                                            <span class="text-muted">(<?php echo htmlspecialchars($cons['categoria']); ?>)</span>
                                        <?php else: ?>
                                            <span class="text-success"><?php echo htmlspecialchars($cons['tema_personalizado']); ?></span>
                                            <span class="text-muted">(Personalizado)</span>
                                        <?php endif; ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if ($consejerias_sesion[0]['observaciones']): ?>
                                <div class="bg-light rounded p-2 small">
                                    <i class="fas fa-sticky-note text-muted"></i>
                                    <?php echo nl2br(htmlspecialchars($consejerias_sesion[0]['observaciones'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div><!-- /col-md-9 -->
</div><!-- /row -->
  
<?php endif; ?>  
  
<script>
// Datos de ocupación por semana desde PHP
const ocupadosPorSemana = <?php echo json_encode($ocupadosPorSemana ?? []); ?>;

document.addEventListener('DOMContentLoaded', function() {
    activarProteccionFormulario();

    const semanaSelect = document.getElementById('semana_id');
    const cabanaSelect = document.getElementById('cabana_id');

    // ── Solo ejecutar lógica del formulario si estamos en add/edit ──
    if (!cabanaSelect) return;
    const infoCabana = document.getElementById('info_cabana');
    const barraOcupacion = document.getElementById('barra_ocupacion');
    const textoOcupacion = document.getElementById('texto_ocupacion');

    // Actualizar texto de cabañas cuando cambia la semana
    function actualizarCabanas() {
        if (!semanaSelect || !cabanaSelect) return;

        const semanaId = semanaSelect.value;

        Array.from(cabanaSelect.options).forEach(option => {
            if (!option.value) return;

            const cabanaId = option.value;
            const capacidad = parseInt(option.dataset.capacidad) || 0;
            const genero = option.dataset.genero || '';

            // Obtener ocupados para esta semana específica
            let ocupados = 0;
            if (semanaId && ocupadosPorSemana[semanaId] && ocupadosPorSemana[semanaId][cabanaId]) {
                ocupados = parseInt(ocupadosPorSemana[semanaId][cabanaId]);
            }

            const disponibles = capacidad - ocupados;
            const icono = genero === 'masculino' ? '♂' : '♀';

            option.textContent = `${option.dataset.nombre || ''} ${icono} - ${ocupados}/${capacidad} (${disponibles} disponibles)`;

            // Reconstruir texto con nombre correcto
            option.textContent = `${cabanaSelect.options[cabanaSelect.selectedIndex === option.index ? 0 : option.index].dataset.nombreOriginal || option.value} ${icono} - ${ocupados}/${capacidad}`;
        });

        // Actualizar info de cabaña seleccionada
        actualizarInfoCabana();
    }

    // Guardar nombres originales al cargar
    Array.from(cabanaSelect.options).forEach((option, index) => {
        if (option.value) {
            const capacidad = option.dataset.capacidad;
            const genero = option.dataset.genero;
            const ocupadosTotal = option.dataset.ocupadosTotal || 0;
            const icono = genero === 'masculino' ? '♂' : '♀';
            // Guardar nombre base sin contadores
            const textoBase = option.textContent.trim().split('(')[0].trim().split('-')[0].trim();
            option.dataset.nombreBase = textoBase;
        }
    });

    // Función principal que actualiza el select de cabañas
    function actualizarSelectCabanas() {
        if (!semanaSelect || !cabanaSelect) return;

        const semanaId = semanaSelect.value;

        Array.from(cabanaSelect.options).forEach(option => {
            if (!option.value) return;

            const cabanaId = option.value;
            const capacidad = parseInt(option.dataset.capacidad) || 0;
            const genero = option.dataset.genero || '';
            const nombreBase = option.dataset.nombreBase || option.value;
            const icono = genero === 'masculino' ? '♂' : '♀';

            let ocupados = 0;
            if (semanaId && ocupadosPorSemana[semanaId] && ocupadosPorSemana[semanaId][cabanaId]) {
                ocupados = parseInt(ocupadosPorSemana[semanaId][cabanaId]);
            }

            const disponibles = capacidad - ocupados;
            option.textContent = `${nombreBase} ${icono} - ${ocupados}/${capacidad} (${disponibles} disponibles)`;
        });

        actualizarInfoCabana();
    }

    // Mostrar barra de ocupación al seleccionar cabaña
    function actualizarInfoCabana() {
        if (!cabanaSelect || !infoCabana) return;

        const selected = cabanaSelect.options[cabanaSelect.selectedIndex];
        if (!selected || !selected.value) {
            infoCabana.style.display = 'none';
            return;
        }

        const semanaId = semanaSelect ? semanaSelect.value : '';
        const cabanaId = selected.value;
        const capacidad = parseInt(selected.dataset.capacidad) || 0;

        let ocupados = 0;
        if (semanaId && ocupadosPorSemana[semanaId] && ocupadosPorSemana[semanaId][cabanaId]) {
            ocupados = parseInt(ocupadosPorSemana[semanaId][cabanaId]);
        }

        const disponibles = capacidad - ocupados;
        const porcentaje = capacidad > 0 ? (ocupados / capacidad) * 100 : 0;

        // Color de barra
        let colorBarra = 'bg-success';
        if (porcentaje >= 90) colorBarra = 'bg-danger';
        else if (porcentaje >= 70) colorBarra = 'bg-warning';

        barraOcupacion.style.width = Math.min(100, porcentaje) + '%';
        barraOcupacion.className = 'progress-bar ' + colorBarra;

        const textoSemana = semanaId ? 'en esta semana' : 'en total';
        textoOcupacion.textContent = `${ocupados}/${capacidad} acampantes ${textoSemana} · ${disponibles} lugar(es) disponible(s)`;
        textoOcupacion.className = disponibles <= 0 ? 'text-danger small fw-bold' : 'text-muted small';

        infoCabana.style.display = 'block';
    }

    // Validación de género al cambiar cabaña o sexo
    function validarGenero() {
        const alertaGenero = document.getElementById('alerta_genero');
        if (!alertaGenero || !cabanaSelect) return;
    
        const selected = cabanaSelect.options[cabanaSelect.selectedIndex];
        const sexoSelect = document.getElementById('sexo');
    
        if (!selected || !selected.value || !sexoSelect || !sexoSelect.value) {
            alertaGenero.style.display = 'none';
            return;
        }
    
        const generoCabana = selected.dataset.genero;
        const sexoAcampante = sexoSelect.value;
    
        if (generoCabana && sexoAcampante && generoCabana !== sexoAcampante) {
            alertaGenero.style.display = 'block';
        } else {
            alertaGenero.style.display = 'none';
        }
    }
    
    // Eventos
    if (semanaSelect) {
        semanaSelect.addEventListener('change', actualizarSelectCabanas);
    }
    
    if (cabanaSelect) {
        cabanaSelect.addEventListener('change', function() {
            actualizarInfoCabana();
            validarGenero();
        });
    }
    
    const sexoSelectEl = document.getElementById('sexo');
    if (sexoSelectEl) {
        sexoSelectEl.addEventListener('change', validarGenero);
    }

    // Ejecutar al cargar si ya hay semana seleccionada
    if (semanaSelect && semanaSelect.value) {
        actualizarSelectCabanas();
    }
    if (cabanaSelect && cabanaSelect.value) {
        actualizarInfoCabana();
    }

    // ── Validación de rango de edad en tiempo real ─────────────
    const rangosEdad    = <?php echo json_encode($rangosEdadJS ?? []); ?>;
    const campoEdad     = document.getElementById('campo_edad');
    const bloqueRango   = document.getElementById('bloque_rango_edad');
    const textoRango    = document.getElementById('texto_rango');
    const chkAutorizado = document.getElementById('edad_autorizada');

    function validarRangoEdad() {
        if (!campoEdad || !bloqueRango) return;

        const semanaId = semanaSelect ? semanaSelect.value : '';
        const cabanaId = cabanaSelect ? cabanaSelect.value  : '';
        const edad     = parseInt(campoEdad.value) || 0;

        if (!semanaId || !cabanaId || !edad) {
            bloqueRango.style.display = 'none';
            return;
        }

        const rango = rangosEdad[semanaId] && rangosEdad[semanaId][cabanaId]
            ? rangosEdad[semanaId][cabanaId]
            : null;

        if (!rango) {
            bloqueRango.style.display = 'none';
            return;
        }

        const fueraDeRango = edad < rango.min || edad > rango.max;

        if (fueraDeRango) {
            const desc = rango.desc ? ` (${rango.desc})` : '';
            textoRango.textContent =
                `⚠️ La edad (${edad} años) está fuera del rango configurado para esta cabaña: ` +
                `${rango.min}–${rango.max} años${desc}.`;
            bloqueRango.style.display = 'block';
        } else {
            bloqueRango.style.display = 'none';
            if (chkAutorizado) chkAutorizado.checked = false;
        }
    }

    if (campoEdad)   campoEdad.addEventListener('input',  validarRangoEdad);
    if (semanaSelect) semanaSelect.addEventListener('change', validarRangoEdad);
    if (cabanaSelect) cabanaSelect.addEventListener('change', validarRangoEdad);

    // Ejecutar al cargar en edición
    validarRangoEdad();

    // ── Activar protección DESPUÉS de toda la inicialización ──
    // Así los cambios programáticos de JS no disparan el flag
    setTimeout(activarProteccionFormulario, 300);
});
</script>
  
<?php include '../includes/footer.php'; ?>  