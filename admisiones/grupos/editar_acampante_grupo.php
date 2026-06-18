<?php
// admisiones/grupos/editar_acampante_grupo.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador()) {
    header('Location: ../../login.php'); exit();
}

$id       = (int)($_GET['id'] ?? 0);
$grupo_id = (int)($_GET['grupo_id'] ?? 0);
$error    = '';

if (!$id || !$grupo_id) { header('Location: lista_grupos.php'); exit(); }

$stmt = $pdo->prepare("SELECT * FROM acampantes WHERE id = ? AND grupo_id = ?");
$stmt->execute([$id, $grupo_id]);
$ac = $stmt->fetch();
if (!$ac) { header("Location: ver_grupo.php?id={$grupo_id}"); exit(); }

if ($_POST) {
    try {
        $curp_raw = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($_POST['curp'] ?? '')));
        if (strlen($curp_raw) > 18) $curp_raw = substr($curp_raw, 0, 18);
        if (strlen($curp_raw) < 10) $curp_raw = '';

        $pdo->prepare("
            UPDATE acampantes SET
                nombre             = ?,
                edad               = ?,
                sexo               = ?,
                curp               = ?,
                iglesia            = ?,
                asiste_iglesia     = ?,
                primera_vez_campamento       = ?,
                contacto_emergencia_nombre   = ?,
                contacto_emergencia_telefono = ?,
                costo_total        = ?
            WHERE id = ? AND grupo_id = ?
        ")->execute([
            trim($_POST['nombre']),
            is_numeric($_POST['edad']) ? (int)$_POST['edad'] : null,
            $_POST['sexo'] ?? '',
            $curp_raw ?: null,
            trim($_POST['iglesia'] ?? ''),
            isset($_POST['asiste_iglesia']) ? 1 : 0,
            isset($_POST['primera_vez']) ? 1 : 0,
            trim($_POST['cont_nombre'] ?? ''),
            trim($_POST['cont_telefono'] ?? ''),
            (float)$_POST['costo_total'],
            $id, $grupo_id
        ]);

        registrarLog($pdo, 'acampante_grupo_editado',
            "Acampante ID {$id} del grupo {$grupo_id} editado",
            'admisiones', 'success');

        header("Location: ver_grupo.php?id={$grupo_id}&message=" .
            urlencode("✅ Acampante actualizado"));
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$base_path = '../';
include '../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-user-edit"></i> Editar Acampante (Grupo)</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="lista_grupos.php">Grupos</a></li>
                <li class="breadcrumb-item">
                    <a href="ver_grupo.php?id=<?= $grupo_id ?>">Ver Grupo</a>
                </li>
                <li class="breadcrumb-item active">Editar Acampante</li>
            </ol>
        </nav>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-md-8">
<form method="POST">
<div class="card">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0">
            <i class="fas fa-user"></i>
            <?= htmlspecialchars($ac['nombre']) ?>
        </h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label fw-bold">Nombre completo *</label>
                <input type="text" class="form-control" name="nombre" required
                       value="<?= htmlspecialchars($ac['nombre']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Edad</label>
                <input type="number" class="form-control" name="edad"
                       min="1" max="99" value="<?= $ac['edad'] ?? '' ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Sexo</label>
                <select class="form-select" name="sexo">
                    <option value="">—</option>
                    <option value="masculino"  <?= $ac['sexo']==='masculino' ?'selected':'' ?>>Masculino</option>
                    <option value="femenino"   <?= $ac['sexo']==='femenino'  ?'selected':'' ?>>Femenino</option>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-bold">CURP</label>
                <input type="text" class="form-control font-monospace text-uppercase"
                       name="curp" maxlength="18"
                       value="<?= htmlspecialchars($ac['curp'] ?? '') ?>"
                       placeholder="18 caracteres">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Costo individual</label>
                <div class="input-group">
                    <span class="input-group-text">$</span>
                    <input type="number" class="form-control" name="costo_total"
                           step="0.01" min="0"
                           value="<?= $ac['costo_total'] ?? 0 ?>">
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-bold">Iglesia</label>
                <input type="text" class="form-control" name="iglesia"
                       value="<?= htmlspecialchars($ac['iglesia'] ?? '') ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end mb-1">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox"
                           name="asiste_iglesia" id="asiste_ig"
                           <?= $ac['asiste_iglesia'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="asiste_ig">
                        Asiste a iglesia
                    </label>
                </div>
            </div>
            <div class="col-md-3 d-flex align-items-end mb-1">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox"
                           name="primera_vez" id="primera_vez"
                           <?= $ac['primera_vez_campamento'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="primera_vez">
                        Primera vez
                    </label>
                </div>
            </div>

            <div class="col-12"><hr class="my-1"></div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Contacto emergencia</label>
                <input type="text" class="form-control" name="cont_nombre"
                       value="<?= htmlspecialchars($ac['contacto_emergencia_nombre'] ?? '') ?>"
                       placeholder="Nombre del contacto">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Teléfono emergencia</label>
                <input type="text" class="form-control" name="cont_telefono"
                       value="<?= htmlspecialchars($ac['contacto_emergencia_telefono'] ?? '') ?>"
                       placeholder="Número">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex gap-2 justify-content-end">
        <a href="ver_grupo.php?id=<?= $grupo_id ?>" class="btn btn-secondary">
            Cancelar
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Guardar Cambios
        </button>
    </div>
</div>
</form>
</div>
</div>

<?php include '../../includes/footer.php'; ?>