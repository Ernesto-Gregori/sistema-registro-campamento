<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador() && !esEncargadoConsejeros()) {
    header('Location: ../login.php');
    exit();
}

$titulo    = "Gestión de Pagos";
$id        = (int)($_GET['id'] ?? 0);
$semana_id = $_GET['semana_id'] ?? null;
$error     = '';
$message   = '';

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

// Procesar nuevo abono
if ($_POST && isset($_POST['accion']) && $_POST['accion'] === 'agregar_pago') {
    try {
        $monto     = (float)($_POST['monto'] ?? 0);
        $modo      = $_POST['modo_pago'] ?? 'efectivo';
        $notas     = trim($_POST['notas'] ?? '');
        $fecha_pago = $_POST['fecha_pago'] ?? date('Y-m-d H:i:s');

        if ($monto <= 0) throw new Exception("El monto debe ser mayor a 0");

        // Verificar que no supere el saldo pendiente
        $saldo_info = calcularSaldoAcampante($pdo, $id);
        if ($monto > $saldo_info['saldo'] + 0.01) {
            throw new Exception("El monto ($" . number_format($monto, 2) . ") supera el saldo pendiente ($" . number_format($saldo_info['saldo'], 2) . ")");
        }

        $pdo->prepare("
            INSERT INTO pagos_acampante 
                (acampante_id, monto, modo_pago, notas, registrado_por, fecha_pago)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$id, $monto, $modo, $notas, $_SESSION['user_id'], $fecha_pago]);

        registrarLog($pdo, 'pago_registrado',
            "Pago \${$monto} para {$acampante['nombre']}",
            'admisiones', 'success');

        $message = "✅ Pago de $" . number_format($monto, 2) . " registrado correctamente";

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Eliminar pago
if (isset($_GET['accion']) && $_GET['accion'] === 'eliminar_pago' && isset($_GET['pago_id'])) {
    try {
        $pago_id = (int)$_GET['pago_id'];
        $pdo->prepare("DELETE FROM pagos_acampante WHERE id = ? AND acampante_id = ?")
            ->execute([$pago_id, $id]);
        $message = "✅ Pago eliminado";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Obtener pagos del acampante
$stmt = $pdo->prepare("
    SELECT p.*, u.username AS registrado_por_nombre
    FROM pagos_acampante p
    LEFT JOIN usuarios u ON u.id = p.registrado_por
    WHERE p.acampante_id = ?
    ORDER BY p.fecha_pago DESC
");
$stmt->execute([$id]);
$pagos = $stmt->fetchAll();

// Calcular saldo actualizado
$saldo_info = calcularSaldoAcampante($pdo, $id);

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-dollar-sign"></i> <?php echo $titulo; ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item">
                        <a href="lista_acampantes.php?semana_id=<?php echo $semana_id; ?>">Lista</a>
                    </li>
                    <li class="breadcrumb-item active">Pagos</li>
                </ol>
            </nav>
        </div>
        <a href="lista_acampantes.php?semana_id=<?php echo $semana_id; ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
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

<div class="row g-4">

    <!-- Columna izquierda — Info acampante + saldo -->
    <div class="col-md-4">

        <!-- Tarjeta acampante -->
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="fw-bold mb-1">
                    <?php echo htmlspecialchars($acampante['nombre']); ?>
                </h5>
                <div class="text-muted small mb-3">
                    <?php echo $acampante['sexo'] === 'masculino' ? '♂ Masculino' : '♀ Femenino'; ?>
                    · <?php echo $acampante['edad']; ?> años
                    <?php if ($acampante['iglesia']): ?>
                    · <?php echo htmlspecialchars($acampante['iglesia']); ?>
                    <?php endif; ?>
                </div>

                <!-- Saldo visual -->
                <?php
                $pct = $saldo_info['costo_total'] > 0
                    ? min(100, round($saldo_info['total_pagado'] / $saldo_info['costo_total'] * 100))
                    : 0;
                $color = $saldo_info['pagado_100'] ? 'success' :
                         ($saldo_info['total_pagado'] > 0 ? 'warning' : 'danger');
                ?>
                <div class="progress mb-2" style="height:10px;">
                    <div class="progress-bar bg-<?php echo $color; ?>"
                         style="width:<?php echo $pct; ?>%"></div>
                </div>

                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted">Costo total:</span>
                    <span class="fw-bold">$<?php echo number_format($saldo_info['costo_total'], 2); ?></span>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted">Total pagado:</span>
                    <span class="fw-bold text-success">$<?php echo number_format($saldo_info['total_pagado'], 2); ?></span>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between">
                    <span class="fw-bold">Saldo pendiente:</span>
                    <span class="fw-bold text-<?php echo $color; ?> fs-5">
                        $<?php echo number_format($saldo_info['saldo'], 2); ?>
                    </span>
                </div>

                <?php if ($saldo_info['pagado_100']): ?>
                <div class="alert alert-success mt-3 mb-0 py-2 text-center small">
                    <i class="fas fa-check-circle"></i> <strong>¡Pago completo!</strong>
                    <?php if (!$acampante['llego']): ?>
                    <br>Listo para check-in.
                    <a href="checkin.php?accion=confirmar&id=<?php echo $id; ?>&semana_id=<?php echo $semana_id; ?>"
                       class="btn btn-success btn-sm w-100 mt-2">
                        <i class="fas fa-qrcode"></i> Hacer Check-in
                    </a>
                    <?php else: ?>
                    <br><i class="fas fa-id-badge"></i> Ya realizó check-in.
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario nuevo pago -->
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
                                   step="0.01" min="0.01"
                                   max="<?php echo $saldo_info['saldo']; ?>"
                                   placeholder="0.00" required>
                        </div>
                        <small class="text-muted">
                            Saldo pendiente: $<?php echo number_format($saldo_info['saldo'], 2); ?>
                        </small>
                        <!-- Botón pago completo -->
                        <button type="button" class="btn btn-outline-success btn-sm w-100 mt-2"
                                onclick="document.querySelector('input[name=monto]').value = '<?php echo $saldo_info['saldo']; ?>'">
                            <i class="fas fa-check"></i> Pagar saldo completo
                            ($<?php echo number_format($saldo_info['saldo'], 2); ?>)
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
                               value="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Notas</label>
                        <input type="text" class="form-control" name="notas"
                               placeholder="Referencia, comprobante, etc.">
                    </div>

                    <button type="submit" class="btn btn-success w-100">
                        <i class="fas fa-save"></i> Registrar Pago
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Columna derecha — Historial de pagos -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fas fa-history"></i> Historial de Pagos
                    <span class="badge bg-secondary ms-1"><?php echo count($pagos); ?></span>
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
                                <?php echo date('d/m/Y H:i', strtotime($p['fecha_pago'])); ?>
                            </td>
                            <td>
                                <span class="fw-bold text-success fs-6">
                                    $<?php echo number_format($p['monto'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $iconos = [
                                    'efectivo'     => '💵',
                                    'banco'        => '🏦',
                                    'transferencia'=> '📱'
                                ];
                                echo ($iconos[$p['modo_pago']] ?? '') . ' ' .
                                     ucfirst($p['modo_pago']);
                                ?>
                            </td>
                            <td>
                                <?php if ($p['es_pago_registro']): ?>
                                <span class="badge bg-primary">Al inscribir</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Abono</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?php echo htmlspecialchars($p['notas'] ?? '—'); ?>
                            </td>
                            <td class="small text-muted">
                                <?php echo htmlspecialchars($p['registrado_por_nombre'] ?? '—'); ?>
                            </td>
                            <td>
                                <?php if (!$p['es_pago_registro'] || esAdministrador()): ?>
                                <a href="?id=<?php echo $id; ?>&semana_id=<?php echo $semana_id; ?>&accion=eliminar_pago&pago_id=<?php echo $p['id']; ?>"
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
                                    $<?php echo number_format($saldo_info['total_pagado'], 2); ?>
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

<?php include '../includes/footer.php'; ?>