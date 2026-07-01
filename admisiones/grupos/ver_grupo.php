<?php
// admisiones/grupos/ver_grupo.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador() && !esEncargadoConsejeros()) {
    header('Location: ../../login.php'); exit();
}

$id      = (int)($_GET['id'] ?? 0);
$message = $_GET['message'] ?? '';
$error   = '';

// ── Leer mensajes flash de sesión ─────────────────────────────────────────────
if (empty($message) && isset($_SESSION['mensaje_exito'])) {
    $message = $_SESSION['mensaje_exito'];
    unset($_SESSION['mensaje_exito']);
}
if (empty($error) && isset($_SESSION['mensaje_error'])) {
    $error = $_SESSION['mensaje_error'];
    unset($_SESSION['mensaje_error']);
}

// ── Código de acceso recién creado ───────────────────────────────────────────
$codigo_acceso_nuevo = $_SESSION['codigo_acceso_nuevo'] ?? '';
if (!empty($codigo_acceso_nuevo)) {
    unset($_SESSION['codigo_acceso_nuevo']);
}

if (!$id) { header('Location: lista_grupos.php'); exit(); }

$stmt = $pdo->prepare("
    SELECT g.*, s.nombre AS semana_nombre, s.costo_campamento
    FROM grupos_campamento g
    LEFT JOIN semanas_campamento s ON s.id = g.semana_id
    WHERE g.id = ?
");
$stmt->execute([$id]);
$grupo = $stmt->fetch();
if (!$grupo) { header('Location: lista_grupos.php'); exit(); }

// ── Saldo usando SUM(acampantes.costo_total) ──────────────────────────────
// ✅ FIX BECA: costo_total = 0 → pagado_100 = true (beca completa)
function calcularSaldoGrupo(PDO $pdo, int $grupo_id): array {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(id)                        AS total_acampantes,
            COALESCE(SUM(costo_total), 0)    AS costo_total_real
        FROM acampantes
        WHERE grupo_id = ? AND estado = 'activo'
    ");
    $stmt->execute([$grupo_id]);
    $datos = $stmt->fetch();

    $stmt2 = $pdo->prepare("
        SELECT COALESCE(SUM(monto), 0) FROM pagos_grupo WHERE grupo_id = ?
    ");
    $stmt2->execute([$grupo_id]);
    $total_pagado = (float)$stmt2->fetchColumn();

    $costo_total      = (float)$datos['costo_total_real'];
    $total_acampantes = (int)$datos['total_acampantes'];
    $saldo            = max(0, $costo_total - $total_pagado);

    // ✅ FIX: costo=0 (grupo con beca completa) también es "pagado al 100%"
    $pagado_100 = ($costo_total == 0 && $total_acampantes > 0)
               || ($costo_total  > 0 && $total_pagado >= $costo_total);

    return compact('total_acampantes', 'total_pagado', 'costo_total', 'saldo', 'pagado_100');
}

// ── Procesar pago ─────────────────────────────────────────────────────────
if ($_POST && ($_POST['accion'] ?? '') === 'agregar_pago') {
    // ── NUEVO: solo administración y administrador pueden registrar pagos
    if (!esAdministracion() && !esAdministrador()) {
        $error = "Sin permisos para registrar pagos.";
    } else {
        try {
            $monto      = (float)($_POST['monto'] ?? 0);
            $modo       = $_POST['modo_pago'] ?? 'efectivo';
            $notas_p    = trim($_POST['notas'] ?? '');
            $fecha_pago = $_POST['fecha_pago'] ?? date('Y-m-d H:i:s');

            if ($monto <= 0) throw new Exception("El monto debe ser mayor a 0");

            $saldo_info = calcularSaldoGrupo($pdo, $id);
            if ($monto > $saldo_info['saldo'] + 0.01) {
                throw new Exception(
                    "El monto (\$" . number_format($monto, 2) . ") supera el saldo pendiente (\$" .
                    number_format($saldo_info['saldo'], 2) . ")"
                );
            }

            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO pagos_grupo (grupo_id, monto, modo_pago, notas, registrado_por, fecha_pago)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$id, $monto, $modo, $notas_p, $_SESSION['user_id'], $fecha_pago]);
            $pdo->commit();

            registrarLog($pdo, 'pago_grupo',
                "Pago \${$monto} encargado '{$grupo['encargado_nombre']}'",
                'admisiones', 'success');
            $message = "✅ Pago de $" . number_format($monto, 2) . " registrado";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// ── Eliminar pago ─────────────────────────────────────────────────────────
if (isset($_GET['accion']) && $_GET['accion'] === 'eliminar_pago' && isset($_GET['pago_id'])) {
    try {
        $pdo->prepare("DELETE FROM pagos_grupo WHERE id = ? AND grupo_id = ?")
            ->execute([(int)$_GET['pago_id'], $id]);
        $message = "✅ Pago eliminado";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Recargar datos
$stmt = $pdo->prepare("
    SELECT g.*, s.nombre AS semana_nombre
    FROM grupos_campamento g
    LEFT JOIN semanas_campamento s ON s.id = g.semana_id
    WHERE g.id = ?
");
$stmt->execute([$id]);
$grupo = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT * FROM acampantes
    WHERE grupo_id = ? AND estado = 'activo'
    ORDER BY nombre
");
$stmt->execute([$id]);
$acampantes = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT pg.*, u.username AS registrado_por_nombre
    FROM pagos_grupo pg
    LEFT JOIN usuarios u ON u.id = pg.registrado_por
    WHERE pg.grupo_id = ?
    ORDER BY pg.fecha_pago DESC
");
$stmt->execute([$id]);
$pagos = $stmt->fetchAll();

$saldo_info = calcularSaldoGrupo($pdo, $id);

include '../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1>
                <i class="fas fa-users"></i>
                <?= htmlspecialchars($grupo['encargado_nombre']) ?>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="lista_grupos.php">Grupos</a></li>
                    <li class="breadcrumb-item active"><?= htmlspecialchars($grupo['encargado_nombre']) ?></li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            <a href="editar_grupo.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-edit"></i> Editar grupo
            </a>
            <a href="nuevo_acampante_grupo.php?grupo_id=<?= $id ?>"
               class="btn btn-outline-success btn-sm">
                <i class="fas fa-user-plus"></i> Agregar acampante
            </a>
        </div>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show border-2">
    <?= $message ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!empty($codigo_acceso_nuevo) || (!empty($grupo['codigo_acceso']) && (esAdmisiones() || esAdministrador()))): ?>
<?php $codigo_mostrar = $codigo_acceso_nuevo ?: ($grupo['codigo_acceso'] ?? ''); ?>
<div class="alert alert-info alert-dismissible fade show">
    <div class="d-flex align-items-center flex-wrap gap-2">
        <i class="fas fa-key fa-lg"></i>
        <div>
            <strong>Código de acceso para el encargado:</strong>
            <span class="font-monospace fs-5 mx-2"><?= htmlspecialchars($codigo_mostrar) ?></span>
            <div class="small text-muted">
                El encargado puede usar este código junto con su nombre en
                <a href="../../acceso-encargado.php" target="_blank" class="alert-link">acceso-encargado.php</a>
                para ver y gestionar su grupo.
            </div>
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Panel izquierdo ───────────────────────────────────────────── -->
    <div class="col-md-4">

        <!-- Info del grupo -->
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="rounded-circle bg-primary d-flex align-items-center
                                justify-content-center text-white fw-bold"
                         style="width:48px;height:48px;font-size:1.2rem;">
                        <?= mb_strtoupper(mb_substr($grupo['encargado_nombre'], 0, 1)) ?>
                    </div>
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars($grupo['encargado_nombre']) ?></div>
                        <div class="small text-muted">
                            <i class="fas fa-calendar-week fa-xs"></i>
                            <?= htmlspecialchars($grupo['semana_nombre']) ?>
                        </div>
                    </div>
                </div>
                <div class="small mb-1">
                    <i class="fas fa-user-tie text-primary fa-xs me-1"></i>
                    <strong>Encargado:</strong>
                    <?= htmlspecialchars($grupo['encargado_nombre']) ?>
                </div>
                <?php if ($grupo['encargado_telefono']): ?>
                <div class="small mb-1">
                    <i class="fas fa-phone fa-xs me-1 text-muted"></i>
                    <?= htmlspecialchars($grupo['encargado_telefono']) ?>
                </div>
                <?php endif; ?>
                <?php if ($grupo['encargado_email']): ?>
                <div class="small mb-2">
                    <i class="fas fa-envelope fa-xs me-1 text-muted"></i>
                    <?= htmlspecialchars($grupo['encargado_email']) ?>
                </div>
                <?php endif; ?>
                <?php if ($grupo['notas']): ?>
                <div class="alert alert-light small py-2 mb-0">
                    <i class="fas fa-sticky-note fa-xs me-1"></i>
                    <?= nl2br(htmlspecialchars($grupo['notas'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Estado de pago -->
        <?php
        $pct = $saldo_info['costo_total'] > 0
            ? min(100, round($saldo_info['total_pagado'] / $saldo_info['costo_total'] * 100))
            : ($saldo_info['pagado_100'] ? 100 : 0); // si costo=0 y hay acampantes → 100%
        $color_pago = $saldo_info['pagado_100'] ? 'success'
                    : ($saldo_info['total_pagado'] > 0 ? 'warning' : 'danger');
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-dollar-sign"></i> Estado de Pago</h6>
                <?php if ($saldo_info['pagado_100']): ?>
                <span class="badge bg-success">
                    <i class="fas fa-check-double"></i> Completo
                </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Acampantes:</span>
                        <span class="fw-bold"><?= $saldo_info['total_acampantes'] ?></span>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Costo total del grupo:</span>
                        <span class="fw-bold">
                            $<?= number_format($saldo_info['costo_total'], 2) ?>
                            <?php if ($saldo_info['costo_total'] == 0 && $saldo_info['total_acampantes'] > 0): ?>
                            <span class="badge bg-info ms-1" title="Beca completa">
                                <i class="fas fa-award fa-xs"></i> Beca
                            </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Total pagado:</span>
                        <span class="fw-bold text-success">
                            $<?= number_format($saldo_info['total_pagado'], 2) ?>
                        </span>
                    </div>
                    <div class="alert alert-<?= $saldo_info['saldo'] > 0 ? 'warning' : 'success' ?>
                                py-2 mb-0 d-flex justify-content-between align-items-center">
                        <span class="fw-bold">Saldo pendiente:</span>
                        <span class="fw-bold fs-5">
                            $<?= number_format($saldo_info['saldo'], 2) ?>
                        </span>
                    </div>
                </div>

                <div class="progress mb-1" style="height:14px; border-radius:7px;">
                    <div class="progress-bar bg-<?= $color_pago ?>"
                         style="width:<?= $pct ?>%; border-radius:7px;">
                        <?php if ($pct >= 20): ?>
                        <span class="small fw-bold"><?= $pct ?>%</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($pct < 20): ?>
                <div class="text-end small text-muted"><?= $pct ?>% pagado</div>
                <?php endif; ?>

                <div class="small text-muted mt-2 text-center">
                    <i class="fas fa-info-circle fa-xs"></i>
                    El total suma el costo individual de cada acampante.
                    Los descuentos y becas se reflejan automáticamente.
                </div>

                <?php if ($saldo_info['pagado_100']): ?>
                <div class="alert alert-success mt-3 mb-0 py-2 text-center small">
                    <i class="fas fa-check-double"></i>
                    <strong>¡Pago completo!</strong><br>
                    El grupo está listo. Administración realizará el check-in al llegar.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario de pago (solo si hay saldo pendiente) -->
        <?php if (!$saldo_info['pagado_100'] && $saldo_info['costo_total'] > 0
                && (esAdministracion() || esAdministrador())): ?>
        <div class="card mb-3">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0">
                    <i class="fas fa-plus-circle"></i> Registrar Pago del Grupo
                </h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="agregar_pago">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Monto *</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" name="monto"
                                   id="inputMontoGrupo"
                                   step="0.01" min="0.01"
                                   max="<?= number_format($saldo_info['saldo'], 2, '.', '') ?>"
                                   placeholder="0.00" required>
                        </div>
                        <button type="button"
                                class="btn btn-outline-success btn-sm w-100 mt-2"
                                onclick="document.getElementById('inputMontoGrupo').value=
                                    '<?= number_format($saldo_info['saldo'], 2, '.', '') ?>';">
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
                        <label class="form-label fw-bold">Fecha</label>
                        <input type="datetime-local" class="form-control"
                               name="fecha_pago"
                               value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Notas</label>
                        <input type="text" class="form-control" name="notas"
                               placeholder="Referencia, comprobante...">
                    </div>

                    <!-- Preview dinámico -->
                    <div class="p-2 rounded bg-light border small mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Saldo después:</span>
                            <span id="previewSaldoGrupo" class="fw-bold text-danger">
                                $<?= number_format($saldo_info['saldo'], 2) ?>
                            </span>
                        </div>
                        <div id="previewCheckinGrupo"
                             class="text-success fw-bold d-none mt-1 text-center">
                            <i class="fas fa-check-double"></i>
                            ¡Pago completo! El grupo pasará a Administración para check-in.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success w-100">
                        <i class="fas fa-save"></i> Registrar Pago
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Aviso para inscripción: pago pendiente, lo gestiona Administración -->
        <?php if (!$saldo_info['pagado_100'] && $saldo_info['costo_total'] > 0
                  && esAdmisiones()): ?>
        <div class="card mb-3 border-warning">
            <div class="card-body py-3 text-center">
                <i class="fas fa-cash-register fa-2x text-warning mb-2 d-block"></i>
                <div class="fw-bold">Pago pendiente</div>
                <small class="text-muted">
                    El registro del pago lo realiza el área de
                    <strong>Administración</strong>.<br>
                    Asegúrate de que los documentos de todos los acampantes
                    estén marcados como revisados.
                </small>
                <?php
                $sin_docs = count(array_filter($acampantes, fn($a) => !$a['documentos_revisados']));
                if ($sin_docs > 0): ?>
                <div class="alert alert-warning mt-2 mb-0 py-2 small">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong><?= $sin_docs ?></strong>
                    acampante<?= $sin_docs > 1 ? 's' : '' ?> sin documentos revisados
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Historial de pagos -->
        <?php if (!empty($pagos)): ?>
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fas fa-history"></i> Historial de Pagos
                    <span class="badge bg-secondary ms-1"><?= count($pagos) ?></span>
                </h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($pagos as $p): ?>
                    <tr>
                        <td class="small text-muted">
                            <?= date('d/m/Y', strtotime($p['fecha_pago'])) ?>
                        </td>
                        <td class="fw-bold text-success">
                            $<?= number_format($p['monto'], 2) ?>
                        </td>
                        <td class="small">
                            <?php
                            $iconos = ['efectivo'=>'💵','banco'=>'🏦','transferencia'=>'📱'];
                            echo ($iconos[$p['modo_pago']] ?? '') . ' ' . ucfirst($p['modo_pago']);
                            ?>
                        </td>
                        <?php if (!empty($p['notas'])): ?>
                        <td class="small text-muted">
                            <?= htmlspecialchars(mb_substr($p['notas'], 0, 20)) ?>
                        </td>
                        <?php else: ?>
                        <td></td>
                        <?php endif; ?>
                        <td>
                            <?php if (esAdministrador() || esAdministracion()): ?>
                            <a href="?id=<?= $id ?>&accion=eliminar_pago&pago_id=<?= $p['id'] ?>"
                               class="btn btn-sm btn-outline-danger"
                               onclick="return confirm('¿Eliminar este pago?')">
                                <i class="fas fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td>Total</td>
                            <td class="text-success">
                                $<?= number_format($saldo_info['total_pagado'], 2) ?>
                            </td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /col izquierdo -->

    <!-- ── Panel derecho: Acampantes ─────────────────────────────────── -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="fas fa-list"></i> Acampantes del Grupo
                    <span class="badge bg-primary ms-1"><?= count($acampantes) ?></span>
                </h6>
                <?php
                $docs_ok    = count(array_filter($acampantes, fn($a) => $a['documentos_revisados']));
                $llegaron   = count(array_filter($acampantes, fn($a) => $a['llego']));
                $sin_revisar = count($acampantes) - $docs_ok;
                ?>
                <div class="d-flex gap-2 small">
                    <span class="badge bg-success">
                        <i class="fas fa-check-double"></i> <?= $docs_ok ?> docs OK
                    </span>
                    <span class="badge bg-primary">
                        <i class="fas fa-sign-in-alt"></i> <?= $llegaron ?> check-in
                    </span>
                    <?php if ($sin_revisar > 0): ?>
                    <span class="badge bg-secondary">
                        <i class="fas fa-hourglass-start"></i> <?= $sin_revisar ?> sin revisar
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($acampantes)): ?>
            <div class="card-body text-center text-muted py-5">
                <i class="fas fa-user-slash fa-3x mb-3 opacity-25"></i>
                <p>No hay acampantes en este grupo.</p>
                <a href="nuevo_acampante_grupo.php?grupo_id=<?= $id ?>"
                   class="btn btn-success btn-sm">
                    <i class="fas fa-user-plus"></i> Agregar acampante
                </a>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Nombre</th>
                            <th>Edad</th>
                            <th>Sexo</th>
                            <th>CURP</th>
                            <th class="text-end">Costo</th>
                            <th class="text-center">Docs / Estado</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $total_costo_acampantes = 0;
                    foreach ($acampantes as $i => $ac):
                        $total_costo_acampantes += (float)$ac['costo_total'];
                    ?>
                    <tr class="<?= $ac['llego'] ? 'table-success' : '' ?>">
                        <td class="text-muted small"><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($ac['nombre']) ?></div>
                            <?php if ($ac['iglesia']): ?>
                            <small class="text-muted">
                                <?= htmlspecialchars($ac['iglesia']) ?>
                            </small>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= $ac['edad'] ?? '—' ?></td>
                        <td class="small text-center">
                            <?php if ($ac['sexo'] === 'masculino'): ?>
                                <span class="text-primary" title="Masculino">♂</span>
                            <?php elseif ($ac['sexo'] === 'femenino'): ?>
                                <span class="text-danger" title="Femenino">♀</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small font-monospace text-muted">
                            <?= $ac['curp']
                                ? htmlspecialchars($ac['curp'])
                                : '<span class="text-danger small">Sin CURP</span>' ?>
                        </td>
                        <td class="text-end fw-bold">
                            <?php if ((float)$ac['costo_total'] == 0): ?>
                                <span class="badge bg-info">
                                    <i class="fas fa-award fa-xs"></i> Beca
                                </span>
                            <?php else: ?>
                                $<?= number_format($ac['costo_total'], 0) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($ac['documentos_revisados']): ?>
                                <span class="badge bg-success d-block mb-1"
                                      title="Verificado el <?= date('d/m/Y H:i', strtotime($ac['documentos_revisados_at'])) ?>">
                                    <i class="fas fa-check-double fa-xs"></i> Docs OK
                                </span>
                            <?php else: ?>
                                <a href="../marcar_docs.php?id=<?= $ac['id'] ?>&grupo_id=<?= $id ?>"
                                   class="btn btn-sm btn-outline-success d-block mb-1"
                                   onclick="return confirm('¿Documentos de <?= htmlspecialchars($ac['nombre'], ENT_QUOTES) ?> verificados?')">
                                    <i class="fas fa-clipboard-check fa-xs"></i> Revisar
                                </a>
                            <?php endif; ?>
                        
                            <?php if ($ac['llego']): ?>
                                <span class="badge bg-primary"
                                      title="Llegó el <?= $ac['fecha_llegada'] ? date('d/m/Y H:i', strtotime($ac['fecha_llegada'])) : '' ?>">
                                    <i class="fas fa-sign-in-alt fa-xs"></i> Check-in ✓
                                </span>
                            <?php elseif ($ac['documentos_revisados']): ?>
                                <span class="badge bg-warning text-dark"
                                      title="Esperando pago en Administración">
                                    <i class="fas fa-clock fa-xs"></i> En caja
                                </span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border">
                                    <i class="fas fa-hourglass-start fa-xs"></i> Sin revisar
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="editar_acampante_grupo.php?id=<?= $ac['id'] ?>&grupo_id=<?= $id ?>"
                                   class="btn btn-outline-primary"
                                   title="Editar acampante">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if (esAdministrador() || esAdmisiones()): ?>
                                <a href="eliminar_acampante_grupo.php?acampante_id=<?= $ac['id'] ?>&grupo_id=<?= $id ?>"
                                   class="btn btn-outline-danger"
                                   title="Eliminar acampante"
                                   onclick="return confirm('¿Eliminar permanentemente a <?= htmlspecialchars($ac['nombre'], ENT_QUOTES) ?> del grupo? Esta acción no se puede deshacer.')">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="5" class="text-end">Total grupo:</td>
                            <td class="text-end">
                                $<?= number_format($total_costo_acampantes, 0) ?>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
const saldoActualGrupo = <?= json_encode((float)$saldo_info['saldo']) ?>;
const inputMonto       = document.getElementById('inputMontoGrupo');
const previewSaldo     = document.getElementById('previewSaldoGrupo');
const previewCheckin   = document.getElementById('previewCheckinGrupo');

if (inputMonto) {
    inputMonto.addEventListener('input', () => {
        const abono    = parseFloat(inputMonto.value) || 0;
        const restante = Math.max(0, saldoActualGrupo - abono);
        previewSaldo.textContent = '$' + restante.toLocaleString('es-MX', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
        if (abono >= saldoActualGrupo && abono > 0) {
            previewSaldo.className = 'fw-bold text-success';
            previewCheckin.classList.remove('d-none');
        } else {
            previewSaldo.className = 'fw-bold text-danger';
            previewCheckin.classList.add('d-none');
        }
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
