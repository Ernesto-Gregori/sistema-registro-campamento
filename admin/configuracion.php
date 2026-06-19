<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$titulo  = "Configuración Global";
$message = '';
$error   = '';

// ── SUBIR LOGO ───────────────────────────────────────────────
if ($_POST && ($_POST['post_accion'] ?? '') === 'guardar' && !empty($_FILES['logo_file']['name'])) {
    $file       = $_FILES['logo_file'];
    $allowed    = ['image/png','image/jpeg','image/svg+xml','image/webp'];
    $max_size   = 512 * 1024; // 500 KB

    if (!in_array($file['type'], $allowed)) {
        $error = "Formato no permitido. Usa PNG, JPG, SVG o WEBP.";
    } elseif ($file['size'] > $max_size) {
        $error = "El logo no puede superar 500 KB.";
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Error al subir el archivo.";
    } else {
        $destino = $_SERVER['DOCUMENT_ROOT'] . '/assets/img/logo_sistema.png';
        // Convertir a PNG si es necesario o guardar tal cual
        if (move_uploaded_file($file['tmp_name'], $destino)) {
            $message = "✅ Logo subido correctamente";
            registrarLog($pdo, 'logo_actualizado',
                "Logo del sistema actualizado (" . round($file['size']/1024,1) . " KB)",
                'sistema', 'info');
        } else {
            $error = "No se pudo guardar el logo. Verifica permisos de /assets/img/";
        }
    }
}

// ── ELIMINAR LOGO ────────────────────────────────────────────
if ($_POST && ($_POST['post_accion'] ?? '') === 'eliminar_logo') {
    $logo_disco = $_SERVER['DOCUMENT_ROOT'] . '/assets/img/logo_sistema.png';
    if (file_exists($logo_disco)) {
        unlink($logo_disco);
        registrarLog($pdo, 'logo_eliminado', "Logo del sistema eliminado", 'sistema', 'warning');
    }
    $message = "✅ Logo eliminado — se mostrará el nombre del sistema";
    header("Location: configuracion.php?ok=1");
    exit();
}

// ── GUARDAR configuración ────────────────────────────────────
if ($_POST && ($_POST['post_accion'] ?? '') === 'guardar') {
    try {
        $campos = [
            'nombre_campamento',
            'nombre_sistema',
            'anio_activo',
            'pais',
            'division_territorial',
            'capacidad_default',
            'sesiones_meta',
            'color_primario',
            'mantenimiento_modo',
        ];

        $stmt = $pdo->prepare("UPDATE configuracion SET valor = ? WHERE clave = ?");
        $cambios = [];

        foreach ($campos as $campo) {
            if (!isset($_POST[$campo])) continue;

            $valor_nuevo = trim($_POST[$campo]);

            // Obtener valor anterior para el log
            $stmt_old = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = ?");
            $stmt_old->execute([$campo]);
            $valor_old = $stmt_old->fetchColumn();

            if ($valor_old !== $valor_nuevo) {
                $stmt->execute([$valor_nuevo, $campo]);
                $cambios[] = "$campo: '$valor_old' → '$valor_nuevo'";
            }
        }

        // Checkbox mantenimiento_modo (viene como 1 o no viene)
        $modo_mant = isset($_POST['mantenimiento_modo']) ? '1' : '0';
        $stmt->execute([$modo_mant, 'mantenimiento_modo']);

        if (!empty($cambios)) {
            registrarLog($pdo, 'configuracion_actualizada',
                "Cambios: " . implode(' | ', $cambios),
                'sistema', 'info');
        }

        $message = "✅ Configuración guardada correctamente";

    } catch (Exception $e) {
        $error = "Error al guardar: " . $e->getMessage();
    }
}

// ── CARGAR configuración actual ──────────────────────────────
try {
    $stmt = $pdo->query("SELECT * FROM configuracion ORDER BY id");
    $configs_raw = $stmt->fetchAll();

    // Organizar por clave para acceso fácil
    $cfg = [];
    foreach ($configs_raw as $row) {
        $cfg[$row['clave']] = $row;
    }
} catch (Exception $e) {
    $error = "Error al cargar configuración: " . $e->getMessage();
    $cfg   = [];
}

// ── Info del sistema ─────────────────────────────────────────
$info_sistema = [
    'PHP'        => phpversion(),
    'MySQL'      => $pdo->query("SELECT VERSION()")->fetchColumn(),
    'Servidor'   => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
    'Disco libre'=> function_exists('disk_free_space')
                    ? round(disk_free_space('/') / 1024 / 1024 / 1024, 2) . ' GB'
                    : 'N/A',
    'Memoria PHP'=> ini_get('memory_limit'),
    'Max upload' => ini_get('upload_max_filesize'),
];

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-sliders-h"></i> <?php echo $titulo; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="dashboard.php">Dashboard</a>
                </li>
                <li class="breadcrumb-item active">Configuración</li>
            </ol>
        </nav>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Alerta modo mantenimiento -->
<?php if (($cfg['mantenimiento_modo']['valor'] ?? '0') === '1'): ?>
<div class="alert alert-warning">
    <i class="fas fa-tools"></i>
    <strong>Modo mantenimiento ACTIVO</strong> — 
    Los usuarios no administradores no pueden acceder al sistema.
</div>
<?php endif; ?>

<form method="POST" id="formConfig">
    <input type="hidden" name="post_accion" value="guardar">

    <div class="row">

        <!-- ── Columna izquierda ── -->
        <div class="col-md-7">

            <!-- Identidad del campamento -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-campground"></i> Identidad del Sistema
                    </h5>
                </div>
                <div class="card-body">
            
                    <!-- Nombre del sistema -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">
                            <i class="fas fa-signature"></i> Nombre del Sistema
                        </label>
                        <input type="text" class="form-control" name="nombre_sistema"
                               value="<?php echo htmlspecialchars($cfg['nombre_sistema']['valor'] ?? 'ConectaPV'); ?>"
                               placeholder="Ej: ConectaPV">
                        <small class="text-muted">
                            Aparece en el header y en el título del navegador
                        </small>
                    </div>
            
                    <hr>
            
                    <!-- Logo del sistema -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-image"></i> Logo del Sistema
                        </label>
            
                        <?php
                        $logo_path  = '/assets/img/logo_sistema.png';
                        $logo_disco = $_SERVER['DOCUMENT_ROOT'] . $logo_path;
                        $tiene_logo = file_exists($logo_disco);
                        ?>
            
                        <!-- Preview actual -->
                        <div class="mb-3">
                            <?php if ($tiene_logo): ?>
                            <div class="d-flex align-items-center gap-3 p-3 border rounded bg-light">
                                <img src="<?php echo $logo_path; ?>?v=<?php echo filemtime($logo_disco); ?>"
                                     style="height:60px; width:auto; object-fit:contain;"
                                     alt="Logo actual">
                                <div>
                                    <span class="badge bg-success mb-1">
                                        <i class="fas fa-check"></i> Logo activo
                                    </span>
                                    <br>
                                    <small class="text-muted">
                                        <?php echo round(filesize($logo_disco) / 1024, 1); ?> KB
                                    </small>
                                </div>
                                <!-- Botón eliminar logo -->
                                <form method="POST" class="ms-auto"
                                      onsubmit="return confirm('¿Eliminar el logo? Se mostrará el nombre del sistema.')">
                                    <input type="hidden" name="post_accion" value="eliminar_logo">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                        <i class="fas fa-trash"></i> Quitar logo
                                    </button>
                                </form>
                            </div>
                            <?php else: ?>
                            <div class="p-3 border rounded bg-light text-muted text-center">
                                <i class="fas fa-campground fa-2x mb-2 d-block"></i>
                                Sin logo — se muestra el nombre del sistema
                            </div>
                            <?php endif; ?>
                        </div>
            
                        <!-- Subir nuevo logo -->
                        <label class="form-label">
                            <?php echo $tiene_logo ? 'Reemplazar logo:' : 'Subir logo:'; ?>
                        </label>
                        <input type="file" class="form-control" name="logo_file"
                               id="logoFile" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                               onchange="previewLogo(this)">
                        <small class="text-muted">
                            PNG, JPG, SVG o WEBP — máximo 500 KB — 
                            Recomendado: fondo transparente (PNG), altura mínima 72px
                        </small>
            
                        <!-- Preview antes de guardar -->
                        <div id="logoPreview" class="mt-2" style="display:none;">
                            <p class="small text-muted mb-1">Vista previa:</p>
                            <div class="p-2 border rounded" style="background:#004f68; display:inline-block;">
                                <img id="imgPreview" src="" alt="Preview"
                                     style="height:36px; width:auto; object-fit:contain;">
                            </div>
                        </div>
                    </div>
            
                </div>
            </div>
            
                        <!-- Configuración del campamento -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-globe-americas"></i> Configuración del Campamento
                    </h5>
                </div>
                <div class="card-body">

                    <!-- País -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-flag"></i> País
                        </label>
                        <select class="form-select" name="pais" id="selectPais"
                                onchange="actualizarDivision(this.value)">
                            <?php
                            $pais_actual = $cfg['pais']['valor'] ?? 'El Salvador';
                            $paises_lista = [
                                'El Salvador','Guatemala','Honduras','Nicaragua',
                                'Costa Rica','Panamá','México','Colombia','Venezuela',
                                'Perú','Chile','Argentina','Ecuador','Bolivia',
                                'Paraguay','Uruguay','Cuba','República Dominicana',
                            ];
                            foreach ($paises_lista as $p):
                            ?>
                            <option value="<?= htmlspecialchars($p) ?>"
                                <?= $pais_actual === $p ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            Determina los estados/departamentos disponibles al registrar acampantes
                        </small>
                    </div>

                    <!-- División territorial (se actualiza automáticamente) -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-map-marked-alt"></i> División Territorial
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-layer-group"></i>
                            </span>
                            <input type="text" class="form-control" id="campoDivision"
                                   name="division_territorial" readonly
                                   value="<?= htmlspecialchars($cfg['division_territorial']['valor'] ?? 'Departamento') ?>">
                        </div>
                        <small class="text-muted">
                            Se actualiza automáticamente al cambiar el país
                        </small>
                    </div>

                    <hr>

                    <!-- Nombre del campamento -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-campground"></i> Nombre del Campamento
                        </label>
                        <input type="text" class="form-control" name="nombre_campamento"
                               value="<?= htmlspecialchars($cfg['nombre_campamento']['valor'] ?? '') ?>"
                               placeholder="Ej: Campamento Palabra de Vida">
                    </div>

                    <!-- Año activo -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-calendar"></i> Año Activo
                        </label>
                        <input type="number" class="form-control" name="anio_activo"
                               min="2020" max="2099"
                               value="<?= htmlspecialchars($cfg['anio_activo']['valor'] ?? date('Y')) ?>">
                        <small class="text-muted">
                            Año que se usa para filtrar registros sin semana específica
                        </small>
                    </div>

                    <hr>

                    <!-- Configuración de operación -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-bed"></i> Capacidad Default de Cabañas
                            </label>
                            <input type="number" class="form-control" name="capacidad_default"
                                   min="1" max="200"
                                   value="<?= htmlspecialchars($cfg['capacidad_default']['valor'] ?? '15') ?>">
                            <small class="text-muted">
                                Capacidad sugerida al crear una cabaña nueva
                            </small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                <i class="fas fa-comments"></i> Meta de Sesiones por Consejero
                            </label>
                            <input type="number" class="form-control" name="sesiones_meta"
                                   min="1" max="50"
                                   value="<?= htmlspecialchars($cfg['sesiones_meta']['valor'] ?? '3') ?>">
                            <small class="text-muted">
                                Número objetivo de sesiones de consejería
                            </small>
                        </div>
                    </div>

                    <hr>

                    <!-- Color primario -->
                    <div class="mb-0">
                        <label class="form-label fw-bold">
                            <i class="fas fa-palette"></i> Color Primario
                        </label>
                        <div class="input-group">
                            <input type="color" class="form-control form-control-color"
                                   id="colorPicker"
                                   value="<?= htmlspecialchars($cfg['color_primario']['valor'] ?? '#004f68') ?>"
                                   onchange="actualizarColorHex(this.value)">
                            <input type="text" class="form-control" id="colorHex"
                                   name="color_primario"
                                   value="<?= htmlspecialchars($cfg['color_primario']['valor'] ?? '#004f68') ?>"
                                   placeholder="#004f68"
                                   oninput="actualizarColorPicker(this.value)">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="document.getElementById('colorHex').value='#004f68';
                                             document.getElementById('colorPicker').value='#004f68';">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                        </div>
                        <small class="text-muted">
                            Solo visual, no aplica CSS en tiempo real en esta versión
                        </small>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Columna derecha ── -->
        <div class="col-md-5">

            <!-- Sistema -->
            <div class="card mb-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-server"></i> Sistema
                    </h5>
                </div>
                <div class="card-body">

                    <!-- Modo mantenimiento -->
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox"
                                   name="mantenimiento_modo" id="modoMant" role="switch"
                                   <?php echo ($cfg['mantenimiento_modo']['valor'] ?? '0') === '1' ? 'checked' : ''; ?>
                                   onchange="toggleAlertaMant(this.checked)">
                            <label class="form-check-label fw-bold text-danger" for="modoMant">
                                <i class="fas fa-tools"></i> Modo Mantenimiento
                            </label>
                        </div>
                        <small class="text-muted d-block mt-1">
                            Bloquea acceso a todos los usuarios excepto administradores
                        </small>
                        <div id="alertaMant" class="alert alert-warning py-2 mt-2 small"
                             style="display:<?php echo ($cfg['mantenimiento_modo']['valor'] ?? '0') === '1' ? 'block' : 'none'; ?>">
                            <i class="fas fa-exclamation-triangle"></i>
                            Al activar esto, <strong>solo tú</strong> podrás acceder al sistema.
                        </div>
                    </div>

                    <hr>

                    <!-- Versión (solo lectura) -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Versión del Sistema</label>
                        <input type="text" class="form-control"
                               value="<?php echo htmlspecialchars($cfg['version_sistema']['valor'] ?? '1.0.0'); ?>"
                               readonly>
                        <small class="text-muted">Solo el desarrollador puede cambiar esto</small>
                    </div>

                </div>
            </div>

            <!-- Info del servidor -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle"></i> Información del Servidor
                    </h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <?php foreach ($info_sistema as $label => $valor): ?>
                        <tr>
                            <td class="text-muted small ps-3"><?php echo $label; ?></td>
                            <td class="small fw-bold"><?php echo htmlspecialchars($valor); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <!-- Última actualización -->
            <?php
            $ultima_update = '';
            foreach ($configs_raw as $row) {
                if ($row['updated_at'] > $ultima_update) {
                    $ultima_update = $row['updated_at'];
                }
            }
            ?>
            <?php if ($ultima_update): ?>
            <div class="alert alert-light border small">
                <i class="fas fa-clock text-muted"></i>
                Última modificación:
                <strong><?php echo date('d/m/Y H:i', strtotime($ultima_update)); ?></strong>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Botones -->
    <div class="d-flex justify-content-between align-items-center mt-2 mb-4">
        <a href="dashboard_admin.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-save"></i> Guardar Configuración
        </button>
    </div>

</form>

<script>
    
const divisionesPorPais = {
    'El Salvador':         'Departamento',
    'Guatemala':           'Departamento',
    'Honduras':            'Departamento',
    'Nicaragua':           'Departamento',
    'Costa Rica':          'Provincia',
    'Panamá':              'Provincia',
    'México':              'Estado',
    'Colombia':            'Departamento',
    'Venezuela':           'Estado',
    'Perú':                'Región',
    'Chile':               'Región',
    'Argentina':           'Provincia',
    'Ecuador':             'Provincia',
    'Bolivia':             'Departamento',
    'Paraguay':            'Departamento',
    'Uruguay':             'Departamento',
    'Cuba':                'Provincia',
    'República Dominicana':'Provincia',
};

// Preview del logo antes de guardar
function previewLogo(input) {
    const preview = document.getElementById('logoPreview');
    const img     = document.getElementById('imgPreview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            img.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function actualizarDivision(pais) {
    const div = divisionesPorPais[pais] || 'Departamento';
    document.getElementById('campoDivision').value = div;
    // También actualizar el hidden para que se guarde
    const hiddenDiv = document.querySelector('[name="division_territorial"]');
    if (hiddenDiv) hiddenDiv.value = div;
}

function actualizarColorHex(valor) {
    document.getElementById('colorHex').value = valor;
}
function actualizarColorPicker(valor) {
    if (/^#[0-9A-Fa-f]{6}$/.test(valor)) {
        document.getElementById('colorPicker').value = valor;
    }
}
function toggleAlertaMant(activo) {
    document.getElementById('alertaMant').style.display = activo ? 'block' : 'none';
}
</script>

<?php include '../includes/footer.php'; ?>