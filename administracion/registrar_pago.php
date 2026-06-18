<?php
// administracion/registrar_pago.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministracion() && !esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$id        = (int)($_GET['id'] ?? 0);
$semana_id = (int)($_GET['semana_id'] ?? 0);
$error     = '';
$message   = '';

if (!$id) {
    header("Location: lista_pagos.php?semana_id=$semana_id");
    exit();
}

// Datos del acampante + saldo
$stmt = $pdo->prepare("
    SELECT a.*,
           COALESCE(SUM(p.monto), 0)               AS pagado,
           a.costo_total - COALESCE(SUM(p.monto),0) AS saldo
    FROM acampantes a
    LEFT JOIN pagos_acampante p ON p.acampante_id = a.id
    WHERE a.id = ? AND a.estado = 'activo'
    GROUP BY a.id
");
$stmt->execute([$id]);
$acampante = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$acampante || !$acampante['documentos_revisados']) {
    header("Location: lista_pagos.php?semana_id=$semana_id&error=" 
        . urlencode("Acampante no encontrado o sin documentos revisados"));
    exit();
}

$saldo  = max(0, (float)$acampante['saldo']);
$pagado = (float)$acampante['pagado'];
$costo  = (float)$acampante['costo_total'];

// ── POST: registrar pago + check-in ─────────────────────────────────────────
if ($_POST) {
    try {
        $monto     = (float)($_POST['monto'] ?? 0);
        $modo      = $_POST['modo_pago'] ?? 'efectivo';
        $notas     = trim($_POST['notas'] ?? '');
        $hacer_checkin = isset($_POST['hacer_checkin']);

        $modos_validos = ['efectivo', 'banco', 'transferencia'];
        if (!in_array($modo, $modos_validos)) throw new Exception("Modo de pago inválido");

        $pdo->beginTransaction();

        // Registrar pago si hay monto
        if ($monto > 0) {
            if ($monto > $saldo + 0.01) {
                throw new Exception("El monto ($" . number_format($monto, 2) 
                    . ") supera el saldo pendiente ($" . number_format($saldo, 2) . ")");
            }
            $pdo->prepare("
                INSERT INTO pagos_acampante
                    (acampante_id, monto, modo_pago, notas, registrado_por, fecha_pago)
                VALUES (?, ?, ?, ?, ?, NOW())
            ")->execute([$id, $monto, $modo, $notas, $_SESSION['user_id']]);

            // Recalcular saldo después del pago
            $stmt2 = $pdo->prepare("
                SELECT a.costo_total - COALESCE(SUM(p.monto), 0) AS saldo_nuevo
                FROM acampantes a
                LEFT JOIN pagos_acampante p ON p.acampante_id = a.id
                WHERE a.id = ? GROUP BY a.id
            ");
            $stmt2->execute([$id]);
            $saldo_nuevo = (float)$stmt2->fetchColumn();
        } else {
            $saldo_nuevo = $saldo;
        }

        // Check-in: si pago completo O es beca O se marcó manualmente
        $puede_checkin = ($costo == 0) || ($saldo_nuevo <= 0.01) || $hacer_checkin;

        if ($puede_checkin) {
            $pdo->prepare("
                UPDATE acampantes 
                SET llego = 1, fecha_llegada = NOW()
                WHERE id = ? AND llego = 0
            ")->execute([$id]);
        }

        $pdo->commit();

        registrarLog($pdo, 'pago_registrado',
            "Pago $" . number_format($monto, 2) 
            . " ({$modo}) para '{$acampante['nombre']}'"
            . ($puede_checkin ? " + check-in automático" : ""),
            'administracion', 'success'
        );

        $msg = $monto > 0 ? "✅ Pago de $" . number_format($monto, 2) . " registrado" : "✅ Check-in registrado";
        if ($puede_checkin) $msg .= " · Check-in activado";

        header("Location: lista_pagos.php?semana_id=$semana_id&message=" . urlencode($msg));
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$base_path = '../';
include '../includes/header.php';
?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col">
        <h1 class="mb-1"><i class="fas fa-dollar-sign"></i> Registrar Pago</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="lista_pagos.php?semana_id=<?= $semana_id ?>">Caja</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($acampante['nombre']) ?></li>
            </ol>
        </nav>
    </div>
</div>

<div class="row g-4">
    <!-- Datos del acampante -->
    <div class="col-md-5">
        <div class="card mb-3">
            <div class="card-header bg-dark text-white">
                <h6 class="mb-0"><i class="fas fa-user"></i> Acampante</h6>
            </div>
            <div class="card-body">
                <h5 class="fw-bold"><?= htmlspecialchars($acampante['nombre']) ?></h5>
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><th class="text-muted" width="120">Edad</th>
                        <td><?= $acampante['edad'] ?? '—' ?></td></tr>
                    <tr><th class="text-muted">Sexo</th>
                        <td><?= $acampante['sexo'] === 'masculino' ? '♂ Masculino' : '♀ Femenino' ?></td></tr>
                    <tr><th class="text-muted">Iglesia</th>
                        <td><?= htmlspecialchars($acampante['iglesia'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Contacto</th>
                        <td><?= htmlspecialchars($acampante['contacto'] ?? '—') ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Estado de pago -->
        <div class="card">
            <div class="card-header bg-<?= $saldo <= 0 ? 'success' : 'warning text-dark' ?>">
                <h6 class="mb-0 <?= $saldo <= 0 ? 'text-white' : '' ?>">
                    <i class="fas fa-receipt"></i> Estado de Pago
                </h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Costo total:</span>
                    <span class="fw-bold">
                        <?= $costo == 0
                            ? '<span class="badge bg-info">Beca</span>'
                            : '$' . number_format($costo, 2) ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Ya pagado:</span>
                    <span class="fw-bold text-success">$<?= number_format($pagado, 2) ?></span>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between">
                    <span class="fw-bold">Saldo pendiente:</span>
                    <span class="fw-bold fs-5 <?= $saldo <= 0 ? 'text-success' : 'text-danger' ?>">
                        $<?= number_format($saldo, 2) ?>
                    </span>
                </div>
                <?php if ($costo > 0 && $saldo > 0): ?>
                <div class="progress mt-2" style="height:8px;">
                    <div class="progress-bar bg-success" 
                         style="width:<?= min(100, round($pagado/$costo*100)) ?>%"></div>
                </div>
                <small class="text-muted"><?= round($pagado/$costo*100) ?>% pagado</small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Formulario de pago -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h6 class="mb-0"><i class="fas fa-cash-register"></i> Registrar Pago</h6>
            </div>
            <div class="card-body">
                <?php if ($costo == 0 || $saldo <= 0): ?>
                <!-- Beca o ya pagado: solo check-in -->
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= $costo == 0 ? 'Este acampante tiene beca completa.' : 'El pago está completo.' ?>
                    Al confirmar se activará el check-in.
                </div>
                <form method="POST">
                    <input type="hidden" name="monto" value="0">
                    <input type="hidden" name="modo_pago" value="efectivo">
                    <input type="hidden" name="hacer_checkin" value="1">
                    <div class="d-flex gap-2">
                        <a href="lista_pagos.php?semana_id=<?= $semana_id ?>"
                           class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Volver
                        </a>
                        <button type="submit" class="btn btn-success btn-lg flex-grow-1">
                            <i class="fas fa-sign-in-alt"></i> Confirmar Check-in
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <!-- Tiene saldo pendiente -->
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Monto a cobrar *</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text fw-bold">$</span>
                            <input type="number" class="form-control form-control-lg fw-bold"
                                   name="monto" step="0.01" min="0.01"
                                   max="<?= $saldo ?>"
                                   value="<?= number_format($saldo, 2, '.', '') ?>"
                                   required>
                        </div>
                        <small class="text-muted">Saldo pendiente: <strong>$<?= number_format($saldo, 2) ?></strong></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Modo de pago *</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php
                            $modos_btn = [
                                'efectivo'      => ['icon' => 'fas fa-money-bill', 'label' => 'Efectivo',      'color' => 'success'],
                                'banco'         => ['icon' => 'fas fa-university', 'label' => 'Banco',         'color' => 'primary'],
                                'transferencia' => ['icon' => 'fas fa-exchange-alt','label'=> 'Transferencia', 'color' => 'info'],
                            ];
                            foreach ($modos_btn as $val => $btn): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio"
                                       name="modo_pago" id="modo_<?= $val ?>"
                                       value="<?= $val ?>"
                                       <?= $val === 'efectivo' ? 'checked' : '' ?>>
                                <label class="form-check-label btn btn-outline-<?= $btn['color'] ?> btn-sm"
                                       for="modo_<?= $val ?>">
                                    <i class="<?= $btn['icon'] ?>"></i> <?= $btn['label'] ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notas <small class="text-muted">(opcional)</small></label>
                        <input type="text" class="form-control" name="notas"
                               placeholder="Referencia, observación...">
                    </div>
                    <div class="d-flex gap-2">
                        <a href="lista_pagos.php?semana_id=<?= $semana_id ?>"
                           class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Volver
                        </a>
                        <button type="submit" class="btn btn-warning btn-lg flex-grow-1">
                            <i class="fas fa-cash-register"></i> Registrar Pago y Check-in
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>