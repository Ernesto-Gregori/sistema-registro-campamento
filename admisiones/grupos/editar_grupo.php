<?php
// admisiones/grupos/editar_grupo.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador()) {
    header('Location: ../../login.php'); exit();
}

$titulo    = "Editar grupo";
$id    = (int)($_GET['id'] ?? 0);
$error = '';
if (!$id) { header('Location: lista_grupos.php'); exit(); }

$stmt = $pdo->prepare("SELECT * FROM grupos_campamento WHERE id = ?");
$stmt->execute([$id]);
$grupo = $stmt->fetch();
if (!$grupo) { header('Location: lista_grupos.php'); exit(); }

$year = obtenerAnioCampamento();
$stmt = $pdo->prepare("SELECT * FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio");
$stmt->execute([$year]);
$semanas = $stmt->fetchAll();

if ($_POST) {
    try {
        $pdo->prepare("
            UPDATE grupos_campamento SET
                nombre             = ?,
                encargado_nombre   = ?,
                encargado_telefono = ?,
                encargado_email    = ?,
                semana_id          = ?,
                costo_por_persona  = ?,
                notas              = ?
            WHERE id = ?
        ")->execute([
            trim($_POST['nombre_grupo']),
            trim($_POST['encargado_nombre']),
            trim($_POST['encargado_telefono'] ?? ''),
            trim($_POST['encargado_email'] ?? ''),
            (int)$_POST['semana_id'],
            (float)$_POST['costo_por_persona'],
            trim($_POST['notas'] ?? ''),
            $id
        ]);

        registrarLog($pdo, 'grupo_editado', "Grupo ID {$id} editado", 'admisiones', 'success');
        header("Location: ver_grupo.php?id={$id}&message=" . urlencode("✅ Grupo actualizado"));
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
        <h1><i class="fas fa-edit"></i> Editar Grupo</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="lista_grupos.php">Grupos</a></li>
                <li class="breadcrumb-item">
                    <a href="ver_grupo.php?id=<?= $id ?>">
                        <?= htmlspecialchars($grupo['nombre']) ?>
                    </a>
                </li>
                <li class="breadcrumb-item active">Editar</li>
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
<div class="col-md-7">
<form method="POST">
<div class="card">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0"><i class="fas fa-users-cog"></i> Datos del Grupo</h6>
    </div>
    <div class="card-body">

        <div class="mb-3">
            <label class="form-label fw-bold">Nombre del Grupo *</label>
            <input type="text" class="form-control" name="nombre_grupo" required
                   value="<?= htmlspecialchars($grupo['nombre']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold">Semana *</label>
            <select class="form-select" name="semana_id" required>
                <?php foreach ($semanas as $s): ?>
                <option value="<?= $s['id'] ?>"
                        <?= $s['id'] == $grupo['semana_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold">Costo por persona *</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" class="form-control" name="costo_por_persona"
                       step="0.01" min="0" required
                       value="<?= $grupo['costo_por_persona'] ?>">
            </div>
            <small class="text-warning">
                <i class="fas fa-exclamation-triangle fa-xs"></i>
                Cambiar esto actualiza el cálculo del total esperado del grupo.
            </small>
        </div>

        <hr>
        <h6 class="text-muted mb-3"><i class="fas fa-user-tie"></i> Encargado</h6>

        <div class="mb-3">
            <label class="form-label fw-bold">Nombre *</label>
            <input type="text" class="form-control" name="encargado_nombre" required
                   value="<?= htmlspecialchars($grupo['encargado_nombre']) ?>">
        </div>
        <div class="row">
            <div class="col-6 mb-3">
                <label class="form-label fw-bold">Teléfono</label>
                <input type="text" class="form-control" name="encargado_telefono"
                       value="<?= htmlspecialchars($grupo['encargado_telefono'] ?? '') ?>">
            </div>
            <div class="col-6 mb-3">
                <label class="form-label fw-bold">Email</label>
                <input type="email" class="form-control" name="encargado_email"
                       value="<?= htmlspecialchars($grupo['encargado_email'] ?? '') ?>">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold">Notas</label>
            <textarea class="form-control" name="notas" rows="2">
<?= htmlspecialchars($grupo['notas'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="card-footer d-flex gap-2 justify-content-end">
        <a href="ver_grupo.php?id=<?= $id ?>" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Guardar Cambios
        </button>
    </div>
</div>
</form>
</div>
</div>

<?php include '../../includes/footer.php'; ?>