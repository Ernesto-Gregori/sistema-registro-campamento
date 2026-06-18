<?php
// ─────────────────────────────────────────────────────────────────────────────
// admisiones/estadisticas.php
// ─────────────────────────────────────────────────────────────────────────────
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministracion() && !esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$titulo = "Estadísticas";

// ── Semana seleccionada ──────────────────────────────────────────────────────
$semana_id = isset($_GET['semana_id']) ? (int)$_GET['semana_id'] : 0;

$stmt_sems = $pdo->query("SELECT * FROM semanas_campamento ORDER BY fecha_inicio DESC");
$semanas   = $stmt_sems->fetchAll(PDO::FETCH_ASSOC);

if (!$semana_id) {
    foreach ($semanas as $s) {
        if ($s['activa']) { $semana_id = (int)$s['id']; break; }
    }
}
$semana_actual = null;
foreach ($semanas as $s) {
    if ((int)$s['id'] === $semana_id) { $semana_actual = $s; break; }
}

// ── GUARD: sin semana ────────────────────────────────────────────────────────
if (!$semana_id || !$semana_actual) {
    include '../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
        <div>
            <h1 class="mb-1"><i class="fas fa-chart-bar"></i> <?= htmlspecialchars($titulo) ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Estadísticas</li>
                </ol>
            </nav>
        </div>
        <form method="GET" class="d-flex gap-2 align-items-center">
            <select name="semana_id" class="form-select" onchange="this.form.submit()" style="min-width:220px;">
                <option value="">-- Seleccionar semana --</option>
                <?php foreach ($semanas as $s): ?>
                    <option value="<?= (int)$s['id'] ?>">
                        <?= htmlspecialchars($s['nombre']) ?><?= $s['activa'] ? ' ✓' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i> Selecciona una semana para ver las estadísticas.
    </div>
    <?php
    include '../includes/footer.php';
    exit();
}

// ── BLOQUE 1: Resumen hombres / mujeres ─────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(sexo = 'masculino'), 0)                        AS hombres_inscritos,
        COALESCE(SUM(sexo = 'femenino'), 0)                         AS mujeres_inscritas,
        COALESCE(SUM(sexo = 'masculino' AND llego = 1), 0)          AS hombres_llegaron,
        COALESCE(SUM(sexo = 'femenino'  AND llego = 1), 0)          AS mujeres_llegaron,
        COUNT(*)                                                    AS total_inscritos,
        COALESCE(SUM(llego = 1), 0)                                 AS total_llegaron,
        COALESCE(SUM(primera_vez_campamento = 1), 0)                AS primera_vez_inscritos,
        COALESCE(SUM(primera_vez_campamento = 1 AND llego = 1), 0)  AS primera_vez_llegaron
    FROM acampantes
    WHERE semana_id = ? AND estado = 'activo'
");
$stmt->execute([$semana_id]);
$resumen = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resumen || $resumen['total_inscritos'] === null) {
    $resumen = [
        'hombres_inscritos' => 0, 'mujeres_inscritas' => 0,
        'hombres_llegaron'  => 0, 'mujeres_llegaron'  => 0,
        'total_inscritos'   => 0, 'total_llegaron'    => 0,
        'primera_vez_inscritos' => 0, 'primera_vez_llegaron' => 0,
    ];
}

// ── BLOQUE 2: Pagos individuales por modo ───────────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.modo_pago,
           COALESCE(SUM(p.monto), 0)         AS total_monto,
           COUNT(DISTINCT p.acampante_id)     AS num_acampantes
    FROM pagos_acampante p
    INNER JOIN acampantes a ON p.acampante_id = a.id
    WHERE a.semana_id = ? AND a.estado = 'activo'
    GROUP BY p.modo_pago
");
$stmt->execute([$semana_id]);
$pagos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pagos_ind       = ['banco' => 0.0, 'efectivo' => 0.0, 'transferencia' => 0.0];
$pagos_ind_count = ['banco' => 0,   'efectivo' => 0,   'transferencia' => 0];
foreach ($pagos_raw as $p) {
    if (array_key_exists($p['modo_pago'], $pagos_ind)) {
        $pagos_ind[$p['modo_pago']]       = (float)$p['total_monto'];
        $pagos_ind_count[$p['modo_pago']] = (int)$p['num_acampantes'];
    }
}
$total_cobrado_ind = array_sum($pagos_ind);

// ── BLOQUE 2b: Pagos de grupos por modo ─────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT pg.modo_pago,
           COALESCE(SUM(pg.monto), 0)         AS total_monto,
           COUNT(DISTINCT pg.grupo_id)         AS num_grupos
    FROM pagos_grupo pg
    INNER JOIN grupos_campamento g ON g.id = pg.grupo_id
    WHERE g.semana_id = ? AND g.estado = 'activo'
    GROUP BY pg.modo_pago
");
$stmt->execute([$semana_id]);
$pagos_grp_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pagos_grp       = ['banco' => 0.0, 'efectivo' => 0.0, 'transferencia' => 0.0];
$pagos_grp_count = ['banco' => 0,   'efectivo' => 0,   'transferencia' => 0];
foreach ($pagos_grp_raw as $p) {
    if (array_key_exists($p['modo_pago'], $pagos_grp)) {
        $pagos_grp[$p['modo_pago']]       = (float)$p['total_monto'];
        $pagos_grp_count[$p['modo_pago']] = (int)$p['num_grupos'];
    }
}
$total_cobrado_grp = array_sum($pagos_grp);

// ── Totales combinados ───────────────────────────────────────────────────────
$modos_keys  = ['banco', 'efectivo', 'transferencia'];
$pagos_total = [];
foreach ($modos_keys as $k) {
    $pagos_total[$k] = $pagos_ind[$k] + $pagos_grp[$k];
}
$total_cobrado = array_sum($pagos_total);

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(costo_total), 0)
    FROM acampantes
    WHERE semana_id = ? AND estado = 'activo'
");
$stmt->execute([$semana_id]);
$total_esperado  = (float)$stmt->fetchColumn();
$saldo_pendiente = $total_esperado - $total_cobrado;

// ── BLOQUE 3: Iglesias paginadas ─────────────────────────────────────────────
$iglesia_filtro = trim($_GET['iglesia_buscar'] ?? '');
$ig_page        = max(1, (int)($_GET['ig_page'] ?? 1));
$ig_per_page    = 20;

$sql_count  = "SELECT COUNT(DISTINCT iglesia) FROM acampantes
               WHERE semana_id = ? AND estado = 'activo'
                 AND iglesia IS NOT NULL AND iglesia != ''
                 AND iglesia NOT IN ('Si','No')";
$p_count    = [$semana_id];
if ($iglesia_filtro !== '') {
    $sql_count .= " AND iglesia LIKE ?";
    $p_count[]  = "%$iglesia_filtro%";
}
$stmt_count     = $pdo->prepare($sql_count);
$stmt_count->execute($p_count);
$ig_total_rows  = (int)$stmt_count->fetchColumn();
$ig_total_pages = max(1, (int)ceil($ig_total_rows / $ig_per_page));
$ig_page        = min($ig_page, $ig_total_pages);
$ig_offset      = ($ig_page - 1) * $ig_per_page;

$sql_igl  = "
    SELECT
        a.iglesia,
        COUNT(*)                             AS inscritos,
        COALESCE(SUM(a.llego = 1), 0)        AS llegaron,
        COALESCE(SUM(a.costo_total), 0)      AS total_esperado,
        COALESCE(
            (SELECT SUM(p2.monto)
             FROM pagos_acampante p2
             INNER JOIN acampantes a2 ON p2.acampante_id = a2.id
             WHERE a2.semana_id = ? AND a2.estado = 'activo'
               AND a2.iglesia = a.iglesia)
        , 0) AS total_pagado
    FROM acampantes a
    WHERE a.semana_id = ? AND a.estado = 'activo'
      AND a.iglesia IS NOT NULL AND a.iglesia != ''
      AND a.iglesia NOT IN ('Si','No')";
$p_igl = [$semana_id, $semana_id];
if ($iglesia_filtro !== '') {
    $sql_igl .= " AND a.iglesia LIKE ?";
    $p_igl[]  = "%$iglesia_filtro%";
}
$sql_igl .= " GROUP BY a.iglesia ORDER BY inscritos DESC, a.iglesia LIMIT ? OFFSET ?";

$stmt_igl = $pdo->prepare($sql_igl);
// Bind parámetros normales
foreach ($p_igl as $i => $v) {
    $stmt_igl->bindValue($i + 1, $v);
}
// LIMIT y OFFSET como enteros — fix crítico para MySQL/PDO
$stmt_igl->bindValue(count($p_igl) + 1, $ig_per_page, PDO::PARAM_INT);
$stmt_igl->bindValue(count($p_igl) + 2, $ig_offset,   PDO::PARAM_INT);
$stmt_igl->execute();
$iglesias = $stmt_igl->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM acampantes
    WHERE semana_id = ? AND estado = 'activo'
      AND (iglesia IS NULL OR iglesia = '' OR iglesia IN ('Si','No'))
");
$stmt->execute([$semana_id]);
$sin_iglesia = (int)$stmt->fetchColumn();

// ── BLOQUE 4: Grupos de campamento ──────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        g.id,
        g.encargado_nombre                                              AS grupo_nombre,
        g.encargado_nombre,
        COALESCE(
            (SELECT COUNT(*)
             FROM acampantes a
             WHERE a.grupo_id = g.id AND a.estado = 'activo'), 0)   AS total_acampantes,
        COALESCE(
            (SELECT SUM(a.costo_total)
             FROM acampantes a
             WHERE a.grupo_id = g.id AND a.estado = 'activo'), 0)   AS costo_total_real,
        COALESCE(
            (SELECT SUM(pg.monto)
             FROM pagos_grupo pg
             WHERE pg.grupo_id = g.id), 0)                          AS total_pagado,
        COALESCE(
            (SELECT COUNT(*)
             FROM acampantes a
             WHERE a.grupo_id = g.id AND a.estado = 'activo'
               AND a.llego = 1), 0)                                 AS total_llegaron
    FROM grupos_campamento g
    WHERE g.semana_id = ? AND g.estado = 'activo'
    ORDER BY g.encargado_nombre
");
$stmt->execute([$semana_id]);
$grupos_campamento = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── BLOQUE 5: Becados ────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        a.id, a.nombre, a.sexo, a.edad, a.iglesia,
        a.llego, a.fecha_llegada,
        g.encargado_nombre AS grupo_nombre
    FROM acampantes a
    LEFT JOIN grupos_campamento g ON g.id = a.grupo_id
    WHERE a.semana_id = ? AND a.estado = 'activo' AND a.costo_total = 0
    ORDER BY a.nombre
");
$stmt->execute([$semana_id]);
$becados = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<!-- ══ CABECERA + SELECTOR ══ -->
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
    <div>
        <h1 class="mb-1"><i class="fas fa-chart-bar"></i> <?= $titulo ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Estadísticas</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <select name="semana_id" class="form-select" onchange="this.form.submit()" style="min-width:220px;">
                <option value="">-- Seleccionar semana --</option>
                <?php foreach ($semanas as $s): ?>
                <option value="<?= $s['id'] ?>" <?= ((int)$s['id'] === $semana_id) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['nombre']) ?><?= $s['activa'] ? ' ✓' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <button onclick="window.print()" class="btn btn-outline-secondary">
            <i class="fas fa-print"></i> Imprimir
        </button>
    </div>
</div>

<!-- Banner semana -->
<div class="alert alert-success border-0 mb-4 d-flex align-items-center gap-3">
    <i class="fas fa-calendar-week fa-2x"></i>
    <div>
        <strong class="fs-5"><?= htmlspecialchars($semana_actual['nombre']) ?></strong>
        <div class="text-muted small">
            <?= date('d/m/Y', strtotime($semana_actual['fecha_inicio'])) ?> —
            <?= date('d/m/Y', strtotime($semana_actual['fecha_fin'])) ?>
            &nbsp;·&nbsp; Precio base:
            <strong>$<?= number_format($semana_actual['costo_campamento'], 2) ?></strong>
        </div>
    </div>
</div>

<!-- ══ §1 ASISTENCIA ══ -->
<div class="section-label text-uppercase text-muted small fw-bold mb-2 ps-1">
    <i class="fas fa-users fa-xs me-1"></i> Asistencia
</div>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-primary text-white h-100">
            <div class="card-body text-center py-4">
                <i class="fas fa-users fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0"><?= $resumen['total_inscritos'] ?></h2>
                <div class="small opacity-75">Total Inscritos</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-success text-white h-100">
            <div class="card-body text-center py-4">
                <i class="fas fa-map-marker-alt fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0"><?= $resumen['total_llegaron'] ?></h2>
                <div class="small opacity-75">Han Llegado</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-warning text-dark h-100">
            <div class="card-body text-center py-4">
                <i class="fas fa-star fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0"><?= $resumen['primera_vez_llegaron'] ?></h2>
                <div class="small opacity-50">
                    Primera vez llegaron
                    <span class="fw-bold d-block">(<?= $resumen['primera_vez_inscritos'] ?> inscritos)</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-danger text-white h-100">
            <div class="card-body text-center py-4">
                <i class="fas fa-hourglass-half fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0">
                    <?= $resumen['total_inscritos'] - $resumen['total_llegaron'] ?>
                </h2>
                <div class="small opacity-75">Pendientes de llegar</div>
            </div>
        </div>
    </div>
</div>

<!-- Tabla ♂/♀ + Balance de Pagos -->
<div class="row g-3 mb-5">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header bg-dark text-white">
                <h6 class="mb-0"><i class="fas fa-venus-mars"></i> Resumen por Sexo</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered mb-0 text-center">
                    <thead class="table-secondary">
                        <tr>
                            <th class="text-start ps-3"></th>
                            <th>Inscritos</th>
                            <th>Llegaron</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-primary">
                            <td class="fw-bold text-start ps-3"><i class="fas fa-mars"></i> Hombre</td>
                            <td class="fw-bold fs-5"><?= $resumen['hombres_inscritos'] ?></td>
                            <td class="fw-bold fs-5 text-success"><?= $resumen['hombres_llegaron'] ?></td>
                        </tr>
                        <tr class="table-danger">
                            <td class="fw-bold text-start ps-3"><i class="fas fa-venus"></i> Mujer</td>
                            <td class="fw-bold fs-5"><?= $resumen['mujeres_inscritas'] ?></td>
                            <td class="fw-bold fs-5 text-success"><?= $resumen['mujeres_llegaron'] ?></td>
                        </tr>
                        <tr class="table-dark text-white">
                            <td class="fw-bold text-start ps-3">TOTAL</td>
                            <td class="fw-bold fs-5"><?= $resumen['total_inscritos'] ?></td>
                            <td class="fw-bold fs-5"><?= $resumen['total_llegaron'] ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ══ §2 BALANCE DE PAGOS ══ -->
    <div class="col-md-8">
        <div class="section-label text-uppercase text-muted small fw-bold mb-2 ps-1 d-none d-md-block">
            <i class="fas fa-dollar-sign fa-xs me-1"></i> Balance de Pagos
        </div>
        <div class="card h-100">
            <div class="card-header bg-dark text-white">
                <h6 class="mb-0">
                    <i class="fas fa-dollar-sign"></i> Balance General de Pagos
                    <small class="text-muted ms-2 fw-normal">individuales + grupos</small>
                </h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered mb-0">
                    <thead class="table-secondary text-center">
                        <tr>
                            <th class="text-start ps-3">Modo de Pago</th>
                            <th>Indiv.</th>
                            <th>Grupos</th>
                            <th class="text-end pe-3">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $modos = [
                        'banco'         => ['label' => 'Banco',        'icon' => 'fas fa-university',   'color' => 'primary'],
                        'efectivo'      => ['label' => 'Efectivo',      'icon' => 'fas fa-money-bill',   'color' => 'success'],
                        'transferencia' => ['label' => 'Transferencia', 'icon' => 'fas fa-exchange-alt', 'color' => 'info'],
                    ];
                    foreach ($modos as $key => $m): ?>
                    <tr>
                        <td class="ps-3">
                            <span class="badge bg-<?= $m['color'] ?> me-2">
                                <i class="<?= $m['icon'] ?>"></i>
                            </span>
                            <?= $m['label'] ?>
                        </td>
                        <td class="text-center text-muted small">
                            <?php if ($pagos_ind[$key] > 0): ?>
                                $<?= number_format($pagos_ind[$key], 0) ?>
                                <span class="text-muted">(<?= $pagos_ind_count[$key] ?>)</span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-center text-muted small">
                            <?php if ($pagos_grp[$key] > 0): ?>
                                $<?= number_format($pagos_grp[$key], 0) ?>
                                <span class="text-muted">(<?= $pagos_grp_count[$key] ?>g)</span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-end fw-bold pe-3">
                            $<?= number_format($pagos_total[$key], 2) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-success fw-bold">
                            <td class="ps-3">SUMA COBRADA</td>
                            <td class="text-center small text-muted">$<?= number_format($total_cobrado_ind, 0) ?></td>
                            <td class="text-center small text-muted">$<?= number_format($total_cobrado_grp, 0) ?></td>
                            <td class="text-end fs-6 pe-3">$<?= number_format($total_cobrado, 2) ?></td>
                        </tr>
                        <tr class="table-light">
                            <td class="text-muted ps-3">Total esperado</td>
                            <td colspan="2"></td>
                            <td class="text-end text-muted pe-3">$<?= number_format($total_esperado, 2) ?></td>
                        </tr>
                        <tr class="<?= $saldo_pendiente > 0 ? 'table-danger' : 'table-success' ?> fw-bold">
                            <td class="ps-3">SALDO PENDIENTE</td>
                            <td colspan="2"></td>
                            <td class="text-end pe-3">$<?= number_format($saldo_pendiente, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ══ §3 GRUPOS DE CAMPAMENTO ══ -->
<?php if (!empty($grupos_campamento)): ?>
<div class="section-label text-uppercase text-muted small fw-bold mb-2 ps-1">
    <i class="fas fa-users fa-xs me-1"></i> Grupos de Campamento
</div>
<div class="card mb-5">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">
            <i class="fas fa-users"></i> Grupos de Campamento
            <span class="badge bg-secondary ms-1"><?= count($grupos_campamento) ?></span>
        </h6>
        <?php
        $g_total_ac  = array_sum(array_column($grupos_campamento, 'total_acampantes'));
        $g_total_pag = array_sum(array_column($grupos_campamento, 'total_pagado'));
        $g_total_cos = array_sum(array_column($grupos_campamento, 'costo_total_real'));
        $g_total_lle = array_sum(array_column($grupos_campamento, 'total_llegaron'));
        ?>
        <small class="text-muted">
            <?= $g_total_ac ?> acampantes · $<?= number_format($g_total_cos, 0) ?> esperado
        </small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-secondary">
                    <tr>
                        <th>#</th>
                        <th>Grupo / Encargado</th>
                        <th class="text-center">Acampantes</th>
                        <th class="text-center">Check-in</th>
                        <th class="text-center" style="min-width:110px;">% Asistencia</th>
                        <th class="text-end">Costo</th>
                        <th class="text-end">Pagado</th>
                        <th class="text-end">Saldo</th>
                        <th class="text-center">Estado Pago</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($grupos_campamento as $gi => $gr):
                    $costo_gr      = (float)$gr['costo_total_real'];
                    $pagado_gr     = (float)$gr['total_pagado'];
                    $saldo_gr      = max(0, $costo_gr - $pagado_gr);
                    $es_beca_gr    = ($costo_gr == 0 && $gr['total_acampantes'] > 0);
                    $pagado_100_gr = $es_beca_gr || ($costo_gr > 0 && $pagado_gr >= $costo_gr);
                    $pct_gr        = $costo_gr > 0 ? min(100, round($pagado_gr / $costo_gr * 100)) : ($pagado_100_gr ? 100 : 0);
                    $pct_asist_gr  = $gr['total_acampantes'] > 0
                        ? round(($gr['total_llegaron'] / $gr['total_acampantes']) * 100) : 0;
                ?>
                <tr class="<?= $pagado_100_gr ? 'table-success' : '' ?>">
                    <td class="text-muted small"><?= $gi + 1 ?></td>
                    <td>
                        <a href="grupos/ver_grupo.php?id=<?= $gr['id'] ?>"
                           class="fw-bold text-decoration-none">
                            <?= htmlspecialchars($gr['grupo_nombre']) ?>
                        </a>
                    </td>
                    <td class="text-center"><span class="badge bg-primary"><?= $gr['total_acampantes'] ?></span></td>
                    <td class="text-center"><span class="badge bg-success"><?= $gr['total_llegaron'] ?></span></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:6px;">
                                <div class="progress-bar bg-<?= $pct_asist_gr >= 80 ? 'success' : ($pct_asist_gr >= 50 ? 'warning' : 'danger') ?>"
                                     style="width:<?= $pct_asist_gr ?>%"></div>
                            </div>
                            <small class="text-nowrap"><?= $pct_asist_gr ?>%</small>
                        </div>
                    </td>
                    <td class="text-end small">
                        <?= $es_beca_gr
                            ? '<span class="badge bg-info"><i class="fas fa-award fa-xs"></i> Beca</span>'
                            : '$' . number_format($costo_gr, 0) ?>
                    </td>
                    <td class="text-end small fw-bold text-success">$<?= number_format($pagado_gr, 0) ?></td>
                    <td class="text-end small <?= $saldo_gr > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                        $<?= number_format($saldo_gr, 0) ?>
                    </td>
                    <td class="text-center">
                        <?php if ($es_beca_gr): ?>
                            <span class="badge bg-info"><i class="fas fa-award fa-xs"></i> Beca</span>
                        <?php elseif ($pagado_100_gr): ?>
                            <span class="badge bg-success"><i class="fas fa-check-double fa-xs"></i> Pagado</span>
                        <?php elseif ($pagado_gr > 0): ?>
                            <div class="d-flex align-items-center gap-1">
                                <div class="progress" style="height:6px;min-width:50px;">
                                    <div class="progress-bar bg-warning" style="width:<?= $pct_gr ?>%"></div>
                                </div>
                                <small class="text-warning fw-bold"><?= $pct_gr ?>%</small>
                            </div>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="fas fa-times fa-xs"></i> Sin pago</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="table-dark fw-bold">
                    <td colspan="2">TOTALES</td>
                    <td class="text-center"><?= $g_total_ac ?></td>
                    <td class="text-center"><?= $g_total_lle ?></td>
                    <td></td>
                    <td class="text-end">$<?= number_format($g_total_cos, 0) ?></td>
                    <td class="text-end text-success">$<?= number_format($g_total_pag, 0) ?></td>
                    <td class="text-end text-danger">$<?= number_format(max(0, $g_total_cos - $g_total_pag), 0) ?></td>
                    <td></td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ §4 BECADOS ══ -->
<?php if (!empty($becados)):
    $becados_llegaron = count(array_filter($becados, fn($b) => $b['llego']));
?>
<div class="section-label text-uppercase text-muted small fw-bold mb-2 ps-1">
    <i class="fas fa-award fa-xs me-1"></i> Becados
</div>
<div class="card mb-5">
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">
            <i class="fas fa-award"></i> Acampantes con Beca Completa
            <span class="badge bg-white text-dark ms-1"><?= count($becados) ?></span>
        </h6>
        <small>
            <i class="fas fa-check-circle"></i>
            <?= $becados_llegaron ?> de <?= count($becados) ?> han llegado
        </small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-secondary">
                    <tr>
                        <th>#</th>
                        <th>Nombre</th>
                        <th class="text-center">Sexo</th>
                        <th class="text-center">Edad</th>
                        <th>Iglesia</th>
                        <th>Grupo</th>
                        <th class="text-center">Check-in</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($becados as $bi => $b): ?>
                <tr class="<?= $b['llego'] ? 'table-success' : '' ?>">
                    <td class="text-muted small"><?= $bi + 1 ?></td>
                    <td class="fw-bold"><?= htmlspecialchars($b['nombre']) ?></td>
                    <td class="text-center">
                        <?php if ($b['sexo'] === 'masculino'): ?>
                            <span class="text-primary">♂</span>
                        <?php elseif ($b['sexo'] === 'femenino'): ?>
                            <span class="text-danger">♀</span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-center small"><?= $b['edad'] ?? '—' ?></td>
                    <td class="small text-muted">
                        <?= $b['iglesia']
                            ? htmlspecialchars($b['iglesia'])
                            : '<span class="text-danger">Sin iglesia</span>' ?>
                    </td>
                    <td class="small">
                        <?= $b['grupo_nombre']
                            ? '<span class="badge bg-secondary"><i class="fas fa-users fa-xs"></i> ' . htmlspecialchars($b['grupo_nombre']) . '</span>'
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-center">
                        <?php if ($b['llego']): ?>
                            <span class="badge bg-success"><i class="fas fa-check"></i> Sí</span>
                            <?php if ($b['fecha_llegada']): ?>
                            <br><small class="text-muted" style="font-size:.7rem;">
                                <?= date('d/m H:i', strtotime($b['fecha_llegada'])) ?>
                            </small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-secondary"><i class="fas fa-clock"></i> Pendiente</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="6">
                            Total: <strong><?= count($becados) ?></strong>
                            &nbsp;·&nbsp; Llegaron: <strong class="text-success"><?= $becados_llegaron ?></strong>
                            &nbsp;·&nbsp; Pendientes: <strong class="text-danger"><?= count($becados) - $becados_llegaron ?></strong>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ §5 IGLESIAS ══ -->
<div class="section-label text-uppercase text-muted small fw-bold mb-2 ps-1">
    <i class="fas fa-church fa-xs me-1"></i> Iglesias
</div>
<div class="card mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0">
            <i class="fas fa-church"></i> Grupos por Iglesia
            <span class="badge bg-secondary ms-1"><?= $ig_total_rows ?></span>
        </h6>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ($sin_iglesia > 0): ?>
            <small class="text-warning">
                <i class="fas fa-exclamation-triangle"></i> <?= $sin_iglesia ?> sin iglesia
            </small>
            <?php endif; ?>
            <form method="GET" class="d-flex gap-1">
                <input type="hidden" name="semana_id" value="<?= $semana_id ?>">
                <input type="text" name="iglesia_buscar"
                       class="form-control form-control-sm"
                       placeholder="🔍 Buscar iglesia..."
                       value="<?= htmlspecialchars($iglesia_filtro) ?>"
                       style="min-width:180px;">
                <button class="btn btn-sm btn-light" type="submit">
                    <i class="fas fa-search"></i>
                </button>
                <?php if ($iglesia_filtro): ?>
                <a href="?semana_id=<?= $semana_id ?>" class="btn btn-sm btn-outline-light">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-secondary">
                    <tr>
                        <th>#</th>
                        <th>Iglesia</th>
                        <th class="text-center">Inscritos</th>
                        <th class="text-center">Llegaron</th>
                        <th class="text-center" style="min-width:110px;">% Asistencia</th>
                        <th class="text-end">Esperado</th>
                        <th class="text-end">Pagado</th>
                        <th class="text-end">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($iglesias)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">
                        <?= $iglesia_filtro
                            ? 'Sin resultados para "' . htmlspecialchars($iglesia_filtro) . '"'
                            : 'Sin datos de iglesias' ?>
                    </td></tr>
                <?php else: ?>
                <?php foreach ($iglesias as $i => $igl):
                    $pct_asist = $igl['inscritos'] > 0
                        ? round(($igl['llegaron'] / $igl['inscritos']) * 100) : 0;
                    $saldo_igl = (float)$igl['total_esperado'] - (float)$igl['total_pagado'];
                    $row_num   = $ig_offset + $i + 1;
                    $url_igl   = 'lista_acampantes.php?semana_id=' . $semana_id
                               . '&search=' . urlencode($igl['iglesia']);
                ?>
                <tr>
                    <td class="text-muted small"><?= $row_num ?></td>
                    <td>
                        <a href="<?= $url_igl ?>" class="text-decoration-none fw-bold"
                           title="Ver acampantes de <?= htmlspecialchars($igl['iglesia']) ?>">
                            <i class="fas fa-church text-muted me-1 fa-xs"></i>
                            <?= htmlspecialchars($igl['iglesia']) ?>
                            <i class="fas fa-external-link-alt fa-xs text-muted ms-1"></i>
                        </a>
                    </td>
                    <td class="text-center"><span class="badge bg-primary"><?= $igl['inscritos'] ?></span></td>
                    <td class="text-center"><span class="badge bg-success"><?= $igl['llegaron'] ?></span></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:6px;">
                                <div class="progress-bar bg-<?= $pct_asist >= 80 ? 'success' : ($pct_asist >= 50 ? 'warning' : 'danger') ?>"
                                     style="width:<?= $pct_asist ?>%"></div>
                            </div>
                            <small class="text-nowrap"><?= $pct_asist ?>%</small>
                        </div>
                    </td>
                    <td class="text-end small">$<?= number_format($igl['total_esperado'], 2) ?></td>
                    <td class="text-end small fw-bold text-success">$<?= number_format($igl['total_pagado'], 2) ?></td>
                    <td class="text-end small <?= $saldo_igl > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                        $<?= number_format($saldo_igl, 2) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <!-- Totales página -->
                <tr class="table-dark fw-bold">
                    <td colspan="2">TOTALES <?= $ig_total_pages > 1 ? '(pág. ' . $ig_page . ')' : '' ?></td>
                    <td class="text-center"><?= array_sum(array_column($iglesias, 'inscritos')) ?></td>
                    <td class="text-center"><?= array_sum(array_column($iglesias, 'llegaron')) ?></td>
                    <td></td>
                    <td class="text-end">$<?= number_format(array_sum(array_column($iglesias, 'total_esperado')), 2) ?></td>
                    <td class="text-end text-success">$<?= number_format(array_sum(array_column($iglesias, 'total_pagado')), 2) ?></td>
                    <td class="text-end text-danger">
                        $<?= number_format(
                            array_sum(array_column($iglesias, 'total_esperado')) -
                            array_sum(array_column($iglesias, 'total_pagado')), 2) ?>
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Paginación -->
        <?php if ($ig_total_pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top bg-light">
            <small class="text-muted">
                Mostrando <?= $ig_offset + 1 ?>–<?= min($ig_offset + $ig_per_page, $ig_total_rows) ?>
                de <?= $ig_total_rows ?> iglesias
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $ig_total_pages; $p++): ?>
                    <li class="page-item <?= $p == $ig_page ? 'active' : '' ?>">
                        <a class="page-link"
                           href="?semana_id=<?= $semana_id ?>&ig_page=<?= $p ?><?= $iglesia_filtro ? '&iglesia_buscar=' . urlencode($iglesia_filtro) : '' ?>">
                            <?= $p ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ESTILOS -->
<style>
.section-label {
    letter-spacing: .06em;
    border-left: 3px solid #6c757d;
    padding-left: .5rem !important;
}
@media print {
    .navbar, .sidebar, nav, footer,
    .breadcrumb, button, .btn,
    form select, form input { display: none !important; }
    body { font-size: 11pt; }
    .col-md-4, .col-md-8 { width: 50% !important; float: left; }
    .card { border: 1px solid #ccc !important; break-inside: avoid; }
    .card-header { background-color: #333 !important; color: white !important; }
    .progress { background-color: #eee !important; }
    .progress-bar { print-color-adjust: exact; }
    .table-primary  { background-color: #cfe2ff !important; print-color-adjust: exact; }
    .table-danger   { background-color: #f8d7da !important; print-color-adjust: exact; }
    .table-dark     { background-color: #212529 !important; color: white !important; print-color-adjust: exact; }
    .table-success  { background-color: #d1e7dd !important; print-color-adjust: exact; }
    .table-info     { background-color: #d3f4ff !important; print-color-adjust: exact; }
    h1::after {
        content: " — <?= htmlspecialchars(addslashes($semana_actual['nombre'] ?? '')) ?>";
        font-size: 14pt; color: #666;
    }
}
</style>

<?php include '../includes/footer.php'; ?>