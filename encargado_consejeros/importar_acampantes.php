<?php  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
if (!esEncargadoConsejeros()) {
    header('Location: ../consejero/dashboard.php');  
    exit();  
}  
  
$titulo = "Importar Acampantes";  
$message = '';  
$error = '';  
$preview_data = null;  
  
// Verificar si hay librería Excel  
$excelDisponible = false;  
$libPath = realpath(dirname(__FILE__) . '/../libs/SimpleXLSX.php');  
  
if (file_exists($libPath)) {  
    require_once $libPath;  
    // SimpleXLSX está bajo namespace Shuchkin  
    $excelDisponible = class_exists('Shuchkin\SimpleXLSX');  
}   
  
// FUNCIONES  
function leerCSV($archivoPath) {  
    $datos = [];  
      
    if (!file_exists($archivoPath)) {  
        throw new Exception("Archivo no encontrado");  
    }  
      
    $handle = fopen($archivoPath, "r");  
    if ($handle === FALSE) {  
        throw new Exception("No se puede abrir el archivo");  
    }  
      
    // Leer encabezados  
    $headers = fgetcsv($handle, 10000, ",");  
    if (!$headers) {  
        fclose($handle);  
        throw new Exception("Archivo vacío o sin encabezados");  
    }  
      
    // Limpiar encabezados (quitar BOM y espacios)  
    $headers = array_map(function($h) {  
        return trim(str_replace("\xEF\xBB\xBF", '', $h));  
    }, $headers);  
      
    // Leer filas  
    $filaNum = 1;  
    while (($row = fgetcsv($handle, 10000, ",")) !== FALSE) {  
        $filaNum++;  
          
        // Saltar filas vacías  
        if (empty(array_filter($row))) {  
            continue;  
        }  
          
        if (count($row) !== count($headers)) {  
            continue; // Saltar filas con diferente número de columnas  
        }  
          
        $datos[] = array_combine($headers, $row);  
    }  
      
    fclose($handle);  
    return $datos;  
}  
  
function leerExcel($archivoPath) {  
    if (!class_exists('Shuchkin\SimpleXLSX')) {  
        throw new Exception("Librería SimpleXLSX no disponible");  
    }
    
    // Verificar que el archivo existe y es legible
    if (!file_exists($archivoPath)) {
        throw new Exception("Archivo Excel no encontrado");
    }
    
    if (!is_readable($archivoPath)) {
        throw new Exception("No se puede leer el archivo Excel");
    }
    
    // Verificar tamaño mínimo (un Excel vacío tiene al menos 4KB)
    $fileSize = filesize($archivoPath);
    if ($fileSize < 1024) {
        throw new Exception("Archivo Excel demasiado pequeño o corrupto");
    }
    
    // Verificar firma del archivo (PK para ZIP)
    $handle = fopen($archivoPath, 'rb');
    $signature = fread($handle, 4);
    fclose($handle);
    
    if (substr($signature, 0, 2) !== 'PK') {
        throw new Exception("El archivo no es un Excel válido (.xlsx). Use formato .xlsx o CSV");
    }
    
    $xlsx = \Shuchkin\SimpleXLSX::parse($archivoPath);
    
    if ($xlsx === false) {    
        throw new Exception("Error al leer Excel: " . \Shuchkin\SimpleXLSX::parseError());  
    }  
      
    $rows = $xlsx->rows();  
    if (empty($rows)) {  
        $parseError = \Shuchkin\SimpleXLSX::parseError();
        throw new Exception("Error al leer Excel: " . ($parseError ?: "Formato inválido"));  
    }  
      
    $headers = array_shift($rows); 
    
    // Validar que hay encabezados
    if (empty($headers) || empty(array_filter($headers))) {
        throw new Exception("El archivo no tiene encabezados válidos");
    }
    
    $datos = [];  
      
    foreach ($rows as $row) {  
        if (empty(array_filter($row))) {  
            continue;  
        }  
          
        if (count($row) === count($headers)) {  
            $datos[] = array_combine($headers, $row);  
        }  
    }  
      
    return $datos;  
}  
  
// PROCESAMIENTO  
if ($_SERVER['REQUEST_METHOD'] === 'POST') {  
      
    // PASO 1: PREVIEW  
    if (isset($_POST['accion']) && $_POST['accion'] === 'preview' && isset($_FILES['archivo'])) {  
        try {  
            $archivo = $_FILES['archivo'];  
              
            // Validar errores de carga
            if ($archivo['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = match($archivo['error']) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "Archivo demasiado grande",
                    UPLOAD_ERR_PARTIAL => "El archivo se cargó parcialmente",
                    UPLOAD_ERR_NO_FILE => "No se seleccionó ningún archivo",
                    default => "Error al subir archivo (código: {$archivo['error']})"
                };
                throw new Exception($errorMsg);  
            }  
              
            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));  
              
            // Validar extensión  
            if (!in_array($extension, ['csv', 'xlsx', 'xls'])) {  
                throw new Exception("Solo archivos CSV o Excel permitidos");  
            }  
              
            // Para .xls (Excel antiguo), solicitar .xlsx
            if ($extension === 'xls') {
                throw new Exception("Por favor, guarde el archivo como .xlsx (Excel moderno) en lugar de .xls");
            }
            
            if ($extension === 'xlsx' && !$excelDisponible) {  
                throw new Exception("Librería Excel no instalada. Use formato CSV");  
            }  
              
            // Leer archivo  
            if ($extension === 'csv') {  
                $datos = leerCSV($archivo['tmp_name']);  
            } else {  
                $datos = leerExcel($archivo['tmp_name']);  
            }  
              
            if (empty($datos)) {  
                throw new Exception("El archivo no contiene datos validos");  
            }  
              
            // Guardar datos en sesión para el siguiente paso  
            $_SESSION['datos_importacion'] = $datos;  
            $preview_data = $datos;  
              
        } catch (Exception $e) {  
            $error = $e->getMessage();  
        }  
    }  
      
    // PASO 2: IMPORTAR  
    else if (isset($_POST['accion']) && $_POST['accion'] === 'importar') {  
        try {  
            if (!isset($_SESSION['datos_importacion']) || empty($_SESSION['datos_importacion'])) {  
                throw new Exception("No hay datos para importar. Sube el archivo nuevamente");  
            }  
              
            $datos = $_SESSION['datos_importacion'];  
            $year = obtenerAnioCampamento();  
              
            // Obtener cabañas  
            $stmt = $pdo->query("SELECT id, nombre_cabana FROM cabanas WHERE activa = 1");  
            $cabanas = $stmt->fetchAll(PDO::FETCH_ASSOC);  
              
            $exitosos = 0;  
            $errores = 0;  
            $detalles_errores = [];  
              
            foreach ($datos as $index => $fila) {  
                try {  
                    // Obtener valores (intentar diferentes variantes de nombres)  
                    $nombre = trim($fila['Nombre'] ?? $fila['nombre'] ?? $fila['NOMBRE'] ?? '');  
                    $iglesia = trim($fila['Iglesia'] ?? $fila['iglesia'] ?? $fila['IGLESIA'] ?? '');  
                    $contacto = trim($fila['Contacto'] ?? $fila['contacto'] ?? $fila['CONTACTO'] ?? '');  
                    $cabana_nombre = trim($fila['Cabaña'] ?? $fila['Cabana'] ?? $fila['cabana'] ?? $fila['CABAÑA'] ?? '');  
                      
                    // Validar campos requeridos  
                    if (empty($nombre)) {  
                        throw new Exception("Nombre vacío");  
                    }  
                      
                    if (empty($iglesia)) {  
                        throw new Exception("Iglesia vacía");  
                    }  
                      
                    // Buscar cabaña  
                    $cabana_id = null;  
                    if (!empty($cabana_nombre)) {  
                        foreach ($cabanas as $cab) {  
                            if (strcasecmp($cab['nombre_cabana'], $cabana_nombre) === 0) {  
                                $cabana_id = $cab['id'];  
                                break;  
                            }  
                        }  
                    }  
                      
                    // INSERTAR en base de datos  
                    $sql = "INSERT INTO acampantes (nombre, iglesia, contacto, cabana_id, year_campamento, estado)   
                            VALUES (:nombre, :iglesia, :contacto, :cabana_id, :year, 'activo')";  
                      
                    $stmt = $pdo->prepare($sql);  
                    $resultado = $stmt->execute([  
                        ':nombre' => $nombre,  
                        ':iglesia' => $iglesia,  
                        ':contacto' => $contacto,  
                        ':cabana_id' => $cabana_id,  
                        ':year' => $year  
                    ]);  
                      
                    if ($resultado) {  
                        $exitosos++;  
                    } else {  
                        throw new Exception("Error al insertar");  
                    }  
                      
                } catch (Exception $e) {  
                    $errores++;  
                    $detalles_errores[] = "Fila " . ($index + 2) . ": " . $e->getMessage();  
                }  
            }  
              
            // Limpiar sesión  
            unset($_SESSION['datos_importacion']);  
              
            if ($exitosos > 0) {  
                $message = "✓ Importación exitosa: $exitosos acampantes agregados";  
                if ($errores > 0) {  
                    $message .= " ($errores errores)";  
                }  
            } else {  
                throw new Exception("No se pudo importar ningún acampante. Errores: " . implode(", ", $detalles_errores));  
            }  
              
        } catch (Exception $e) {  
            $error = $e->getMessage();  
        }  
    }  
}  
  
include '../includes/header.php';  
?>  
  
<div class="row mb-4">  
    <div class="col-12">  
        <h1><i class="fas fa-file-upload"></i> <?php echo $titulo; ?></h1>  
        <nav aria-label="breadcrumb">  
            <ol class="breadcrumb">  
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>  
                <li class="breadcrumb-item"><a href="acampantes.php">Acampantes</a></li>  
                <li class="breadcrumb-item active">Importar</li>  
            </ol>  
        </nav>  
    </div>  
</div>  
  
<?php if ($message): ?>  
    <div class="alert alert-success">  
        <i class="fas fa-check-circle"></i> <strong><?php echo $message; ?></strong>  
        <div class="mt-3">  
            <a href="acampantes.php" class="btn btn-success">Ver Acampantes</a>  
            <a href="importar_acampantes.php" class="btn btn-outline-success">Importar Más</a>  
        </div>  
    </div>  
<?php endif; ?>  
  
<?php if ($error): ?>  
    <div class="alert alert-danger">  
        <i class="fas fa-exclamation-triangle"></i> <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>  
    </div>  
<?php endif; ?>  
  
<?php if ($preview_data === null): ?>  
<!-- FORMULARIO -->  
<div class="row">  
    <div class="col-md-8">  
        <div class="card">  
            <div class="card-header bg-primary text-white">  
                <h5 class="mb-0">Subir Archivo</h5>  
            </div>  
            <div class="card-body">  
                <form method="POST" enctype="multipart/form-data">  
                    <input type="hidden" name="accion" value="preview">  
                      
                    <div class="mb-3">  
                        <label class="form-label"><strong>Archivo CSV o Excel</strong></label>  
                        <input type="file" class="form-control" name="archivo" accept=".csv,.xlsx,.xls" required>  
                        <small class="text-muted">Formatos: CSV, Excel (.xlsx)</small>  
                    </div>  
                      
                    <div class="alert alert-info">  
                        <strong>Formato requerido:</strong>  
                        <ul class="mb-0">  
                            <li><strong>Nombre</strong> - Obligatorio</li>  
                            <li><strong>Iglesia</strong> - Obligatorio</li>  
                            <li><strong>Contacto</strong> - Opcional</li>  
                            <li><strong>Cabaña</strong> - Opcional</li>  
                        </ul>  
                    </div>  
                      
                    <div class="d-flex justify-content-between">  
                        <a href="acampantes.php" class="btn btn-secondary">Cancelar</a>  
                        <button type="submit" class="btn btn-primary">  
                            <i class="fas fa-search"></i> Previsualizar  
                        </button>  
                    </div>  
                </form>  
            </div>  
        </div>  
    </div>  
      
    <div class="col-md-4">  
        <div class="card">  
            <div class="card-header">  
                <h6>Descargar Plantilla</h6>  
            </div>  
            <div class="card-body">  
                <a href="descargar_plantilla.php?tipo=csv" class="btn btn-outline-primary btn-sm w-100 mb-2">  
                    <i class="fas fa-download"></i> Plantilla CSV  
                </a>  
                <a href="descargar_plantilla.php?tipo=excel" class="btn btn-outline-success btn-sm w-100">  
                    <i class="fas fa-download"></i> Plantilla Excel  
                </a>  
            </div>  
        </div>  
    </div>  
</div>  
  
<?php else: ?>  
<!-- PREVIEW -->  
<div class="card">  
    <div class="card-header bg-success text-white">  
        <h5>Vista Previa - <?php echo count($preview_data); ?> registros</h5>  
    </div>  
    <div class="card-body">  
        <div class="table-responsive" style="max-height: 400px;">  
            <table class="table table-sm table-bordered">  
                <thead class="table-dark">  
                    <tr>  
                        <th>#</th>  
                        <th>Nombre</th>  
                        <th>Iglesia</th>  
                        <th>Contacto</th>  
                        <th>Cabaña</th>  
                    </tr>  
                </thead>  
                <tbody>  
                    <?php foreach ($preview_data as $index => $fila): ?>  
                    <tr>  
                        <td><?php echo $index + 1; ?></td>  
                        <td><?php echo htmlspecialchars($fila['Nombre'] ?? $fila['nombre'] ?? ''); ?></td>  
                        <td><?php echo htmlspecialchars($fila['Iglesia'] ?? $fila['iglesia'] ?? ''); ?></td>  
                        <td><?php echo htmlspecialchars($fila['Contacto'] ?? $fila['contacto'] ?? ''); ?></td>  
                        <td><?php echo htmlspecialchars($fila['Cabaña'] ?? $fila['Cabana'] ?? $fila['cabana'] ?? ''); ?></td>  
                    </tr>  
                    <?php endforeach; ?>  
                </tbody>  
            </table>  
        </div>  
          
        <form method="POST">  
            <input type="hidden" name="accion" value="importar">  
            <div class="d-flex justify-content-between mt-3">  
                <a href="importar_acampantes.php" class="btn btn-secondary">Cancelar</a>  
                <button type="submit" class="btn btn-success" onclick="return confirm('¿Confirmas importar <?php echo count($preview_data); ?> acampantes?')">  
                    <i class="fas fa-check"></i> Confirmar Importación  
                </button>  
            </div>  
        </form>  
    </div>  
</div>  
<?php endif; ?>  
  
<?php include '../includes/footer.php'; ?>  