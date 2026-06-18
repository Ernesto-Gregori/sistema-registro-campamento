<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador() && !esEncargadoConsejeros()) {
    header('Location: ../login.php');
    exit();
}

$titulo = "Panel de Admisiones";
$year   = obtenerAnioCampamento();

// Semanas del año activo
$stmt_s = $pdo->prepare("
    SELECT * FROM semanas_campamento
    WHERE year_campamento = ?
    ORDER BY fecha_inicio
");
$stmt_s->execute([$year]);
$semanas = $stmt_s->fetchAll();

// Semana activa seleccionada
$semana_id = $_GET['semana_id'] ?? null;
if (!$semana_id && !empty($semanas)) {
    foreach ($semanas as $s) {
        if ($s['activa']) { $semana_id = $s['id']; break; }
    }
    if (!$semana_id) $semana_id = $semanas[0]['id'];
}

$semana_actual = null;
foreach ($semanas as $s) {
    if ($s['id'] == $semana_id) { $semana_actual = $s; break; }
}

// Stats generales
$stats = $semana_id ? resumenPagosSemana($pdo, (int)$semana_id) : [];

// ── Grupos de campamento con saldo pendiente ──────────────────────────────
$grupos = [];
if ($semana_id) {
    $stmt = $pdo->prepare("
        SELECT
            g.id,
            g.encargado_nombre                                                AS grupo_nombre,
            g.encargado_nombre,
            (SELECT COUNT(*)
             FROM acampantes a
             WHERE a.grupo_id = g.id AND a.estado = 'activo')      AS total_ac,
            COALESCE(
             (SELECT SUM(a.costo_total)
              FROM acampantes a
              WHERE a.grupo_id = g.id AND a.estado = 'activo'), 0) AS costo_real,
            COALESCE(
             (SELECT SUM(pg.monto)
              FROM pagos_grupo pg
              WHERE pg.grupo_id = g.id), 0)                        AS pagado,
            (SELECT COUNT(*)
             FROM acampantes a
             WHERE a.grupo_id = g.id AND a.estado = 'activo'
               AND a.llego = 1)                                    AS llegaron
        FROM grupos_campamento g
        WHERE g.semana_id = ? AND g.estado = 'activo'
        ORDER BY g.encargado_nombre
    ");
    $stmt->execute([$semana_id]);
    $grupos = $stmt->fetchAll();
}

// ── Individuales pendientes de pago (no en grupo) ─────────────────────────
$individuales_pendientes = 0;
$individuales_sin_pago   = 0;
$individuales_becados_sin_checkin = 0;
if ($semana_id) {
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN a.costo_total > 0 AND
                          COALESCE((SELECT SUM(p.monto) FROM pagos_acampante p
                                    WHERE p.acampante_id = a.id),0) < a.costo_total
                     THEN 1 ELSE 0 END)                             AS con_saldo,
            SUM(CASE WHEN a.costo_total > 0 AND
                          COALESCE((SELECT SUM(p.monto) FROM pagos_acampante p
                                    WHERE p.acampante_id = a.id),0) = 0
                     THEN 1 ELSE 0 END)                             AS sin_pago,
            SUM(CASE WHEN a.costo_total = 0 AND a.llego = 0
                     THEN 1 ELSE 0 END)                             AS becados_sin_checkin
        FROM acampantes a
        WHERE a.semana_id = ? AND a.estado = 'activo'
          AND (a.grupo_id IS NULL OR a.grupo_id = 0)
    ");
    $stmt->execute([$semana_id]);
    $ind = $stmt->fetch();
    $individuales_pendientes        = (int)($ind['con_saldo'] ?? 0);
    $individuales_sin_pago          = (int)($ind['sin_pago']  ?? 0);
    $individuales_becados_sin_checkin = (int)($ind['becados_sin_checkin'] ?? 0);
}

// ── Saldo por sexo (individuales) ─────────────────────────────────────────
$saldo_sexo = ['masculino' => 0, 'femenino' => 0];
if ($semana_id) {
    $stmt = $pdo->prepare("
        SELECT a.sexo,
               SUM(a.costo_total) - COALESCE(SUM(p.monto),0) AS saldo
        FROM acampantes a
        LEFT JOIN pagos_acampante p ON p.acampante_id = a.id
        WHERE a.semana_id = ? AND a.estado = 'activo'
          AND (a.grupo_id IS NULL OR a.grupo_id = 0)
          AND a.costo_total > 0
        GROUP BY a.sexo
    ");
    $stmt->execute([$semana_id]);
    foreach ($stmt->fetchAll() as $row) {
        $saldo_sexo[$row['sexo']] = max(0, (float)$row['saldo']);
    }
}

include '../includes/header.php';
?>

<!-- ── Cabecera ─────────────────────────────────────────────────────────── -->
<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-clipboard-list"></i> <?= $titulo ?></h1>
            <p class="text-muted mb-0">Año <?= $year ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="inscribir.php<?= $semana_id ? "?semana_id=$semana_id" : '' ?>"
               class="btn btn-success">
                <i class="fas fa-user-plus"></i> Nueva Inscripción
            </a>
            <a href="checkin.php<?= $semana_id ? "?semana_id=$semana_id" : '' ?>"
               class="btn btn-primary">
                <i class="fas fa-qrcode"></i> Check-in
            </a>
            <a href="importar.php" class="btn btn-outline-secondary">
                <i class="fas fa-file-csv"></i> Importar CSV
            </a>
        </div>
    </div>
</div>

<!-- ── Selector de semana ───────────────────────────────────────────────── -->
<?php if (!empty($semanas)): ?>
<div class="card mb-4">
    <div class="card-body py-2">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="fw-bold text-muted small">
                <i class="fas fa-calendar-week"></i> Semana:
            </span>
            <?php foreach ($semanas as $s): ?>
            <a href="?semana_id=<?= $s['id'] ?>"
               class="btn btn-sm <?= $semana_id == $s['id'] ? 'btn-dark' : 'btn-outline-secondary' ?>">
                <?= htmlspecialchars($s['nombre']) ?>
                <?php if ($s['activa']): ?>
                <span class="badge bg-success ms-1">Activa</span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($semana_id && !empty($stats)): ?>

<!-- ── Alertas de acción rápida ─────────────────────────────────────────── -->
<?php if ($individuales_sin_pago > 0): ?>
<div class="alert alert-danger py-2 d-flex align-items-center justify-content-between gap-2 mb-3">
    <div class="small">
        <i class="fas fa-exclamation-circle me-1"></i>
        <strong><?= $individuales_sin_pago ?></strong>
        acampante<?= $individuales_sin_pago > 1 ? 's' : '' ?> individual<?= $individuales_sin_pago > 1 ? 'es' : '' ?>
        sin ningún pago registrado.
    </div>
    <a href="lista_acampantes.php?semana_id=<?= $semana_id ?>&pago=sin_pago"
       class="btn btn-sm btn-danger text-nowrap">
        <i class="fas fa-dollar-sign"></i> Ver
    </a>
</div>
<?php endif; ?>

<?php if ($individuales_becados_sin_checkin > 0): ?>
<div class="alert alert-info py-2 d-flex align-items-center justify-content-between gap-2 mb-3">
    <div class="small">
        <i class="fas fa-award me-1"></i>
        <strong><?= $individuales_becados_sin_checkin ?></strong>
        becado<?= $individuales_becados_sin_checkin > 1 ? 's' : '' ?> aún sin check-in.
    </div>
    <a href="lista_acampantes.php?semana_id=<?= $semana_id ?>&pago=completo&checkin=pendientes"
       class="btn btn-sm btn-info text-nowrap">
        <i class="fas fa-qrcode"></i> Ver
    </a>
</div>
<?php endif; ?>

<!-- ── Stats cards ───────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="display-6 fw-bold text-primary">
                    <?= $stats['total_inscritos'] ?? 0 ?>
                </div>
                <small class="text-muted">Inscritos</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="display-6 fw-bold text-success">
                    <?= $stats['pagados_completo'] ?? 0 ?>
                </div>
                <small class="text-muted">Pago completo</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="display-6 fw-bold text-warning">
                    <?= $stats['total_llegaron'] ?? 0 ?>
                </div>
                <small class="text-muted">Check-in ✓</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="display-6 fw-bold text-danger" style="font-size:1.6rem!important;">
                    $<?= number_format(
                        ($stats['recaudacion_esperada'] ?? 0) - ($stats['recaudacion_real'] ?? 0), 0) ?>
                </div>
                <small class="text-muted">Saldo pendiente</small>
            </div>
        </div>
    </div>
</div>

<!-- ── Barra de recaudación ──────────────────────────────────────────────── -->
<?php
$esp  = (float)($stats['recaudacion_esperada'] ?? 0);
$real = (float)($stats['recaudacion_real']     ?? 0);
$pct  = $esp > 0 ? min(100, round($real / $esp * 100)) : 0;
?>
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between mb-1">
            <small class="fw-bold">Recaudación total</small>
            <small class="text-muted">
                $<?= number_format($real, 0) ?> /
                $<?= number_format($esp,  0) ?>
                (<?= $pct ?>%)
            </small>
        </div>
        <div class="progress mb-2" style="height:10px;">
            <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
        </div>
        <!-- Desglose hombres / mujeres -->
        <div class="row g-2 mt-1">
            <div class="col-6">
                <div class="d-flex align-items-center gap-2 small">
                    <span class="badge bg-info">♂</span>
                    <span class="text-muted">Saldo hombres:</span>
                    <span class="fw-bold text-<?= $saldo_sexo['masculino'] > 0 ? 'danger' : 'success' ?>">
                        $<?= number_format($saldo_sexo['masculino'], 0) ?>
                    </span>
                </div>
            </div>
            <div class="col-6">
                <div class="d-flex align-items-center gap-2 small">
                    <span class="badge bg-danger">♀</span>
                    <span class="text-muted">Saldo mujeres:</span>
                    <span class="fw-bold text-<?= $saldo_sexo['femenino'] > 0 ? 'danger' : 'success' ?>">
                        $<?= number_format($saldo_sexo['femenino'], 0) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Accesos rápidos ───────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <a href="lista_acampantes.php?semana_id=<?= $semana_id ?>"
           class="card text-decoration-none h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;background:#e8f4fd;border-radius:10px;
                            display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-user text-primary"></i>
                </div>
                <div>
                    <div class="fw-bold">Individuales</div>
                    <small class="text-muted">Lista sin grupo</small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="grupos/lista_grupos.php<?= $semana_id ? "?semana_id=$semana_id" : '' ?>"
           class="card text-decoration-none h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;background:#f0e8fd;border-radius:10px;
                            display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-users text-purple" style="color:#7c3aed;"></i>
                </div>
                <div>
                    <div class="fw-bold">Grupos</div>
                    <small class="text-muted">
                        <?= count($grupos) ?> grupo<?= count($grupos) != 1 ? 's' : '' ?> registrado<?= count($grupos) != 1 ? 's' : '' ?>
                    </small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="estadisticas.php?semana_id=<?= $semana_id ?>"
           class="card text-decoration-none h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;background:#e8f9ef;border-radius:10px;
                            display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-chart-bar text-success"></i>
                </div>
                <div>
                    <div class="fw-bold">Estadísticas</div>
                    <small class="text-muted">Reportes y desglose</small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="checkin.php?semana_id=<?= $semana_id ?>"
           class="card text-decoration-none h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;background:#fff3e0;border-radius:10px;
                            display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-check-circle text-warning"></i>
                </div>
                <div>
                    <div class="fw-bold">Check-in</div>
                    <small class="text-muted">Registrar llegada</small>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- ── Resumen de grupos ─────────────────────────────────────────────────── -->
<?php if (!empty($grupos)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">
            <i class="fas fa-users"></i> Grupos de Campamento
            <span class="badge bg-secondary ms-1"><?= count($grupos) ?></span>
        </h6>
        <a href="grupos/lista_grupos.php?semana_id=<?= $semana_id ?>"
           class="btn btn-sm btn-outline-secondary">
            Ver todos <i class="fas fa-arrow-right fa-xs ms-1"></i>
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Grupo</th>
                        <th class="text-center">Acampantes</th>
                        <th class="text-center">Check-in</th>
                        <th class="text-end">Saldo</th>
                        <th class="text-center">Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($grupos as $g):
                    $costo_g  = (float)$g['costo_real'];
                    $pagado_g = (float)$g['pagado'];
                    $saldo_g  = max(0, $costo_g - $pagado_g);
                    $es_beca_g = ($costo_g == 0 && $g['total_ac'] > 0);
                    $pagado_100_g = $es_beca_g || ($costo_g > 0 && $pagado_g >= $costo_g);
                    $pct_g = $costo_g > 0
                        ? min(100, round($pagado_g / $costo_g * 100))
                        : ($pagado_100_g ? 100 : 0);
                ?>
                <tr class="<?= $pagado_100_g ? 'table-success' : '' ?>">
                    <td>
                        <div class="fw-bold">
                            <?= htmlspecialchars($g['grupo_nombre']) ?>
                        </div>
                        <small class="text-muted">
                            <i class="fas fa-user-tie fa-xs"></i>
                            <?= htmlspecialchars($g['encargado_nombre']) ?>
                        </small>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-primary"><?= $g['total_ac'] ?></span>
                    </td>
                    <td class="text-center">
                        <?php
                        $pct_llegaron = $g['total_ac'] > 0
                            ? round($g['llegaron'] / $g['total_ac'] * 100) : 0;
                        ?>
                        <div class="d-flex align-items-center gap-1 justify-content-center">
                            <small><?= $g['llegaron'] ?>/<?= $g['total_ac'] ?></small>
                            <div class="progress" style="height:5px;width:50px;">
                                <div class="progress-bar bg-<?= $pct_llegaron == 100 ? 'success' : 'warning' ?>"
                                     style="width:<?= $pct_llegaron ?>%"></div>
                            </div>
                        </div>
                    </td>
                    <td class="text-end fw-bold <?= $saldo_g > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= $es_beca_g ? '<span class="badge bg-info"><i class="fas fa-award fa-xs"></i> Beca</span>'
                                       : '$' . number_format($saldo_g, 0) ?>
                    </td>
                    <td class="text-center">
                        <?php if ($es_beca_g): ?>
                            <span class="badge bg-info">Beca completa</span>
                        <?php elseif ($pagado_100_g): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-check-double fa-xs"></i> Pagado
                            </span>
                        <?php elseif ($pagado_g > 0): ?>
                            <div class="d-flex align-items-center gap-1 justify-content-center">
                                <div class="progress" style="height:5px;width:50px;">
                                    <div class="progress-bar bg-warning"
                                         style="width:<?= $pct_g ?>%"></div>
                                </div>
                                <small class="text-warning fw-bold"><?= $pct_g ?>%</small>
                            </div>
                        <?php else: ?>
                            <span class="badge bg-danger">Sin pago</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="grupos/ver_grupo.php?id=<?= $g['id'] ?>"
                           class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <!-- Totales -->
                <?php
                $g_tot_ac  = array_sum(array_column($grupos, 'total_ac'));
                $g_tot_lle = array_sum(array_column($grupos, 'llegaron'));
                $g_tot_cos = array_sum(array_column($grupos, 'costo_real'));
                $g_tot_pag = array_sum(array_column($grupos, 'pagado'));
                $g_tot_sal = max(0, $g_tot_cos - $g_tot_pag);
                ?>
                <tr class="table-dark fw-bold">
                    <td>TOTALES</td>
                    <td class="text-center"><?= $g_tot_ac ?></td>
                    <td class="text-center"><?= $g_tot_lle ?>/<?= $g_tot_ac ?></td>
                    <td class="text-end text-<?= $g_tot_sal > 0 ? 'danger' : 'success' ?>">
                        $<?= number_format($g_tot_sal, 0) ?>
                    </td>
                    <td colspan="2"></td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Pendientes de pago (individuales) ────────────────────────────────── -->
<?php if ($individuales_pendientes > 0): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">
            <i class="fas fa-exclamation-circle text-warning"></i>
            Individuales con saldo pendiente
            <span class="badge bg-warning text-dark ms-1"><?= $individuales_pendientes ?></span>
        </h6>
        <a href="lista_acampantes.php?semana_id=<?= $semana_id ?>&pago=parcial"
           class="btn btn-sm btn-outline-warning">
            Ver todos <i class="fas fa-arrow-right fa-xs ms-1"></i>
        </a>
    </div>
    <div class="card-body py-3 px-4">
        <div class="row g-3">
            <div class="col-sm-4 text-center">
                <div class="fs-4 fw-bold text-danger"><?= $individuales_pendientes ?></div>
                <small class="text-muted">Con saldo pendiente</small>
            </div>
            <div class="col-sm-4 text-center">
                <div class="fs-4 fw-bold text-danger">$<?= number_format($saldo_sexo['masculino'] + $saldo_sexo['femenino'], 0) ?></div>
                <small class="text-muted">Total a cobrar</small>
            </div>
            <div class="col-sm-4 text-center">
                <div class="fs-4 fw-bold text-warning"><?= $individuales_sin_pago ?></div>
                <small class="text-muted">Sin ningún pago</small>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; /* fin if semana_id && stats */ ?>

<?php if (empty($semanas)): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    No hay semanas configuradas para <?= $year ?>.
    Pide al encargado de consejeros que las cree primero.
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>