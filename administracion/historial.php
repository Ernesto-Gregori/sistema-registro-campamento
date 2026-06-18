<?php
// administracion/historial.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministracion() && !esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$titulo = "Historial de Pagos";

// ── Filtros ──────────────────────────────────────────────────────────────────
$semana_id   = isset($_GET['semana_id'])  ? (int)$_GET['semana_id']        : 0;
$fecha_desde = trim($_GET['fecha_desde'] ?? '');
$fecha_hasta = trim($_GET['fecha_hasta'] ?? '');
$modo_filtro = trim($_GET['modo_pago']   ?? '');
$search      = trim($_GET['search']      ?? '');
$tipo        = trim($_GET['tipo']        ?? 'todos'); // todos | individual | grupo

// Defaults de fecha: semana actual
if (!$fecha_desde) $fecha_desde = date('Y-m-d');
if (!$fecha_hasta) $fecha_hasta = date('Y-m-d');

// Semanas
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

// ── PAGOS INDIVIDUALES ───────────────────────────────────────────────────────
$pagos_ind = [];
if ($tipo === 'todos' || $tipo === 'individual') {
    $sql_ind = "
        SELECT
            'individual'            AS tipo,
            p.id                    AS pago_id,
            p.fecha_pago,
            p.monto,
            p.modo_pago,
            p.notas,
            a.id                    AS acampante_id,
            a.nombre                AS acampante_nombre,
            a.sexo,
            a.iglesia,
            a.costo_total,
            NULL                    AS grupo_encargado,
            u.username              AS cajero
        FROM pagos_acampante p
        INNER JOIN acampantes a ON p.acampante_id = a.id
        LEFT JOIN  usuarios u   ON p.registrado_por = u.id
        WHERE a.semana_id = ?
          AND DATE(p.fecha_pago) BETWEEN ? AND ?
    ";
    $p_ind = [$semana_id, $fecha_desde, $fecha_hasta];

    if ($modo_filtro !== '') {
        $sql_ind .= " AND p.modo_pago = ?";
        $p_ind[]  = $modo_filtro;
    }
    if ($search !== '') {
        $sql_ind .= " AND a.nombre LIKE ?";
        $p_ind[]  = "%$search%";
    }

    $stmt = $pdo->prepare($sql_ind);
    $stmt->execute($p_ind);
    $pagos_ind = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── PAGOS DE GRUPOS ──────────────────────────────────────────────────────────
$pagos_grp = [];
if ($tipo === 'todos' || $tipo === 'grupo') {
    $sql_grp = "
        SELECT
            'grupo'                     AS tipo,
            pg.id                       AS pago_id,
            pg.fecha_pago,
            pg.monto,
            pg.modo_pago,
            pg.notas,
            g.id                        AS acampante_id,
            g.encargado_nombre          AS acampante_nombre,
            NULL                        AS sexo,
            NULL                        AS iglesia,
            NULL                        AS costo_total,
            g.encargado_nombre          AS grupo_encargado,
            u.username                  AS cajero
        FROM pagos_grupo pg
        INNER JOIN grupos_campamento g ON g.id = pg.grupo_id
        LEFT JOIN  usuarios u           ON pg.registrado_por = u.id
        WHERE g.semana_id = ?
          AND DATE(pg.fecha_pago) BETWEEN ? AND ?
    ";
    $p_grp = [$semana_id, $fecha_desde, $fecha_hasta];

    if ($modo_filtro !== '') {
        $sql_grp .= " AND pg.modo_pago = ?";
        $p_grp[]  = $modo_filtro;
    }
    if ($search !== '') {
        $sql_grp .= " AND g.encargado_nombre LIKE ?";
        $p_grp[]  = "%$search%";
    }

    $stmt = $pdo->prepare($sql_grp);
    $stmt->execute($p_grp);
    $pagos_grp = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Unir y ordenar por fecha DESC ────────────────────────────────────────────
$todos_pagos = array_merge($pagos_ind, $pagos_grp);
usort($todos_pagos, fn($a, $b) => strtotime($b['fecha_pago']) - strtotime($a['fecha_pago']));

// ── Totales por modo de pago ─────────────────────────────────────────────────
$totales = ['efectivo' => 0.0, 'banco' => 0.0, 'transferencia' => 0.0];
$total_general = 0.0;
foreach ($todos_pagos as $p) {
    $m = $p['modo_pago'];
    if (isset($totales[$m])) $totales[$m] += (float)$p['monto'];
    $total_general += (float)$p['monto'];
}

$base_path = '../';
include '../includes/header.php';
?>

<!-- ══ CABECERA ══ -->
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
    <div>
        <h1 class="mb-1"><i class="fas fa-history"></i> <?= $titulo ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item">
                    <a href="dashboard.php">Dashboard</a>
                </li>
                <li class="breadcrumb-item active">Historial</li>
            </ol>
        </nav>
    </div>
    <a href="lista_pagos.php?semana_id=<?= $semana_id ?>"
       class="btn btn-warning">
        <i class="fas fa-cash-register"></i> Ir a Caja
    </a>
</div>

<!-- ══ FILTROS ══ -->
<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <h6 class="mb-0"><i class="fas fa-filter"></i> Filtros</h6>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <!-- Semana -->
            <div class="col-md-3">
                <label class="form-label fw-bold small">Semana</label>
                <select name="semana_id" class="form-select form-select-sm">
                    <?php foreach ($semanas as $s): ?>
                    <option value="<?= $s['id'] ?>"
                            <?= ((int)$s['id'] === $semana_id) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['nombre']) ?><?= $s['activa'] ? ' ✓' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Desde -->
            <div class="col-md-2">
                <label class="form-label fw-bold small">Desde</label>
                <input type="date" name="fecha_desde" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($fecha_desde) ?>">
            </div>
            <!-- Hasta -->
            <div class="col-md-2">
                <label class="form-label fw-bold small">Hasta</label>
                <input type="date" name="fecha_hasta" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($fecha_hasta) ?>">
            </div>
            <!-- Modo pago -->
            <div class="col-md-2">
                <label class="form-label fw-bold small">Modo de pago</label>
                <select name="modo_pago" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="efectivo"      <?= $modo_filtro === 'efectivo'      ? 'selected' : '' ?>>Efectivo</option>
                    <option value="banco"         <?= $modo_filtro === 'banco'         ? 'selected' : '' ?>>Banco</option>
                    <option value="transferencia" <?= $modo_filtro === 'transferencia' ? 'selected' : '' ?>>Transferencia</option>
                </select>
            </div>
            <!-- Tipo -->
            <div class="col-md-2">
                <label class="form-label fw-bold small">Tipo</label>
                <select name="tipo" class="form-select form-select-sm">
                    <option value="todos"      <?= $tipo === 'todos'      ? 'selected' : '' ?>>Todos</option>
                    <option value="individual" <?= $tipo === 'individual' ? 'selected' : '' ?>>Individuales</option>
                    <option value="grupo"      <?= $tipo === 'grupo'      ? 'selected' : '' ?>>Grupos</option>
                </select>
            </div>
            <!-- Buscar -->
            <div class="col-md-3">
                <label class="form-label fw-bold small">Buscar nombre</label>
                <div class="input-group input-group-sm">
                    <input type="text" name="search" class="form-control"
                           placeholder="Nombre del acampante..."
                           value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                    <?php if ($search || $modo_filtro || $tipo !== 'todos'
                               || $fecha_desde !== date('Y-m-d')
                               || $fecha_hasta !== date('Y-m-d')): ?>
                    <a href="?semana_id=<?= $semana_id ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-times"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ══ TARJETAS RESUMEN ══ -->
<div class="row g-3 mb-4">
    <?php
    $modos_info = [
        'efectivo'      => ['label' => 'Efectivo',      'icon' => 'fas fa-money-bill-wave', 'color' => 'success'],
        'banco'         => ['label' => 'Banco',          'icon' => 'fas fa-university',      'color' => 'primary'],
        'transferencia' => ['label' => 'Transferencia',  'icon' => 'fas fa-exchange-alt',    'color' => 'info'],
    ];
    foreach ($modos_info as $k => $mi):
    ?>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-<?= $mi['color'] ?> text-white h-100">
            <div class="card-body text-center py-3">
                <i class="<?= $mi['icon'] ?> fa-lg mb-1 opacity-75"></i>
                <h4 class="fw-bold mb-0">$<?= number_format($totales[$k], 2) ?></h4>
                <div class="small opacity-75"><?= $mi['label'] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-dark text-white h-100">
            <div class="card-body text-center py-3">
                <i class="fas fa-dollar-sign fa-lg mb-1 opacity-75"></i>
                <h4 class="fw-bold mb-0">$<?= number_format($total_general, 2) ?></h4>
                <div class="small opacity-75">
                    Total · <?= count($todos_pagos) ?> registros
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ TABLA DE PAGOS ══ -->
<div class="card">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0">
            <i class="fas fa-list"></i> Registros de Pago
            <span class="badge bg-secondary ms-1"><?= count($todos_pagos) ?></span>
        </h6>
        <div class="d-flex gap-2 align-items-center">
            <!-- Rango de fechas activo -->
            <small class="text-muted">
                <i class="fas fa-calendar-alt"></i>
                <?= date('d/m/Y', strtotime($fecha_desde)) ?>
                <?= $fecha_desde !== $fecha_hasta
                    ? ' — ' . date('d/m/Y', strtotime($fecha_hasta))
                    : '' ?>
            </small>
            <!-- Botón imprimir -->
            <button onclick="window.print()" class="btn btn-sm btn-outline-light">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($todos_pagos)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-inbox fa-3x mb-3 d-block opacity-25"></i>
                <strong>Sin pagos en este rango</strong><br>
                <small>Ajusta los filtros de fecha o semana</small>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="tablaHistorial">
                <thead class="table-secondary">
                    <tr>
                        <th style="min-width:160px;">Fecha / Hora</th>
                        <th>Nombre</th>
                        <th class="text-center">Tipo</th>
                        <th class="text-center">Modo</th>
                        <th class="text-end">Monto</th>
                        <th>Notas</th>
                        <th>Cajero</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $fecha_grupo_actual = '';
                $subtotal_dia = 0.0;
                $count_dia    = 0;

                foreach ($todos_pagos as $idx => $p):
                    $fecha_pago = date('Y-m-d', strtotime($p['fecha_pago']));
                    $hora_pago  = date('H:i',   strtotime($p['fecha_pago']));

                    // ── Separador de día ────────────────────────────
                    if ($fecha_pago !== $fecha_grupo_actual):
                        // Mostrar subtotal del día anterior si aplica
                        if ($fecha_grupo_actual !== '' && $subtotal_dia > 0): ?>
                        <tr class="table-light text-end fw-bold border-top">
                            <td colspan="4" class="text-muted small ps-3">
                                Subtotal <?= date('d/m/Y', strtotime($fecha_grupo_actual)) ?>
                                <span class="text-muted fw-normal">(<?= $count_dia ?> registros)</span>
                            </td>
                            <td class="text-end text-success pe-3">
                                $<?= number_format($subtotal_dia, 2) ?>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                        <?php endif;

                        $fecha_grupo_actual = $fecha_pago;
                        $subtotal_dia = 0.0;
                        $count_dia    = 0;
                        ?>
                        <tr class="table-dark">
                            <td colspan="7" class="ps-3 py-2">
                                <i class="fas fa-calendar-day fa-xs me-1"></i>
                                <strong><?= date('l d \d\e F \d\e Y',
                                    strtotime($fecha_pago)) ?></strong>
                                <?php if ($fecha_pago === date('Y-m-d')): ?>
                                    <span class="badge bg-warning text-dark ms-1">Hoy</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif;

                    $subtotal_dia += (float)$p['monto'];
                    $count_dia++;

                    // Color de modo
                    $m_color = [
                        'efectivo'      => 'success',
                        'banco'         => 'primary',
                        'transferencia' => 'info',
                    ][$p['modo_pago']] ?? 'secondary';

                    // Tipo badge
                    $tipo_badge = $p['tipo'] === 'grupo'
                        ? '<span class="badge bg-secondary"><i class="fas fa-users fa-xs"></i> Grupo</span>'
                        : '<span class="badge bg-light text-dark border"><i class="fas fa-user fa-xs"></i> Indiv.</span>';
                ?>
                <tr>
                    <td class="small text-muted ps-3">
                        <i class="fas fa-clock fa-xs me-1"></i><?= $hora_pago ?>
                        <div class="text-muted" style="font-size:.7rem;">
                            ID #<?= $p['pago_id'] ?>
                        </div>
                    </td>
                    <td>
                        <span class="fw-bold"><?= htmlspecialchars($p['acampante_nombre']) ?></span>
                        <?php if ($p['iglesia']): ?>
                        <div class="small text-muted">
                            <i class="fas fa-church fa-xs"></i>
                            <?= htmlspecialchars($p['iglesia']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= $tipo_badge ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $m_color ?>">
                            <?= ucfirst($p['modo_pago']) ?>
                        </span>
                    </td>
                    <td class="text-end fw-bold text-success pe-3">
                        $<?= number_format((float)$p['monto'], 2) ?>
                    </td>
                    <td class="small text-muted">
                        <?= htmlspecialchars($p['notas'] ?? '—') ?>
                    </td>
                    <td class="small text-muted">
                        <i class="fas fa-user-tie fa-xs"></i>
                        <?= htmlspecialchars($p['cajero'] ?? '—') ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <!-- Subtotal del último día -->
                <?php if ($subtotal_dia > 0): ?>
                <tr class="table-light text-end fw-bold border-top">
                    <td colspan="4" class="text-muted small ps-3">
                        Subtotal <?= date('d/m/Y', strtotime($fecha_grupo_actual)) ?>
                        <span class="text-muted fw-normal">(<?= $count_dia ?> registros)</span>
                    </td>
                    <td class="text-end text-success pe-3">
                        $<?= number_format($subtotal_dia, 2) ?>
                    </td>
                    <td colspan="2"></td>
                </tr>
                <?php endif; ?>

                <!-- TOTAL GENERAL -->
                <tr class="table-dark fw-bold">
                    <td colspan="4" class="ps-3">
                        TOTAL GENERAL
                        <span class="fw-normal text-muted small ms-1">
                            (<?= count($todos_pagos) ?> registros ·
                            <?= date('d/m/Y', strtotime($fecha_desde)) ?>
                            <?= $fecha_desde !== $fecha_hasta
                                ? '→ ' . date('d/m/Y', strtotime($fecha_hasta))
                                : '' ?>)
                        </span>
                    </td>
                    <td class="text-end text-success fs-6 pe-3">
                        $<?= number_format($total_general, 2) ?>
                    </td>
                    <td colspan="2"></td>
                </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ ESTILOS IMPRESIÓN ══ -->
<style>
@media print {
    .navbar, .sidebar, nav, footer,
    .breadcrumb, button, .btn,
    form, .card-header .btn { display: none !important; }
    body { font-size: 10pt; }
    .card { border: 1px solid #ccc !important; break-inside: avoid; }
    .card-header { background-color: #333 !important; color: #fff !important; print-color-adjust: exact; }
    .table-dark   { background-color: #222 !important; color: #fff !important; print-color-adjust: exact; }
    .table-light  { background-color: #f8f9fa !important; print-color-adjust: exact; }
    .badge        { border: 1px solid #999 !important; print-color-adjust: exact; }
    h1::after {
        content: " — <?= htmlspecialchars(addslashes($semana_actual['nombre'] ?? '')) ?>";
        font-size: 12pt; color: #555;
    }
}
</style>

<?php include '../includes/footer.php'; ?>