<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$titulo = "Estadísticas";

// ── Semana seleccionada ──────────────────────────────────────────────────────
$semana_id = isset($_GET['semana_id']) ? (int)$_GET['semana_id'] : null;

// Obtener todas las semanas
$stmt_sems = $pdo->query("SELECT * FROM semanas_campamento ORDER BY fecha_inicio DESC");
$semanas   = $stmt_sems->fetchAll();

// Si no eligieron semana, usar la activa
if (!$semana_id) {
    foreach ($semanas as $s) {
        if ($s['activa']) { $semana_id = $s['id']; break; }
    }
}

// Datos de la semana seleccionada
$semana_actual = null;
foreach ($semanas as $s) {
    if ($s['id'] == $semana_id) { $semana_actual = $s; break; }
}

// ── BLOQUE 1: Resumen hombres / mujeres ─────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        SUM(sexo = 'masculino')                         AS hombres_inscritos,
        SUM(sexo = 'femenino')                          AS mujeres_inscritas,
        SUM(sexo = 'masculino' AND llego = 1)           AS hombres_llegaron,
        SUM(sexo = 'femenino'  AND llego = 1)           AS mujeres_llegaron,
        COUNT(*)                                        AS total_inscritos,
        SUM(llego = 1)                                  AS total_llegaron,
        SUM(primera_vez_campamento = 1)                 AS primera_vez_inscritos,
        SUM(primera_vez_campamento = 1 AND llego = 1)   AS primera_vez_llegaron
    FROM acampantes
    WHERE semana_id = ? AND estado = 'activo'
");
$stmt->execute([$semana_id]);
$resumen = $stmt->fetch();

// ── BLOQUE 2: Balance general de pagos por modo ──────────────────────────────
// pagos_acampante: modo_pago enum('efectivo','banco','transferencia')
$stmt = $pdo->prepare("
    SELECT
        p.modo_pago,
        SUM(p.monto)  AS total_monto,
        COUNT(DISTINCT p.acampante_id) AS num_acampantes
    FROM pagos_acampante p
    INNER JOIN acampantes a ON p.acampante_id = a.id
    WHERE a.semana_id = ? AND a.estado = 'activo'
    GROUP BY p.modo_pago
");
$stmt->execute([$semana_id]);
$pagos_raw = $stmt->fetchAll();

// Estructurar por modo
$pagos = ['banco' => 0, 'efectivo' => 0, 'transferencia' => 0];
$pagos_count = ['banco' => 0, 'efectivo' => 0, 'transferencia' => 0];
foreach ($pagos_raw as $p) {
    $pagos[$p['modo_pago']]       = (float)$p['total_monto'];
    $pagos_count[$p['modo_pago']] = (int)$p['num_acampantes'];
}
$total_cobrado = array_sum($pagos);

// Total esperado (costo_total de acampantes que llegaron)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(costo_total), 0) AS total_esperado
    FROM acampantes
    WHERE semana_id = ? AND estado = 'activo'
");
$stmt->execute([$semana_id]);
$total_esperado = (float)$stmt->fetchColumn();
$saldo_pendiente = $total_esperado - $total_cobrado;

// ── BLOQUE 3: Grupos de iglesia ──────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        iglesia,
        COUNT(*)               AS inscritos,
        SUM(llego = 1)         AS llegaron,
        SUM(costo_total)       AS total_esperado,
        COALESCE(
            (SELECT SUM(p2.monto)
             FROM pagos_acampante p2
             INNER JOIN acampantes a2 ON p2.acampante_id = a2.id
             WHERE a2.semana_id = ? AND a2.estado = 'activo'
               AND a2.iglesia = a.iglesia)
        , 0)                   AS total_pagado
    FROM acampantes a
    WHERE semana_id = ? AND estado = 'activo'
      AND iglesia IS NOT NULL AND iglesia != '' AND iglesia != 'Si' AND iglesia != 'No'
    GROUP BY iglesia
    ORDER BY llegaron DESC, inscritos DESC
");
$stmt->execute([$semana_id, $semana_id]);
$iglesias = $stmt->fetchAll();

// ── BLOQUE 4: Acampantes sin iglesia asignada ────────────────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM acampantes
    WHERE semana_id = ? AND estado = 'activo'
      AND (iglesia IS NULL OR iglesia = '' OR iglesia IN ('Si','No'))
");
$stmt->execute([$semana_id]);
$sin_iglesia = (int)$stmt->fetchColumn();

include '../includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════
     CABECERA + SELECTOR DE SEMANA
══════════════════════════════════════════════════════════ -->
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
    <div>
        <h1 class="mb-1"><i class="fas fa-chart-bar"></i> <?php echo $titulo; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Estadísticas</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <!-- Selector de semana -->
        <form method="GET" class="d-flex gap-2 align-items-center">
            <select name="semana_id" class="form-select" onchange="this.form.submit()" style="min-width:220px;">
                <option value="">-- Seleccionar semana --</option>
                <?php foreach ($semanas as $s): ?>
                <option value="<?php echo $s['id']; ?>"
                    <?php echo ($s['id'] == $semana_id) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($s['nombre']); ?>
                    <?php echo $s['activa'] ? ' ✓' : ''; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <!-- Botón imprimir -->
        <button onclick="window.print()" class="btn btn-outline-secondary">
            <i class="fas fa-print"></i> Imprimir
        </button>
    </div>
</div>

<?php if (!$semana_actual): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i> Selecciona una semana para ver las estadísticas.
</div>
<?php include '../includes/footer.php'; exit(); ?>
<?php endif; ?>

<!-- Banner semana -->
<div class="alert alert-success border-0 mb-4 d-flex align-items-center gap-3">
    <i class="fas fa-calendar-week fa-2x"></i>
    <div>
        <strong class="fs-5"><?php echo htmlspecialchars($semana_actual['nombre']); ?></strong>
        <div class="text-muted small">
            <?php echo date('d/m/Y', strtotime($semana_actual['fecha_inicio'])); ?> —
            <?php echo date('d/m/Y', strtotime($semana_actual['fecha_fin'])); ?>
            &nbsp;·&nbsp; Precio base:
            <strong>$<?php echo number_format($semana_actual['costo_campamento'], 2); ?></strong>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     BLOQUE 1 — RESUMEN INSCRITOS / LLEGARON
══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">

    <!-- Tarjetas grandes -->
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-primary text-white h-100">
            <div class="card-body text-center py-4">
                <i class="fas fa-users fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0"><?php echo $resumen['total_inscritos']; ?></h2>
                <div class="small opacity-75">Total Inscritos</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-success text-white h-100">
            <div class="card-body text-center py-4">
                <i class="fas fa-map-marker-alt fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0"><?php echo $resumen['total_llegaron']; ?></h2>
                <div class="small opacity-75">Han Llegado</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-warning text-dark h-100">
            <div class="card-body text-center py-4">
                <i class="fas fa-star fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0"><?php echo $resumen['primera_vez_llegaron']; ?></h2>
                <div class="small opacity-50">
                    Primera vez llegaron
                    <span class="fw-bold d-block">
                        (<?php echo $resumen['primera_vez_inscritos']; ?> inscritos)
                    </span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-danger text-white h-100">
            <div class="card-body text-center py-4">
                <i class="fas fa-hourglass-half fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0">
                    <?php echo $resumen['total_inscritos'] - $resumen['total_llegaron']; ?>
                </h2>
                <div class="small opacity-75">Pendientes de llegar</div>
            </div>
        </div>
    </div>
</div>

<!-- Tabla Hombres / Mujeres -->
<div class="row g-3 mb-4">
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header bg-dark text-white">
                <h6 class="mb-0"><i class="fas fa-venus-mars"></i> Resumen por Sexo</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered mb-0 text-center">
                    <thead class="table-secondary">
                        <tr>
                            <th></th>
                            <th><i class="fas fa-users"></i> Inscritos</th>
                            <th><i class="fas fa-map-marker-alt"></i> Llegaron</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-primary">
                            <td class="fw-bold text-start ps-3">
                                <i class="fas fa-mars"></i> Hombre
                            </td>
                            <td class="fw-bold fs-5"><?php echo $resumen['hombres_inscritos']; ?></td>
                            <td class="fw-bold fs-5 text-success"><?php echo $resumen['hombres_llegaron']; ?></td>
                        </tr>
                        <tr class="table-danger">
                            <td class="fw-bold text-start ps-3">
                                <i class="fas fa-venus"></i> Mujer
                            </td>
                            <td class="fw-bold fs-5"><?php echo $resumen['mujeres_inscritas']; ?></td>
                            <td class="fw-bold fs-5 text-success"><?php echo $resumen['mujeres_llegaron']; ?></td>
                        </tr>
                        <tr class="table-dark text-white">
                            <td class="fw-bold text-start ps-3">TOTAL</td>
                            <td class="fw-bold fs-5"><?php echo $resumen['total_inscritos']; ?></td>
                            <td class="fw-bold fs-5"><?php echo $resumen['total_llegaron']; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Balance general de pagos -->
    <div class="col-md-7">
        <div class="card h-100">
            <div class="card-header bg-dark text-white">
                <h6 class="mb-0"><i class="fas fa-dollar-sign"></i> Balance General de Pagos</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered mb-0">
                    <thead class="table-secondary text-center">
                        <tr>
                            <th>Modo de Pago</th>
                            <th>Acampantes</th>
                            <th class="text-end">Total Cobrado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $modos = [
                            'banco'         => ['label' => 'Banco',         'icon' => 'fas fa-university',   'color' => 'primary'],
                            'efectivo'      => ['label' => 'Efectivo',       'icon' => 'fas fa-money-bill',   'color' => 'success'],
                            'transferencia' => ['label' => 'Transferencia',  'icon' => 'fas fa-exchange-alt', 'color' => 'info'],
                        ];
                        foreach ($modos as $key => $m):
                        ?>
                        <tr>
                            <td>
                                <span class="badge bg-<?php echo $m['color']; ?> me-2">
                                    <i class="<?php echo $m['icon']; ?>"></i>
                                </span>
                                <?php echo $m['label']; ?>
                            </td>
                            <td class="text-center"><?php echo $pagos_count[$key]; ?></td>
                            <td class="text-end fw-bold">$<?php echo number_format($pagos[$key], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-success fw-bold">
                            <td>SUMA COBRADA</td>
                            <td></td>
                            <td class="text-end fs-5">$<?php echo number_format($total_cobrado, 2); ?></td>
                        </tr>
                        <tr class="table-light">
                            <td class="text-muted">Total esperado</td>
                            <td></td>
                            <td class="text-end text-muted">$<?php echo number_format($total_esperado, 2); ?></td>
                        </tr>
                        <tr class="<?php echo $saldo_pendiente > 0 ? 'table-danger' : 'table-success'; ?> fw-bold">
                            <td>SALDO PENDIENTE</td>
                            <td></td>
                            <td class="text-end">$<?php echo number_format($saldo_pendiente, 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     BLOQUE 2 — GRUPOS POR IGLESIA
══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">
            <i class="fas fa-church"></i> Grupos por Iglesia
            <span class="badge bg-secondary ms-1"><?php echo count($iglesias); ?></span>
        </h6>
        <?php if ($sin_iglesia > 0): ?>
        <small class="text-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo $sin_iglesia; ?> acampante(s) sin iglesia registrada
        </small>
        <?php endif; ?>
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
                        <th class="text-center">% Asistencia</th>
                        <th class="text-end">Esperado</th>
                        <th class="text-end">Pagado</th>
                        <th class="text-end">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($iglesias)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            Sin datos de iglesias
                        </td>
                    </tr>
                <?php else: ?>
                <?php foreach ($iglesias as $i => $igl):
                    $pct_asist = $igl['inscritos'] > 0
                        ? round(($igl['llegaron'] / $igl['inscritos']) * 100) : 0;
                    $saldo_igl = (float)$igl['total_esperado'] - (float)$igl['total_pagado'];
                ?>
                <tr>
                    <td class="text-muted small"><?php echo $i + 1; ?></td>
                    <td>
                        <i class="fas fa-church text-muted me-1"></i>
                        <strong><?php echo htmlspecialchars($igl['iglesia']); ?></strong>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-primary"><?php echo $igl['inscritos']; ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-success"><?php echo $igl['llegaron']; ?></span>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:6px;">
                                <div class="progress-bar bg-<?php echo $pct_asist >= 80 ? 'success' : ($pct_asist >= 50 ? 'warning' : 'danger'); ?>"
                                     style="width:<?php echo $pct_asist; ?>%"></div>
                            </div>
                            <small><?php echo $pct_asist; ?>%</small>
                        </div>
                    </td>
                    <td class="text-end small">$<?php echo number_format($igl['total_esperado'], 2); ?></td>
                    <td class="text-end small fw-bold text-success">$<?php echo number_format($igl['total_pagado'], 2); ?></td>
                    <td class="text-end small <?php echo $saldo_igl > 0 ? 'text-danger fw-bold' : 'text-success'; ?>">
                        $<?php echo number_format($saldo_igl, 2); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <!-- Totales -->
                <tr class="table-dark fw-bold">
                    <td colspan="2">TOTALES</td>
                    <td class="text-center"><?php echo array_sum(array_column($iglesias, 'inscritos')); ?></td>
                    <td class="text-center"><?php echo array_sum(array_column($iglesias, 'llegaron')); ?></td>
                    <td></td>
                    <td class="text-end">$<?php echo number_format(array_sum(array_column($iglesias, 'total_esperado')), 2); ?></td>
                    <td class="text-end text-success">$<?php echo number_format(array_sum(array_column($iglesias, 'total_pagado')), 2); ?></td>
                    <td class="text-end text-danger">
                        $<?php echo number_format(
                            array_sum(array_column($iglesias, 'total_esperado')) -
                            array_sum(array_column($iglesias, 'total_pagado')), 2
                        ); ?>
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     ESTILOS DE IMPRESIÓN
══════════════════════════════════════════════════════════ -->
<style>
@media print {
    /* Ocultar nav, sidebar, header, footer, botones */
    .navbar, .sidebar, nav, footer,
    .breadcrumb, button, .btn,
    form select { display: none !important; }

    /* Una sola columna, sin márgenes */
    body { font-size: 11pt; }
    .col-md-5, .col-md-7 { width: 50% !important; float: left; }
    .card { border: 1px solid #ccc !important; break-inside: avoid; }
    .card-header { background-color: #333 !important; color: white !important; }
    .progress { background-color: #eee !important; }
    .progress-bar { print-color-adjust: exact; }

    /* Forzar colores de tabla */
    .table-primary  { background-color: #cfe2ff !important; print-color-adjust: exact; }
    .table-danger   { background-color: #f8d7da !important; print-color-adjust: exact; }
    .table-dark     { background-color: #212529 !important; color: white !important; print-color-adjust: exact; }
    .table-success  { background-color: #d1e7dd !important; print-color-adjust: exact; }

    /* Título de la página impresa */
    h1::after {
        content: " — <?php echo htmlspecialchars(addslashes($semana_actual['nombre'] ?? '')); ?>";
        font-size: 14pt;
        color: #666;
    }
}
</style>

<?php include '../includes/footer.php'; ?>