<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esEncargadoConsejeros()) {
    header('Location: ../consejero/dashboard.php');
    exit();
}

$titulo  = "Configuración de Equipos";
$message = '';
$error   = '';

// ── Guardar cambios ────────────────────────────────────────────
if ($_POST && isset($_POST['guardar'])) {
    try {
        $stmt = $pdo->prepare("UPDATE equipos 
                                SET nombre=?, color=?, color_hex=?, emoji=?
                                WHERE clave=?");

        foreach (['equipo_1', 'equipo_2'] as $clave) {
            $nombre    = trim($_POST[$clave]['nombre'] ?? '');
            $color_hex = trim($_POST[$clave]['color_hex'] ?? '#000000');
            $emoji     = trim($_POST[$clave]['emoji'] ?? '⚪');

            if (empty($nombre))
                throw new Exception("El nombre del equipo no puede estar vacío");

            // Mapear color_hex a clase Bootstrap más cercana
            $color = mapearColorBootstrap($color_hex);

            $stmt->execute([$nombre, $color, $color_hex, $emoji, $clave]);
        }

        $message = "Equipos actualizados correctamente";
        header("Location: equipos.php?message=" . urlencode($message));
        exit();

    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// ── Obtener equipos ────────────────────────────────────────────
$stmt_eq  = $pdo->query("SELECT * FROM equipos ORDER BY id");
$equipos  = $stmt_eq->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['message'])) $message = $_GET['message'];

// ── Helper: mapear hex a clase Bootstrap ──────────────────────
function mapearColorBootstrap(string $hex): string {
    $hex = ltrim($hex, '#');
    $r   = hexdec(substr($hex, 0, 2));
    $g   = hexdec(substr($hex, 2, 2));
    $b   = hexdec(substr($hex, 4, 2));

    // Comparar con colores Bootstrap principales
    $colores = [
        'success' => [25,  135, 84],
        'primary' => [13,  110, 253],
        'danger'  => [220, 53,  69],
        'warning' => [255, 193, 7],
        'info'    => [13,  202, 240],
        'dark'    => [33,  37,  41],
        'secondary'=> [108, 117, 125],
    ];

    $mejorColor    = 'secondary';
    $menorDistancia = PHP_INT_MAX;

    foreach ($colores as $nombre => [$cr, $cg, $cb]) {
        $distancia = sqrt(pow($r-$cr,2) + pow($g-$cg,2) + pow($b-$cb,2));
        if ($distancia < $menorDistancia) {
            $menorDistancia = $distancia;
            $mejorColor     = $nombre;
        }
    }
    return $mejorColor;
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-palette"></i> <?php echo $titulo; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="cabanas.php">Cabañas</a></li>
                <li class="breadcrumb-item active">Equipos</li>
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

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    Aquí puedes cambiar el <strong>nombre</strong>, <strong>color</strong> y <strong>emoji</strong>
    de los dos equipos. Los cambios se reflejan en toda la aplicación automáticamente.
</div>

<form method="POST">
    <input type="hidden" name="guardar" value="1">

    <div class="row g-4 mb-4">
        <?php foreach ($equipos as $i => $eq): ?>
        <div class="col-md-6">
            <div class="card h-100 border-3" id="card-<?php echo $eq['clave']; ?>"
                 style="border-color: <?php echo htmlspecialchars($eq['color_hex']); ?> !important;">

                <div class="card-header text-white d-flex align-items-center gap-2"
                     id="header-<?php echo $eq['clave']; ?>"
                     style="background-color: <?php echo htmlspecialchars($eq['color_hex']); ?>;">
                    <span id="preview-emoji-<?php echo $eq['clave']; ?>" style="font-size:1.4rem;">
                        <?php echo $eq['emoji']; ?>
                    </span>
                    <h5 class="mb-0" id="preview-nombre-<?php echo $eq['clave']; ?>">
                        <?php echo htmlspecialchars($eq['nombre']); ?>
                    </h5>
                </div>

                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-tag"></i> Nombre del Equipo
                        </label>
                        <input type="text"
                               class="form-control"
                               name="<?php echo $eq['clave']; ?>[nombre]"
                               value="<?php echo htmlspecialchars($eq['nombre']); ?>"
                               placeholder="Ej: Rojo, Azul, Fuego..."
                               maxlength="50"
                               required
                               oninput="actualizarPreview('<?php echo $eq['clave']; ?>')">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-fill-drip"></i> Color del Equipo
                        </label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="color"
                                   class="form-control form-control-color"
                                   name="<?php echo $eq['clave']; ?>[color_hex]"
                                   value="<?php echo htmlspecialchars($eq['color_hex']); ?>"
                                   style="width:80px; height:45px; cursor:pointer;"
                                   oninput="actualizarColor('<?php echo $eq['clave']; ?>', this.value)">
                            <div>
                                <code id="hex-<?php echo $eq['clave']; ?>">
                                    <?php echo htmlspecialchars($eq['color_hex']); ?>
                                </code>
                                <div class="mt-1">
                                    <!-- Colores rápidos -->
                                    <small class="text-muted d-block mb-1">Colores rápidos:</small>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <?php
                                        $rapidos = [
                                            ['#198754','Verde'],
                                            ['#0d6efd','Azul'],
                                            ['#dc3545','Rojo'],
                                            ['#ffc107','Amarillo'],
                                            ['#fd7e14','Naranja'],
                                            ['#6f42c1','Morado'],
                                            ['#0dcaf0','Celeste'],
                                            ['#212529','Negro'],
                                        ];
                                        foreach ($rapidos as [$hex, $label]): ?>
                                        <button type="button"
                                                class="btn btn-sm p-0 border-0 rounded-circle color-rapido"
                                                style="width:28px;height:28px;background:<?php echo $hex; ?>;"
                                                title="<?php echo $label; ?>"
                                                onclick="seleccionarColor('<?php echo $eq['clave']; ?>', '<?php echo $hex; ?>')">
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-smile"></i> Emoji del Equipo
                        </label>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <input type="text"
                                   class="form-control"
                                   name="<?php echo $eq['clave']; ?>[emoji]"
                                   value="<?php echo htmlspecialchars($eq['emoji']); ?>"
                                   maxlength="5"
                                   style="width:80px; font-size:1.5rem; text-align:center;"
                                   oninput="actualizarPreview('<?php echo $eq['clave']; ?>')">
                            <div class="d-flex gap-1 flex-wrap">
                                <?php
                                $emojis = ['🟢','🔵','🔴','🟡','🟠','🟣','⚫','⚪','🏆','⚡','🔥','💧','🌟','🎯'];
                                foreach ($emojis as $em): ?>
                                <button type="button"
                                        class="btn btn-light btn-sm p-1"
                                        style="font-size:1.2rem; line-height:1;"
                                        onclick="seleccionarEmoji('<?php echo $eq['clave']; ?>', '<?php echo $em; ?>')">
                                    <?php echo $em; ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <small class="text-muted">Puedes escribir cualquier emoji o seleccionar uno de la lista</small>
                    </div>

                    <!-- Vista previa -->
                    <div class="border rounded p-2 bg-light">
                        <small class="text-muted fw-bold d-block mb-2">Vista previa de badges:</small>
                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <span class="badge fs-6"
                                  id="badge-<?php echo $eq['clave']; ?>"
                                  style="background-color:<?php echo $eq['color_hex']; ?>;">
                                <span><?php echo $eq['emoji']; ?></span>
                                <span><?php echo htmlspecialchars($eq['nombre']); ?></span>
                            </span>
                            <span class="badge"
                                  id="badge-sm-<?php echo $eq['clave']; ?>"
                                  style="background-color:<?php echo $eq['color_hex']; ?>;">
                                <?php echo $eq['emoji']; ?> Equipo
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex justify-content-between">
        <a href="cabanas.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver a Cabañas
        </a>
        <button type="submit" class="btn btn-success btn-lg">
            <i class="fas fa-save"></i> Guardar Configuración de Equipos
        </button>
    </div>
</form>

<script>
// ── Actualizar vista previa nombre/emoji ───────────────────────
function actualizarPreview(clave) {
    const nombre = document.querySelector(`[name="${clave}[nombre]"]`).value || 'Equipo';
    const emoji  = document.querySelector(`[name="${clave}[emoji]"]`).value  || '⚪';

    document.getElementById('preview-nombre-' + clave).textContent = nombre;
    document.getElementById('preview-emoji-'  + clave).textContent = emoji;

    const badge   = document.getElementById('badge-'    + clave);
    const badgeSm = document.getElementById('badge-sm-' + clave);
    if (badge)   badge.innerHTML   = `<span>${emoji}</span> <span>${nombre}</span>`;
    if (badgeSm) badgeSm.innerHTML = `${emoji} Equipo`;
}

// ── Actualizar color ───────────────────────────────────────────
function actualizarColor(clave, hex) {
    document.getElementById('hex-'    + clave).textContent    = hex;
    document.getElementById('header-' + clave).style.backgroundColor = hex;
    document.getElementById('card-'   + clave).style.borderColor     = hex;

    const badge   = document.getElementById('badge-'    + clave);
    const badgeSm = document.getElementById('badge-sm-' + clave);
    if (badge)   badge.style.backgroundColor   = hex;
    if (badgeSm) badgeSm.style.backgroundColor = hex;
}

// ── Seleccionar color rápido ───────────────────────────────────
function seleccionarColor(clave, hex) {
    document.querySelector(`[name="${clave}[color_hex]"]`).value = hex;
    actualizarColor(clave, hex);
}

// ── Seleccionar emoji ──────────────────────────────────────────
function seleccionarEmoji(clave, emoji) {
    document.querySelector(`[name="${clave}[emoji]"]`).value = emoji;
    actualizarPreview(clave);
}
</script>

<?php include '../includes/footer.php'; ?>