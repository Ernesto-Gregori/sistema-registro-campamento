<?php
// administracion/lista_grupos_admin.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministracion() && !esAdministrador()) {
    header('Location: ../login.php'); exit();
}

$titulo    = "Grupos — Caja";
$year      = obtenerAnioCampamento();
$semana_id = (int)($_GET['semana_id'] ?? 0);
$filtro    = $_GET['filtro'] ?? '';   // pendiente | completo | ''

// Semanas
$stmt_sem = $pdo->prepare(
    "SELECT * FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio"
);
$stmt_sem->execute([$year]);
$semanas = $stmt_sem->fetchAll();

if (!$semana_id && !empty($semanas)) {
    foreach ($semanas as $s) {
        if ($s['activa']) { $semana_id = (int)$s['id']; break; }
    }
    if (!$semana_id) $semana_id = (int)$semanas[0]['id'];
}

// Query grupos con totales de pago
$sql = "
    SELECT
        g.*,
        s.nombre AS semana_nombre,
        COALESCE((
            SELECT COUNT(*)
            FROM acampantes a
            WHERE a.grupo_id = g.id AND a.estado = 'activo'
        ), 0) AS total_acampantes,
        COALESCE((
            SELECT SUM(a.costo_total)
            FROM acampantes a
            WHERE a.grupo_id = g.id AND a.estado = 'activo'
        ), 0) AS costo_grupo,
        COALESCE((
            SELECT SUM(pg2.monto)
            FROM pagos_grupo pg2
            WHERE pg2.grupo_id = g.id
        ), 0) AS total_pagado
    FROM grupos_campamento g
    LEFT JOIN semanas_campamento s ON s.id = g.semana_id
    WHERE g.semana_id = ?
    ORDER BY g.encargado_nombre
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$semana_id]);
$grupos_raw = $stmt->fetchAll();

// Calcular saldo real y aplicar filtro
$grupos = [];
foreach ($grupos_raw as $g) {
    $costo        = (float)$g['costo_grupo'];
    $pagado       = (float)$g['total_pagado'];
    $saldo        = max(0, $costo - $pagado);
    $pagado_100   = ($costo == 0 && $g['total_acampantes'] > 0)
                 || ($costo  > 0 && $pagado >= $costo);

    $g['saldo']      = $saldo;
    $g['pagado_100'] = $pagado_100;
    $g['pct']        = $costo > 0
                        ? min(100, round($pagado / $costo * 100))
                        : ($pagado_100 ? 100 : 0);

    if ($filtro === 'pendiente' && $pagado_100)   continue;
    if ($filtro === 'completo'  && !$pagado_100)  continue;

    $grupos[] = $g;
}

// Totales resumen
$total_grupos    = count($grupos);
$total_pendiente = array_sum(array_column($grupos, 'saldo'));
$total_cobrado   = array_sum(array_map(fn($g) => (float)$g['total_pagado'], $grupos));

include '../includes/header.php';
?>

<!-- ── Cabecera ──────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="fas fa-users"></i> <?= $titulo ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Grupos</li>
            </ol>
        </nav>
    </div>
    <a href="historial.php?semana_id=<?= $semana_id ?>&tipo=grupo"
       class="btn btn-outline-dark btn-sm">
        <i class="fas fa-history"></i> Ver historial de grupos
    </a>
</div>

<!-- ── Selector de semana ─────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="fw-bold text-muted small">
                <i class="fas fa-calendar-week"></i>
            </span>
            <?php foreach ($semanas as $s): ?>
            <a href="?semana_id=<?= $s['id'] ?>&filtro=<?= $filtro ?>"
               class="btn btn-sm <?= $semana_id == $s['id'] ? 'btn-dark' : 'btn-outline-secondary' ?>">
                <?= htmlspecialchars($s['nombre']) ?>
                <?= $s['activa'] ? ' ✓' : '' ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── Tarjetas resumen ────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-primary text-white">
            <div class="card-body text-center py-3">
                <i class="fas fa-users fa-lg mb-1 opacity-75"></i>
                <h4 class="fw-bold mb-0"><?= $total_grupos ?></h4>
                <div class="small opacity-75">Grupos en vista</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-success text-white">
            <div class="card-body text-center py-3">
                <i class="fas fa-check-double fa-lg mb-1 opacity-75"></i>
                <h4 class="fw-bold mb-0">
                    <?= count(array_filter($grupos, fn($g) => $g['pagado_100'])) ?>
                </h4>
                <div class="small opacity-75">Pagados completos</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-success text-white">
            <div class="card-body text-center py-3">
                <i class="fas fa-dollar-sign fa-lg mb-1 opacity-75"></i>
                <h4 class="fw-bold mb-0">$<?= number_format($total_cobrado, 0) ?></h4>
                <div class="small opacity-75">Total cobrado</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-warning text-dark">
            <div class="card-body text-center py-3">
                <i class="fas fa-exclamation-circle fa-lg mb-1 opacity-75"></i>
                <h4 class="fw-bold mb-0">$<?= number_format($total_pendiente, 0) ?></h4>
                <div class="small opacity-75">Saldo por cobrar</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Filtros rápidos ─────────────────────────────────────────────────────── -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="?semana_id=<?= $semana_id ?>"
       class="btn btn-sm <?= $filtro === '' ? 'btn-dark' : 'btn-outline-secondary' ?>">
        <i class="fas fa-list"></i> Todos
    </a>
    <a href="?semana_id=<?= $semana_id ?>&filtro=pendiente"
       class="btn btn-sm <?= $filtro === 'pendiente' ? 'btn-warning' : 'btn-outline-warning' ?>">
        <i class="fas fa-exclamation-circle"></i> Con saldo pendiente
    </a>
    <a href="?semana_id=<?= $semana_id ?>&filtro=completo"
       class="btn btn-sm <?= $filtro === 'completo' ? 'btn-success' : 'btn-outline-success' ?>">
        <i class="fas fa-check-circle"></i> Pagados completos
    </a>
</div>

<!-- ── Tabla de grupos ─────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($grupos)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-inbox fa-3x mb-3 d-block opacity-25"></i>
                <strong>Sin grupos en esta vista</strong>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Encargado / Iglesia</th>
                        <th class="text-center">Acampantes</th>
                        <th class="text-end">Costo total</th>
                        <th class="text-end">Pagado</th>
                        <th>Progreso</th>
                        <th class="text-end">Saldo</th>
                        <th class="text-center">Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($grupos as $g):
                    $color = $g['pagado_100'] ? 'success'
                           : ($g['total_pagado'] > 0 ? 'warning' : 'danger');
                ?>
                <tr class="<?= $g['pagado_100'] ? 'table-success' : '' ?>">
                    <td>
                        <div class="fw-bold">
                            <?= htmlspecialchars($g['encargado_nombre']) ?>
                        </div>
                        <?php if ($g['encargado_telefono']): ?>
                        <small class="text-muted">
                            <i class="fas fa-phone fa-xs"></i>
                            <?= htmlspecialchars($g['encargado_telefono']) ?>
                        </small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-primary">
                            <?= $g['total_acampantes'] ?>
                        </span>
                    </td>
                    <td class="text-end fw-bold">
                        <?php if ($g['costo_grupo'] == 0 && $g['total_acampantes'] > 0): ?>
                            <span class="badge bg-info">
                                <i class="fas fa-award fa-xs"></i> Beca
                            </span>
                        <?php else: ?>
                            $<?= number_format($g['costo_grupo'], 0) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-success fw-bold">
                        $<?= number_format($g['total_pagado'], 0) ?>
                    </td>
                    <td style="min-width:100px;">
                        <div class="progress" style="height:6px;">
                            <div class="progress-bar bg-<?= $color ?>"
                                 style="width:<?= $g['pct'] ?>%"></div>
                        </div>
                        <small class="text-muted"><?= $g['pct'] ?>%</small>
                    </td>
                    <td class="text-end">
                        <?php if ($g['pagado_100']): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-check-double"></i> Completo
                            </span>
                        <?php else: ?>
                            <span class="badge bg-<?= $color ?> fs-6">
                                $<?= number_format($g['saldo'], 0) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <a href="ver_grupo_admin.php?id=<?= $g['id'] ?>"
                           class="btn btn-sm <?= $g['pagado_100']
                               ? 'btn-outline-success'
                               : 'btn-warning' ?>">
                            <?php if (!$g['pagado_100']): ?>
                                <i class="fas fa-cash-register"></i> Cobrar
                            <?php else: ?>
                                <i class="fas fa-eye"></i> Ver
                            <?php endif; ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>