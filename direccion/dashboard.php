<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
verificarMantenimiento($pdo);
if (!esDireccion()) {
    header('Location: ../login.php');
    exit();
}

$titulo = "Dashboard — Dirección de Campamento";

// Semana seleccionada (por GET o la activa por defecto)
$semana_id = (int)($_GET['semana_id'] ?? 0) ?: null;

try {
    // Semana activa
    $stmt = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
    $semana_activa = $stmt->fetch();

    if (!$semana_id && $semana_activa) {
        $semana_id = $semana_activa['id'];
    }

    // Todas las semanas para el selector
    $stmt = $pdo->query("SELECT * FROM semanas_campamento ORDER BY fecha_inicio DESC");
    $todasSemanas = $stmt->fetchAll();

    // Semana seleccionada
    $semana_sel = null;
    if ($semana_id) {
        $stmt = $pdo->prepare("SELECT * FROM semanas_campamento WHERE id = ?");
        $stmt->execute([$semana_id]);
        $semana_sel = $stmt->fetch();
    }

    // ── Condicionales de filtro ───────────────────────────────
    $where_a  = $semana_id ? "a.semana_id = ?"       : "a.year_campamento = YEAR(NOW())";
    $where_p  = $semana_id ? "p.semana_id = ?"       : "YEAR(p.fecha_pago) = YEAR(NOW())";
    $where_sc = $semana_id ? "a.semana_id = ?"       : "a.year_campamento = YEAR(NOW())";
    $param    = $semana_id ?: null;

    // ══ BLOQUE 1: Acampantes ══════════════════════════════════
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                   AS total_registrados,
            COUNT(CASE WHEN a.llego = 1              THEN 1 END)       AS total_llegaron,
            COUNT(CASE WHEN a.primera_vez_campamento = 1 THEN 1 END)   AS primera_vez,
            COUNT(CASE WHEN a.sexo = 'masculino'     THEN 1 END)       AS masculino,
            COUNT(CASE WHEN a.sexo = 'femenino'      THEN 1 END)       AS femenino,
            COUNT(CASE WHEN a.enviado_cabana = 1     THEN 1 END)       AS en_cabana
        FROM acampantes a
        WHERE $where_a AND a.estado = 'activo'
    ");
    $semana_id ? $stmt->execute([$semana_id]) : $stmt->execute([]);
    $stats_acampantes = $stmt->fetch();

    // ══ BLOQUE 2: Finanzas ════════════════════════════════════
    // Pagos individuales por modo
    $pagos_ind       = ['banco' => 0, 'efectivo' => 0, 'transferencia' => 0];
    $pagos_ind_count = ['banco' => 0, 'efectivo' => 0, 'transferencia' => 0];
    $total_cobrado_ind = 0;
    
    try {
        if ($semana_id) {
            $stmt = $pdo->prepare("
                SELECT pa.modo_pago,
                       COALESCE(SUM(pa.monto), 0)         AS total_monto,
                       COUNT(DISTINCT pa.acampante_id)     AS num_acampantes
                FROM pagos_acampante pa
                INNER JOIN acampantes a ON a.id = pa.acampante_id
                WHERE a.semana_id = ? AND a.estado = 'activo'
                GROUP BY pa.modo_pago
            ");
            $stmt->execute([$semana_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT pa.modo_pago,
                       COALESCE(SUM(pa.monto), 0)         AS total_monto,
                       COUNT(DISTINCT pa.acampante_id)     AS num_acampantes
                FROM pagos_acampante pa
                INNER JOIN acampantes a ON a.id = pa.acampante_id
                WHERE a.estado = 'activo'
                GROUP BY pa.modo_pago
            ");
            $stmt->execute([]);
        }
        foreach ($stmt->fetchAll() as $p) {
            $modo = $p['modo_pago'];
            if (isset($pagos_ind[$modo])) {
                $pagos_ind[$modo]       = (float)$p['total_monto'];
                $pagos_ind_count[$modo] = (int)$p['num_acampantes'];
            }
        }
        $total_cobrado_ind = array_sum($pagos_ind);
    } catch (Exception $e) {
        $total_cobrado_ind = 0;
    }

    // Pagos de grupos por modo
    $pagos_grp       = ['banco' => 0, 'efectivo' => 0, 'transferencia' => 0];
    $pagos_grp_count = ['banco' => 0, 'efectivo' => 0, 'transferencia' => 0];
    $total_cobrado_grp = 0;

    try {
        if ($semana_id) {
            $stmt = $pdo->prepare("
                SELECT pg.modo_pago,
                       COALESCE(SUM(pg.monto), 0)        AS total_monto,
                       COUNT(DISTINCT pg.grupo_id)        AS num_grupos
                FROM pagos_grupo pg
                INNER JOIN grupos_campamento g ON g.id = pg.grupo_id
                WHERE g.semana_id = ? AND g.estado = 'activo'
                GROUP BY pg.modo_pago
            ");
            $stmt->execute([$semana_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT pg.modo_pago,
                       COALESCE(SUM(pg.monto), 0)        AS total_monto,
                       COUNT(DISTINCT pg.grupo_id)        AS num_grupos
                FROM pagos_grupo pg
                INNER JOIN grupos_campamento g ON g.id = pg.grupo_id
                WHERE g.estado = 'activo'
                GROUP BY pg.modo_pago
            ");
            $stmt->execute([]);
        }
        foreach ($stmt->fetchAll() as $p) {
            $pagos_grp[$p['modo_pago']]       = (float)$p['total_monto'];
            $pagos_grp_count[$p['modo_pago']] = (int)$p['num_grupos'];
        }
        $total_cobrado_grp = array_sum($pagos_grp);
    } catch (Exception $e) {
        // La tabla pagos_grupo o grupos_campamento no existe en este entorno
        $total_cobrado_grp = 0;
    }

    // Totales combinados
    $modos_keys  = ['banco', 'efectivo', 'transferencia'];
    $pagos_total = [];
    foreach ($modos_keys as $k) {
        $pagos_total[$k] = $pagos_ind[$k] + $pagos_grp[$k];
    }
    $total_cobrado = array_sum($pagos_total);

    // Total esperado (costo_total en acampantes)
    try {
        if ($semana_id) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(costo_total), 0)
                FROM acampantes
                WHERE semana_id = ? AND estado = 'activo'
            ");
            $stmt->execute([$semana_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(costo_total), 0)
                FROM acampantes
                WHERE estado = 'activo'
            ");
            $stmt->execute([]);
        }
        $total_esperado = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
        $total_esperado = 0;
    }

    $saldo_pendiente = max(0, $total_esperado - $total_cobrado);
    // Acampantes sin ningún pago registrado
    try {
        if ($semana_id) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM acampantes a
                WHERE a.semana_id = ? AND a.estado = 'activo'
                  AND NOT EXISTS (
                      SELECT 1 FROM pagos_acampante pa
                      WHERE pa.acampante_id = a.id
                  )
            ");
            $stmt->execute([$semana_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM acampantes a
                WHERE a.estado = 'activo'
                  AND NOT EXISTS (
                      SELECT 1 FROM pagos_acampante pa
                      WHERE pa.acampante_id = a.id
                  )
            ");
            $stmt->execute([]);
        }
        $sin_pago = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $sin_pago = 0;
    }

    // ══ BLOQUE 3: Espiritual (consejeros) ════════════════════
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT a.id)                                              AS total_acampantes,
            COUNT(DISTINCT CASE WHEN sc.id IS NOT NULL THEN a.id END)         AS con_consejeria,
            COUNT(DISTINCT sc.id)                                             AS total_sesiones,
            COUNT(DISTINCT CASE WHEN a.recibio_cristo_semana = 1 THEN a.id END) AS recibio_cristo,
            COUNT(DISTINCT CASE WHEN a.consagro_vida_fogata  = 1 THEN a.id END) AS consagro_vida,
            COUNT(DISTINCT CASE WHEN a.era_creyente_antes    = 1 THEN a.id END) AS era_creyente,
            COUNT(DISTINCT CASE WHEN a.asiste_iglesia        = 1 THEN a.id END) AS asiste_iglesia
        FROM acampantes a
        LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
        WHERE $where_sc AND a.estado = 'activo'
    ");
    $semana_id ? $stmt->execute([$semana_id]) : $stmt->execute([]);
    $stats_espiritual = $stmt->fetch();

    // ══ BLOQUE 4: Progreso por día (llegadas) ═════════════════
    $stmt = $pdo->prepare("
        SELECT
            DATE(a.fecha_llegada) AS dia,
            COUNT(*)              AS cantidad
        FROM acampantes a
        WHERE $where_a AND a.estado = 'activo' AND a.llego = 1
          AND a.fecha_llegada IS NOT NULL
        GROUP BY DATE(a.fecha_llegada)
        ORDER BY dia ASC
        LIMIT 7
    ");
    $semana_id ? $stmt->execute([$semana_id]) : $stmt->execute([]);
    $llegadas_por_dia = $stmt->fetchAll();

    // ══ BLOQUE 5: Cabañas resumen ══════════════════════════════
    $stats_cabanas = ['total_cabanas' => 0, 'capacidad_total' => 0, 'total_acampantes' => 0];
    
    try {
        if ($semana_id) {
            // Subconsulta para evitar SUM(DISTINCT) que falla con capacidades iguales
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*)            AS total_cabanas,
                    SUM(capacidad_max)  AS capacidad_total,
                    SUM(num_acampantes) AS total_acampantes
                FROM (
                    SELECT
                        c.id,
                        c.capacidad_maxima                  AS capacidad_max,
                        COUNT(DISTINCT a.id)                AS num_acampantes
                    FROM cabanas c
                    INNER JOIN acampantes a ON a.cabana_id = c.id
                        AND a.semana_id = ?
                        AND a.estado    = 'activo'
                    WHERE c.activa = 1
                    GROUP BY c.id, c.capacidad_maxima
                ) AS resumen_cabanas
            ");
            $stmt->execute([$semana_id]);
            $result = $stmt->fetch();
    
            // Fallback: si nadie tiene cabana asignada, mostrar todas las activas
            if (!$result || (int)$result['total_cabanas'] === 0) {
                $stmt = $pdo->query("
                    SELECT
                        COUNT(*)                           AS total_cabanas,
                        COALESCE(SUM(capacidad_maxima), 0) AS capacidad_total,
                        0                                  AS total_acampantes
                    FROM cabanas
                    WHERE activa = 1
                ");
                $result = $stmt->fetch();
            }
    
            $stats_cabanas = $result;
    
        } else {
            // Sin semana: todas las cabañas activas del año
            $stmt = $pdo->query("
                SELECT
                    COUNT(*)            AS total_cabanas,
                    SUM(capacidad_max)  AS capacidad_total,
                    SUM(num_acampantes) AS total_acampantes
                FROM (
                    SELECT
                        c.id,
                        c.capacidad_maxima AS capacidad_max,
                        COUNT(DISTINCT a.id) AS num_acampantes
                    FROM cabanas c
                    LEFT JOIN acampantes a ON c.id = a.cabana_id
                        AND a.estado          = 'activo'
                        AND a.year_campamento = YEAR(NOW())
                    WHERE c.activa = 1
                    GROUP BY c.id, c.capacidad_maxima
                ) AS resumen_cabanas
            ");
            $stmt->execute([]);
            $stats_cabanas = $stmt->fetch();
        }
    
    } catch (Exception $e) {
        $stats_cabanas = ['total_cabanas' => 0, 'capacidad_total' => 0, 'total_acampantes' => 0];
    }

    // ══ BLOQUE 6: Top iglesias ════════════════════════════════
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(a.iglesia), ''), 'Sin iglesia') AS iglesia,
            COUNT(*) AS total
        FROM acampantes a
        WHERE $where_a AND a.estado = 'activo'
        GROUP BY iglesia
        ORDER BY total DESC
        LIMIT 5
    ");
    $semana_id ? $stmt->execute([$semana_id]) : $stmt->execute([]);
    $top_iglesias = $stmt->fetchAll();

} catch (Exception $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
}

// Cálculos de porcentajes
// Cálculos de porcentajes
$total_reg   = (int)($stats_acampantes['total_registrados'] ?? 0) ?: 1;
$total_lleg  = (int)($stats_acampantes['total_llegaron']    ?? 0);
$pct_llegada = $total_reg > 0 ? round(($total_lleg / $total_reg) * 100) : 0;

$cap_total = (int)($stats_cabanas['capacidad_total']   ?? 0);
$en_cabana = (int)($stats_cabanas['total_acampantes']  ?? 0);

// Si hay capacidad configurada, calcular ocupacion real
// Si no, usar total_llegaron como referencia y mostrar nota
if ($cap_total > 0) {
    $pct_ocup       = min(round(($en_cabana / $cap_total) * 100, 1), 100);
    $cap_disponible = true;
} else {
    $pct_ocup       = 0;
    $cap_disponible = false;
}
$cap_disponible = $cap_total > 0;
$pct_cristo  = $total_lleg > 0
    ? round(($stats_espiritual['recibio_cristo'] / $total_lleg) * 100, 1) : 0;
$pct_consag  = $total_lleg > 0
    ? round(($stats_espiritual['consagro_vida']  / $total_lleg) * 100, 1) : 0;
$pct_cons_s  = $total_lleg > 0
    ? round(($stats_espiritual['con_consejeria'] / $total_lleg) * 100, 1) : 0;

include '../includes/header.php';
?>

<!-- Encabezado -->
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="mb-1">
            <i class="fas fa-chart-line"></i> Informe de Dirección
        </h1>
        <p class="text-muted mb-0">Vista ejecutiva — solo lectura</p>
    </div>
    <!-- Selector de semana -->
    <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
        <select name="semana_id" class="form-select form-select-sm" style="min-width:220px;"
                onchange="this.form.submit()">
            <option value="">— Todas las semanas —</option>
            <?php foreach ($todasSemanas as $sem): ?>
            <option value="<?= $sem['id'] ?>"
                    <?= $semana_id == $sem['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($sem['nombre']) ?>
                <?= $sem['activa'] ? ' ✓ ACTIVA' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-times"></i>
        </a>
    </form>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<!-- Banner semana activa -->
<?php if ($semana_sel): ?>
<div class="alert alert-success border-0 py-2 mb-3">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <i class="fas fa-broadcast-tower text-success fa-lg"></i>
        <div>
            <strong><?= htmlspecialchars($semana_sel['nombre']) ?></strong>
            <?php if ($semana_sel['activa']): ?>
                <span class="badge bg-success ms-1">ACTIVA</span>
            <?php endif; ?>
            <span class="text-muted ms-2 small">
                <?= date('d/m/Y', strtotime($semana_sel['fecha_inicio'])) ?>
                al
                <?= date('d/m/Y', strtotime($semana_sel['fecha_fin'])) ?>
            </span>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info border-0 py-2 mb-3">
    <i class="fas fa-calendar-alt"></i>
    Mostrando datos acumulados de todas las semanas.
</div>
<?php endif; ?>

<!-- ══ FILA 1: KPIs principales ══ -->
<div class="row g-3 mb-4">

    <!-- Registrados -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <i class="fas fa-clipboard-list fa-2x text-secondary mb-2 opacity-75"></i>
                <h2 class="mb-0 fw-bold"><?= $stats_acampantes['total_registrados'] ?></h2>
                <div class="small text-muted">Registrados</div>
                <div class="text-muted" style="font-size:11px;">Total inscritos</div>
            </div>
        </div>
    </div>

    <!-- Llegaron al campamento -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"
             style="border-left: 4px solid #0d6efd !important;">
            <div class="card-body text-center py-3">
                <i class="fas fa-campground fa-2x text-primary mb-2 opacity-75"></i>
                <h2 class="mb-0 fw-bold text-primary"><?= $total_lleg ?></h2>
                <div class="small text-muted">En campamento</div>
                <div class="mt-1">
                    <div class="progress" style="height:5px;">
                        <div class="progress-bar bg-primary"
                             style="width:<?= $pct_llegada ?>%;"></div>
                    </div>
                    <small class="text-muted"><?= $pct_llegada ?>% de registrados</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Primera vez -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"
             style="border-left: 4px solid #0dcaf0 !important;">
            <div class="card-body text-center py-3">
                <i class="fas fa-star fa-2x text-info mb-2 opacity-75"></i>
                <h2 class="mb-0 fw-bold text-info">
                    <?= $stats_acampantes['primera_vez'] ?>
                </h2>
                <div class="small text-muted">Primera vez</div>
                <small class="text-muted">
                    <?= $total_lleg > 0
                        ? round(($stats_acampantes['primera_vez'] / $total_lleg) * 100) : 0 ?>%
                    del total
                </small>
            </div>
        </div>
    </div>

    <!-- Ocupación -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"
             style="border-left: 4px solid
                <?= !$cap_disponible ? '#6c757d' : ($pct_ocup >= 90 ? '#dc3545' : ($pct_ocup >= 70 ? '#ffc107' : '#198754')) ?> !important;">
            <div class="card-body text-center py-3">
                <i class="fas fa-home fa-2x mb-2 opacity-75
                    text-<?= !$cap_disponible ? 'secondary' : ($pct_ocup >= 90 ? 'danger' : ($pct_ocup >= 70 ? 'warning' : 'success')) ?>">
                </i>
                <h2 class="mb-0 fw-bold"><?= $pct_ocup ?>%</h2>
                <div class="small text-muted">Ocupación cabañas</div>
                <small class="text-muted">
                    <?php if ($cap_disponible): ?>
                        <?= $en_cabana ?> / <?= $cap_total ?>
                    <?php else: ?>
                        Sin config. de cabañas
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
</div>

<!-- ══ FILA 2: Finanzas ══ -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0 pb-0">
                <h6 class="mb-0">
                    <i class="fas fa-dollar-sign text-success"></i>
                    Resumen Financiero
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3 text-center">

                    <div class="col-6 col-md-2">
                        <h4 class="text-success fw-bold mb-0">
                            $<?= number_format($total_cobrado, 2) ?>
                        </h4>
                        <small class="text-muted">Total recaudado</small>
                    </div>

                    <div class="col-6 col-md-2">
                        <h4 class="text-secondary fw-bold mb-0">
                            $<?= number_format($total_esperado, 2) ?>
                        </h4>
                        <small class="text-muted">Total esperado</small>
                    </div>

                    <div class="col-6 col-md-2">
                        <h4 class="text-danger fw-bold mb-0">
                            $<?= number_format($saldo_pendiente, 2) ?>
                        </h4>
                        <small class="text-muted">Saldo pendiente</small>
                    </div>

                    <div class="col-6 col-md-2">
                        <h4 class="text-primary fw-bold mb-0">
                            $<?= number_format($pagos_total['banco'], 2) ?>
                        </h4>
                        <small class="text-muted">
                            <i class="fas fa-university"></i> Banco
                        </small>
                    </div>

                    <div class="col-6 col-md-2">
                        <h4 class="text-success fw-bold mb-0">
                            $<?= number_format($pagos_total['efectivo'], 2) ?>
                        </h4>
                        <small class="text-muted">
                            <i class="fas fa-money-bill"></i> Efectivo
                        </small>
                    </div>

                    <div class="col-6 col-md-2">
                        <h4 class="text-info fw-bold mb-0">
                            $<?= number_format($pagos_total['transferencia'], 2) ?>
                        </h4>
                        <small class="text-muted">
                            <i class="fas fa-exchange-alt"></i> Transferencia
                        </small>
                    </div>

                </div>

                <!-- Barra de avance de recaudación -->
                <?php
                $pct_recaudado = $total_esperado > 0
                    ? min(round(($total_cobrado / $total_esperado) * 100, 1), 100) : 0;
                $color_rec = $pct_recaudado >= 90 ? 'success'
                    : ($pct_recaudado >= 60 ? 'warning' : 'danger');
                ?>
                <div class="mt-3">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Avance de recaudación</span>
                        <span>
                            <?= $pct_recaudado ?>% |
                            <?= $sin_pago ?> acampante(s) sin pago registrado
                        </span>
                    </div>
                    <div class="progress" style="height:14px; border-radius:6px;">
                        <div class="progress-bar bg-<?= $color_rec ?>"
                             style="width:<?= $pct_recaudado ?>%;">
                            <?= $pct_recaudado > 15 ? $pct_recaudado . '%' : '' ?>
                        </div>
                    </div>
                </div>

                <!-- Desglose ind. vs grupos -->
                <div class="row mt-3 text-center text-muted">
                    <div class="col-6">
                        <small>
                            <i class="fas fa-user"></i> Individuales:
                            <strong class="text-dark">
                                $<?= number_format($total_cobrado_ind, 2) ?>
                            </strong>
                        </small>
                    </div>
                    <div class="col-6">
                        <small>
                            <i class="fas fa-users"></i> Grupos:
                            <strong class="text-dark">
                                $<?= number_format($total_cobrado_grp, 2) ?>
                            </strong>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ FILA 3: Espiritual + Género ══ -->
<div class="row g-3 mb-4">

    <!-- Impacto espiritual -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header border-0 pb-0">
                <h6 class="mb-0">
                    <i class="fas fa-cross text-success"></i> Impacto Espiritual
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">

                    <!-- Recibieron Cristo -->
                    <div class="col-6 col-md-3 text-center">
                        <div class="p-3 rounded"
                             style="background: rgba(25,135,84,0.08);">
                            <i class="fas fa-cross fa-2x text-success mb-2"></i>
                            <h3 class="fw-bold text-success mb-0">
                                <?= $stats_espiritual['recibio_cristo'] ?>
                            </h3>
                            <small class="text-muted">Recibieron a Cristo</small>
                            <div class="badge bg-success mt-1"><?= $pct_cristo ?>%</div>
                        </div>
                    </div>

                    <!-- Consagraciones -->
                    <div class="col-6 col-md-3 text-center">
                        <div class="p-3 rounded"
                             style="background: rgba(255,193,7,0.12);">
                            <i class="fas fa-fire fa-2x text-warning mb-2"></i>
                            <h3 class="fw-bold text-warning mb-0">
                                <?= $stats_espiritual['consagro_vida'] ?>
                            </h3>
                            <small class="text-muted">Consagraciones</small>
                            <div class="badge bg-warning text-dark mt-1">
                                <?= $pct_consag ?>%
                            </div>
                        </div>
                    </div>

                    <!-- Con consejería -->
                    <div class="col-6 col-md-3 text-center">
                        <div class="p-3 rounded"
                             style="background: rgba(13,110,253,0.08);">
                            <i class="fas fa-comments fa-2x text-primary mb-2"></i>
                            <h3 class="fw-bold text-primary mb-0">
                                <?= $stats_espiritual['con_consejeria'] ?>
                            </h3>
                            <small class="text-muted">Con consejería</small>
                            <div class="badge bg-primary mt-1"><?= $pct_cons_s ?>%</div>
                        </div>
                    </div>

                    <!-- Ya eran creyentes -->
                    <div class="col-6 col-md-3 text-center">
                        <div class="p-3 rounded bg-light">
                            <i class="fas fa-bible fa-2x text-secondary mb-2"></i>
                            <h3 class="fw-bold mb-0">
                                <?= $stats_espiritual['era_creyente'] ?>
                            </h3>
                            <small class="text-muted">Ya eran creyentes</small>
                        </div>
                    </div>
                </div>

                <!-- Barra de impacto espiritual -->
                <div class="mt-4">
                    <?php
                    $base_esp = max($total_lleg, 1);
                    $items_esp = [
                        ['Recibieron a Cristo', $stats_espiritual['recibio_cristo'], '#198754'],
                        ['Consagraciones',      $stats_espiritual['consagro_vida'],  '#ffc107'],
                        ['Con consejería',      $stats_espiritual['con_consejeria'], '#0d6efd'],
                        ['Asisten a iglesia',   $stats_espiritual['asiste_iglesia'], '#0dcaf0'],
                    ];
                    foreach ($items_esp as [$label, $num, $hex]):
                        $pct = round(($num / $base_esp) * 100, 1);
                    ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted"><?= $label ?></span>
                            <span class="fw-semibold">
                                <?= $num ?>
                                <span class="text-muted fw-normal">(<?= $pct ?>%)</span>
                            </span>
                        </div>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar"
                                 style="width:<?= $pct ?>%; background-color:<?= $hex ?>;">
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Género + Top iglesias -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header border-0 pb-0">
                <h6 class="mb-0">
                    <i class="fas fa-venus-mars"></i> Por Género
                </h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-around text-center mb-2">
                    <div>
                        <i class="fas fa-mars fa-2x text-primary"></i>
                        <h4 class="mb-0 text-primary fw-bold">
                            <?= $stats_acampantes['masculino'] ?>
                        </h4>
                        <small class="text-muted">Masculino</small>
                    </div>
                    <div class="vr"></div>
                    <div>
                        <i class="fas fa-venus fa-2x text-danger"></i>
                        <h4 class="mb-0 text-danger fw-bold">
                            <?= $stats_acampantes['femenino'] ?>
                        </h4>
                        <small class="text-muted">Femenino</small>
                    </div>
                </div>
                <?php
                $pct_m = $total_lleg > 0
                    ? round(($stats_acampantes['masculino'] / $total_lleg) * 100) : 50;
                $pct_f = 100 - $pct_m;
                ?>
                <div class="progress" style="height:14px; border-radius:6px;">
                    <div class="progress-bar bg-primary"
                         style="width:<?= $pct_m ?>%;">
                        <?= $pct_m > 15 ? $pct_m . '%' : '' ?>
                    </div>
                    <div class="progress-bar bg-danger"
                         style="width:<?= $pct_f ?>%;">
                        <?= $pct_f > 15 ? $pct_f . '%' : '' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top iglesias -->
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0 pb-0">
                <h6 class="mb-0">
                    <i class="fas fa-church"></i> Top Iglesias
                </h6>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                <?php
                $max_ig = $top_iglesias[0]['total'] ?? 1;
                foreach ($top_iglesias as $i => $ig):
                    $pct_ig = round(($ig['total'] / $max_ig) * 100);
                ?>
                <li class="list-group-item px-3 py-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary rounded-pill"
                                  style="min-width:20px;"><?= $i + 1 ?></span>
                            <small class="fw-semibold">
                                <?= htmlspecialchars($ig['iglesia']) ?>
                            </small>
                        </div>
                        <span class="badge bg-primary rounded-pill">
                            <?= $ig['total'] ?>
                        </span>
                    </div>
                    <div class="progress" style="height:4px;">
                        <div class="progress-bar bg-primary"
                             style="width:<?= $pct_ig ?>%;"></div>
                    </div>
                </li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- ══ FILA 4: Llegadas por día + tabla comparativa semanas ══ -->
<?php if (!empty($llegadas_por_dia)): ?>
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header border-0 pb-0">
                <h6 class="mb-0">
                    <i class="fas fa-chart-bar"></i> Llegadas por Día
                </h6>
            </div>
            <div class="card-body">
                <?php
                $max_dia = max(array_column($llegadas_por_dia, 'cantidad')) ?: 1;
                foreach ($llegadas_por_dia as $dia):
                    $pct_dia = round(($dia['cantidad'] / $max_dia) * 100);
                ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">
                            <?= date('D d/m', strtotime($dia['dia'])) ?>
                        </span>
                        <span class="fw-semibold"><?= $dia['cantidad'] ?></span>
                    </div>
                    <div class="progress" style="height:10px; border-radius:4px;">
                        <div class="progress-bar bg-primary"
                             style="width:<?= $pct_dia ?>%;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Semáforo rápido de estado -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header border-0 pb-0">
                <h6 class="mb-0">
                    <i class="fas fa-traffic-light"></i> Estado Actual
                </h6>
            </div>
            <div class="card-body">
                <?php
                $indicadores = [
                    [
                        'label'   => 'Llegada de acampantes',
                        'valor'   => $pct_llegada . '%',
                        'detalle' => $total_lleg . ' de ' . $stats_acampantes['total_registrados'] . ' registrados',
                        'color'   => $pct_llegada >= 80 ? 'success' : ($pct_llegada >= 50 ? 'warning' : 'danger'),
                    ],
                    [
                        'label'   => 'Ocupación cabañas',
                        'valor'   => $pct_ocup . '%',
                        'detalle' => $total_lleg . ' de ' . $stats_cabanas['capacidad_total'] . ' lugares',
                        'color'   => $pct_ocup >= 90 ? 'danger' : ($pct_ocup >= 70 ? 'warning' : 'success'),
                    ],
                    [
                        'label'   => 'Cobertura de consejería',
                        'valor'   => $pct_cons_s . '%',
                        'detalle' => $stats_espiritual['con_consejeria'] . ' de ' . $total_lleg . ' atendidos',
                        'color'   => $pct_cons_s >= 80 ? 'success' : ($pct_cons_s >= 50 ? 'warning' : 'danger'),
                    ],
                    [
                        'label'   => 'Acampantes sin pago',
                        'valor'   => $sin_pago,
                        'detalle' => 'registrados sin pago confirmado',
                        'color'   => $sin_pago == 0 ? 'success' : ($sin_pago <= 5 ? 'warning' : 'danger'),
                    ],
                ];
                foreach ($indicadores as $ind):
                ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="rounded-circle bg-<?= $ind['color'] ?> d-flex align-items-center
                                justify-content-center flex-shrink-0"
                         style="width:40px; height:40px; opacity:0.85;">
                        <i class="fas fa-circle text-white" style="font-size:10px;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between">
                            <span class="small fw-semibold"><?= $ind['label'] ?></span>
                            <span class="badge bg-<?= $ind['color'] ?>">
                                <?= $ind['valor'] ?>
                            </span>
                        </div>
                        <small class="text-muted"><?= $ind['detalle'] ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ FILA 5: Comparativa entre semanas ══ -->
<?php if (count($todasSemanas) > 1): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header border-0 pb-0">
        <h6 class="mb-0">
            <i class="fas fa-calendar-week"></i> Comparativa entre Semanas
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Semana</th>
                        <th class="text-center">Registrados</th>
                        <th class="text-center">Llegaron</th>
                        <th class="text-center">1ra vez</th>
                        <th class="text-center">Recaudado</th>
                        <th class="text-center">✝ Cristo</th>
                        <th class="text-center">Consagr.</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($todasSemanas as $sem):
                    // Mini-query por semana
                    $stmt = $pdo->prepare("
                        SELECT
                            COUNT(*)                                            AS registrados,
                            COUNT(CASE WHEN llego=1 THEN 1 END)                AS llegaron,
                            COUNT(CASE WHEN primera_vez_campamento=1 THEN 1 END) AS primera_vez,
                            COUNT(CASE WHEN recibio_cristo_semana=1 THEN 1 END) AS recibio_cristo,
                            COUNT(CASE WHEN consagro_vida_fogata=1  THEN 1 END) AS consagro_vida
                        FROM acampantes
                        WHERE semana_id = ? AND estado = 'activo'
                    ");
                    $stmt->execute([$sem['id']]);
                    $row = $stmt->fetch();

                    try {
                        $stmt2 = $pdo->prepare("
                            SELECT COALESCE(SUM(pa.monto), 0) AS total
                            FROM pagos_acampante pa
                            INNER JOIN acampantes a ON a.id = pa.acampante_id
                            WHERE a.semana_id = ? AND a.estado = 'activo'
                        ");
                        $stmt2->execute([$sem['id']]);
                        $rec_ind = (float)($stmt2->fetchColumn() ?? 0);
                    } catch (Exception $e) {
                        $rec_ind = 0;
                    }
                    
                    try {
                        $stmt3 = $pdo->prepare("
                            SELECT COALESCE(SUM(pg.monto), 0) AS total
                            FROM pagos_grupo pg
                            INNER JOIN grupos_campamento g ON g.id = pg.grupo_id
                            WHERE g.semana_id = ? AND g.estado = 'activo'
                        ");
                        $stmt3->execute([$sem['id']]);
                        $rec_grp = (float)($stmt3->fetchColumn() ?? 0);
                    } catch (Exception $e) {
                        $rec_grp = 0;
                    }
                    
                    $rec = $rec_ind + $rec_grp;
                    $es_actual = $semana_id == $sem['id'];
                ?>
                <tr class="<?= $es_actual ? 'table-primary' : '' ?>">
                    <td>
                        <strong><?= htmlspecialchars($sem['nombre']) ?></strong>
                        <?php if ($sem['activa']): ?>
                            <span class="badge bg-success ms-1" style="font-size:9px;">ACTIVA</span>
                        <?php endif; ?>
                        <div class="text-muted small">
                            <?= date('d/m/Y', strtotime($sem['fecha_inicio'])) ?>
                        </div>
                    </td>
                    <td class="text-center"><?= $row['registrados'] ?></td>
                    <td class="text-center fw-bold text-primary"><?= $row['llegaron'] ?></td>
                    <td class="text-center">
                        <span class="badge bg-info"><?= $row['primera_vez'] ?></span>
                    </td>
                    <td class="text-center fw-bold text-success">
                        $<?= number_format($rec, 2) ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-success"><?= $row['recibio_cristo'] ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-warning text-dark"><?= $row['consagro_vida'] ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>