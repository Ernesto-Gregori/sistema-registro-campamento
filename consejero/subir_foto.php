<?php  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
if (!esConsejero()) {  
    header('Location: ../admin/dashboard.php');  
    exit();  
}  
  
$titulo = "Subir Foto de Acampante";  
$message = '';  
$error = '';  
$acampante = null;  
$year = obtenerAnioCampamento();  
  
// Obtener ID de cabaña directamente de la sesión  
$cabana_id = $_SESSION['cabana_id'] ?? null;  
  
if (!$cabana_id) {  
    $error = "No tienes una cabaña asignada. Contacta al administrador.";  
}  
  
// Obtener información de la cabaña para mostrar  
$cabana_nombre = '';  
if ($cabana_id) {  
    try {  
        $stmt = $pdo->prepare("SELECT nombre_cabana FROM cabanas WHERE id = ?");  
        $stmt->execute([$cabana_id]);  
        $cabanaDatos = $stmt->fetch();  
        $cabana_nombre = $cabanaDatos['nombre_cabana'] ?? '';  
    } catch (Exception $e) {  
        // Silenciar error  
    }  
}  
  
// Obtener acampante a subir foto  
$acampante_id = $_GET['acampante_id'] ?? null;  
  
// Obtener semana activa
$stmt_sem = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
$semana_activa = $stmt_sem->fetch();
$semana_id_activa = $semana_activa['id'] ?? null;

if ($acampante_id && $cabana_id) {  
    try {
        if ($semana_id_activa) {
            $stmt = $pdo->prepare("SELECT a.*   
                                  FROM acampantes a   
                                  WHERE a.id = ?   
                                  AND a.cabana_id = ?   
                                  AND a.semana_id = ?
                                  AND a.estado = 'activo'");  
            $stmt->execute([$acampante_id, $cabana_id, $semana_id_activa]);
        } else {
            $stmt = $pdo->prepare("SELECT a.*   
                                  FROM acampantes a   
                                  WHERE a.id = ?   
                                  AND a.cabana_id = ?   
                                  AND a.year_campamento = ?  
                                  AND a.estado = 'activo'");  
            $stmt->execute([$acampante_id, $cabana_id, $year]);
        }
        $acampante = $stmt->fetch();  
          
        if (!$acampante) {  
            $error = "Acampante no encontrado o no pertenece a tu cabaña";  
        }  
    } catch (Exception $e) {  
        $error = "Error: " . $e->getMessage();  
    }  
}  
  
// Procesar subida de foto  
if ($_POST && isset($_FILES['foto'])) {  
    try {  
        // Obtener acampante_id del POST  
        $acampante_id_post = $_POST['acampante_id'] ?? $_GET['acampante_id'] ?? null;  
          
        if (!$acampante_id_post) {  
            throw new Exception("No se identificó el acampante");  
        }  
          
        if (!$cabana_id) {  
            throw new Exception("No tienes cabaña asignada");  
        }  
          
        $archivo = $_FILES['foto'];  
          
        if ($archivo['error'] !== UPLOAD_ERR_OK) {  
            throw new Exception("Error al subir archivo. Código: " . $archivo['error']);  
        }  
          
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));  
        $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'gif'];  
          
        if (!in_array($extension, $extensionesPermitidas)) {  
            throw new Exception("Tipo de archivo no permitido. Use JPG, PNG o GIF");  
        }  
          
        if ($archivo['size'] > 5 * 1024 * 1024) {  
            throw new Exception("La imagen es muy grande. Máximo 5MB");  
        }  
          
        // Crear directorio si no existe  
        $dirFotos = '../assets/uploads/fotos_acampantes/';  
        if (!file_exists($dirFotos)) {  
            mkdir($dirFotos, 0755, true);  
        }  
          
        // Eliminar foto anterior si existe  
        $stmt = $pdo->prepare("SELECT foto FROM acampantes WHERE id = ?");  
        $stmt->execute([$acampante_id_post]);  
        $acmpAnterior = $stmt->fetch();  
          
        if ($acmpAnterior && !empty($acmpAnterior['foto']) && file_exists('../' . $acmpAnterior['foto'])) {  
            unlink('../' . $acmpAnterior['foto']);  
        }  
          
        // Crear nombre único para la foto  
        $nombreFoto = 'acampante_' . $acampante_id_post . '_' . time() . '.' . $extension;  
        $rutaDestino = $dirFotos . $nombreFoto;  
        $rutaRelativa = 'assets/uploads/fotos_acampantes/' . $nombreFoto;  
          
        // Mover archivo  
        if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {  
            throw new Exception("Error al guardar la imagen en el servidor");  
        }  
          
        // Guardar en base de datos  
        $stmt = $pdo->prepare("UPDATE acampantes SET foto = ? WHERE id = ? AND cabana_id = ?");  
        $resultado = $stmt->execute([$rutaRelativa, $acampante_id_post, $cabana_id]);  
          
        if (!$resultado) {  
            throw new Exception("Error al actualizar la base de datos");  
        }  
          
        $message = "¡Foto subida exitosamente!";  
          
        // Recargar datos del acampante  
        $stmt = $pdo->prepare("SELECT a.* FROM acampantes a WHERE a.id = ?");  
        $stmt->execute([$acampante_id_post]);  
        $acampante = $stmt->fetch();  
          
        // Limpiar acampante_id de la URL para que no se repita  
        $acampante_id = null;  
          
    } catch (Exception $e) {  
        $error = "Error: " . $e->getMessage();  
    }  
}    
  
// Obtener lista de acampantes de la cabaña filtrados por semana activa
$acampantes = [];  
if ($cabana_id) {  
    try {
        if ($semana_id_activa) {
            $stmt = $pdo->prepare("SELECT a.id, a.nombre, a.foto, a.iglesia, a.contacto  
                                  FROM acampantes a   
                                  WHERE a.cabana_id = ?   
                                  AND a.semana_id = ?   
                                  AND a.estado = 'activo'  
                                  ORDER BY a.nombre");  
            $stmt->execute([$cabana_id, $semana_id_activa]);
        } else {
            $stmt = $pdo->prepare("SELECT a.id, a.nombre, a.foto, a.iglesia, a.contacto  
                                  FROM acampantes a   
                                  WHERE a.cabana_id = ?   
                                  AND a.year_campamento = ?   
                                  AND a.estado = 'activo'  
                                  ORDER BY a.nombre");  
            $stmt->execute([$cabana_id, $year]);
        }
        $acampantes = $stmt->fetchAll(PDO::FETCH_ASSOC);  
    } catch (Exception $e) {  
        $error = "Error al cargar acampantes: " . $e->getMessage();  
    }  
}
  
include '../includes/header.php';  
?>  
  
<div class="row mb-4">  
    <div class="col-12">  
        <h1><i class="fas fa-camera"></i> <?php echo $titulo; ?></h1>  
        <?php if ($cabana_nombre): ?>  
            <p class="text-muted">
                Cabaña: <strong><?php echo htmlspecialchars($cabana_nombre); ?></strong>
                <?php if ($semana_activa): ?>
                    | <span class="badge bg-success">
                        <i class="fas fa-broadcast-tower"></i>
                        <?php echo htmlspecialchars($semana_activa['nombre']); ?>
                      </span>
                <?php else: ?>
                    | <span class="badge bg-warning text-dark">
                        <i class="fas fa-exclamation-triangle"></i> Sin semana activa
                      </span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        
        <?php if (!$semana_activa): ?>
        <div class="alert alert-warning mb-3">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Sin semana activa.</strong> El administrador debe activar una semana para ver los acampantes.
        </div>
        <?php endif; ?> 
        <nav aria-label="breadcrumb">  
            <ol class="breadcrumb">  
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>  
                <li class="breadcrumb-item active">Subir Foto</li>  
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
  
<?php if (!$cabana_id): ?>  
    <!-- Error: sin cabaña -->  
    <div class="alert alert-danger">  
        <h5><i class="fas fa-exclamation-circle"></i> Acceso Denegado</h5>  
        <p>No tienes una cabaña asignada. Por favor contacta al administrador.</p>  
    </div>  
    <a href="dashboard.php" class="btn btn-secondary">Volver al Dashboard</a>  
  
<?php else: ?>  
    <div class="row">  
        <div class="col-md-4">  
            <!-- Selector de acampante -->  
            <div class="card">  
                <div class="card-header bg-primary text-white">  
                    <h5 class="mb-0"><i class="fas fa-list"></i> Mis Acampantes (<?php echo count($acampantes); ?>)</h5>  
                </div>  
                <div class="card-body" style="max-height: 600px; overflow-y: auto;">  
                    <?php if (empty($acampantes)): ?>  
                        <div class="alert alert-warning mb-0">  
                            <i class="fas fa-exclamation-triangle"></i>   
                            <small>No hay acampantes asignados</small>  
                        </div>  
                    <?php else: ?>  
                        <div class="list-group">  
                            <?php foreach ($acampantes as $acmp): ?>  
                            <a href="subir_foto.php?acampante_id=<?php echo $acmp['id']; ?>"  
                               class="list-group-item list-group-item-action <?php echo ($acampante && $acampante['id'] == $acmp['id']) ? 'active' : ''; ?>">  
                                <div class="d-flex justify-content-between align-items-start">  
                                    <div style="flex: 1;">  
                                        <h6 class="mb-1"><?php echo htmlspecialchars($acmp['nombre']); ?></h6>  
                                        <small class="text-muted d-block">  
                                            <i class="fas fa-church"></i> <?php echo htmlspecialchars($acmp['iglesia'] ?? 'N/A'); ?>  
                                        </small>  
                                        <?php if ($acmp['foto']): ?>  
                                            <small class="text-success d-block">  
                                                <i class="fas fa-check-circle"></i> Con foto  
                                            </small>  
                                        <?php else: ?>  
                                            <small class="text-muted d-block">  
                                                <i class="fas fa-camera-slash"></i> Sin foto  
                                            </small>  
                                        <?php endif; ?>  
                                    </div>  
                                    <?php if ($acmp['foto'] && file_exists('../' . $acmp['foto'])): ?>  
                                        <img src="<?php echo htmlspecialchars('../' . $acmp['foto']); ?>"   
                                             alt="<?php echo htmlspecialchars($acmp['nombre']); ?>"   
                                             class="rounded" style="width: 50px; height: 65px; object-fit: cover;">  
                                    <?php endif; ?>  
                                </div>  
                            </a>  
                            <?php endforeach; ?>  
                        </div>  
                    <?php endif; ?>  
                </div>  
            </div>  
        </div>  
          
        <div class="col-md-8">  
            <?php if ($acampante): ?>  
            <!-- Formulario de subida -->  
            <div class="card">  
                <div class="card-header bg-success text-white">  
                    <h5 class="mb-0">  
                        <i class="fas fa-camera"></i> Foto de <?php echo htmlspecialchars($acampante['nombre']); ?>  
                    </h5>  
                </div>  
                <div class="card-body">  
                    <div class="row">  
                        <div class="col-md-6">  
                            <!-- Vista previa actual -->  
                            <h6><i class="fas fa-image"></i> Foto Actual</h6>  
                            <?php if (!empty($acampante['foto']) && file_exists('../' . $acampante['foto'])): ?>  
                                <img src="<?php echo htmlspecialchars('../' . $acampante['foto']); ?>"   
                                     alt="<?php echo htmlspecialchars($acampante['nombre']); ?>"   
                                     class="img-thumbnail w-100" style="max-height: 400px; object-fit: cover;">  
                                <p class="text-success mt-2 small">  
                                    <i class="fas fa-check-circle"></i> Foto guardada  
                                </p>  
                            <?php else: ?>  
                                <div class="bg-light text-center p-5 rounded" style="min-height: 400px; display: flex; align-items: center; justify-content: center;">  
                                    <div>  
                                        <i class="fas fa-camera" style="font-size: 60px; color: #ccc;"></i>  
                                        <p class="text-muted mt-3">Sin foto aún</p>  
                                    </div>  
                                </div>  
                            <?php endif; ?>  
                        </div>  
                          
                        <div class="col-md-6">  
                            <!-- Formulario de subida -->  
                            <h6><i class="fas fa-upload"></i> Subir Nueva Foto</h6>  
                            <form method="POST" enctype="multipart/form-data">  
                                <input type="hidden" name="acampante_id" value="<?php echo $acampante['id']; ?>">
                                <div class="mb-3">  
                                    <label for="foto" class="form-label">  
                                        <strong>Selecciona una foto</strong>  
                                    </label>  
                                    <input type="file" class="form-control form-control-lg" id="foto" name="foto"   
                                           accept="image/jpeg, image/png, image/gif" required  
                                           onchange="previewFoto(this)">  
                                    <small class="form-text text-muted d-block mt-2">  
                                        📸 Formatos: JPG, PNG, GIF<br>  
                                        📦 Tamaño máximo: 5MB<br>  
                                        📐 Se ajustará automáticamente  
                                    </small>  
                                </div>  
                                  
                                <div id="preview" style="display: none;" class="mb-3">  
                                    <label class="form-label">Vista Previa</label>  
                                    <img id="previewImg" src="" alt="Vista previa" class="img-thumbnail w-100" style="max-height: 250px; object-fit: cover;">  
                                </div>  
                                  
                                <div class="alert alert-info small">  
                                    <h6><i class="fas fa-info-circle"></i> Recomendaciones</h6>  
                                    <ul class="mb-0">  
                                        <li>Usa una foto clara y bien iluminada</li>  
                                        <li>El rostro debe ser visible</li>  
                                        <li>Evita fotos pixeladas o borrosas</li>  
                                    </ul>  
                                </div>  
                                  
                                <div class="d-flex gap-2">  
                                    <a href="subir_foto.php" class="btn btn-secondary">  
                                        <i class="fas fa-times"></i> Cancelar  
                                    </a>  
                                    <button type="submit" class="btn btn-success flex-grow-1">  
                                        <i class="fas fa-upload"></i> Subir Foto  
                                    </button>  
                                </div>  
                            </form>  
                        </div>  
                    </div>  
                </div>  
            </div>  
              
            <?php else: ?>  
            <!-- Sin acampante seleccionado -->  
            <div class="card">  
                <div class="card-body text-center py-5">  
                    <i class="fas fa-camera-slash" style="font-size: 60px; color: #ccc;"></i>  
                    <h5 class="mt-3 text-muted">Selecciona un acampante</h5>  
                    <p class="text-muted">Haz clic en un acampante de la lista para subir su foto</p>  
                </div>  
            </div>  
            <?php endif; ?>  
        </div>  
    </div>  
<?php endif; ?>  
  
<script>  
function previewFoto(input) {  
    const preview = document.getElementById('preview');  
    const previewImg = document.getElementById('previewImg');  
      
    if (input.files && input.files[0]) {  
        const reader = new FileReader();  
          
        reader.onload = function(e) {  
            previewImg.src = e.target.result;  
            preview.style.display = 'block';  
        };  
          
        reader.readAsDataURL(input.files[0]);  
    }  
}  
</script>  
  
<?php include '../includes/footer.php'; ?>  