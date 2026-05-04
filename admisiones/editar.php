<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador() && !esEncargadoConsejeros()) {
    header('Location: ../login.php');
    exit();
}

$titulo    = "Editar Acampante";
$id        = (int)($_GET['id'] ?? 0);
$semana_id = $_GET['semana_id'] ?? null;
$error     = '';
$message   = '';

if (!$id) { header('Location: lista_acampantes.php'); exit(); }

$stmt = $pdo->prepare("SELECT * FROM acampantes WHERE id = ?");
$stmt->execute([$id]);
$acampante = $stmt->fetch();
if (!$acampante) { header('Location: lista_acampantes.php'); exit(); }

// Semanas
$semanas = $pdo->prepare("SELECT * FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio");
$semanas->execute([obtenerAnioCampamento()]);
$semanas = $semanas->fetchAll();

if ($_POST) {
    try {
        $nombre     = trim($_POST['nombre'] ?? '');
        $edad       = (int)($_POST['edad'] ?? 0);
        $sexo       = $_POST['sexo'] ?? '';
        $iglesia    = trim($_POST['iglesia'] ?? '');
        $asiste     = isset($_POST['asiste_iglesia']) ? 1 : 0;
        $primera    = isset($_POST['primera_vez_campamento']) ? 1 : 0;
        $contacto_n = trim($_POST['contacto_emergencia_nombre']   ?? '');
        $contacto_t = trim($_POST['contacto_emergencia_telefono'] ?? '');
        $alergias   = trim($_POST['alergias_enfermedades'] ?? '');
        $obs        = trim($_POST['observaciones'] ?? '');
        $costo      = (float)($_POST['costo_total'] ?? 0);
        $sid        = (int)($_POST['semana_id'] ?? $acampante['semana_id']);
        $estado     = $_POST['estado'] ?? 'activo';

        if (empty($nombre)) throw new Exception("El nombre es obligatorio");
        if ($edad < 1)      throw new Exception("La edad es obligatoria");
        if (empty($sexo))   throw new Exception("El sexo es obligatorio");

        $pdo->prepare("
            UPDATE acampantes SET
                nombre = ?, edad = ?, sexo = ?, iglesia = ?,
                asiste_iglesia = ?, primera_vez_campamento = ?,
                contacto_emergencia_nombre = ?, contacto_emergencia_telefono = ?,
                alergias_enfermedades = ?, observaciones = ?,
                costo_total = ?, semana_id = ?, estado = ?
            WHERE id = ?
        ")->execute([
            $nombre, $edad, $sexo, $iglesia,
            $asiste, $primera,
            $contacto_n, $contacto_t,
            $alergias, $obs,
            $costo, $sid, $estado, $id
        ]);

        registrarLog($pdo, 'acampante_editado',
            "Edición: {$nombre} (ID {$id})",
            'admisiones', 'info');

        $message = "✅ Datos actualizados correctamente";
        // Recargar el acampante
        $stmt = $pdo->prepare("SELECT * FROM acampantes WHERE id = ?");
        $stmt->execute([$id]);
        $acampante = $stmt->fetch();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$saldo_info = calcularSaldoAcampante($pdo, $id);

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-user-edit"></i> <?php echo $titulo; ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item">
                        <a href="lista_acampantes.php?semana_id=<?php echo $semana_id; ?>">Lista</a>
                    </li>
                    <li class="breadcrumb-item active">Editar</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            <a href="pagos.php?id=<?php echo $id; ?>&semana_id=<?php echo $semana_id; ?>"
               class="btn btn-outline-success btn-sm">
                <i class="fas fa-dollar-sign"></i> Ver Pagos
                <?php if (!$saldo_info['pagado_100']): ?>
                <span class="badge bg-danger ms-1">
                    $<?php echo number_format($saldo_info['saldo'],0); ?>
                </span>
                <?php endif; ?>
            </a>
            <a href="lista_acampantes.php?semana_id=<?php echo $semana_id; ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <?php echo $message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">

    <!-- Datos personales -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-user"></i> Datos Personales</h6>
            </div>
            <div class="card-body">

                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre Completo *</label>
                    <input type="text" class="form-control" name="nombre" required
                           value="<?php echo htmlspecialchars($acampante['nombre']); ?>">
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Edad *</label>
                        <input type="number" class="form-control" name="edad"
                               min="5" max="99" required
                               value="<?php echo $acampante['edad']; ?>">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Sexo *</label>
                        <select class="form-select" name="sexo" required>
                            <option value="masculino" <?php echo $acampante['sexo']==='masculino'?'selected':''; ?>>♂ Masculino</option>
                            <option value="femenino"  <?php echo $acampante['sexo']==='femenino' ?'selected':''; ?>>♀ Femenino</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox"
                                   name="asiste_iglesia" id="asiste_iglesia"
                                   <?php echo $acampante['asiste_iglesia'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="asiste_iglesia">¿Asiste a iglesia?</label>
                        </div>
                    </div>
                    <div class="col-6 mb-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox"
                                   name="primera_vez_campamento" id="primera_vez"
                                   <?php echo $acampante['primera_vez_campamento'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="primera_vez">¿Primera vez?</label>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Iglesia</label>
                    <input type="text" class="form-control" name="iglesia"
                           value="<?php echo htmlspecialchars($acampante['iglesia'] ?? ''); ?>">
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Contacto Emergencia</label>
                        <input type="text" class="form-control" name="contacto_emergencia_nombre"
                               value="<?php echo htmlspecialchars($acampante['contacto_emergencia_nombre'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Teléfono</label>
                        <input type="text" class="form-control" name="contacto_emergencia_telefono"
                               value="<?php echo htmlspecialchars($acampante['contacto_emergencia_telefono'] ?? ''); ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Alergias / Enfermedades</label>
                    <textarea class="form-control" name="alergias_enfermedades" rows="2"><?php
                        echo htmlspecialchars($acampante['alergias_enfermedades'] ?? '');
                    ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Observaciones</label>
                    <textarea class="form-control" name="observaciones" rows="2"><?php
                        echo htmlspecialchars($acampante['observaciones'] ?? '');
                    ?></textarea>
                </div>

            </div>
        </div>
    </div>

    <!-- Columna derecha -->
    <div class="col-md-4">

        <!-- Semana y costo -->
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-calendar-week"></i> Semana y Costo</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Semana</label>
                    <select class="form-select" name="semana_id">
                        <?php foreach ($semanas as $s): ?>
                        <option value="<?php echo $s['id']; ?>"
                                <?php echo $acampante['semana_id'] == $s['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['nombre']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Costo Total</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" class="form-control" name="costo_total"
                               step="0.01" min="0"
                               value="<?php echo $acampante['costo_total']; ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Estado</label>
                    <select class="form-select" name="estado">
                        <option value="activo"   <?php echo $acampante['estado']==='activo'  ?'selected':''; ?>>Activo</option>
                        <option value="inactivo" <?php echo $acampante['estado']==='inactivo'?'selected':''; ?>>Inactivo</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Resumen pago -->
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-dollar-sign"></i> Estado de Pago</h6>
            </div>
            <div class="card-body">
                <?php
                $pct = $saldo_info['costo_total'] > 0
                    ? min(100, round($saldo_info['total_pagado'] / $saldo_info['costo_total'] * 100))
                    : 0;
                $color = $saldo_info['pagado_100'] ? 'success' :
                         ($saldo_info['total_pagado'] > 0 ? 'warning' : 'danger');
                ?>
                <div class="progress mb-2" style="height:8px;">
                    <div class="progress-bar bg-<?php echo $color; ?>"
                         style="width:<?php echo $pct; ?>%"></div>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                    <span>Pagado:</span>
                    <span class="fw-bold text-success">
                        $<?php echo number_format($saldo_info['total_pagado'],2); ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between small">
                    <span>Saldo:</span>
                    <span class="fw-bold text-<?php echo $color; ?>">
                        $<?php echo number_format($saldo_info['saldo'],2); ?>
                    </span>
                </div>
                <a href="pagos.php?id=<?php echo $id; ?>&semana_id=<?php echo $semana_id; ?>"
                   class="btn btn-outline-success btn-sm w-100 mt-3">
                    <i class="fas fa-history"></i> Ver historial de pagos
                </a>
            </div>
        </div>

        <!-- Check-in status -->
        <div class="card mb-3">
            <div class="card-body text-center">
                <?php if ($acampante['llego']): ?>
                <span class="badge bg-success fs-6 px-3 py-2 d-block mb-2">
                    <i class="fas fa-check-circle"></i> Check-in realizado
                </span>
                <small class="text-muted">
                    <?php echo date('d/m/Y H:i', strtotime($acampante['fecha_llegada'])); ?>
                </small>
                <?php else: ?>
                <span class="badge bg-secondary fs-6 px-3 py-2 d-block mb-2">
                    <i class="fas fa-clock"></i> Pendiente de check-in
                </span>
                <?php if ($saldo_info['pagado_100']): ?>
                <a href="checkin.php?accion=confirmar&id=<?php echo $id; ?>&semana_id=<?php echo $semana_id; ?>"
                   class="btn btn-success btn-sm w-100 mt-1"
                   onclick="return confirm('¿Confirmar llegada?')">
                    <i class="fas fa-qrcode"></i> Hacer Check-in
                </a>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Guardar -->
        <div class="d-flex gap-2">
            <a href="lista_acampantes.php?semana_id=<?php echo $semana_id; ?>"
               class="btn btn-secondary flex-grow-1">
                <i class="fas fa-times"></i> Cancelar
            </a>
            <button type="submit" class="btn btn-primary flex-grow-1">
                <i class="fas fa-save"></i> Guardar
            </button>
        </div>
    </div>

</div>
</form>

<?php include '../includes/footer.php'; ?>