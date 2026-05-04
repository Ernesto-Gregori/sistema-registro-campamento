<?php  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
if (!esEncargadoConsejeros()) {
    header('Location: ../consejero/dashboard.php');  
    exit();  
}  
  
$titulo = "Gestión de Recursos";  
$action = $_GET['action'] ?? 'list';  
$id = $_GET['id'] ?? null;  
$message = '';  
$error = '';  
$year = $_GET['year'] ?? obtenerAnioCampamento();  
  
// Procesamiento de formularios  
if ($_POST) {  
    try {  
        if ($action === 'add' || $action === 'edit') {  
            $titulo_recurso = limpiarDatos($_POST['titulo']);  
            $tipo = $_POST['tipo'];  
            $formato = $_POST['formato'];  
            $descripcion = limpiarDatos($_POST['descripcion']);  
            $version = limpiarDatos($_POST['version']) ?: '1.0';  
            $year_campamento = (int)$_POST['year_campamento'];  
              
            // Validaciones  
            if (empty($titulo_recurso) || empty($tipo) || empty($formato)) {  
                throw new Exception("Todos los campos obligatorios deben completarse");  
            }  
              
            $ruta_archivo = null;  
              
            // Manejar subida de archivo  
            if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {  
                $uploadDir = '../assets/uploads/recursos/';  
                  
                // Crear directorio si no existe  
                if (!file_exists($uploadDir)) {  
                    mkdir($uploadDir, 0755, true);  
                }  
                  
                $extension = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));  
                $nombreArchivo = sanitizarNombreArchivo($titulo_recurso) . '_' . $year_campamento . '_' . time() . '.' . $extension;  
                $rutaCompleta = $uploadDir . $nombreArchivo;  
                  
                // Validar tipo de archivo según formato  
                $tiposPermitidos = [  
                    'pdf' => ['pdf'],  
                    'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv'],  
                    'texto' => ['txt', 'doc', 'docx', 'rtf']  
                ];  
                  
                if (!in_array($extension, $tiposPermitidos[$formato])) {  
                    throw new Exception("Tipo de archivo no válido para el formato seleccionado");  
                }  
                  
                // Validar tamaño (50MB máximo)  
                if ($_FILES['archivo']['size'] > 50 * 1024 * 1024) {  
                    throw new Exception("El archivo es demasiado grande. Máximo 50MB");  
                }  
                  
                if (move_uploaded_file($_FILES['archivo']['tmp_name'], $rutaCompleta)) {  
                    $ruta_archivo = 'assets/uploads/recursos/' . $nombreArchivo;  
                } else {  
                    throw new Exception("Error al subir el archivo");  
                }  
            }  
              
            if ($action === 'add') {  
                if (!$ruta_archivo && $formato !== 'texto') {  
                    throw new Exception("Debe subir un archivo para este tipo de recurso");  
                }  
                  
                $stmt = $pdo->prepare("INSERT INTO recursos (titulo, tipo, formato, ruta_archivo, descripcion, version, year_campamento, subido_por)   
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)");  
                $stmt->execute([$titulo_recurso, $tipo, $formato, $ruta_archivo, $descripcion, $version, $year_campamento, $_SESSION['user_id']]);  
                $message = "Recurso agregado exitosamente";  
            } else {  
                // Actualizar  
                if ($ruta_archivo) {  
                    // Si hay nuevo archivo, eliminar el anterior  
                    $stmt = $pdo->prepare("SELECT ruta_archivo FROM recursos WHERE id = ?");  
                    $stmt->execute([$id]);  
                    $recursoAnterior = $stmt->fetch();  
                    if ($recursoAnterior && file_exists('../' . $recursoAnterior['ruta_archivo'])) {  
                        unlink('../' . $recursoAnterior['ruta_archivo']);  
                    }  
                      
                    $stmt = $pdo->prepare("UPDATE recursos SET titulo=?, tipo=?, formato=?, ruta_archivo=?, descripcion=?, version=?, year_campamento=? WHERE id=?");  
                    $stmt->execute([$titulo_recurso, $tipo, $formato, $ruta_archivo, $descripcion, $version, $year_campamento, $id]);  
                } else {  
                    $stmt = $pdo->prepare("UPDATE recursos SET titulo=?, tipo=?, formato=?, descripcion=?, version=?, year_campamento=? WHERE id=?");  
                    $stmt->execute([$titulo_recurso, $tipo, $formato, $descripcion, $version, $year_campamento, $id]);  
                }  
                $message = "Recurso actualizado exitosamente";  
            }  
              
            header("Location: recursos.php?message=" . urlencode($message));  
            exit();  
        }  
    } catch (Exception $e) {  
        $error = "Error: " . $e->getMessage();  
    }  
}  
  
// Clonar recurso de año anterior al año actual
if ($action === 'clonar' && $id) {
    try {
        $year_actual = obtenerAnioCampamento();
        $stmt = $pdo->prepare("SELECT * FROM recursos WHERE id = ?");
        $stmt->execute([$id]);
        $rec_origen = $stmt->fetch();

        if (!$rec_origen) throw new Exception("Recurso no encontrado");

        // Insertar copia con el año actual
        $stmt = $pdo->prepare("INSERT INTO recursos
                               (titulo, tipo, formato, ruta_archivo, descripcion, version,
                                year_campamento, subido_por, activo)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            $rec_origen['titulo'],
            $rec_origen['tipo'],
            $rec_origen['formato'],
            $rec_origen['ruta_archivo'],  // Comparte el mismo archivo físico
            $rec_origen['descripcion'],
            $rec_origen['version'],
            $year_actual,
            $_SESSION['user_id']
        ]);

        header("Location: recursos.php?message=" . urlencode(
            "Recurso «{$rec_origen['titulo']}» activado para {$year_actual}"
        ));
        exit();
    } catch (Exception $e) {
        $error = "Error al clonar: " . $e->getMessage();
    }
}

// Cambiar estado del recurso  
if ($action === 'toggle' && $id) {
    try {  
        $stmt = $pdo->prepare("UPDATE recursos SET activo = NOT activo WHERE id = ?");  
        $stmt->execute([$id]);  
        header("Location: recursos.php?message=" . urlencode("Estado del recurso actualizado"));  
        exit();  
    } catch (Exception $e) {  
        $error = "Error al cambiar estado: " . $e->getMessage();  
    }  
}  
  
// Eliminar recurso  
if ($action === 'delete' && $id) {  
    try {  
        // Obtener info del archivo para eliminarlo  
        $stmt = $pdo->prepare("SELECT ruta_archivo FROM recursos WHERE id = ?");  
        $stmt->execute([$id]);  
        $recurso = $stmt->fetch();  
          
        // Eliminar archivo físico  
        if ($recurso && $recurso['ruta_archivo'] && file_exists('../' . $recurso['ruta_archivo'])) {  
            unlink('../' . $recurso['ruta_archivo']);  
        }  
          
        // Eliminar registro  
        $stmt = $pdo->prepare("DELETE FROM recursos WHERE id = ?");  
        $stmt->execute([$id]);  
          
        header("Location: recursos.php?message=" . urlencode("Recurso eliminado exitosamente"));  
        exit();  
    } catch (Exception $e) {  
        $error = "Error al eliminar: " . $e->getMessage();  
    }  
}  
  
// Obtener datos para editar  
$recurso = null;  
if ($action === 'edit' && $id) {  
    $stmt = $pdo->prepare("SELECT * FROM recursos WHERE id = ?");  
    $stmt->execute([$id]);  
    $recurso = $stmt->fetch();  
}  
  
// Obtener lista de recursos con filtros  
if ($action === 'list') {  
    $tipo_filter    = $_GET['tipo']    ?? '';  
    $formato_filter = $_GET['formato'] ?? '';  
    $search         = $_GET['search']  ?? '';
    $year_actual    = obtenerAnioCampamento();

    // ── Recursos del año seleccionado ─────────────────────────
    $sql = "SELECT r.*, u.username as subido_por_nombre   
            FROM recursos r   
            LEFT JOIN usuarios u ON r.subido_por = u.id   
            WHERE r.year_campamento = ?";  
    $params = [$year];  
      
    if ($tipo_filter) {  
        $sql .= " AND r.tipo = ?";  
        $params[] = $tipo_filter;  
    }  
    if ($formato_filter) {  
        $sql .= " AND r.formato = ?";  
        $params[] = $formato_filter;  
    }  
    if ($search) {  
        $sql .= " AND (r.titulo LIKE ? OR r.descripcion LIKE ?)";  
        $params[] = "%$search%";  
        $params[] = "%$search%";  
    }  
    $sql .= " ORDER BY r.fecha_subida DESC";  
    $stmt = $pdo->prepare($sql);  
    $stmt->execute($params);  
    $recursos = $stmt->fetchAll();

    // ── Recursos de años ANTERIORES (solo si viendo el año actual) ─
    $recursos_anteriores = [];
    if ($year == $year_actual) {
        $sql_ant = "SELECT r.*, u.username as subido_por_nombre
                    FROM recursos r
                    LEFT JOIN usuarios u ON r.subido_por = u.id
                    WHERE r.year_campamento < ?";
        $params_ant = [$year_actual];

        if ($tipo_filter) {
            $sql_ant .= " AND r.tipo = ?";
            $params_ant[] = $tipo_filter;
        }
        if ($formato_filter) {
            $sql_ant .= " AND r.formato = ?";
            $params_ant[] = $formato_filter;
        }
        if ($search) {
            $sql_ant .= " AND (r.titulo LIKE ? OR r.descripcion LIKE ?)";
            $params_ant[] = "%$search%";
            $params_ant[] = "%$search%";
        }
        $sql_ant .= " ORDER BY r.year_campamento DESC, r.fecha_subida DESC";
        $stmt_ant = $pdo->prepare($sql_ant);
        $stmt_ant->execute($params_ant);
        $recursos_anteriores = $stmt_ant->fetchAll();
    }
}
  
// Obtener años disponibles  
$stmt = $pdo->query("SELECT DISTINCT year_campamento FROM recursos ORDER BY year_campamento DESC");  
$yearsDisponibles = $stmt->fetchAll();  
  
// Mensajes de URL  
if (isset($_GET['message'])) {  
    $message = $_GET['message'];  
}  
  
function sanitizarNombreArchivo($nombre) {  
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $nombre);  
}  
  
include '../includes/header.php';  
?>  
  
<div class="row mb-4">  
    <div class="col-12">  
        <h1><i class="fas fa-folder-open"></i> <?php echo $titulo; ?></h1>  
        <nav aria-label="breadcrumb">  
            <ol class="breadcrumb">  
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>  
                <li class="breadcrumb-item active">Recursos</li>  
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
<!-- Lista de recursos -->  
<div class="card">  
    <div class="card-header d-flex justify-content-between align-items-center">  
        <h5><i class="fas fa-list"></i> Recursos del Campamento <?php echo $year; ?> (<?php echo count($recursos); ?>)</h5>  
        <a href="recursos.php?action=add" class="btn btn-success">  
            <i class="fas fa-plus"></i> Nuevo Recurso  
        </a>  
    </div>  
    <div class="card-body">  
        <!-- Filtros -->  
        <form method="GET" class="row mb-4">  
            <input type="hidden" name="action" value="list">  
            <div class="col-md-2">  
                <select name="year" class="form-select" onchange="this.form.submit()">  
                    <?php foreach ($yearsDisponibles as $yearItem): ?>  
                    <option value="<?php echo $yearItem['year_campamento']; ?>"  
                            <?php echo $year == $yearItem['year_campamento'] ? 'selected' : ''; ?>>  
                        <?php echo $yearItem['year_campamento']; ?>  
                    </option>  
                    <?php endforeach; ?>  
                </select>  
            </div>  
            <div class="col-md-2">  
                <select name="tipo" class="form-select">  
                    <option value="">Todos los tipos</option>  
                    <option value="devocional" <?php echo $tipo_filter == 'devocional' ? 'selected' : ''; ?>>Devocional</option>  
                    <option value="hora_silenciosa" <?php echo $tipo_filter == 'hora_silenciosa' ? 'selected' : ''; ?>>Hora Silenciosa</option>  
                    <option value="apoyo_consejeria" <?php echo $tipo_filter == 'apoyo_consejeria' ? 'selected' : ''; ?>>Apoyo Consejería</option>  
                </select>  
            </div>  
            <div class="col-md-2">  
                <select name="formato" class="form-select">  
                    <option value="">Todos los formatos</option>  
                    <option value="pdf" <?php echo $formato_filter == 'pdf' ? 'selected' : ''; ?>>PDF</option>  
                    <option value="video" <?php echo $formato_filter == 'video' ? 'selected' : ''; ?>>Video</option>  
                    <option value="texto" <?php echo $formato_filter == 'texto' ? 'selected' : ''; ?>>Texto</option>  
                </select>  
            </div>  
            <div class="col-md-3">  
                <input type="text" class="form-control" name="search"   
                       placeholder="Buscar recursos..."   
                       value="<?php echo htmlspecialchars($search ?? ''); ?>">  
            </div>  
            <div class="col-md-2">  
                <button type="submit" class="btn btn-primary w-100">  
                    <i class="fas fa-search"></i> Filtrar  
                </button>  
            </div>  
            <div class="col-md-1">  
                <a href="recursos.php" class="btn btn-secondary w-100" title="Limpiar filtros">  
                    <i class="fas fa-refresh"></i>  
                </a>  
            </div>  
        </form>  
  
        <!-- Tabla de recursos -->  
        <div class="table-responsive">  
            <table class="table table-hover">  
                <thead class="table-dark">  
                    <tr>  
                        <th>Título</th>  
                        <th>Tipo</th>  
                        <th>Formato</th>  
                        <th>Versión</th>  
                        <th>Subido por</th>  
                        <th>Fecha</th>  
                        <th>Estado</th>  
                        <th>Acciones</th>  
                    </tr>  
                </thead>  
                <tbody>  
                    <?php if (empty($recursos)): ?>  
                    <tr>  
                        <td colspan="8" class="text-center text-muted py-4">  
                            <i class="fas fa-folder-open fa-3x mb-3"></i><br>  
                            No se encontraron recursos  
                        </td>  
                    </tr>  
                    <?php else: ?>  
                    <?php foreach ($recursos as $rec): ?>  
                    <tr>  
                        <td>  
                            <strong><?php echo htmlspecialchars($rec['titulo']); ?></strong>  
                            <?php if ($rec['descripcion']): ?>  
                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($rec['descripcion'], 0, 100)); ?>...</small>  
                            <?php endif; ?>  
                        </td>  
                        <td>  
                            <span class="badge <?php   
                                echo $rec['tipo'] == 'devocional' ? 'bg-primary' :   
                                    ($rec['tipo'] == 'hora_silenciosa' ? 'bg-info' : 'bg-success');   
                            ?>">  
                                <?php echo ucfirst(str_replace('_', ' ', $rec['tipo'])); ?>  
                            </span>  
                        </td>  
                        <td>  
                            <i class="fas fa-<?php   
                                echo $rec['formato'] == 'pdf' ? 'file-pdf' :   
                                    ($rec['formato'] == 'video' ? 'video' : 'file-alt');   
                            ?>"></i>  
                            <?php echo strtoupper($rec['formato']); ?>  
                        </td>  
                        <td><?php echo htmlspecialchars($rec['version']); ?></td>  
                        <td><?php echo htmlspecialchars($rec['subido_por_nombre'] ?? 'Encargado de consejeros'); ?></td>  
                        <td><?php echo date('d/m/Y', strtotime($rec['fecha_subida'])); ?></td>  
                        <td>  
                            <span class="badge <?php echo $rec['activo'] ? 'bg-success' : 'bg-secondary'; ?>">  
                                <?php echo $rec['activo'] ? 'Activo' : 'Inactivo'; ?>  
                            </span>  
                        </td>  
                        <td>  
                            <div class="btn-group" role="group">  
                                <?php if ($rec['ruta_archivo']): ?>  
                                <a href="../<?php echo $rec['ruta_archivo']; ?>"   
                                   class="btn btn-sm btn-outline-info" target="_blank" title="Ver archivo">  
                                    <i class="fas fa-eye"></i>  
                                </a>  
                                <?php endif; ?>  
                                <a href="recursos.php?action=edit&id=<?php echo $rec['id']; ?>"   
                                   class="btn btn-sm btn-outline-primary" title="Editar">  
                                    <i class="fas fa-edit"></i>  
                                </a>  
                                <a href="recursos.php?action=toggle&id=<?php echo $rec['id']; ?>"   
                                   class="btn btn-sm btn-outline-<?php echo $rec['activo'] ? 'warning' : 'success'; ?>"   
                                   title="<?php echo $rec['activo'] ? 'Desactivar' : 'Activar'; ?>">  
                                    <i class="fas fa-<?php echo $rec['activo'] ? 'pause' : 'play'; ?>"></i>  
                                </a>  
                                <a href="recursos.php?action=delete&id=<?php echo $rec['id']; ?>"   
                                   class="btn btn-sm btn-outline-danger" title="Eliminar"  
                                   onclick="return confirmarEliminacion('¿Eliminar este recurso y su archivo?')">  
                                    <i class="fas fa-trash"></i>  
                                </a>  
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
  
        <!-- ══ RECURSOS DE AÑOS ANTERIORES ══ -->
        <?php if (!empty($recursos_anteriores)): ?>
        <div class="mt-5">
            <div class="d-flex align-items-center gap-3 mb-3">
                <h5 class="mb-0 text-muted">
                    <i class="fas fa-history"></i> Recursos de Años Anteriores
                    <span class="badge bg-secondary ms-1"><?php echo count($recursos_anteriores); ?></span>
                </h5>
                <span class="text-muted small">
                    — Puedes activarlos para <?php echo $year_actual; ?> o eliminarlos
                </span>
            </div>

            <!-- Agrupar por año -->
            <?php
            $por_year = [];
            foreach ($recursos_anteriores as $rec) {
                $por_year[$rec['year_campamento']][] = $rec;
            }
            foreach ($por_year as $anio => $lista):
            ?>
            <div class="card border-secondary mb-3">
                <div class="card-header bg-secondary bg-opacity-10
                            d-flex justify-content-between align-items-center py-2">
                    <h6 class="mb-0 text-secondary">
                        <i class="fas fa-calendar-alt"></i> Año <?php echo $anio; ?>
                        <span class="badge bg-secondary ms-1"><?php echo count($lista); ?></span>
                    </h6>
                    <small class="text-muted">
                        <?php echo count(array_filter($lista, fn($r) => $r['activo'])); ?> activo(s)
                    </small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Título</th>
                                    <th>Tipo</th>
                                    <th>Formato</th>
                                    <th>Versión</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lista as $rec): ?>
                                <tr class="<?php echo !$rec['activo'] ? 'text-muted' : ''; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($rec['titulo']); ?></strong>
                                        <?php if ($rec['descripcion']): ?>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars(substr($rec['descripcion'], 0, 80)); ?>...
                                        </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php
                                            echo $rec['tipo'] == 'devocional'      ? 'bg-primary' :
                                                ($rec['tipo'] == 'hora_silenciosa' ? 'bg-info'    : 'bg-success');
                                        ?>" style="font-size:10px;">
                                            <?php echo ucfirst(str_replace('_', ' ', $rec['tipo'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <i class="fas fa-<?php
                                            echo $rec['formato'] == 'pdf'   ? 'file-pdf' :
                                                ($rec['formato'] == 'video' ? 'video'    : 'file-alt');
                                        ?> text-muted"></i>
                                        <small><?php echo strtoupper($rec['formato']); ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted">v<?php echo htmlspecialchars($rec['version']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $rec['activo'] ? 'bg-success' : 'bg-secondary'; ?>"
                                              style="font-size:10px;">
                                            <?php echo $rec['activo'] ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 flex-wrap">
                                            <!-- Ver archivo -->
                                            <?php if ($rec['ruta_archivo'] && file_exists('../' . $rec['ruta_archivo'])): ?>
                                            <a href="../<?php echo $rec['ruta_archivo']; ?>"
                                               class="btn btn-sm btn-outline-info" target="_blank"
                                               title="Ver archivo">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php endif; ?>

                                            <!-- Activar para año actual -->
                                            <a href="recursos.php?action=clonar&id=<?php echo $rec['id']; ?>"
                                               class="btn btn-sm btn-success"
                                               title="Usar en <?php echo $year_actual; ?>"
                                               onclick="return confirm('¿Activar este recurso para el año <?php echo $year_actual; ?>?\nSe creará una copia en el año actual.')">
                                                <i class="fas fa-upload"></i>
                                                Usar en <?php echo $year_actual; ?>
                                            </a>

                                            <!-- Toggle estado -->
                                            <a href="recursos.php?action=toggle&id=<?php echo $rec['id']; ?>"
                                               class="btn btn-sm btn-outline-<?php echo $rec['activo'] ? 'warning' : 'secondary'; ?>"
                                               title="<?php echo $rec['activo'] ? 'Desactivar' : 'Activar'; ?>">
                                                <i class="fas fa-<?php echo $rec['activo'] ? 'pause' : 'play'; ?>"></i>
                                            </a>

                                            <!-- Eliminar -->
                                            <a href="recursos.php?action=delete&id=<?php echo $rec['id']; ?>"
                                               class="btn btn-sm btn-outline-danger"
                                               title="Eliminar permanentemente"
                                               onclick="return confirmarEliminacion('¿Eliminar «<?php echo htmlspecialchars($rec['titulo'], ENT_QUOTES); ?>» del año <?php echo $anio; ?>?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

<?php elseif ($action === 'add' || $action === 'edit'): ?>

<!-- Formulario de agregar/editar recurso -->  
<div class="card">  
    <div class="card-header">  
        <h5>  
            <i class="fas fa-<?php echo $action === 'add' ? 'plus' : 'edit'; ?>"></i>  
            <?php echo $action === 'add' ? 'Nuevo' : 'Editar'; ?> Recurso  
        </h5>  
    </div>  
    <div class="card-body">  
        <form method="POST" enctype="multipart/form-data" id="formRecurso">  
            <div class="row">  
                <div class="col-md-8">  
                    <div class="mb-3">  
                        <label for="titulo" class="form-label">Título del Recurso *</label>  
                        <input type="text" class="form-control" id="titulo" name="titulo" required  
                               value="<?php echo htmlspecialchars($recurso['titulo'] ?? ''); ?>"  
                               placeholder="Ej: Devocional Día 1 - La Fe">  
                    </div>  
                      
                    <div class="row">  
                        <div class="col-md-4 mb-3">  
                            <label for="tipo" class="form-label">Tipo de Recurso *</label>  
                            <select class="form-select" id="tipo" name="tipo" required>  
                                <option value="">Seleccionar tipo...</option>  
                                <option value="devocional" <?php echo ($recurso['tipo'] ?? '') == 'devocional' ? 'selected' : ''; ?>>  
                                    Devocional  
                                </option>  
                                <option value="hora_silenciosa" <?php echo ($recurso['tipo'] ?? '') == 'hora_silenciosa' ? 'selected' : ''; ?>>  
                                    Hora Silenciosa  
                                </option>  
                                <option value="apoyo_consejeria" <?php echo ($recurso['tipo'] ?? '') == 'apoyo_consejeria' ? 'selected' : ''; ?>>  
                                    Apoyo Consejería  
                                </option>  
                            </select>  
                        </div>  
                        <div class="col-md-4 mb-3">  
                            <label for="formato" class="form-label">Formato *</label>  
                            <select class="form-select" id="formato" name="formato" required onchange="actualizarTiposArchivo()">  
                                <option value="">Seleccionar formato...</option>  
                                <option value="pdf" <?php echo ($recurso['formato'] ?? '') == 'pdf' ? 'selected' : ''; ?>>  
                                    PDF  
                                </option>  
                                <option value="video" <?php echo ($recurso['formato'] ?? '') == 'video' ? 'selected' : ''; ?>>  
                                    Video  
                                </option>  
                                <option value="texto" <?php echo ($recurso['formato'] ?? '') == 'texto' ? 'selected' : ''; ?>>  
                                    Texto  
                                </option>  
                            </select>  
                        </div>  
                        <div class="col-md-4 mb-3">  
                            <label for="version" class="form-label">Versión</label>  
                            <input type="text" class="form-control" id="version" name="version"  
                                   value="<?php echo htmlspecialchars($recurso['version'] ?? '1.0'); ?>"  
                                   placeholder="1.0">  
                        </div>  
                    </div>  
                      
                    <div class="mb-3">  
                        <label for="descripcion" class="form-label">Descripción</label>  
                        <textarea class="form-control" id="descripcion" name="descripcion" rows="4"  
                                  placeholder="Describe el contenido del recurso..."><?php echo htmlspecialchars($recurso['descripcion'] ?? ''); ?></textarea>  
                    </div>  
                      
                    <div class="mb-3">  
                        <label for="year_campamento" class="form-label">Año del Campamento *</label>  
                        <select class="form-select" id="year_campamento" name="year_campamento" required>  
                            <?php   
                            $currentYear = date('Y');  
                            for ($i = $currentYear; $i >= $currentYear - 5; $i--):   
                            ?>  
                            <option value="<?php echo $i; ?>"   
                                    <?php echo ($recurso['year_campamento'] ?? $currentYear) == $i ? 'selected' : ''; ?>>  
                                <?php echo $i; ?>  
                            </option>  
                            <?php endfor; ?>  
                        </select>  
                    </div>  
                </div>  
                  
                <div class="col-md-4">  
                    <div class="mb-3">  
                        <label for="archivo" class="form-label">  
                            Archivo <?php echo $action === 'edit' ? '(opcional - dejar vacío para mantener actual)' : '*'; ?>  
                        </label>  
                        <input type="file" class="form-control" id="archivo" name="archivo"   
                               <?php echo $action === 'add' ? 'required' : ''; ?>  
                               accept="">  
                        <small id="tiposPermitidos" class="form-text text-muted">  
                            Tipos permitidos: Selecciona formato primero  
                        </small>  
                        <small class="form-text text-muted">Tamaño máximo: 50MB</small>  
                    </div>  
                      
                    <?php if ($action === 'edit' && $recurso && $recurso['ruta_archivo']): ?>  
                    <div class="mb-3">  
                        <label class="form-label">Archivo Actual</label>  
                        <div class="card bg-light">  
                            <div class="card-body p-2">  
                                <small class="text-muted">  
                                    <i class="fas fa-<?php   
                                        echo $recurso['formato'] == 'pdf' ? 'file-pdf' :   
                                            ($recurso['formato'] == 'video' ? 'video' : 'file-alt');   
                                    ?>"></i>  
                                    <?php echo basename($recurso['ruta_archivo']); ?>  
                                </small>  
                                <br>  
                                <a href="../<?php echo $recurso['ruta_archivo']; ?>"   
                                   target="_blank" class="btn btn-sm btn-outline-primary mt-1">  
                                    <i class="fas fa-eye"></i> Ver archivo  
                                </a>  
                            </div>  
                        </div>  
                    </div>  
                    <?php endif; ?>  
                      
                    <div class="card bg-info bg-opacity-10 border-info">  
                        <div class="card-body">  
                            <h6 class="card-title">  
                                <i class="fas fa-info-circle text-info"></i> Información  
                            </h6>  
                            <ul class="small mb-0">  
                                <li><strong>Devocional:</strong> Material para reflexión diaria</li>  
                                <li><strong>Hora Silenciosa:</strong> Guías para tiempo personal</li>  
                                <li><strong>Apoyo Consejería:</strong> Material de apoyo para consejeros</li>  
                            </ul>  
                        </div>  
                    </div>  
                </div>  
            </div>  
              
            <hr>  
              
            <div class="d-flex justify-content-between">  
                <a href="recursos.php" class="btn btn-secondary">  
                    <i class="fas fa-arrow-left"></i> Volver  
                </a>  
                <button type="submit" class="btn btn-success">  
                    <i class="fas fa-save"></i>   
                    <?php echo $action === 'add' ? 'Subir' : 'Actualizar'; ?> Recurso  
                </button>  
            </div>  
        </form>  
    </div>  
</div>  
  
<?php endif; ?>  
  
<script>  
function actualizarTiposArchivo() {  
    const formato = document.getElementById('formato').value;  
    const archivoInput = document.getElementById('archivo');  
    const tiposPermitidos = document.getElementById('tiposPermitidos');  
      
    const tipos = {  
        'pdf': { accept: '.pdf', texto: 'PDF (.pdf)' },  
        'video': { accept: '.mp4,.avi,.mov,.wmv,.flv', texto: 'Videos (.mp4, .avi, .mov, .wmv, .flv)' },  
        'texto': { accept: '.txt,.doc,.docx,.rtf', texto: 'Documentos (.txt, .doc, .docx, .rtf)' }  
    };  
      
    if (formato && tipos[formato]) {  
        archivoInput.setAttribute('accept', tipos[formato].accept);  
        tiposPermitidos.textContent = 'Tipos permitidos: ' + tipos[formato].texto;  
    } else {  
        archivoInput.removeAttribute('accept');  
        tiposPermitidos.textContent = 'Selecciona formato primero';  
    }  
}  
  
// Inicializar al cargar  
document.addEventListener('DOMContentLoaded', function() {

    // Solo activar protección si hay formulario de edición/creación
    const formRecurso = document.getElementById('formRecurso');
    if (formRecurso) {
        if (document.getElementById('formato')) {
            actualizarTiposArchivo();
        }
        // Marcar que este form tiene su propio handler (no interferir con SW)
        window._formHasOwnHandler = true;
    }
}); 
</script>  
  
<?php include '../includes/footer.php'; ?>  