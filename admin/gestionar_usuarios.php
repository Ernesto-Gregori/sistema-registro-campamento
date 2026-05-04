<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$titulo  = "Gestionar Usuarios";
$message = '';
$error   = '';
$accion  = $_GET['accion'] ?? 'lista';
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Obtener cabañas para el select
$cabanas = $pdo->query("SELECT id, nombre_cabana, genero FROM cabanas 
                        WHERE activa = 1 ORDER BY nombre_cabana")->fetchAll();

// ══════════════════════════════════════════════════════
// PROCESAR ACCIONES POST
// ══════════════════════════════════════════════════════
if ($_POST) {
    $post_accion = $_POST['post_accion'] ?? '';

    try {
        // ── CREAR usuario ──────────────────────────────
        if ($post_accion === 'crear') {
            $username       = trim($_POST['username']);
            $password_plain = trim($_POST['password']);
            $rol            = $_POST['rol'];
            $genero_acceso  = $_POST['genero_acceso'] ?? 'ambos';
            $cabana_id      = !empty($_POST['cabana_id']) ? (int)$_POST['cabana_id'] : null;
            $activo         = isset($_POST['activo']) ? 1 : 0;

            if (empty($username) || empty($password_plain) || empty($rol)) {
                throw new Exception("Username, contraseña y rol son obligatorios");
            }

            // Verificar username único
            $check = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetch()) {
                throw new Exception("El username '$username' ya existe");
            }

            $stmt = $pdo->prepare("INSERT INTO usuarios 
                (username, password, rol, genero_acceso, cabana_id, activo)
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $username,
                hashPassword($password_plain),
                $rol,
                $genero_acceso,
                $cabana_id,
                $activo
            ]);

            registrarLog($pdo, 'usuario_creado',
                "Creó usuario '{$username}' con rol '{$rol}'",
                'usuarios', 'success');
            $message = "✅ Usuario '$username' creado correctamente";
            $accion  = 'lista';
        }

        // ── EDITAR usuario ─────────────────────────────
        if ($post_accion === 'editar') {
            $id            = (int)$_POST['id'];
            $username      = trim($_POST['username']);
            $rol           = $_POST['rol'];
            $genero_acceso = $_POST['genero_acceso'] ?? 'ambos';
            $cabana_id     = !empty($_POST['cabana_id']) ? (int)$_POST['cabana_id'] : null;
            $activo        = isset($_POST['activo']) ? 1 : 0;
            $nueva_pass    = trim($_POST['password'] ?? '');

            if (empty($username) || empty($rol)) {
                throw new Exception("Username y rol son obligatorios");
            }

            // Verificar username único (excluyendo el propio)
            $check = $pdo->prepare("SELECT id FROM usuarios 
                                    WHERE username = ? AND id != ?");
            $check->execute([$username, $id]);
            if ($check->fetch()) {
                throw new Exception("El username '$username' ya está en uso");
            }

            if (!empty($nueva_pass)) {
                $stmt = $pdo->prepare("UPDATE usuarios SET
                    username = ?, password = ?,
                    rol = ?, genero_acceso = ?, cabana_id = ?, activo = ?
                    WHERE id = ?");
                $stmt->execute([
                    $username,
                    hashPassword($nueva_pass),
                    $rol, $genero_acceso, $cabana_id, $activo, $id
                ]);
            } else {
                // Sin cambiar contraseña
                $stmt = $pdo->prepare("UPDATE usuarios SET
                    username = ?, rol = ?, genero_acceso = ?,
                    cabana_id = ?, activo = ?
                    WHERE id = ?");
                $stmt->execute([
                    $username, $rol, $genero_acceso,
                    $cabana_id, $activo, $id
                ]);
            }

            registrarLog($pdo, 'usuario_editado',
                "Editó usuario '{$username}'" . (!empty($nueva_pass) ? ' (contraseña cambiada)' : ''),
                'usuarios', 'info');
            $message = "✅ Usuario '$username' actualizado correctamente";
            $accion  = 'lista';
        }

        // ── ELIMINAR usuario ───────────────────────────
        if ($post_accion === 'eliminar') {
            $id = (int)$_POST['id'];

            // No permitir eliminarse a sí mismo
            if ($id === (int)$_SESSION['user_id']) {
                throw new Exception("No puedes eliminarte a ti mismo");
            }

            $stmt = $pdo->prepare("SELECT username, rol FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            $usr = $stmt->fetch();

            if (!$usr) throw new Exception("Usuario no encontrado");

            registrarLog($pdo, 'usuario_eliminado',
                "Eliminó usuario '{$usr['username']}' (rol: {$usr['rol']})",
                'usuarios', 'warning');
            $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
            $message = "✅ Usuario '{$usr['username']}' eliminado";
            $accion  = 'lista';
        }
        
        // ── RESET contraseña ───────────────────────────
        if ($post_accion === 'reset_password') {
            $id           = (int)$_POST['id'];
            $nueva_pass   = trim($_POST['nueva_password']    ?? '');
            $confirmar    = trim($_POST['confirmar_password'] ?? '');
        
            if (empty($nueva_pass))         throw new Exception("Ingresa la nueva contraseña");
            if (strlen($nueva_pass) < 6)    throw new Exception("Mínimo 6 caracteres");
            if ($nueva_pass !== $confirmar) throw new Exception("Las contraseñas no coinciden");
        
            // Obtener username para el log
            $stmt = $pdo->prepare("SELECT username FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            $usr_reset = $stmt->fetch();
            if (!$usr_reset) throw new Exception("Usuario no encontrado");
        
            $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?")
                ->execute([hashPassword($nueva_pass), $id]);
        
            registrarLog($pdo, 'password_reseteada',
                "Contraseña reseteada para usuario '{$usr_reset['username']}'",
                'usuarios', 'warning');
        
            $message = "✅ Contraseña de '{$usr_reset['username']}' actualizada correctamente";
            $accion  = 'lista';
        }

        // ── TOGGLE activo ──────────────────────────────
        if ($post_accion === 'toggle_activo') {
            $id = (int)$_POST['id'];
            if ($id === (int)$_SESSION['user_id']) {
                throw new Exception("No puedes desactivarte a ti mismo");
            }
            $pdo->prepare("UPDATE usuarios SET activo = NOT activo WHERE id = ?")
                ->execute([$id]);
            $message = "✅ Estado del usuario actualizado";
            $accion  = 'lista';
        }

    } catch (Exception $e) {
        $error  = $e->getMessage();
        $accion = $_POST['post_accion'] === 'crear' ? 'nuevo' : 'editar';
    }
}

// ══════════════════════════════════════════════════════
// CARGAR DATOS PARA VISTAS
// ══════════════════════════════════════════════════════

// Lista de usuarios
$filtro_rol = $_GET['rol'] ?? '';
$sql = "SELECT u.*, c.nombre_cabana 
        FROM usuarios u
        LEFT JOIN cabanas c ON u.cabana_id = c.id";
if ($filtro_rol) {
    $stmt = $pdo->prepare($sql . " WHERE u.rol = ? ORDER BY u.rol, u.username");
    $stmt->execute([$filtro_rol]);
} else {
    $stmt = $pdo->query($sql . " ORDER BY u.rol, u.username");
}
$usuarios = $stmt->fetchAll();

// Usuario a editar
$usuario_edit = null;
if ($accion === 'editar' && $edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$edit_id]);
    $usuario_edit = $stmt->fetch();
    if (!$usuario_edit) {
        $error  = "Usuario no encontrado";
        $accion = 'lista';
    }
}

// Colores y labels por rol
$roles_config = [
    'administrador'       => ['color' => 'danger',  'icon' => 'fa-shield-alt',      'label' => 'Administrador'],
    'encargado_consejeros'=> ['color' => 'primary', 'icon' => 'fa-user-tie',        'label' => 'Encargado Consejeros'],
    'consejero'           => ['color' => 'success', 'icon' => 'fa-user-friends',    'label' => 'Consejero'],
    'apoyo'               => ['color' => 'warning', 'icon' => 'fa-hands-helping',   'label' => 'Apoyo'],
    'admisiones'          => ['color' => 'info',    'icon' => 'fa-clipboard-list',  'label' => 'Admisiones'],
];

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-users-cog"></i> <?php echo $titulo; ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Gestionar Usuarios</li>
                </ol>
            </nav>
        </div>
        <?php if ($accion === 'lista'): ?>
        <a href="?accion=nuevo" class="btn btn-success">
            <i class="fas fa-plus"></i> Nuevo Usuario
        </a>
        <?php else: ?>
        <a href="gestionar_usuarios.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver a la lista
        </a>
        <?php endif; ?>
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


<?php
// ══════════════════════════════════════════════════════
// VISTA: FORMULARIO (CREAR / EDITAR)
// ══════════════════════════════════════════════════════
if ($accion === 'nuevo' || $accion === 'editar'):
    $es_editar  = $accion === 'editar';
    $form_user  = $usuario_edit ?? [];
    $form_title = $es_editar
        ? "Editar Usuario: " . htmlspecialchars($form_user['username'])
        : "Nuevo Usuario";
?>

<div class="card mx-auto" style="max-width:580px;">
    <div class="card-header bg-<?php echo $es_editar ? 'warning' : 'success'; ?> text-white">
        <h5 class="mb-0 font-white">
            <i class="fas fa-<?php echo $es_editar ? 'edit' : 'plus'; ?>"></i>
            <?php echo $form_title; ?>
        </h5>
    </div>
    <div class="card-body">
        <form method="POST" id="formUsuario">
            <input type="hidden" name="post_accion" value="<?php echo $es_editar ? 'editar' : 'crear'; ?>">
            <?php if ($es_editar): ?>
            <input type="hidden" name="id" value="<?php echo $form_user['id']; ?>">
            <?php endif; ?>

            <!-- Username -->
            <div class="mb-3">
                <label class="form-label fw-bold">
                    <i class="fas fa-user"></i> Username *
                </label>
                <input type="text" class="form-control" name="username" required
                       value="<?php echo htmlspecialchars($form_user['username'] ?? $_POST['username'] ?? ''); ?>"
                       placeholder="Ej: consejero_juan">
                <small class="text-muted">Sin espacios ni caracteres especiales</small>
            </div>

            <!-- Contraseña -->
            <div class="mb-3">
                <label class="form-label fw-bold">
                    <i class="fas fa-key"></i>
                    Contraseña <?php echo $es_editar ? '(dejar vacío para no cambiar)' : '*'; ?>
                </label>
                <div class="input-group">
                    <input type="text" class="form-control" name="password"
                           id="campo_password"
                           <?php echo !$es_editar ? 'required' : ''; ?>
                           value="<?php echo htmlspecialchars($form_user['password_plain'] ?? ''); ?>"
                           placeholder="<?php echo $es_editar ? 'Nueva contraseña (opcional)' : 'Contraseña'; ?>">
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="generarPassword()">
                        <i class="fas fa-random"></i> Generar
                    </button>
                </div>
                <small class="text-muted">La contraseña se guarda en texto visible para el admin</small>
            </div>

            <!-- Rol -->
            <div class="mb-3">
                <label class="form-label fw-bold">
                    <i class="fas fa-user-tag"></i> Rol *
                </label>
                <select class="form-select" name="rol" id="campo_rol" required
                        onchange="actualizarCamposRol()">
                    <option value="">-- Seleccionar rol --</option>
                    <?php foreach ($roles_config as $rol_val => $cfg): ?>
                    <option value="<?php echo $rol_val; ?>"
                        <?php echo (($form_user['rol'] ?? $_POST['rol'] ?? '') === $rol_val) ? 'selected' : ''; ?>>
                        <?php echo $cfg['label']; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Género acceso (solo para apoyo) -->
            <div class="mb-3" id="bloque_genero" style="display:none;">
                <label class="form-label fw-bold">
                    <i class="fas fa-venus-mars"></i> Acceso por Género
                </label>
                <select class="form-select" name="genero_acceso">
                    <option value="ambos"
                        <?php echo (($form_user['genero_acceso'] ?? 'ambos') === 'ambos') ? 'selected' : ''; ?>>
                        ⚥ Ambos géneros
                    </option>
                    <option value="masculino"
                        <?php echo (($form_user['genero_acceso'] ?? '') === 'masculino') ? 'selected' : ''; ?>>
                        ♂ Solo Masculino
                    </option>
                    <option value="femenino"
                        <?php echo (($form_user['genero_acceso'] ?? '') === 'femenino') ? 'selected' : ''; ?>>
                        ♀ Solo Femenino
                    </option>
                </select>
                <small class="text-muted">Define qué cabañas puede ver el usuario de Apoyo</small>
            </div>

            <!-- Cabaña (solo para consejero) -->
            <div class="mb-3" id="bloque_cabana" style="display:none;">
                <label class="form-label fw-bold">
                    <i class="fas fa-home"></i> Cabaña Asignada
                </label>
                <select class="form-select" name="cabana_id">
                    <option value="">-- Sin cabaña --</option>
                    <?php foreach ($cabanas as $cab): ?>
                    <option value="<?php echo $cab['id']; ?>"
                        <?php echo (($form_user['cabana_id'] ?? '') == $cab['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cab['nombre_cabana']); ?>
                        (<?php echo $cab['genero'] === 'masculino' ? '♂' : '♀'; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Activo -->
            <div class="mb-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="activo"
                           id="campo_activo" role="switch"
                           <?php echo ($form_user['activo'] ?? 1) ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="campo_activo">
                        Usuario Activo
                    </label>
                </div>
            </div>

            <hr>
            <div class="d-flex justify-content-between">
                <a href="gestionar_usuarios.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancelar
                </a>
                <button type="submit" class="btn btn-<?php echo $es_editar ? 'warning' : 'success'; ?>">
                    <i class="fas fa-save"></i>
                    <?php echo $es_editar ? 'Guardar Cambios' : 'Crear Usuario'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function actualizarCamposRol() {
    const rol        = document.getElementById('campo_rol').value;
    const bloqGenero = document.getElementById('bloque_genero');
    const bloqCabana = document.getElementById('bloque_cabana');

    // Género: solo para apoyo
    bloqGenero.style.display = (rol === 'apoyo') ? 'block' : 'none';
    // Cabaña: solo para consejero
    bloqCabana.style.display = (rol === 'consejero') ? 'block' : 'none';
    // Admisiones y otros roles no necesitan campos extra
}

function generarPassword() {
    const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#';
    let pass = '';
    for (let i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('campo_password').value = pass;
}

// Inicializar al cargar
document.addEventListener('DOMContentLoaded', actualizarCamposRol);
</script>

<?php
// ══════════════════════════════════════════════════════
// VISTA: LISTA DE USUARIOS
// ══════════════════════════════════════════════════════
else:
?>

<!-- Filtros por rol -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-bold text-muted small">Filtrar:</span>
            <a href="gestionar_usuarios.php"
               class="btn btn-sm <?php echo !$filtro_rol ? 'btn-dark' : 'btn-outline-dark'; ?>">
                Todos (<?php echo count($usuarios); ?>)
            </a>
            <?php foreach ($roles_config as $rol_val => $cfg):
                $count = count(array_filter($usuarios, fn($u) => $u['rol'] === $rol_val));
            ?>
            <a href="?rol=<?php echo $rol_val; ?>"
               class="btn btn-sm <?php echo $filtro_rol === $rol_val ? 'btn-'.$cfg['color'] : 'btn-outline-'.$cfg['color']; ?>">
                <i class="fas <?php echo $cfg['icon']; ?>"></i>
                <?php echo $cfg['label']; ?> (<?php echo $count; ?>)
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Tabla de usuarios -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Username</th>
                        <th>Contraseña</th>
                        <th>Rol</th>
                        <th>Cabaña / Género</th>
                        <th>Estado</th>
                        <th>Creado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($usuarios)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        No hay usuarios registrados
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($usuarios as $u):
                    $cfg     = $roles_config[$u['rol']] ?? ['color'=>'secondary','icon'=>'fa-user','label'=>$u['rol']];
                    $esYo    = (int)$u['id'] === (int)$_SESSION['user_id'];
                ?>
                <tr class="<?php echo !$u['activo'] ? 'table-secondary opacity-75' : ''; ?>">
                    <td><small class="text-muted"><?php echo $u['id']; ?></small></td>

                    <td>
                        <strong><?php echo htmlspecialchars($u['username']); ?></strong>
                        <?php if ($esYo): ?>
                        <span class="badge bg-dark ms-1" title="Tu cuenta">Tú</span>
                        <?php endif; ?>
                    </td>

                    <!-- Contraseña — botón reset directo -->
                    <td>
                        <?php if (!$esYo): ?>
                        <button class="btn btn-sm btn-outline-warning"
                                onclick="abrirResetPass(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>')"
                                title="Cambiar contraseña">
                            <i class="fas fa-key"></i>
                        </button>
                        <?php else: ?>
                        <a href="cambiar_password.php" class="btn btn-sm btn-outline-dark" title="Cambiar mi contraseña">
                            <i class="fas fa-key"></i>
                        </a>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span class="badge bg-<?php echo $cfg['color']; ?>">
                            <i class="fas <?php echo $cfg['icon']; ?>"></i>
                            <?php echo $cfg['label']; ?>
                        </span>
                    </td>

                    <td class="small">
                        <?php if ($u['nombre_cabana']): ?>
                            <i class="fas fa-home text-muted"></i>
                            <?php echo htmlspecialchars($u['nombre_cabana']); ?>
                        <?php elseif ($u['genero_acceso'] && $u['genero_acceso'] !== 'ambos'): ?>
                            <?php echo $u['genero_acceso'] === 'masculino' ? '♂ Solo M' : '♀ Solo F'; ?>
                        <?php elseif ($u['genero_acceso'] === 'ambos'): ?>
                            <span class="text-muted">⚥ Ambos</span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if ($u['activo']): ?>
                        <span class="badge bg-success">Activo</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Inactivo</span>
                        <?php endif; ?>
                    </td>

                    <td class="small text-muted">
                        <?php echo date('d/m/Y', strtotime($u['created_at'])); ?>
                    </td>

                    <td>
                        <div class="d-flex gap-1">
                            <!-- Editar -->
                            <a href="?accion=editar&id=<?php echo $u['id']; ?>"
                               class="btn btn-sm btn-outline-warning" title="Editar">
                                <i class="fas fa-edit"></i>
                            </a>

                            <!-- Toggle activo -->
                            <?php if (!$esYo): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="post_accion" value="toggle_activo">
                                <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                <button type="submit"
                                        class="btn btn-sm btn-outline-<?php echo $u['activo'] ? 'secondary' : 'success'; ?>"
                                        title="<?php echo $u['activo'] ? 'Desactivar' : 'Activar'; ?>">
                                    <i class="fas fa-<?php echo $u['activo'] ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                </button>
                            </form>

                            <!-- Eliminar -->
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('¿Eliminar usuario <?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>? Esta acción no se puede deshacer.')">
                                <input type="hidden" name="post_accion" value="eliminar">
                                <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Reset Contraseña -->
<div class="modal fade" id="modalResetPass" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">
                    <i class="fas fa-key"></i> Cambiar Contraseña
                </h5>
                <button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formResetPass">
                <input type="hidden" name="post_accion" value="reset_password">
                <input type="hidden" name="id" id="reset_user_id">
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Usuario: <strong id="reset_username"></strong>
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Nueva Contraseña *</label>
                        <div class="input-group">
                            <input type="password" class="form-control"
                                   name="nueva_password" id="modal_pass"
                                   required minlength="6"
                                   placeholder="Mínimo 6 caracteres">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="toggleModalPass()">
                                <i class="fas fa-eye" id="modal_eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-bold small">Confirmar *</label>
                        <input type="password" class="form-control"
                               name="confirmar_password" id="modal_pass2"
                               required placeholder="Repetir contraseña"
                               oninput="checkModalPass()">
                        <div id="modal_pass_msg" class="small mt-1" style="display:none;"></div>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-1"
                            onclick="generarPasswordModal()">
                        <i class="fas fa-random"></i> Generar contraseña segura
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm"
                            data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning btn-sm" id="btnModalGuardar">
                        <i class="fas fa-save"></i> Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function abrirResetPass(id, username) {
    document.getElementById('reset_user_id').value = id;
    document.getElementById('reset_username').textContent = username;
    document.getElementById('modal_pass').value  = '';
    document.getElementById('modal_pass2').value = '';
    document.getElementById('modal_pass_msg').style.display = 'none';
    document.getElementById('btnModalGuardar').disabled = false;
    new bootstrap.Modal(document.getElementById('modalResetPass')).show();
}

function toggleModalPass() {
    const input = document.getElementById('modal_pass');
    const eye   = document.getElementById('modal_eye');
    if (input.type === 'password') {
        input.type = 'text';
        eye.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        eye.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

function checkModalPass() {
    const p1  = document.getElementById('modal_pass').value;
    const p2  = document.getElementById('modal_pass2').value;
    const msg = document.getElementById('modal_pass_msg');
    const btn = document.getElementById('btnModalGuardar');
    if (!p2) { msg.style.display = 'none'; return; }
    if (p1 === p2) {
        msg.innerHTML = '<i class="fas fa-check text-success"></i> Coinciden';
        msg.className = 'small mt-1 text-success';
        btn.disabled  = false;
    } else {
        msg.innerHTML = '<i class="fas fa-times text-danger"></i> No coinciden';
        msg.className = 'small mt-1 text-danger';
        btn.disabled  = true;
    }
    msg.style.display = 'block';
}

function generarPasswordModal() {
    const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#';
    let pass = '';
    for (let i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById('modal_pass');
    input.type  = 'text';
    input.value = pass;
    document.getElementById('modal_eye').classList.replace('fa-eye', 'fa-eye-slash');
    // Copiar al portapapeles automáticamente
    navigator.clipboard.writeText(pass).then(() => {
        const toast = document.createElement('div');
        toast.className = 'position-fixed bottom-0 end-0 m-3 alert alert-success py-2 px-3 shadow';
        toast.style.zIndex = 9999;
        toast.innerHTML = '<i class="fas fa-check"></i> Contraseña generada y copiada';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2500);
    });
}
</script>

<?php endif; ?>

<?php include '../includes/footer.php'; ?>