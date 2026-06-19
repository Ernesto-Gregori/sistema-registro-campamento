<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$titulo  = "Gestor de Backups";
$message = '';
$error   = '';

$backupDir = '../backups/';
if (!file_exists($backupDir)) mkdir($backupDir, 0755, true);

// ── Procesar acciones POST ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // 1. Subir backup desde PC
    if ($accion === 'subir_backup') {
        try {
            if (empty($_FILES['archivo_backup']['name'])) {
                throw new Exception("Selecciona un archivo JSON para subir.");
            }

            $archivo  = $_FILES['archivo_backup'];
            $nombre   = basename($archivo['name']);
            $ext      = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

            if ($ext !== 'json') {
                throw new Exception("Solo se permiten archivos .json");
            }
            if ($archivo['size'] > 50 * 1024 * 1024) { // 50 MB máximo
                throw new Exception("El archivo supera el límite de 50 MB");
            }
            if ($archivo['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Error al subir el archivo (código: {$archivo['error']})");
            }

            // Validar que es un JSON válido con estructura de backup
            $contenido = file_get_contents($archivo['tmp_name']);
            $datos     = json_decode($contenido, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("El archivo no es un JSON válido: " . json_last_error_msg());
            }
            if (!is_array($datos) || empty($datos)) {
                throw new Exception("El JSON no tiene la estructura esperada de backup.");
            }

            // Renombrar si ya existe un archivo con el mismo nombre
            $destino = $backupDir . $nombre;
            if (file_exists($destino)) {
                $nombre  = pathinfo($nombre, PATHINFO_FILENAME)
                           . '_subido_' . date('His') . '.json';
                $destino = $backupDir . $nombre;
            }

            move_uploaded_file($archivo['tmp_name'], $destino);
            $tamano  = round(filesize($destino) / 1024, 1);
            $message = "Backup subido correctamente: $nombre ({$tamano} KB)";
            registrarLog($pdo, 'backup_subido',
                "Backup subido desde PC: $nombre ({$tamano} KB)",
                'backups', 'info');

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    // 2. Descargar backup
    if ($accion === 'descargar') {
        $nombre = basename($_POST['archivo'] ?? '');
        $ruta   = $backupDir . $nombre;
        if ($nombre && file_exists($ruta) && pathinfo($ruta, PATHINFO_EXTENSION) === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $nombre . '"');
            header('Content-Length: ' . filesize($ruta));
            readfile($ruta);
            exit();
        }
        $error = "Archivo no encontrado.";
    }

    // 3. Eliminar backup
    if ($accion === 'eliminar') {
        $nombre = basename($_POST['archivo'] ?? '');
        $ruta   = $backupDir . $nombre;
        if ($nombre && file_exists($ruta) && pathinfo($ruta, PATHINFO_EXTENSION) === 'json') {
            unlink($ruta);
            $message = "Backup eliminado: $nombre";
            registrarLog($pdo, 'backup_eliminado',
                "Backup eliminado: $nombre", 'backups', 'warning');
        } else {
            $error = "Archivo no encontrado.";
        }
    }

    // 4. Ver contenido del backup
    if ($accion === 'ver_contenido') {
        $nombre = basename($_POST['archivo'] ?? '');
        $ruta   = $backupDir . $nombre;
        if ($nombre && file_exists($ruta)) {
            $contenido    = file_get_contents($ruta);
            $datos_backup = json_decode($contenido, true) ?? [];
            // Se muestra abajo en la vista
        } else {
            $error = "Archivo no encontrado.";
        }
    }
}

// Listar todos los backups disponibles
$backups = array_reverse(glob($backupDir . '*.json') ?: []);

// Calcular tamaño total de backups
$tamano_total = array_sum(array_map('filesize', $backups));

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-archive"></i> <?= $titulo ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Backups</li>
                </ol>
            </nav>
        </div>
        <!-- Botón generar backup rápido -->
        <form method="POST" action="mantenimiento.php">
            <input type="hidden" name="accion" value="backup_datos">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-plus"></i> Generar Nuevo Backup
            </button>
        </form>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">

    <!-- ── Panel izquierdo: subir backup ── -->
    <div class="col-md-4 mb-4">

        <!-- Subir backup -->
        <div class="card mb-3">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="fas fa-upload"></i> Subir Backup desde PC</h6>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="formSubir">
                    <input type="hidden" name="accion" value="subir_backup">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Archivo JSON</label>
                        <input type="file" class="form-control form-control-sm"
                               name="archivo_backup" accept=".json" required
                               onchange="mostrarInfoArchivo(this)">
                        <div id="info_archivo" class="small text-muted mt-1" style="display:none;"></div>
                    </div>
                    <div class="alert alert-info py-2 px-3 small mb-3">
                        <i class="fas fa-info-circle"></i>
                        Solo archivos <strong>.json</strong> generados por este sistema.
                        Máximo 50 MB.
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-upload"></i> Subir Backup
                    </button>
                </form>
            </div>
        </div>

        <!-- Resumen -->
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-info-circle"></i> Resumen</h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="small text-muted">Total de backups:</span>
                    <strong><?= count($backups) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="small text-muted">Espacio usado:</span>
                    <strong><?= round($tamano_total / 1024, 1) ?> KB</strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="small text-muted">Último backup:</span>
                    <strong class="small">
                        <?= !empty($backups)
                            ? date('d/m/Y H:i', filemtime($backups[0]))
                            : 'Ninguno' ?>
                    </strong>
                </div>
                <hr class="my-2">
                <a href="mantenimiento.php" class="btn btn-outline-secondary btn-sm w-100">
                    <i class="fas fa-tools"></i> Ir a Mantenimiento
                </a>
            </div>
        </div>

    </div>

    <!-- ── Panel derecho: lista de backups ── -->
    <div class="col-md-8 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list"></i> Backups Disponibles</h5>
                <span class="badge bg-secondary"><?= count($backups) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($backups)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-archive fa-3x mb-3 opacity-25"></i>
                    <p class="mb-1">No hay backups disponibles</p>
                    <small>Genera uno desde Mantenimiento o sube uno desde tu PC</small>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Archivo</th>
                                <th class="text-center">Tamaño</th>
                                <th class="text-center">Fecha</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($backups as $i => $backup):
                            $nombre_archivo = basename($backup);
                            $kb             = round(filesize($backup) / 1024, 1);
                            $fecha          = date('d/m/Y H:i', filemtime($backup));
                            $es_reciente    = filemtime($backup) > strtotime('-24 hours');
                        ?>
                        <tr>
                            <td>
                                <?php if ($es_reciente): ?>
                                <span class="badge bg-success me-1" style="font-size:9px;">NUEVO</span>
                                <?php endif; ?>
                                <span class="small fw-bold font-monospace"
                                      title="<?= $nombre_archivo ?>">
                                    <?= htmlspecialchars(
                                        strlen($nombre_archivo) > 35
                                        ? substr($nombre_archivo, 0, 32) . '...'
                                        : $nombre_archivo
                                    ) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <small><?= $kb ?> KB</small>
                            </td>
                            <td class="text-center">
                                <small><?= $fecha ?></small>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">

                                    <!-- Ver contenido -->
                                    <form method="POST">
                                        <input type="hidden" name="accion"  value="ver_contenido">
                                        <input type="hidden" name="archivo" value="<?= $nombre_archivo ?>">
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-info"
                                                title="Ver contenido">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </form>

                                    <!-- Descargar -->
                                    <form method="POST">
                                        <input type="hidden" name="accion"  value="descargar">
                                        <input type="hidden" name="archivo" value="<?= $nombre_archivo ?>">
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-primary"
                                                title="Descargar">
                                            <i class="fas fa-download"></i>
                                        </button>
                                    </form>

                                    <!-- Eliminar -->
                                    <form method="POST"
                                          onsubmit="return confirm('¿Eliminar el backup <?= htmlspecialchars($nombre_archivo, ENT_QUOTES) ?>?')">
                                        <input type="hidden" name="accion"  value="eliminar">
                                        <input type="hidden" name="archivo" value="<?= $nombre_archivo ?>">
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>

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

</div>

<!-- ── Vista de contenido del backup ── -->
<?php if (!empty($datos_backup)): ?>
<div class="row">
    <div class="col-12">
        <div class="card border-info">
            <div class="card-header bg-info text-white d-flex justify-content-between">
                <h6 class="mb-0">
                    <i class="fas fa-search"></i>
                    Contenido: <?= htmlspecialchars($nombre ?? '') ?>
                </h6>
                <button class="btn btn-sm btn-light"
                        onclick="document.getElementById('contenido_backup').classList.toggle('d-none')">
                    <i class="fas fa-eye-slash"></i> Ocultar
                </button>
            </div>
            <div class="card-body" id="contenido_backup">
                <div class="row">
                    <?php foreach ($datos_backup as $tabla => $filas): ?>
                    <div class="col-md-4 mb-3">
                        <div class="card border-0 bg-light">
                            <div class="card-body py-2 px-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong class="small">
                                        <i class="fas fa-table text-secondary me-1"></i>
                                        <?= htmlspecialchars($tabla) ?>
                                    </strong>
                                    <span class="badge bg-secondary">
                                        <?= count($filas) ?> filas
                                    </span>
                                </div>
                                <?php if (!empty($filas)): ?>
                                <small class="text-muted d-block mt-1">
                                    Columnas: <?= implode(', ', array_keys($filas[0])) ?>
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="alert alert-warning py-2 px-3 small mb-0 mt-2">
                    <i class="fas fa-exclamation-triangle"></i>
                    Este es un backup de referencia en JSON. Para restaurar la BD completa
                    usa un backup SQL desde phpMyAdmin.
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function mostrarInfoArchivo(input) {
    const info = document.getElementById('info_archivo');
    if (input.files && input.files[0]) {
        const f    = input.files[0];
        const kb   = (f.size / 1024).toFixed(1);
        const esOk = f.name.endsWith('.json');
        info.innerHTML = esOk
            ? `<i class="fas fa-check-circle text-success"></i> ${f.name} (${kb} KB)`
            : `<i class="fas fa-times-circle text-danger"></i> Solo se permiten archivos .json`;
        info.className = 'small mt-1 ' + (esOk ? 'text-success' : 'text-danger');
        info.style.display = 'block';
    }
}
</script>

<?php include '../includes/footer.php'; ?>