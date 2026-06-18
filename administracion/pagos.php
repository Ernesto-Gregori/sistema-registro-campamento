<?php
// admisiones/pagos.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministracion() && !esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$titulo    = "Gestión de Pagos";
$id        = (int)($_GET['id'] ?? 0);
$semana_id = $_GET['semana_id'] ?? null;
$error     = '';
$message   = '';
$checkin_auto = false; // flag para mostrar celebración en UI

if (!$id) {
    header('Location: lista_acampantes.php');
    exit();
}

// Obtener acampante
$stmt = $pdo->prepare("SELECT * FROM acampantes WHERE id = ?");
$stmt->execute([$id]);
$acampante = $stmt->fetch();
if (!$acampante) {
    header('Location: lista_acampantes.php');
    exit();
}

// ── Agregar abono ──────────────────────────────────────────────────────────
if ($_POST && isset($_POST['accion']) && $_POST['accion'] === 'agregar_pago') {
    try {
        $monto      = (float)($_POST['monto'] ?? 0);
        $modo       = $_POST['modo_pago']  ?? 'efectivo';
        $notas      = trim($_POST['notas'] ?? '');
        $fecha_pago = $_POST['fecha_pago'] ?? date('Y-m-d H:i:s');

        if ($monto <= 0) throw new Exception("El monto debe ser mayor a 0");

        // Verificar que no supere el saldo pendiente
        $saldo_info = calcularSaldoAcampante($pdo, $id);
        if ($monto > $saldo_info['saldo'] + 0.01) {
            throw new Exception(
                "El monto ($" . number_format($monto, 2) .
                ") supera el saldo pendiente ($" . number_format($saldo_info['saldo'], 2) . ")"
            );
        }

        $pdo->beginTransaction();

        $pdo->prepare("
            INSERT INTO pagos_acampante
                (acampante_id, monto, modo_pago, notas, registrado_por, fecha_pago)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$id, $monto, $modo, $notas, $_SESSION['user_id'], $fecha_pago]);

        $pdo->commit();

        // ── Check-in automático si el pago quedó completo ─────────────
        // Se llama DESPUÉS del commit para que la suma de pagos sea correcta
        $checkin_auto = verificarYActivarCheckin($pdo, $id);

        registrarLog($pdo, 'pago_registrado',
            "Pago \${$monto} para {$acampante['nombre']} (ID {$id})" .
            ($checkin_auto ? ' | Check-in automático activado' : ''),
            'admisiones', 'success');

        if ($checkin_auto) {
            $message = "✅ Pago de $" . number_format($monto, 2) .
                       " registrado — <strong>¡Pago completo! Check-in activado automáticamente 🎉</strong>";
        } else {
            $message = "✅ Pago de $" . number_format($monto, 2) . " registrado correctamente";
        }

        // Recargar acampante para reflejar llego=1 si se activó
        $stmt = $pdo->prepare("SELECT * FROM acampantes WHERE id = ?");
        $stmt->execute([$id]);
        $acampante = $stmt->fetch();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ── Eliminar pago ──────────────────────────────────────────────────────────
if (isset($_GET['accion']) && $_GET['accion'] === 'eliminar_pago' && isset($_GET['pago_id'])) {
    try {
        $pago_id = (int)$_GET['pago_id'];
        $pdo->prepare("DELETE FROM pagos_acampante WHERE id = ? AND acampante_id = ?")
            ->execute([$pago_id, $id]);
        $message = "✅ Pago eliminado";

        // Recargar acampante
        $stmt = $pdo->prepare("SELECT * FROM acampantes WHERE id = ?");
        $stmt->execute([$id]);
        $acampante = $stmt->fetch();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Obtener historial de pagos
$stmt = $pdo->prepare("
    SELECT p.*, u.username AS registrado_por_nombre
    FROM   pagos_acampante p
    LEFT   JOIN usuarios u ON u.id = p.registrado_por
    WHERE  p.acampante_id = ?
    ORDER  BY p.fecha_pago DESC
");
$stmt->execute([$id]);
$pagos = $stmt->fetchAll();

// Calcular saldo actualizado
$saldo_info = calcularSaldoAcampante($pdo, $id);

include '../includes/header.php';
?>

<!-- ── Cabecera ─────────────────────────────────────────────────────────── -->
<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-dollar-sign"></i> <?= $titulo ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item">
                        <a href="lista_acampantes.php?semana_id=<?= $semana_id ?>">Lista</a>
                    </li>
                    <li class="breadcrumb-item active">Pagos</li>
                </ol>
            </nav>
        </div>
        <a href="lista_acampantes.php?semana_id=<?= $semana_id ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
</div>

<!-- ── Alertas ──────────────────────────────────────────────────────────── -->
<?php if ($message): ?>
<div class="alert alert-<?= $checkin_auto ? 'success border-success' : 'success' ?> alert-dismissible fade show
     <?= $checkin_auto ? 'border-2' : '' ?>">
    <?php if ($checkin_auto): ?>
    <div class="d-flex align-items-center gap-2">
        <span style="font-size:2rem;">🎉</span>
        <div><?= $message ?></div>
    </div>
    <?php else: ?>
    <?= $message ?>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Columna izquierda — Info acampante + saldo + form ───────────── -->
    <div class="col-md-4">

        <!-- Tarjeta acampante -->
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="fw-bold mb-1"><?= htmlspecialchars($acampante['nombre']) ?></h5>
                <div class="text-muted small mb-3">
                    <?= $acampante['sexo'] === 'masculino' ? '♂ Masculino' : '♀ Femenino' ?>
                    · <?= $acampante['edad'] ?> años
                    <?php if ($acampante['iglesia']): ?>
                    · <?= htmlspecialchars($acampante['iglesia']) ?>
                    <?php endif; ?>
                    <?php if (!empty($acampante['curp'])): ?>
                    <br><span class="font-monospace">
                        <i class="fas fa-id-card fa-xs me-1"></i><?= htmlspecialchars($acampante['curp']) ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- Barra de progreso de pago -->
                <?php
                $pct = $saldo_info['costo_total'] > 0
                    ? min(100, round($saldo_info['total_pagado'] / $saldo_info['costo_total'] * 100))
                    : 0;
                $color = $saldo_info['pagado_100'] ? 'success'
                       : ($saldo_info['total_pagado'] > 0 ? 'warning' : 'danger');
                ?>
                <div class="progress mb-2" style="height:10px;">
                    <div class="progress-bar bg-<?= $color ?>"
                         style="width:<?= $pct ?>%"></div>
                </div>
                <div class="text-end small text-muted mb-2"><?= $pct ?>%</div>

                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted">Costo total:</span>
                    <span class="fw-bold">$<?= number_format($saldo_info['costo_total'], 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted">Total pagado:</span>
                    <span class="fw-bold text-success">$<?= number_format($saldo_info['total_pagado'], 2) ?></span>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between">
                    <span class="fw-bold">Saldo pendiente:</span>
                    <span class="fw-bold text-<?= $color ?> fs-5">
                        $<?= number_format(max(0, $saldo_info['saldo']), 2) ?>
                    </span>
                </div>

                <!-- Estado check-in -->
                <?php if ($saldo_info['pagado_100']): ?>
                <div class="alert alert-success mt-3 mb-0 py-2 text-center small">
                    <i class="fas fa-check-circle"></i> <strong>¡Pago completo!</strong>
                    <?php if ($acampante['llego']): ?>
                    <br><i class="fas fa-id-badge"></i>
                    Check-in realizado
                    <?php if ($acampante['fecha_llegada']): ?>
                    <br><small class="text-muted">
                        <?= date('d/m/Y H:i', strtotime($acampante['fecha_llegada'])) ?>
                    </small>
                    <?php endif; ?>
                    <?php else: ?>
                    <!-- Este estado no debería ocurrir con el check-in automático,
                         pero lo dejamos como respaldo manual -->
                    <br><span class="text-warning">Check-in pendiente</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario nuevo abono — solo si hay saldo pendiente -->
        <?php if (!$saldo_info['pagado_100']): ?>
        <div class="card">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0"><i class="fas fa-plus"></i> Registrar Abono</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="agregar_pago">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Monto *</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" name="monto"
                                   id="inputMonto"
                                   step="0.01" min="0.01"
                                   max="<?= $saldo_info['saldo'] ?>"
                                   placeholder="0.00" required>
                        </div>
                        <small class="text-muted">
                            Saldo pendiente: $<?= number_format($saldo_info['saldo'], 2) ?>
                        </small>
                        <!-- Botón pago completo -->
                        <button type="button"
                                class="btn btn-outline-success btn-sm w-100 mt-2"
                                onclick="document.getElementById('inputMonto').value = '<?= number_format($saldo_info['saldo'], 2, '.', '') ?>'">
                            <i class="fas fa-check-double"></i>
                            Pagar saldo completo
                            ($<?= number_format($saldo_info['saldo'], 2) ?>)
                        </button>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Modo de Pago</label>
                        <select class="form-select" name="modo_pago">
                            <option value="efectivo">💵 Efectivo</option>
                            <option value="banco">🏦 Banco</option>
                            <option value="transferencia">📱 Transferencia</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Fecha del pago</label>
                        <input type="datetime-local" class="form-control" name="fecha_pago"
                               value="<?= date('Y-m-d\TH:i') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Notas</label>
                        <input type="text" class="form-control" name="notas"
                               placeholder="Referencia, comprobante, etc.">
                    </div>

                    <!-- Preview del resultado antes de guardar -->
                    <div class="p-2 rounded bg-light border small mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Saldo después del abono:</span>
                            <span class="fw-bold" id="previewSaldo">
                                $<?= number_format($saldo_info['saldo'], 2) ?>
                            </span>
                        </div>
                        <div id="previewCheckin" class="text-success fw-bold d-none mt-1 text-center">
                            <i class="fas fa-magic"></i> ¡Este abono completará el pago y activará el check-in!
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success w-100">
                        <i class="fas fa-save"></i> Registrar Pago
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Columna derecha — Historial de pagos ────────────────────────── -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fas fa-history"></i> Historial de Pagos
                    <span class="badge bg-secondary ms-1"><?= count($pagos) ?></span>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($pagos)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-receipt fa-3x mb-3 opacity-25"></i>
                    <p>No hay pagos registrados aún</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Monto</th>
                                <th>Modo</th>
                                <th>Tipo</th>
                                <th>Notas</th>
                                <th>Registrado por</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pagos as $p): ?>
                        <tr>
                            <td class="small">
                                <?= date('d/m/Y H:i', strtotime($p['fecha_pago'])) ?>
                            </td>
                            <td>
                                <span class="fw-bold text-success fs-6">
                                    $<?= number_format($p['monto'], 2) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $iconos = [
                                    'efectivo'      => '💵',
                                    'banco'         => '🏦',
                                    'transferencia' => '📱',
                                ];
                                echo ($iconos[$p['modo_pago']] ?? '') . ' ' . ucfirst($p['modo_pago']);
                                ?>
                            </td>
                            <td>
                                <?= $p['es_pago_registro']
                                    ? '<span class="badge bg-primary">Al inscribir</span>'
                                    : '<span class="badge bg-secondary">Abono</span>' ?>
                            </td>
                            <td class="small text-muted">
                                <?= htmlspecialchars($p['notas'] ?? '—') ?>
                            </td>
                            <td class="small text-muted">
                                <?= htmlspecialchars($p['registrado_por_nombre'] ?? '—') ?>
                            </td>
                            <td>
                                <?php if (!$p['es_pago_registro'] || esAdministrador()): ?>
                                <a href="?id=<?= $id ?>&semana_id=<?= $semana_id ?>&accion=eliminar_pago&pago_id=<?= $p['id'] ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('¿Eliminar este pago?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td class="fw-bold">Total</td>
                                <td class="fw-bold text-success">
                                    $<?= number_format($saldo_info['total_pagado'], 2) ?>
                                </td>
                                <td colspan="5"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── JS: preview check-in automático ─────────────────────────────────── -->
<script>
const saldoPendiente = <?= json_encode((float)max(0, $saldo_info['saldo'])) ?>;
const inputMonto     = document.getElementById('inputMonto');
const previewSaldo   = document.getElementById('previewSaldo');
const previewCheckin = document.getElementById('previewCheckin');

if (inputMonto) {
    inputMonto.addEventListener('input', () => {
        const abono    = parseFloat(inputMonto.value) || 0;
        const restante = Math.max(0, saldoPendiente - abono);

        previewSaldo.textContent = '$' + restante.toLocaleString('es-MX', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        // Mostrar aviso si este abono completará el pago
        if (abono >= saldoPendiente && abono > 0) {
            previewSaldo.className   = 'fw-bold text-success';
            previewCheckin.classList.remove('d-none');
        } else {
            previewSaldo.className   = 'fw-bold text-danger';
            previewCheckin.classList.add('d-none');
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>