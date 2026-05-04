<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esEncargadoConsejeros()) {
    header('Location: ../consejero/dashboard.php');
    exit();
}

$titulo = "Gestión de Usuarios de Apoyo";
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;
$message = '';
$error = '';

// Procesar formulario
if ($_POST) {
    try {
        if ($action === 'add') {
            $username = limpiarDatos($_POST['username']);
            $password = $_POST['password'];
            $confirmar = $_POST['confirmar_password'];
            $genero_acceso = $_POST['genero_acceso'] ?? 'ambos';

            if (empty($username)) throw new Exception("El nombre de usuario es obligatorio");
            if (empty($password)) throw new Exception("La contraseña es obligatoria");
            if (strlen($password) < 6) throw new Exception("La contraseña debe tener al menos 6 caracteres");
            if ($password !== $confirmar) throw new Exception("Las contraseñas no coinciden");
            if (!in_array($genero_acceso, ['masculino', 'femenino', 'ambos']))
                throw new Exception("Género de acceso inválido");

            // Verificar que no exista el usuario
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) throw new Exception("Ya existe un usuario con ese nombre");

            $password_hash = hashPassword($password);

            $stmt = $pdo->prepare("INSERT INTO usuarios (username, password, rol, genero_acceso, activo) 
                                   VALUES (?, ?, 'apoyo', ?, 1)");
            $stmt->execute([$username, $password_hash, $genero_acceso]);

            $message = "Usuario de apoyo creado exitosamente";
            header("Location: usuarios.php?message=" . urlencode($message));
            exit();

        } elseif ($action === 'edit') {
            $username = limpiarDatos($_POST['username']);
            $genero_acceso = $_POST['genero_acceso'] ?? 'ambos';
            $activo = isset($_POST['activo']) ? 1 : 0;

            if (empty($username)) throw new Exception("El nombre de usuario es obligatorio");
            if (!in_array($genero_acceso, ['masculino', 'femenino', 'ambos']))
                throw new Exception("Género de acceso inválido");

            // Verificar que no exista otro usuario con ese nombre
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ? AND id != ?");
            $stmt->execute([$username, $id]);
            if ($stmt->fetch()) throw new Exception("Ya existe otro usuario con ese nombre");

            if (!empty($_POST['password'])) {
                $password = $_POST['password'];
                $confirmar = $_POST['confirmar_password'];
                if (strlen($password) < 6) throw new Exception("La contraseña debe tener al menos 6 caracteres");
                if ($password !== $confirmar) throw new Exception("Las contraseñas no coinciden");
                $password_hash = hashPassword($password);

                $stmt = $pdo->prepare("UPDATE usuarios SET username=?, password=?, genero_acceso=?, activo=? WHERE id=?");
                $stmt->execute([$username, $password_hash, $genero_acceso, $activo, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET username=?, genero_acceso=?, activo=? WHERE id=?");
                $stmt->execute([$username, $genero_acceso, $activo, $id]);
            }

            $message = "Usuario actualizado exitosamente";
            header("Location: usuarios.php?message=" . urlencode($message));
            exit();
        }

    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Eliminar usuario
if ($action === 'delete' && $id) {
    try {
        // No permitir eliminar encargado_consejeros
        $stmt = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if ($u && $u['rol'] === 'encargado_consejeros') {
            throw new Exception("No puedes eliminar un encargado de onsejeros");
        }
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND rol = 'apoyo'");
        $stmt->execute([$id]);
        header("Location: usuarios.php?message=" . urlencode("Usuario eliminado exitosamente"));
        exit();
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Toggle activo
if ($action === 'toggle' && $id) {
    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET activo = NOT activo WHERE id = ? AND rol = 'apoyo'");
        $stmt->execute([$id]);
        header("Location: usuarios.php?message=" . urlencode("Estado actualizado"));
        exit();
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Obtener usuario para editar
$usuario = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? AND rol = 'apoyo'");
    $stmt->execute([$id]);
    $usuario = $stmt->fetch();
    if (!$usuario) {
        $error = "Usuario no encontrado";
        $action = 'list';
    }
}

// Obtener lista de usuarios apoyo
if ($action === 'list') {
    $stmt = $pdo->query("SELECT * FROM usuarios WHERE rol = 'apoyo' ORDER BY username");
    $usuarios = $stmt->fetchAll();
}

if (isset($_GET['message'])) $message = $_GET['message'];

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-users-cog"></i> <?php echo $titulo; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Usuarios de Apoyo</li>
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

<?php if ($action === 'list'): ?>
<!-- Lista -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list"></i> Usuarios de Apoyo (<?php echo count($usuarios); ?>)</h5>
        <a href="usuarios.php?action=add" class="btn btn-success">
            <i class="fas fa-plus"></i> Nuevo Usuario de Apoyo
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($usuarios)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-users fa-3x mb-3"></i>
            <p>No hay usuarios de apoyo creados aún</p>
            <a href="usuarios.php?action=add" class="btn btn-success">
                <i class="fas fa-plus"></i> Crear Primer Usuario
            </a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Usuario</th>
                        <th>Acceso de Género</th>
                        <th>Cabañas que puede ver</th>
                        <th>Estado</th>
                        <th>Creado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td>
                            <strong><i class="fas fa-user"></i> <?php echo htmlspecialchars($u['username']); ?></strong>
                        </td>
                        <td>
                            <?php if ($u['genero_acceso'] === 'masculino'): ?>
                                <span class="badge bg-primary">
                                    <i class="fas fa-mars"></i> Solo Masculino
                                </span>
                            <?php elseif ($u['genero_acceso'] === 'femenino'): ?>
                                <span class="badge bg-danger">
                                    <i class="fas fa-venus"></i> Solo Femenino
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-venus-mars"></i> Ambos géneros
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            // Mostrar qué cabañas puede ver
                            if ($u['genero_acceso'] === 'ambos') {
                                $stmt = $pdo->query("SELECT COUNT(*) as total FROM cabanas WHERE activa = 1");
                            } else {
                                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM cabanas WHERE activa = 1 AND genero = ?");
                                $stmt->execute([$u['genero_acceso']]);
                            }
                            $totalCabs = $stmt->fetch()['total'];
                            ?>
                            <small class="text-muted"><?php echo $totalCabs; ?> cabaña(s) activa(s)</small>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $u['activo'] ? 'success' : 'secondary'; ?>">
                                <?php echo $u['activo'] ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </td>
                        <td><small><?php echo date('d/m/Y', strtotime($u['created_at'])); ?></small></td>
                        <td>
                            <div class="btn-group">
                                <a href="usuarios.php?action=edit&id=<?php echo $u['id']; ?>"
                                   class="btn btn-sm btn-outline-primary" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="usuarios.php?action=toggle&id=<?php echo $u['id']; ?>"
                                   class="btn btn-sm btn-outline-<?php echo $u['activo'] ? 'warning' : 'success'; ?>"
                                   title="<?php echo $u['activo'] ? 'Desactivar' : 'Activar'; ?>"
                                   onclick="return confirm('¿Cambiar estado de este usuario?')">
                                    <i class="fas fa-<?php echo $u['activo'] ? 'pause' : 'play'; ?>"></i>
                                </a>
                                <a href="usuarios.php?action=delete&id=<?php echo $u['id']; ?>"
                                   class="btn btn-sm btn-outline-danger" title="Eliminar"
                                   onclick="return confirm('¿Eliminar este usuario? Esta acción no se puede deshacer.')">
                                    <i class="fas fa-trash"></i>
                                </a>
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

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<!-- Formulario -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-<?php echo $action === 'add' ? 'plus' : 'edit'; ?>"></i>
            <?php echo $action === 'add' ? 'Nuevo Usuario de Apoyo' : 'Editar Usuario: ' . htmlspecialchars($usuario['username']); ?>
        </h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="row">
                <div class="col-md-6">
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-user"></i> Credenciales de Acceso
                    </h6>

                    <div class="mb-3">
                        <label class="form-label"><strong>Nombre de Usuario *</strong></label>
                        <input type="text" class="form-control" name="username" required
                               value="<?php echo htmlspecialchars($usuario['username'] ?? ''); ?>"
                               placeholder="Ej: apoyo_masculino">
                        <small class="text-muted">Este nombre usará para iniciar sesión</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <strong>Contraseña <?php echo $action === 'edit' ? '(dejar vacío para mantener)' : '*'; ?></strong>
                        </label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="password"
                                   id="password"
                                   <?php echo $action === 'add' ? 'required' : ''; ?>
                                   placeholder="Mínimo 6 caracteres">
                            <button class="btn btn-outline-secondary" type="button" id="togglePass">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong>Confirmar Contraseña <?php echo $action === 'add' ? '*' : ''; ?></strong></label>
                        <input type="password" class="form-control" name="confirmar_password"
                               id="confirmar_password"
                               <?php echo $action === 'add' ? 'required' : ''; ?>
                               placeholder="Repite la contraseña">
                    </div>

                    <?php if ($action === 'edit'): ?>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="activo"
                                   id="activo" <?php echo $usuario['activo'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="activo">
                                Usuario activo
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-venus-mars"></i> Permisos de Género
                    </h6>

                    <div class="mb-3">
                        <label class="form-label"><strong>¿Qué género puede registrar? *</strong></label>

                        <div class="row g-3">
                            <!-- Masculino -->
                            <div class="col-12">
                                <div class="form-check card p-3 border-primary <?php echo ($usuario['genero_acceso'] ?? '') === 'masculino' ? 'bg-primary bg-opacity-10' : ''; ?>"
                                     id="card_masculino" style="cursor:pointer;">
                                    <input class="form-check-input" type="radio" name="genero_acceso"
                                           id="genero_masculino" value="masculino"
                                           <?php echo ($usuario['genero_acceso'] ?? '') === 'masculino' ? 'checked' : ''; ?>>
                                    <label class="form-check-label w-100" for="genero_masculino" style="cursor:pointer;">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-mars fa-2x text-primary"></i>
                                            <div>
                                                <strong>Solo Masculino</strong><br>
                                                <small class="text-muted">
                                                    Solo puede ver y registrar en cabañas de varones
                                                </small>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Femenino -->
                            <div class="col-12">
                                <div class="form-check card p-3 border-danger <?php echo ($usuario['genero_acceso'] ?? '') === 'femenino' ? 'bg-danger bg-opacity-10' : ''; ?>"
                                     id="card_femenino" style="cursor:pointer;">
                                    <input class="form-check-input" type="radio" name="genero_acceso"
                                           id="genero_femenino" value="femenino"
                                           <?php echo ($usuario['genero_acceso'] ?? '') === 'femenino' ? 'checked' : ''; ?>>
                                    <label class="form-check-label w-100" for="genero_femenino" style="cursor:pointer;">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-venus fa-2x text-danger"></i>
                                            <div>
                                                <strong>Solo Femenino</strong><br>
                                                <small class="text-muted">
                                                    Solo puede ver y registrar en cabañas de mujeres
                                                </small>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Ambos -->
                            <div class="col-12">
                                <div class="form-check card p-3 border-secondary <?php echo (($usuario['genero_acceso'] ?? 'ambos') === 'ambos') ? 'bg-secondary bg-opacity-10' : ''; ?>"
                                     id="card_ambos" style="cursor:pointer;">
                                    <input class="form-check-input" type="radio" name="genero_acceso"
                                           id="genero_ambos" value="ambos"
                                           <?php echo (($usuario['genero_acceso'] ?? 'ambos') === 'ambos') ? 'checked' : ''; ?>>
                                    <label class="form-check-label w-100" for="genero_ambos" style="cursor:pointer;">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-venus-mars fa-2x text-secondary"></i>
                                            <div>
                                                <strong>Ambos géneros</strong><br>
                                                <small class="text-muted">
                                                    Puede ver y registrar en todas las cabañas
                                                </small>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Vista previa de cabañas accesibles -->
                    <div class="card mt-3 bg-light" id="preview_cabanas">
                        <div class="card-body py-2">
                            <h6 class="mb-2"><i class="fas fa-home"></i> Cabañas accesibles:</h6>
                            <div id="lista_cabanas_preview">
                                <?php
                                $genero_actual = $usuario['genero_acceso'] ?? 'ambos';
                                if ($genero_actual === 'ambos') {
                                    $stmt_prev = $pdo->query("SELECT nombre_cabana, genero FROM cabanas WHERE activa = 1 ORDER BY genero, nombre_cabana");
                                } else {
                                    $stmt_prev = $pdo->prepare("SELECT nombre_cabana, genero FROM cabanas WHERE activa = 1 AND genero = ? ORDER BY nombre_cabana");
                                    $stmt_prev->execute([$genero_actual]);
                                }
                                $cabs_preview = $stmt_prev->fetchAll();
                                foreach ($cabs_preview as $cp):
                                ?>
                                <span class="badge bg-<?php echo $cp['genero'] === 'masculino' ? 'primary' : 'danger'; ?> me-1 mb-1">
                                    <i class="fas fa-<?php echo $cp['genero'] === 'masculino' ? 'mars' : 'venus'; ?>"></i>
                                    <?php echo htmlspecialchars($cp['nombre_cabana']); ?>
                                </span>
                                <?php endforeach; ?>
                                <?php if (empty($cabs_preview)): ?>
                                <small class="text-muted">Sin cabañas activas</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="usuarios.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i>
                    <?php echo $action === 'add' ? 'Crear Usuario' : 'Guardar Cambios'; ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Toggle password
document.getElementById('togglePass')?.addEventListener('click', function() {
    const input = document.getElementById('password');
    const icon = this.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fas fa-eye';
    }
});

// Datos de cabañas para preview
const cabanasPorGenero = <?php
    $stmt_all = $pdo->query("SELECT nombre_cabana, genero FROM cabanas WHERE activa = 1 ORDER BY genero, nombre_cabana");
    $all_cabs = $stmt_all->fetchAll();
    echo json_encode($all_cabs);
?>;

function actualizarPreviewCabanas(genero) {
    const container = document.getElementById('lista_cabanas_preview');
    if (!container) return;

    const filtradas = genero === 'ambos'
        ? cabanasPorGenero
        : cabanasPorGenero.filter(c => c.genero === genero);

    if (filtradas.length === 0) {
        container.innerHTML = '<small class="text-muted">Sin cabañas activas para este género</small>';
        return;
    }

    container.innerHTML = filtradas.map(c => {
        const color = c.genero === 'masculino' ? 'primary' : 'danger';
        const icon = c.genero === 'masculino' ? 'mars' : 'venus';
        return `<span class="badge bg-${color} me-1 mb-1">
                    <i class="fas fa-${icon}"></i> ${c.nombre_cabana}
                </span>`;
    }).join('');
}

// Resaltar card seleccionada
function actualizarCards(genero) {
    document.getElementById('card_masculino')?.classList.remove('bg-primary', 'bg-opacity-10');
    document.getElementById('card_femenino')?.classList.remove('bg-danger', 'bg-opacity-10');
    document.getElementById('card_ambos')?.classList.remove('bg-secondary', 'bg-opacity-10');

    if (genero === 'masculino') {
        document.getElementById('card_masculino')?.classList.add('bg-primary', 'bg-opacity-10');
    } else if (genero === 'femenino') {
        document.getElementById('card_femenino')?.classList.add('bg-danger', 'bg-opacity-10');
    } else {
        document.getElementById('card_ambos')?.classList.add('bg-secondary', 'bg-opacity-10');
    }
}

// Escuchar cambios en radio buttons
document.querySelectorAll('input[name="genero_acceso"]').forEach(radio => {
    radio.addEventListener('change', function() {
        actualizarPreviewCabanas(this.value);
        actualizarCards(this.value);
    });
});
</script>

<?php include '../includes/footer.php'; ?>