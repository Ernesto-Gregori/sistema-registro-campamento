<?php
// encargado/panel.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarAccesoEncargado();

$grupo_id = obtenerGrupoEncargado();
$message  = $_GET['message'] ?? '';
$error    = '';

// Leer mensajes flash
if (empty($message) && isset($_SESSION['mensaje_exito'])) {
    $message = $_SESSION['mensaje_exito'];
    unset($_SESSION['mensaje_exito']);
}
if (empty($error) && isset($_SESSION['mensaje_error'])) {
    $error = $_SESSION['mensaje_error'];
    unset($_SESSION['mensaje_error']);
}

// Obtener datos del grupo
$stmt = $pdo->prepare("
    SELECT g.*, s.nombre AS semana_nombre, s.costo_campamento
    FROM grupos_campamento g
    LEFT JOIN semanas_campamento s ON s.id = g.semana_id
    WHERE g.id = ? AND g.estado = 'activo'
");
$stmt->execute([$grupo_id]);
$grupo = $stmt->fetch();

if (!$grupo) {
    cerrarSesionEncargado();
    header('Location: ../acceso-encargado.php');
    exit();
}

// Calcular saldo del grupo
function calcularSaldoGrupo(PDO $pdo, int $grupo_id): array {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(id)                     AS total_acampantes,
            COALESCE(SUM(costo_total), 0) AS costo_total_real
        FROM acampantes
        WHERE grupo_id = ? AND estado = 'activo'
    ");
    $stmt->execute([$grupo_id]);
    $datos = $stmt->fetch();

    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) FROM pagos_grupo WHERE grupo_id = ?");
    $stmt2->execute([$grupo_id]);
    $total_pagado = (float)$stmt2->fetchColumn();

    $costo_total      = (float)$datos['costo_total_real'];
    $total_acampantes = (int)$datos['total_acampantes'];
    $saldo            = max(0, $costo_total - $total_pagado);
    $pagado_100 = ($costo_total == 0 && $total_acampantes > 0)
               || ($costo_total  > 0 && $total_pagado >= $costo_total);

    return compact('total_acampantes', 'total_pagado', 'costo_total', 'saldo', 'pagado_100');
}

$saldo_info = calcularSaldoGrupo($pdo, $grupo_id);

// Obtener acampantes del grupo
$stmt = $pdo->prepare("
    SELECT * FROM acampantes
    WHERE grupo_id = ? AND estado = 'activo'
    ORDER BY nombre
");
$stmt->execute([$grupo_id]);
$acampantes = $stmt->fetchAll();

// Obtener pagos del grupo (solo lectura)
$stmt = $pdo->prepare("
    SELECT pg.*, u.username AS registrado_por_nombre
    FROM pagos_grupo pg
    LEFT JOIN usuarios u ON u.id = pg.registrado_por
    WHERE pg.grupo_id = ?
    ORDER BY pg.fecha_pago DESC
");
$stmt->execute([$grupo_id]);
$pagos = $stmt->fetchAll();

include '../includes/header.php';
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
                    <li class="breadcrumb-item active">Mi Grupo</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            <a href="nuevo_acampante.php" class="btn btn-outline-success btn-sm">
                <i class="fas fa-user-plus"></i> Agregar acampante
            </a>
            <a href="logout.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-sign-out-alt"></i> Salir
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

<div class="row g-4">

    <!-- Panel izquierdo -->
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

        <!-- Estado de pago (solo lectura) -->
        <?php
        $pct = $saldo_info['costo_total'] > 0
            ? min(100, round($saldo_info['total_pagado'] / $saldo_info['costo_total'] * 100))
            : ($saldo_info['pagado_100'] ? 100 : 0);
        $color_pago = $saldo_info['pagado_100'] ? 'success'
                    : ($saldo_info['total_pagado'] > 0 ? 'warning' : 'danger');
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-dollar-sign"></i> Estado de Pago</h6>
                <?php if ($saldo_info['pagado_100']): ?>
                <span class="badge bg-success"><i class="fas fa-check-double"></i> Completo</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted">Acampantes:</span>
                    <span class="fw-bold"><?= $saldo_info['total_acampantes'] ?></span>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted">Costo total:</span>
                    <span class="fw-bold">$<?= number_format($saldo_info['costo_total'], 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted">Total pagado:</span>
                    <span class="fw-bold text-success">$<?= number_format($saldo_info['total_pagado'], 2) ?></span>
                </div>
                <div class="alert alert-<?= $saldo_info['saldo'] > 0 ? 'warning' : 'success' ?>
                            py-2 mb-2 d-flex justify-content-between align-items-center">
                    <span class="fw-bold">Saldo pendiente:</span>
                    <span class="fw-bold fs-5">$<?= number_format($saldo_info['saldo'], 2) ?></span>
                </div>
                <div class="progress mb-1" style="height:14px; border-radius:7px;">
                    <div class="progress-bar bg-<?= $color_pago ?>"
                         style="width:<?= $pct ?>%; border-radius:7px;">
                        <?php if ($pct >= 20): ?><span class="small fw-bold"><?= $pct ?>%</span><?php endif; ?>
                    </div>
                </div>
                <?php if ($pct < 20): ?>
                <div class="text-end small text-muted"><?= $pct ?>% pagado</div>
                <?php endif; ?>
                <div class="small text-muted mt-2 text-center">
                    <i class="fas fa-info-circle fa-xs"></i>
                    El pago lo gestiona el área de Administración.
                </div>
            </div>
        </div>

        <!-- Historial de pagos (solo lectura) -->
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
                        <td class="fw-bold text-success">$<?= number_format($p['monto'], 2) ?></td>
                        <td class="small"><?= ucfirst($p['modo_pago']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td>Total</td>
                            <td class="text-success">$<?= number_format($saldo_info['total_pagado'], 2) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Panel derecho: Acampantes -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="fas fa-list"></i> Acampantes del Grupo
                    <span class="badge bg-primary ms-1"><?= count($acampantes) ?></span>
                </h6>
                <?php
                $docs_ok     = count(array_filter($acampantes, fn($a) => $a['documentos_revisados']));
                $llegaron    = count(array_filter($acampantes, fn($a) => $a['llego']));
                $sin_revisar = count($acampantes) - $docs_ok;
                ?>
                <div class="d-flex gap-2 small">
                    <span class="badge bg-success"><i class="fas fa-check-double"></i> <?= $docs_ok ?> docs OK</span>
                    <span class="badge bg-primary"><i class="fas fa-sign-in-alt"></i> <?= $llegaron ?> check-in</span>
                    <?php if ($sin_revisar > 0): ?>
                    <span class="badge bg-secondary"><i class="fas fa-hourglass-start"></i> <?= $sin_revisar ?> sin revisar</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($acampantes)): ?>
            <div class="card-body text-center text-muted py-5">
                <i class="fas fa-user-slash fa-3x mb-3 opacity-25"></i>
                <p>No hay acampantes en tu grupo.</p>
                <a href="nuevo_acampante.php" class="btn btn-success btn-sm">
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
                            <th class="text-center">Estado</th>
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
                            <small class="text-muted"><?= htmlspecialchars($ac['iglesia']) ?></small>
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
                                <span class="badge bg-info"><i class="fas fa-award fa-xs"></i> Beca</span>
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
                                <span class="badge bg-light text-muted border">
                                    <i class="fas fa-hourglass-start fa-xs"></i> Sin revisar
                                </span>
                            <?php endif; ?>
                            <?php if ($ac['llego']): ?>
                                <span class="badge bg-primary"
                                      title="Llegó el <?= $ac['fecha_llegada'] ? date('d/m/Y H:i', strtotime($ac['fecha_llegada'])) : '' ?>">
                                    <i class="fas fa-sign-in-alt fa-xs"></i> Check-in ✓
                                </span>
                            <?php elseif ($ac['documentos_revisados']): ?>
                                <span class="badge bg-warning text-dark" title="Esperando pago">
                                    <i class="fas fa-clock fa-xs"></i> En caja
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="editar_acampante.php?id=<?= $ac['id'] ?>"
                                   class="btn btn-outline-primary" title="Editar acampante">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="eliminar_acampante.php?acampante_id=<?= $ac['id'] ?>"
                                   class="btn btn-outline-danger" title="Eliminar acampante"
                                   onclick="return confirm('¿Eliminar permanentemente a <?= htmlspecialchars($ac['nombre'], ENT_QUOTES) ?>? Esta acción no se puede deshacer.')">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="5" class="text-end">Total grupo:</td>
                            <td class="text-end">$<?= number_format($total_costo_acampantes, 0) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php include '../includes/footer.php'; ?>
