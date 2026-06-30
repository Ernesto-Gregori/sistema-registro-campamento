<?php
// admisiones/grupos/generar_codigos_existentes.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador()) {
    header('Location: ../../login.php');
    exit();
}

$mensaje = '';
$generados = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar'])) {
    try {
        // Obtener todos los grupos sin código de acceso
        $stmt = $pdo->query("
            SELECT id FROM grupos_campamento
            WHERE codigo_acceso IS NULL OR codigo_acceso = ''
        ");
        $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($grupos as $g) {
            $codigo = generarCodigoAccesoGrupo();
            // Asegurar unicidad
            $intentos = 0;
            while ($intentos < 10) {
                $check = $pdo->prepare("SELECT id FROM grupos_campamento WHERE codigo_acceso = ? LIMIT 1");
                $check->execute([$codigo]);
                if (!$check->fetch()) break;
                $codigo = generarCodigoAccesoGrupo();
                $intentos++;
            }

            $upd = $pdo->prepare("UPDATE grupos_campamento SET codigo_acceso = ? WHERE id = ?");
            $upd->execute([$codigo, $g['id']]);
            $generados++;
        }

        registrarLog($pdo, 'generar_codigos_grupos',
            "Se generaron {$generados} códigos de acceso para grupos existentes",
            'admisiones', 'success');

        $mensaje = "✅ Se generaron {$generados} códigos de acceso correctamente.";
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

// Contar grupos sin código
$stmt = $pdo->query("SELECT COUNT(*) FROM grupos_campamento WHERE codigo_acceso IS NULL OR codigo_acceso = ''");
$sin_codigo = (int)$stmt->fetchColumn();

$base_path = '../';
include '../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-key"></i> Generar Códigos de Acceso</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="lista_grupos.php">Grupos</a></li>
                <li class="breadcrumb-item active">Generar Códigos</li>
            </ol>
        </nav>
    </div>
</div>

<?php if ($mensaje): ?>
<div class="alert alert-<?= strpos($mensaje, '✅') !== false ? 'success' : 'danger' ?> alert-dismissible fade show">
    <?= $mensaje ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="rounded-circle bg-warning d-flex align-items-center justify-content-center text-white"
                 style="width:56px;height:56px;font-size:1.5rem;">
                <i class="fas fa-key"></i>
            </div>
            <div>
                <h5 class="mb-1">Grupos sin código de acceso</h5>
                <p class="mb-0 text-muted">
                    Actualmente hay <strong><?= $sin_codigo ?></strong> grupo<?= $sin_codigo !== 1 ? 's' : '' ?>
                    sin código de acceso.
                </p>
            </div>
        </div>

        <?php if ($sin_codigo > 0): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Al generar los códigos, cada grupo recibirá un código único como <strong>GRP-XXXXXX</strong>.
            El encargado podrá usarlo junto con su nombre para acceder al panel del grupo.
        </div>
        <form method="POST">
            <input type="hidden" name="confirmar" value="1">
            <button type="submit" class="btn btn-success"
                    onclick="return confirm('¿Generar códigos para los <?= $sin_codigo ?> grupo(s) sin código?')">
                <i class="fas fa-magic me-2"></i> Generar códigos ahora
            </button>
        </form>
        <?php else: ?>
        <div class="alert alert-success mb-0">
            <i class="fas fa-check-double me-2"></i>
            Todos los grupos ya tienen código de acceso.
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="mt-3">
    <a href="lista_grupos.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Volver a grupos
    </a>
</div>

<?php include '../../includes/footer.php'; ?>
