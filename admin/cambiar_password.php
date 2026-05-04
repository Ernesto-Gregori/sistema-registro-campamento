<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$titulo  = "Cambiar Mi Contraseña";
$message = '';
$error   = '';

if ($_POST) {
    try {
        $actual    = $_POST['contrasena_actual']  ?? '';
        $nueva     = $_POST['contrasena_nueva']   ?? '';
        $confirmar = $_POST['confirmar_contrasena'] ?? '';

        if (empty($actual))              throw new Exception("Ingresa tu contraseña actual");
        if (empty($nueva))               throw new Exception("Ingresa una nueva contraseña");
        if (strlen($nueva) < 6)          throw new Exception("Mínimo 6 caracteres");
        if ($nueva !== $confirmar)        throw new Exception("Las contraseñas no coinciden");

        // Verificar contraseña actual
        $stmt = $pdo->prepare("SELECT password FROM usuarios WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $usuario = $stmt->fetch();

        if (!password_verify($actual, $usuario['password'])) {
            throw new Exception("Contraseña actual incorrecta");
        }

        // Actualizar hash y password_plain
        $stmt = $pdo->prepare("UPDATE usuarios 
                               SET password = ?, password_plain = ? 
                               WHERE id = ?");
        $stmt->execute([
            hashPassword($nueva),
            $nueva,
            $_SESSION['user_id']
        ]);

        registrarLog($pdo, 'password_cambiada',
            "Administrador cambió su propia contraseña",
            'usuarios', 'info');

        $message = "✅ Contraseña actualizada correctamente";

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-key"></i> <?php echo $titulo; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="dashboard.php">Dashboard</a>
                </li>
                <li class="breadcrumb-item active">Cambiar Contraseña</li>
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

<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0">
                    <i class="fas fa-user-shield"></i>
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                    — Administrador
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">

                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-lock"></i> Contraseña Actual *
                        </label>
                        <div class="input-group">
                            <input type="password" class="form-control"
                                   name="contrasena_actual" id="pass_actual" required>
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="toggleVer('pass_actual', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-key"></i> Nueva Contraseña *
                        </label>
                        <div class="input-group">
                            <input type="password" class="form-control"
                                   name="contrasena_nueva" id="pass_nueva"
                                   required minlength="6"
                                   oninput="verificarCoincidencia()">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="toggleVer('pass_nueva', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Mínimo 6 caracteres</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">
                            <i class="fas fa-check-double"></i> Confirmar Contraseña *
                        </label>
                        <div class="input-group">
                            <input type="password" class="form-control"
                                   name="confirmar_contrasena" id="pass_confirmar"
                                   required
                                   oninput="verificarCoincidencia()">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="toggleVer('pass_confirmar', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div id="msg_coincidencia" class="mt-1 small" style="display:none;"></div>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="dashboard_admin.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Volver
                        </a>
                        <button type="submit" class="btn btn-dark" id="btnGuardar">
                            <i class="fas fa-save"></i> Cambiar Contraseña
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleVer(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

function verificarCoincidencia() {
    const nueva     = document.getElementById('pass_nueva').value;
    const confirmar = document.getElementById('pass_confirmar').value;
    const msg       = document.getElementById('msg_coincidencia');
    const btn       = document.getElementById('btnGuardar');

    if (!confirmar) { msg.style.display = 'none'; return; }

    if (nueva === confirmar) {
        msg.innerHTML  = '<i class="fas fa-check text-success"></i> Las contraseñas coinciden';
        msg.className  = 'mt-1 small text-success';
        msg.style.display = 'block';
        btn.disabled   = false;
    } else {
        msg.innerHTML  = '<i class="fas fa-times text-danger"></i> Las contraseñas no coinciden';
        msg.className  = 'mt-1 small text-danger';
        msg.style.display = 'block';
        btn.disabled   = true;
    }
}
</script>

<?php include '../includes/footer.php'; ?>