<?php
// admisiones/grupos/lista_grupos.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador() && !esEncargadoConsejeros()) {
    header('Location: ../../login.php'); exit();
}

$year      = obtenerAnioCampamento();
$semana_id = $_GET['semana_id'] ?? null;
$message   = $_GET['message'] ?? '';

// Semanas activas
$stmt = $pdo->prepare("SELECT * FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio");
$stmt->execute([$year]);
$semanas = $stmt->fetchAll();

// ── Grupos con saldo calculado desde SUM(acampantes.costo_total) ──────────
// Así los descuentos individuales quedan reflejados en el total del grupo
$where_semana = $semana_id ? "AND g.semana_id = ?" : "";
$params = $semana_id ? [$year, $semana_id] : [$year];

$stmt = $pdo->prepare("
    SELECT
        g.*,
        s.nombre AS semana_nombre,
        (SELECT COUNT(*)
         FROM acampantes a
         WHERE a.grupo_id = g.id AND a.estado = 'activo')           AS total_acampantes,
        COALESCE(
         (SELECT SUM(a.costo_total)
          FROM acampantes a
          WHERE a.grupo_id = g.id AND a.estado = 'activo'), 0)      AS costo_total_real,
        COALESCE(
         (SELECT SUM(pg.monto)
          FROM pagos_grupo pg
          WHERE pg.grupo_id = g.id), 0)                             AS total_pagado,
        (SELECT COUNT(*)
         FROM acampantes a
         WHERE a.grupo_id = g.id AND a.estado = 'activo'
           AND a.llego = 1)                                         AS total_llegaron
    FROM grupos_campamento g
    LEFT JOIN semanas_campamento s ON s.id = g.semana_id
    WHERE g.year_campamento = ? AND g.estado = 'activo'
    {$where_semana}
    ORDER BY g.semana_id, g.encargado_nombre
");
$stmt->execute($params);
$grupos = $stmt->fetchAll();

$base_path = '../';
include '../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-users"></i> Grupos de Campamento</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Grupos</li>
                </ol>
            </nav>
        </div>
        <a href="nuevo_grupo.php<?= $semana_id ? "?semana_id=$semana_id" : '' ?>"
           class="btn btn-success">
            <i class="fas fa-plus"></i> Nuevo Grupo
        </a>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filtro por semana -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="GET" class="d-flex align-items-center gap-3 flex-wrap">
            <label class="fw-bold mb-0 text-nowrap">
                <i class="fas fa-filter"></i> Semana:
            </label>
            <select name="semana_id" class="form-select w-auto"
                    onchange="this.form.submit()">
                <option value="">Todas las semanas</option>
                <?php foreach ($semanas as $s): ?>
                <option value="<?= $s['id'] ?>"
                        <?= $semana_id == $s['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php if (empty($grupos)): ?>
<div class="text-center text-muted py-5">
    <i class="fas fa-users fa-4x mb-3 opacity-25"></i>
    <h5>No hay grupos registrados</h5>
    <p>Crea el primer grupo para comenzar la inscripción colectiva.</p>
    <a href="nuevo_grupo.php" class="btn btn-success">
        <i class="fas fa-plus"></i> Crear primer grupo
    </a>
</div>
<?php else: ?>

<div class="row g-3">
<?php foreach ($grupos as $g):
    // Usar SUM real de costos individuales (incluye descuentos)
    $costo_total = (float)$g['costo_total_real'];
    $pagado      = (float)$g['total_pagado'];
    $saldo       = max(0, $costo_total - $pagado);
    $pagado_100  = $costo_total > 0 && $pagado >= $costo_total;
    $pct         = $costo_total > 0 ? min(100, round($pagado / $costo_total * 100)) : 0;
    $color       = $pagado_100 ? 'success' : ($pagado > 0 ? 'warning' : 'danger');
?>
<div class="col-md-6 col-xl-4">
    <div class="card h-100 <?= $pagado_100 ? 'border-success' : '' ?>">
        <div class="card-header d-flex justify-content-between align-items-start">
            <div>
                <h6 class="mb-0 fw-bold">
                    <?= htmlspecialchars($g['nombre']) ?>
                </h6>
                <small class="text-muted">
                    <i class="fas fa-calendar-week fa-xs"></i>
                    <?= htmlspecialchars($g['semana_nombre']) ?>
                </small>
            </div>
            <?php if ($pagado_100): ?>
            <span class="badge bg-success">
                <i class="fas fa-check-double"></i> Pagado
            </span>
            <?php else: ?>
            <span class="badge bg-<?= $color ?>"><?= $pct ?>%</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <!-- Encargado -->
            <div class="small text-muted mb-2">
                <i class="fas fa-user-tie fa-xs me-1"></i>
                <strong><?= htmlspecialchars($g['encargado_nombre']) ?></strong>
                <?php if ($g['encargado_telefono']): ?>
                · <?= htmlspecialchars($g['encargado_telefono']) ?>
                <?php endif; ?>
            </div>

            <!-- Stats -->
            <div class="d-flex gap-3 mb-3">
                <div class="text-center">
                    <div class="fs-4 fw-bold text-primary">
                        <?= $g['total_acampantes'] ?>
                    </div>
                    <div class="small text-muted">Acampantes</div>
                </div>
                <div class="text-center">
                    <div class="fs-4 fw-bold text-success">
                        <?= $g['total_llegaron'] ?>
                    </div>
                    <div class="small text-muted">Check-in</div>
                </div>
                <div class="text-center">
                    <div class="fs-4 fw-bold text-secondary">
                        <?= $g['total_acampantes'] - $g['total_llegaron'] ?>
                    </div>
                    <div class="small text-muted">Pendientes</div>
                </div>
            </div>

            <!-- Barra de pago -->
            <div class="progress mb-1" style="height:8px;">
                <div class="progress-bar bg-<?= $color ?>"
                     style="width:<?= $pct ?>%"></div>
            </div>
            <div class="d-flex justify-content-between small">
                <span class="text-muted">
                    Pagado:
                    <strong class="text-success">
                        $<?= number_format($pagado, 0) ?>
                    </strong>
                </span>
                <span class="text-muted">
                    Total:
                    <strong>$<?= number_format($costo_total, 0) ?></strong>
                </span>
            </div>
            <?php if ($saldo > 0): ?>
            <div class="small text-danger mt-1">
                Pendiente: <strong>$<?= number_format($saldo, 0) ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-footer d-flex gap-2">
            <a href="ver_grupo.php?id=<?= $g['id'] ?>"
               class="btn btn-primary btn-sm flex-grow-1">
                <i class="fas fa-eye"></i> Ver detalle
            </a>
            <a href="editar_grupo.php?id=<?= $g['id'] ?>"
               class="btn btn-outline-secondary btn-sm"
               title="Editar grupo">
                <i class="fas fa-edit"></i>
            </a>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ── Resumen global ───────────────────────────────────────────────────── -->
<?php
$total_g   = count($grupos);
$total_ac  = array_sum(array_column($grupos, 'total_acampantes'));
$total_pag = array_sum(array_column($grupos, 'total_pagado'));
$total_cos = array_sum(array_column($grupos, 'costo_total_real'));
$total_pend = max(0, $total_cos - $total_pag);
$total_llegaron = array_sum(array_column($grupos, 'total_llegaron'));
?>
<div class="card mt-4">
    <div class="card-header">
        <h6 class="mb-0">
            <i class="fas fa-chart-bar"></i> Resumen Global
        </h6>
    </div>
    <div class="card-body">
        <div class="row text-center g-3">
            <div class="col-6 col-md-2">
                <div class="fs-3 fw-bold text-primary"><?= $total_g ?></div>
                <div class="text-muted small">Grupos</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="fs-3 fw-bold text-info"><?= $total_ac ?></div>
                <div class="text-muted small">Acampantes</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="fs-3 fw-bold text-success"><?= $total_llegaron ?></div>
                <div class="text-muted small">Con check-in</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="fs-3 fw-bold text-dark">
                    $<?= number_format($total_cos, 0) ?>
                </div>
                <div class="text-muted small">Total esperado</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="fs-3 fw-bold text-success">
                    $<?= number_format($total_pag, 0) ?>
                </div>
                <div class="text-muted small">Cobrado</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="fs-3 fw-bold text-danger">
                    $<?= number_format($total_pend, 0) ?>
                </div>
                <div class="text-muted small">Pendiente</div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include '../../includes/footer.php'; ?>



