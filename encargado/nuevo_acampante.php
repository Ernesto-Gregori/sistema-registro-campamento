<?php
// encargado/nuevo_acampante.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarAccesoEncargado();

$grupo_id = obtenerGrupoEncargado();
$error = '';

$stmt = $pdo->prepare("
    SELECT g.*, s.costo_campamento
    FROM grupos_campamento g
    LEFT JOIN semanas_campamento s ON s.id = g.semana_id
    WHERE g.id = ?
");
$stmt->execute([$grupo_id]);
$grupo = $stmt->fetch();
if (!$grupo) { header('Location: logout.php'); exit(); }

if ($_POST) {
    try {
        $curp = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($_POST['curp'] ?? '')));
        if (strlen($curp) > 18) $curp = substr($curp, 0, 18);
        if (strlen($curp) < 10) $curp = '';

        $nombre = trim($_POST['nombre'] ?? '');
        if (empty($nombre)) throw new Exception("El nombre es obligatorio");

        $costo = (float)$_POST['costo_total'];
        if ($costo <= 0) $costo = $grupo['costo_por_persona'] > 0
            ? $grupo['costo_por_persona']
            : (float)$grupo['costo_campamento'];

        $year = obtenerAnioCampamento();
        $pdo->prepare("
            INSERT INTO acampantes
                (nombre, curp, edad, sexo, iglesia,
                 asiste_iglesia, primera_vez_campamento,
                 contacto_emergencia_nombre, contacto_emergencia_telefono,
                 semana_id, grupo_id, year_campamento, costo_total,
                 estado, registrado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'activo',NULL)
        ")->execute([
            $nombre, $curp ?: null,
            is_numeric($_POST['edad']) ? (int)$_POST['edad'] : null,
            $_POST['sexo'] ?? '',
            trim($_POST['iglesia'] ?? ''),
            isset($_POST['asiste_iglesia']) ? 1 : 0,
            isset($_POST['primera_vez']) ? 1 : 0,
            trim($_POST['cont_nombre'] ?? ''),
            trim($_POST['cont_telefono'] ?? ''),
            $grupo['semana_id'], $grupo_id, $year, $costo
        ]);

        registrarLog($pdo, 'encargado_acampante_agregado',
            "Acampante '{$nombre}' agregado al grupo {$grupo_id} por el encargado",
            'encargado', 'success');

        $_SESSION['mensaje_exito'] = "✅ Acampante '{$nombre}' agregado correctamente.";
        header('Location: panel.php');
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-user-plus"></i> Agregar Acampante</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="panel.php">Mi Grupo</a></li>
                <li class="breadcrumb-item active">Agregar Acampante</li>
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
    <div class="card-header bg-success text-white">
        <h6 class="mb-0"><i class="fas fa-user-plus"></i> Datos del Acampante</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label fw-bold">Nombre completo *</label>
                <input type="text" class="form-control" name="nombre" required autofocus
                       value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Edad</label>
                <input type="number" class="form-control" name="edad" min="1" max="99"
                       value="<?= $_POST['edad'] ?? '' ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Sexo</label>
                <select class="form-select" name="sexo">
                    <option value="">—</option>
                    <option value="masculino">Masculino</option>
                    <option value="femenino">Femenino</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">CURP</label>
                <input type="text" class="form-control font-monospace text-uppercase"
                       name="curp" maxlength="18"
                       value="<?= htmlspecialchars($_POST['curp'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Costo individual</label>
                <div class="input-group">
                    <span class="input-group-text">$</span>
                    <input type="number" class="form-control" name="costo_total"
                           step="0.01" min="0"
                           value="<?= $_POST['costo_total'] ?? $grupo['costo_por_persona'] ?>">
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Iglesia</label>
                <input type="text" class="form-control" name="iglesia"
                       value="<?= htmlspecialchars($_POST['iglesia'] ?? $grupo['encargado_nombre']) ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end mb-1">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="asiste_iglesia" id="asiste_ig" checked>
                    <label class="form-check-label" for="asiste_ig">Asiste a iglesia</label>
                </div>
            </div>
            <div class="col-md-3 d-flex align-items-end mb-1">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="primera_vez" id="primera_vez">
                    <label class="form-check-label" for="primera_vez">Primera vez</label>
                </div>
            </div>
            <div class="col-12"><hr class="my-1"></div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Contacto de emergencia</label>
                <input type="text" class="form-control" name="cont_nombre"
                       value="<?= htmlspecialchars($_POST['cont_nombre'] ?? '') ?>" placeholder="Nombre">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Teléfono emergencia</label>
                <input type="text" class="form-control" name="cont_telefono"
                       value="<?= htmlspecialchars($_POST['cont_telefono'] ?? '') ?>">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex gap-2 justify-content-end">
        <a href="panel.php" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-success">
            <i class="fas fa-save"></i> Agregar al Grupo
        </button>
    </div>
</div>
</form>
</div>
</div>

<?php include '../includes/footer.php'; ?>
