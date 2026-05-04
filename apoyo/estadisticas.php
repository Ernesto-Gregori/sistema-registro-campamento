<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esApoyo()) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$titulo = "Estadísticas";

// ── Config equipos ─────────────────────────────────────────────
$equipos_config = obtenerEquipos($pdo);

// ── Semanas disponibles ────────────────────────────────────────
$stmt_semanas = $pdo->query("SELECT * FROM semanas_campamento ORDER BY fecha_inicio DESC");
$semanas      = $stmt_semanas->fetchAll();

$semana_sel_id = $_GET['semana_id'] ?? null;
if (!$semana_sel_id) {
    foreach ($semanas as $s) {
        if ($s['activa']) { $semana_sel_id = $s['id']; break; }
    }
}
$semana_sel_id = $semana_sel_id ? (int)$semana_sel_id : null;

$semana_sel = null;
foreach ($semanas as $s) {
    if ($s['id'] == $semana_sel_id) { $semana_sel = $s; break; }
}

// ── Cabaña filtro (opcional) ───────────────────────────────────
$cabana_filtro = isset($_GET['cabana_id']) && $_GET['cabana_id'] !== ''
    ? (int)$_GET['cabana_id'] : null;

// ── Género de acceso ───────────────────────────────────────────
$stmt = $pdo->prepare("SELECT genero_acceso FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$genero_acceso = $stmt->fetch()['genero_acceso'] ?? 'ambos';
$where_genero  = $genero_acceso !== 'ambos' ? "AND c.genero = " . $pdo->quote($genero_acceso) : "";

// ── Cabañas disponibles para el filtro ────────────────────────
$stmt_cabs = $pdo->query("SELECT id, nombre_cabana, genero, equipo
                           FROM cabanas WHERE activa = 1
                           ORDER BY equipo, nombre_cabana");
$cabanas_lista = $stmt_cabs->fetchAll();
if ($genero_acceso !== 'ambos') {
    $cabanas_lista = array_filter($cabanas_lista, fn($c) => $c['genero'] === $genero_acceso);
}

// ── Estadísticas ───────────────────────────────────────────────
$stats = [];
if ($semana_sel_id) {
    try {
        // ── 1. Total acampantes ────────────────────────────────
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM acampantes a
                               JOIN cabanas c ON a.cabana_id = c.id
                               WHERE a.semana_id = ? AND a.estado = 'activo' $where_genero");
        $stmt->execute([$semana_sel_id]);
        $stats['total_acampantes'] = $stmt->fetch()['total'];

        // ── 2. Por género ──────────────────────────────────────
        $stmt = $pdo->prepare("SELECT a.sexo, COUNT(*) as total
                               FROM acampantes a
                               JOIN cabanas c ON a.cabana_id = c.id
                               WHERE a.semana_id = ? AND a.estado = 'activo' $where_genero
                               GROUP BY a.sexo");
        $stmt->execute([$semana_sel_id]);
        $stats['por_genero'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // ── 3. Por equipo ──────────────────────────────────────
        $stmt = $pdo->prepare("SELECT c.equipo,
                               COUNT(DISTINCT a.id)    as total_acampantes,
                               SUM(c.capacidad_maxima) as capacidad,
                               COUNT(DISTINCT sc.id)   as total_sesiones,
                               COUNT(DISTINCT CASE WHEN sc.id IS NOT NULL THEN a.id END) as acampantes_con_consejeria
                               FROM cabanas c
                               LEFT JOIN acampantes a ON c.id = a.cabana_id
                                   AND a.semana_id = ? AND a.estado = 'activo'
                               LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
                               WHERE c.activa = 1 AND c.equipo IS NOT NULL $where_genero
                               GROUP BY c.equipo
                               ORDER BY c.equipo");
        $stmt->execute([$semana_sel_id]);
        $stats['por_equipo'] = $stmt->fetchAll();

        // ── 4. Por cabaña con consejerías ─────────────────────
        $where_cab_filtro = $cabana_filtro ? "AND c.id = $cabana_filtro" : "";
        $stmt = $pdo->prepare("SELECT c.id, c.nombre_cabana, c.capacidad_maxima,
                               c.genero, c.equipo,
                               COUNT(DISTINCT a.id)  as total_acampantes,
                               COUNT(DISTINCT sc.id) as total_sesiones,
                               COUNT(DISTINCT CASE WHEN sc.id IS NOT NULL THEN a.id END) as acampantes_con_sesion
                               FROM cabanas c
                               LEFT JOIN acampantes a ON c.id = a.cabana_id
                                   AND a.semana_id = ? AND a.estado = 'activo'
                               LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
                               WHERE c.activa = 1 $where_genero $where_cab_filtro
                               GROUP BY c.id
                               ORDER BY c.equipo, c.genero, c.nombre_cabana");
        $stmt->execute([$semana_sel_id]);
        $stats['por_cabana'] = $stmt->fetchAll();

        // ── 5. Acampantes individuales con consejerías ─────────
        $where_ind_cab = $cabana_filtro ? "AND a.cabana_id = $cabana_filtro" : "";
        $stmt = $pdo->prepare("SELECT a.id, a.cabana_id, a.nombre, a.sexo, a.iglesia,
                               a.recibio_cristo_semana, a.consagro_vida_fogata,
                               a.era_creyente_antes,   a.asiste_iglesia,
                               c.nombre_cabana, c.equipo, c.genero as cab_genero,
                               COUNT(sc.id)           as total_sesiones,
                               MAX(sc.fecha_sesion)   as ultima_sesion
                               FROM acampantes a
                               JOIN cabanas c ON a.cabana_id = c.id
                               LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
                               WHERE a.semana_id = ? AND a.estado = 'activo' $where_genero $where_ind_cab
                               GROUP BY a.id, a.nombre, a.sexo, a.iglesia,
                                        c.nombre_cabana, c.equipo, c.genero
                               ORDER BY c.equipo, c.nombre_cabana, a.nombre");
        $stmt->execute([$semana_sel_id]);
        $stats['acampantes'] = $stmt->fetchAll();
        
        // ── 6. Impacto espiritual (campos en tabla acampantes) ─
        $where_imp_cab = $cabana_filtro ? "AND a.cabana_id = $cabana_filtro" : "";
        $stmt = $pdo->prepare("SELECT
                               COUNT(CASE WHEN a.recibio_cristo_semana = 1 THEN 1 END) as recibio_cristo,
                               COUNT(CASE WHEN a.consagro_vida_fogata  = 1 THEN 1 END) as consagro_vida,
                               COUNT(CASE WHEN a.era_creyente_antes    = 1 THEN 1 END) as era_creyente,
                               COUNT(CASE WHEN a.asiste_iglesia        = 1 THEN 1 END) as asiste_iglesia,
                               COUNT(*)                                                as total_con_sesion
                               FROM acampantes a
                               JOIN cabanas c ON a.cabana_id = c.id
                               WHERE a.semana_id = ? AND a.estado = 'activo'
                                     $where_genero $where_imp_cab");
        $stmt->execute([$semana_sel_id]);
        $stats['impacto'] = $stmt->fetch();

        // Totales generales
        $stats['capacidad_total']   = array_sum(array_column($stats['por_cabana'], 'capacidad_maxima'));
        $stats['total_sesiones']    = array_sum(array_column($stats['por_cabana'], 'total_sesiones'));
        $stats['con_consejeria']    = array_sum(array_column($stats['por_cabana'], 'acampantes_con_sesion'));
        $stats['sin_consejeria']    = $stats['total_acampantes'] - $stats['con_consejeria'];
        $stats['pct_ocupacion']     = $stats['capacidad_total'] > 0
            ? round(($stats['total_acampantes'] / $stats['capacidad_total']) * 100, 1) : 0;
        $stats['pct_consejeria']    = $stats['total_acampantes'] > 0
            ? round(($stats['con_consejeria'] / $stats['total_acampantes']) * 100, 1) : 0;
        $stats['cabanas_llenas']    = count(array_filter($stats['por_cabana'],
            fn($c) => $c['total_acampantes'] >= $c['capacidad_maxima']));
        $stats['lugares_libres']    = $stats['capacidad_total'] - $stats['total_acampantes'];

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-chart-bar"></i> Estadísticas</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Estadísticas</li>
            </ol>
        </nav>
    </div>
</div>

<!-- ── Filtros ── -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="d-flex align-items-center gap-3 flex-wrap">
            <label class="fw-bold mb-0">
                <i class="fas fa-calendar-week"></i> Semana:
            </label>
            <select name="semana_id" class="form-select w-auto" onchange="this.form.submit()">
                <option value="">-- Seleccionar --</option>
                <?php foreach ($semanas as $sem): ?>
                <option value="<?php echo $sem['id']; ?>"
                        <?php echo $sem['id'] == $semana_sel_id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($sem['nombre']); ?>
                    <?php echo $sem['activa'] ? '✓ ACTIVA' : ''; ?>
                    (<?php echo date('d/m/Y', strtotime($sem['fecha_inicio'])); ?>)
                </option>
                <?php endforeach; ?>
            </select>

            <?php if ($semana_sel_id): ?>
            <label class="fw-bold mb-0">
                <i class="fas fa-home"></i> Cabaña:
            </label>
            <select name="cabana_id" class="form-select w-auto" onchange="this.form.submit()">
                <option value="">Todas las cabañas</option>
                <?php foreach ($cabanas_lista as $cl): ?>
                <option value="<?php echo $cl['id']; ?>"
                        <?php echo $cl['id'] == $cabana_filtro ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cl['nombre_cabana']); ?>
                    (<?php echo ucfirst($cl['genero']); ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <?php if ($semana_sel): ?>
            <span class="badge bg-<?php echo $semana_sel['activa'] ? 'success' : 'secondary'; ?> fs-6">
                <?php echo $semana_sel['activa'] ? '🟢 Activa' : '⚫ Inactiva'; ?>
            </span>
            <?php endif; ?>

            <?php if ($cabana_filtro): ?>
            <a href="estadisticas.php?semana_id=<?php echo $semana_sel_id; ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-times"></i> Quitar filtro
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (!$semana_sel_id): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    Selecciona una semana para ver las estadísticas.
</div>

<?php elseif (isset($error)): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
</div>

<?php else: ?>

<!-- ══ TARJETAS RESUMEN ══ -->
<div class="row mb-4">
    <div class="col-6 col-md-3 mb-3">
        <div class="card text-center h-100 border-primary">
            <div class="card-body">
                <i class="fas fa-users fa-2x text-primary mb-2"></i>
                <h2 class="mb-0 text-primary"><?php echo $stats['total_acampantes']; ?></h2>
                <p class="mb-0 text-muted small">Total Acampantes</p>
                <small class="text-muted">
                    <?php echo $stats['total_acampantes']; ?>/<?php echo $stats['capacidad_total']; ?>
                    (<?php echo $stats['pct_ocupacion']; ?>%)
                </small>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-3 mb-3">
        <div class="card text-center h-100 border-success">
            <div class="card-body">
                <i class="fas fa-comments fa-2x text-success mb-2"></i>
                <h2 class="mb-0 text-success"><?php echo $stats['total_sesiones']; ?></h2>
                <p class="mb-0 text-muted small">Sesiones de Consejería</p>
                <small class="text-muted">
                    Prom. <?php echo $stats['total_acampantes'] > 0
                        ? round($stats['total_sesiones'] / $stats['total_acampantes'], 1) : 0; ?>
                    por acampante
                </small>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-3 mb-3">
        <?php $pctC = $stats['pct_consejeria']; ?>
        <div class="card text-center h-100 border-<?php echo $pctC >= 80 ? 'success' : ($pctC >= 50 ? 'warning' : 'danger'); ?>">
            <div class="card-body">
                <i class="fas fa-user-check fa-2x text-<?php echo $pctC >= 80 ? 'success' : ($pctC >= 50 ? 'warning' : 'danger'); ?> mb-2"></i>
                <h2 class="mb-0"><?php echo $pctC; ?>%</h2>
                <p class="mb-0 text-muted small">Con Consejería</p>
                <small class="text-muted">
                    <?php echo $stats['con_consejeria']; ?> de <?php echo $stats['total_acampantes']; ?>
                </small>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-3 mb-3">
        <div class="card text-center h-100 border-danger">
            <div class="card-body">
                <i class="fas fa-user-clock fa-2x text-danger mb-2"></i>
                <h2 class="mb-0 text-danger"><?php echo $stats['sin_consejeria']; ?></h2>
                <p class="mb-0 text-muted small">Sin Consejería</p>
                <small class="text-muted">Pendientes de atender</small>
            </div>
        </div>
    </div>
</div>

<!-- ══ FILA 2: Género + Equipos ══ -->
<div class="row mb-4">

    <!-- Por género -->
    <div class="col-md-4 mb-3">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-venus-mars"></i> Distribución por Género</h6>
            </div>
            <div class="card-body">
                <?php
                $total_m = $stats['por_genero']['masculino'] ?? 0;
                $total_f = $stats['por_genero']['femenino']  ?? 0;
                $total_g = $total_m + $total_f;
                $pct_m   = $total_g > 0 ? round(($total_m / $total_g) * 100) : 0;
                $pct_f   = $total_g > 0 ? round(($total_f / $total_g) * 100) : 0;
                ?>
                <div class="d-flex justify-content-around text-center mb-3">
                    <div>
                        <i class="fas fa-mars fa-2x text-primary"></i>
                        <h3 class="mb-0 text-primary"><?php echo $total_m; ?></h3>
                        <small class="text-muted">Masculino</small>
                        <div class="badge bg-primary mt-1"><?php echo $pct_m; ?>%</div>
                    </div>
                    <div class="vr"></div>
                    <div>
                        <i class="fas fa-venus fa-2x text-danger"></i>
                        <h3 class="mb-0 text-danger"><?php echo $total_f; ?></h3>
                        <small class="text-muted">Femenino</small>
                        <div class="badge bg-danger mt-1"><?php echo $pct_f; ?>%</div>
                    </div>
                </div>
                <div class="progress mb-1" style="height:20px; border-radius:10px;">
                    <div class="progress-bar bg-primary"
                         style="width:<?php echo $pct_m; ?>%"
                         title="Masculino: <?php echo $total_m; ?>">
                        <?php echo $pct_m > 10 ? $pct_m.'%' : ''; ?>
                    </div>
                    <div class="progress-bar bg-danger"
                         style="width:<?php echo $pct_f; ?>%"
                         title="Femenino: <?php echo $total_f; ?>">
                        <?php echo $pct_f > 10 ? $pct_f.'%' : ''; ?>
                    </div>
                </div>
                <div class="d-flex justify-content-between mt-1">
                    <small class="text-primary">♂ <?php echo $total_m; ?></small>
                    <small class="text-danger">♀ <?php echo $total_f; ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Por equipo -->
    <div class="col-md-8 mb-3">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-shield-alt"></i> Estadísticas por Equipo</h6>
            </div>
            <div class="card-body">
                <?php if (empty($stats['por_equipo'])): ?>
                <p class="text-muted text-center">Sin datos de equipos</p>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($stats['por_equipo'] as $eq):
                        $eqData   = $equipos_config[$eq['equipo']] ?? null;
                        $hexEq    = $eqData['color_hex'] ?? '#6c757d';
                        $emojiEq  = $eqData['emoji']     ?? '⚪';
                        $labelEq  = $eqData['nombre']    ?? ucfirst($eq['equipo']);
                        $pctOcup  = $eq['capacidad'] > 0
                            ? round(($eq['total_acampantes'] / $eq['capacidad']) * 100, 1) : 0;
                        $pctCons  = $eq['total_acampantes'] > 0
                            ? round(($eq['acampantes_con_consejeria'] / $eq['total_acampantes']) * 100, 1) : 0;
                        $barHex   = $pctOcup >= 90 ? '#dc3545' : ($pctOcup >= 70 ? '#ffc107' : $hexEq);
                        $barCons  = $pctCons >= 80 ? '#198754' : ($pctCons >= 50 ? '#ffc107' : '#dc3545');
                    ?>
                    <div class="col-md-6">
                        <div class="rounded p-3 text-white mb-2"
                             style="background-color: <?php echo $hexEq; ?>;">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">
                                    <?php echo $emojiEq; ?> <?php echo htmlspecialchars($labelEq); ?>
                                </h6>
                                <span class="badge bg-white"
                                      style="color:<?php echo $hexEq; ?>; font-size:.9rem;">
                                    <?php echo $eq['total_acampantes']; ?> acampantes
                                </span>
                            </div>
                        </div>
                        <!-- Ocupación -->
                        <div class="mb-2 px-1">
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">Ocupación</small>
                                <small class="fw-bold"><?php echo $pctOcup; ?>%</small>
                            </div>
                            <div class="progress" style="height:8px;">
                                <div class="progress-bar"
                                     style="width:<?php echo $pctOcup; ?>%;
                                            background-color:<?php echo $barHex; ?>;">
                                </div>
                            </div>
                            <small class="text-muted">
                                <?php echo $eq['total_acampantes']; ?>/<?php echo $eq['capacidad']; ?>
                            </small>
                        </div>
                        <!-- Consejerías -->
                        <div class="px-1">
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">
                                    <i class="fas fa-comments"></i> Consejerías
                                </small>
                                <small class="fw-bold"><?php echo $pctCons; ?>%</small>
                            </div>
                            <div class="progress" style="height:8px;">
                                <div class="progress-bar"
                                     style="width:<?php echo $pctCons; ?>%;
                                            background-color:<?php echo $barCons; ?>;">
                                </div>
                            </div>
                            <small class="text-muted">
                                <?php echo $eq['acampantes_con_consejeria']; ?> con sesión
                                · <?php echo $eq['total_sesiones']; ?> sesiones totales
                            </small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══ IMPACTO ESPIRITUAL ══ -->
<?php
$imp         = $stats['impacto'];
$base_imp    = max((int)$imp['total_con_sesion'], 1);
$total_ac    = max($stats['total_acampantes'], 1);

$pct_cristo  = round(($imp['recibio_cristo'] / $total_ac) * 100, 1);
$pct_consag  = round(($imp['consagro_vida']  / $total_ac) * 100, 1);
$pct_creyent = round(($imp['era_creyente']   / $total_ac) * 100, 1);
$pct_iglesia = round(($imp['asiste_iglesia'] / $total_ac) * 100, 1);
?>
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0">
            <i class="fas fa-cross"></i> Impacto Espiritual
            <small class="text-muted">— sobre <?php echo $stats['total_acampantes']; ?> acampantes</small>
        </h6>
    </div>
    <div class="card-body">
        <div class="row g-3">

            <!-- Recibieron a Cristo -->
            <div class="col-6 col-md-3">
                <div class="card text-center border-warning h-100">
                    <div class="card-body py-3">
                        <div class="mb-2" style="font-size:2rem;">✝️</div>
                        <h3 class="mb-0 text-warning"><?php echo $imp['recibio_cristo']; ?></h3>
                        <p class="small text-muted mb-1">Recibieron a Cristo</p>
                        <div class="progress mb-1" style="height:8px;">
                            <div class="progress-bar bg-warning"
                                 style="width:<?php echo $pct_cristo; ?>%;"></div>
                        </div>
                        <small class="text-muted"><?php echo $pct_cristo; ?>%</small>
                    </div>
                </div>
            </div>

            <!-- Consagraron su vida -->
            <div class="col-6 col-md-3">
                <div class="card text-center border-primary h-100">
                    <div class="card-body py-3">
                        <div class="mb-2" style="font-size:2rem;">🙏</div>
                        <h3 class="mb-0 text-primary"><?php echo $imp['consagro_vida']; ?></h3>
                        <p class="small text-muted mb-1">Consagraron su vida</p>
                        <div class="progress mb-1" style="height:8px;">
                            <div class="progress-bar bg-primary"
                                 style="width:<?php echo $pct_consag; ?>%;"></div>
                        </div>
                        <small class="text-muted"><?php echo $pct_consag; ?>%</small>
                    </div>
                </div>
            </div>

            <!-- Eran creyentes antes -->
            <div class="col-6 col-md-3">
                <div class="card text-center border-success h-100">
                    <div class="card-body py-3">
                        <div class="mb-2" style="font-size:2rem;">📖</div>
                        <h3 class="mb-0 text-success"><?php echo $imp['era_creyente']; ?></h3>
                        <p class="small text-muted mb-1">Eran creyentes antes</p>
                        <div class="progress mb-1" style="height:8px;">
                            <div class="progress-bar bg-success"
                                 style="width:<?php echo $pct_creyent; ?>%;"></div>
                        </div>
                        <small class="text-muted"><?php echo $pct_creyent; ?>%</small>
                    </div>
                </div>
            </div>

            <!-- Asisten a iglesia -->
            <div class="col-6 col-md-3">
                <div class="card text-center border-info h-100">
                    <div class="card-body py-3">
                        <div class="mb-2" style="font-size:2rem;">⛪</div>
                        <h3 class="mb-0 text-info"><?php echo $imp['asiste_iglesia']; ?></h3>
                        <p class="small text-muted mb-1">Asisten a iglesia</p>
                        <div class="progress mb-1" style="height:8px;">
                            <div class="progress-bar bg-info"
                                 style="width:<?php echo $pct_iglesia; ?>%;"></div>
                        </div>
                        <small class="text-muted"><?php echo $pct_iglesia; ?>%</small>
                    </div>
                </div>
            </div>

        </div>

        <!-- Barra resumen total -->
        <?php if ($stats['total_acampantes'] > 0): ?>
        <div class="mt-3 p-3 bg-light rounded">
            <div class="d-flex justify-content-between mb-1">
                <small class="fw-bold text-muted">Resumen visual del impacto</small>
                <small class="text-muted"><?php echo $stats['total_acampantes']; ?> acampantes</small>
            </div>
            <div class="progress" style="height:22px; border-radius:8px;">
                <?php if ($pct_cristo > 0): ?>
                <div class="progress-bar bg-warning"
                     style="width:<?php echo $pct_cristo; ?>%"
                     title="Recibieron a Cristo: <?php echo $imp['recibio_cristo']; ?>">
                    <?php echo $pct_cristo > 5 ? '✝️' : ''; ?>
                </div>
                <?php endif; ?>
                <?php if ($pct_consag > 0): ?>
                <div class="progress-bar bg-primary"
                     style="width:<?php echo $pct_consag; ?>%"
                     title="Consagraron: <?php echo $imp['consagro_vida']; ?>">
                    <?php echo $pct_consag > 5 ? '🙏' : ''; ?>
                </div>
                <?php endif; ?>
                <?php if ($pct_creyent > 0): ?>
                <div class="progress-bar bg-success"
                     style="width:<?php echo $pct_creyent; ?>%"
                     title="Eran creyentes: <?php echo $imp['era_creyente']; ?>">
                    <?php echo $pct_creyent > 5 ? '📖' : ''; ?>
                </div>
                <?php endif; ?>
                <?php
                $resto = 100 - $pct_cristo - $pct_consag - $pct_creyent;
                if ($resto > 0): ?>
                <div class="progress-bar bg-light border"
                     style="width:<?php echo $resto; ?>%; color:#999;">
                </div>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-3 mt-1 flex-wrap">
                <small><span class="badge bg-warning">✝️</span> Cristo: <?php echo $imp['recibio_cristo']; ?></small>
                <small><span class="badge bg-primary">🙏</span> Consagración: <?php echo $imp['consagro_vida']; ?></small>
                <small><span class="badge bg-success">📖</span> Creyentes: <?php echo $imp['era_creyente']; ?></small>
                <small><span class="badge bg-info">⛪</span> Asisten: <?php echo $imp['asiste_iglesia']; ?></small>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ CONSEJERÍAS POR CABAÑA ══ -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0">
            <i class="fas fa-home"></i> Consejerías por Cabaña
            <?php if ($cabana_filtro): ?>
            <span class="badge bg-info ms-2">Filtro activo</span>
            <?php endif; ?>
        </h6>
    </div>
    <div class="card-body">
        <?php
        $grupos_cab = [];
        foreach ($stats['por_cabana'] as $cab) {
            $eq = $cab['equipo'] ?? 'sin_equipo';
            $grupos_cab[$eq][] = $cab;
        }
        foreach ($grupos_cab as $equipo => $lista_cab):
            $eqData  = $equipos_config[$equipo] ?? null;
            $hexEq   = $eqData['color_hex'] ?? '#6c757d';
            $emojiEq = $eqData['emoji']     ?? '⚪';
            $labelEq = $equipo === 'sin_equipo'
                ? 'Sin equipo' : ($eqData['nombre'] ?? ucfirst($equipo));
            $masc = array_filter($lista_cab, fn($c) => $c['genero'] === 'masculino');
            $fem  = array_filter($lista_cab, fn($c) => $c['genero'] === 'femenino');
        ?>
        <div class="mb-4">
            <h6 class="mb-3">
                <span class="badge fs-6 px-3 py-2"
                      style="background-color: <?php echo $hexEq; ?>;">
                    <?php echo $emojiEq; ?> <?php echo htmlspecialchars($labelEq); ?>
                </span>
            </h6>
            <div class="row g-3">
            <?php foreach ([[$masc,'primary','mars'],[$fem,'danger','venus']] as [$lista_g,$color,$icon]):
                if (empty($lista_g)) continue;
            ?>
            <div class="<?php echo !empty($masc) && !empty($fem) ? 'col-md-6' : 'col-12'; ?>">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-<?php echo $color; ?>">
                        <tr>
                            <th><i class="fas fa-<?php echo $icon; ?>"></i> Cabaña</th>
                            <th class="text-center">Acampantes</th>
                            <th class="text-center">Sesiones</th>
                            <th class="text-center">Con Cons.</th>
                            <th style="min-width:110px;">% Cons.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lista_g as $cab):
                            $pctOcup = $cab['capacidad_maxima'] > 0
                                ? round(($cab['total_acampantes'] / $cab['capacidad_maxima']) * 100) : 0;
                            $pctCons = $cab['total_acampantes'] > 0
                                ? round(($cab['acampantes_con_sesion'] / $cab['total_acampantes']) * 100) : 0;
                            $barCons = $pctCons >= 80 ? '#198754' : ($pctCons >= 50 ? '#ffc107' : '#dc3545');
                            $llena   = $cab['total_acampantes'] >= $cab['capacidad_maxima'];
                        ?>
                        <tr>
                            <td>
                                <strong>
                                    <a href="estadisticas.php?semana_id=<?php echo $semana_sel_id; ?>&cabana_id=<?php echo $cab['id']; ?>"
                                       class="text-decoration-none text-dark">
                                        <?php echo htmlspecialchars($cab['nombre_cabana']); ?>
                                    </a>
                                </strong>
                                <?php if ($llena): ?>
                                <span class="badge bg-danger ms-1" style="font-size:9px;">Llena</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="fw-bold"><?php echo $cab['total_acampantes']; ?></span>
                                <small class="text-muted">/<?php echo $cab['capacidad_maxima']; ?></small>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-success">
                                    <?php echo $cab['total_sesiones']; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php $sin = $cab['total_acampantes'] - $cab['acampantes_con_sesion']; ?>
                                <span class="badge bg-success">
                                    <?php echo $cab['acampantes_con_sesion']; ?>
                                </span>
                                <?php if ($sin > 0): ?>
                                <span class="badge bg-danger ms-1">
                                    <?php echo $sin; ?> sin
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <div class="progress flex-grow-1" style="height:12px;">
                                        <div class="progress-bar"
                                             style="width:<?php echo $pctCons; ?>%;
                                                    background-color:<?php echo $barCons; ?>;">
                                        </div>
                                    </div>
                                    <small class="fw-bold" style="min-width:32px;">
                                        <?php echo $pctCons; ?>%
                                    </small>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══ ACAMPANTES INDIVIDUALES ══ -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0">
            <i class="fas fa-user"></i> Acampantes Individuales
            <small class="text-muted">— sesiones de consejería</small>
            <?php if ($cabana_filtro): ?>
            <?php
            $cab_nombre = '';
            foreach ($cabanas_lista as $cl) {
                if ($cl['id'] == $cabana_filtro) { $cab_nombre = $cl['nombre_cabana']; break; }
            }
            ?>
            <span class="badge bg-info ms-1">
                Cabaña: <?php echo htmlspecialchars($cab_nombre); ?>
            </span>
            <?php endif; ?>
        </h6>
        <!-- Filtros rápidos JS -->
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-outline-secondary active" onclick="filtrarAcampantes('todos', this)">
                Todos (<?php echo count($stats['acampantes']); ?>)
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="filtrarAcampantes('sin', this)">
                Sin consejería (<?php echo $stats['sin_consejeria']; ?>)
            </button>
            <button class="btn btn-sm btn-outline-success" onclick="filtrarAcampantes('con', this)">
                Con consejería (<?php echo $stats['con_consejeria']; ?>)
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <!-- Búsqueda rápida -->
        <div class="p-3 border-bottom">
            <input type="text" class="form-control form-control-sm"
                   id="busqueda-acampante"
                   placeholder="🔍 Buscar por nombre, cabaña o iglesia..."
                   oninput="buscarAcampante(this.value)">
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" id="tabla-acampantes">
                    <thead class="table-dark">
                        <tr>
                            <th>Acampante</th>
                            <th>Cabaña</th>
                            <th>Equipo</th>
                            <th class="text-center">Sesiones</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center">Decisión</th>
                            <th>Última Sesión</th>
                        </tr>
                    </thead>
                <tbody>
                    <?php foreach ($stats['acampantes'] as $ac):
                        $tieneSesion  = $ac['total_sesiones'] > 0;
                        $eqAcData     = $equipos_config[$ac['equipo']] ?? null;
                        $hexAc        = $eqAcData['color_hex'] ?? '#6c757d';
                        $emojiAc      = $eqAcData['emoji']     ?? '⚪';
                        $nombreEqAc   = $eqAcData['nombre']    ?? ucfirst($ac['equipo'] ?? '');
                        $iconoSexo    = $ac['sexo'] === 'masculino' ? 'mars text-primary' : 'venus text-danger';
                    ?>
                    <tr class="fila-acampante <?php echo $tieneSesion ? 'tiene-sesion' : 'sin-sesion'; ?>"
                        data-nombre="<?php echo strtolower($ac['nombre']); ?>"
                        data-cabana="<?php echo strtolower($ac['nombre_cabana']); ?>"
                        data-iglesia="<?php echo strtolower($ac['iglesia'] ?? ''); ?>">
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-<?php echo $iconoSexo; ?>"></i>
                                <div>
                                    <strong class="small">
                                        <?php echo htmlspecialchars($ac['nombre']); ?>
                                    </strong>
                                    <?php if (!empty($ac['iglesia'])): ?>
                                    <div class="text-muted" style="font-size:11px;">
                                        <i class="fas fa-church"></i>
                                        <?php echo htmlspecialchars($ac['iglesia']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <a href="estadisticas.php?semana_id=<?php echo $semana_sel_id; ?>&cabana_id=<?php echo $ac['cabana_id']; ?>"
                               class="text-decoration-none small fw-bold">
                                <i class="fas fa-home fa-xs text-muted"></i>
                                <?php echo htmlspecialchars($ac['nombre_cabana']); ?>
                            </a>
                        </td>
                        <td>
                            <?php if (!empty($ac['equipo'])): ?>
                            <span class="badge"
                                  style="background-color:<?php echo $hexAc; ?>; font-size:11px;">
                                <?php echo $emojiAc; ?> <?php echo htmlspecialchars($nombreEqAc); ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($tieneSesion): ?>
                            <span class="badge bg-success fs-6"
                                  title="<?php echo $ac['total_sesiones']; ?> sesión(es) registrada(s)">
                                <?php echo $ac['total_sesiones']; ?>
                                <i class="fas fa-comments ms-1" style="font-size:10px;"></i>
                            </span>
                            <?php else: ?>
                            <span class="badge bg-secondary">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($tieneSesion): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-check"></i> Con consejería
                            </span>
                            <?php else: ?>
                            <span class="badge bg-danger">
                                <i class="fas fa-clock"></i> Pendiente
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="d-flex justify-content-center gap-1 flex-wrap">
                                <?php if ($ac['recibio_cristo_semana']): ?>
                                <span title="Recibió a Cristo" style="font-size:1.1rem;">✝️</span>
                                <?php endif; ?>
                                <?php if ($ac['consagro_vida_fogata']): ?>
                                <span title="Consagró su vida" style="font-size:1.1rem;">🙏</span>
                                <?php endif; ?>
                                <?php if ($ac['era_creyente_antes']): ?>
                                <span title="Era creyente antes" style="font-size:1.1rem;">📖</span>
                                <?php endif; ?>
                                <?php if ($ac['asiste_iglesia']): ?>
                                <span title="Asiste a iglesia" style="font-size:1.1rem;">⛪</span>
                                <?php endif; ?>
                                <?php if (!$ac['recibio_cristo_semana'] && !$ac['consagro_vida_fogata']
                                       && !$ac['era_creyente_antes']    && !$ac['asiste_iglesia']): ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (empty($stats['acampantes'])): ?>
        <div class="text-center text-muted py-4">
            <i class="fas fa-users fa-2x mb-2"></i>
            <p>No hay acampantes registrados</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<script>
// ── Filtrar por estado de consejería ──────────────────────────
function filtrarAcampantes(tipo, btn) {
    document.querySelectorAll('.fila-acampante').forEach(function (fila) {
        if (tipo === 'todos') {
            fila.style.display = '';
        } else if (tipo === 'sin') {
            fila.style.display = fila.classList.contains('sin-sesion') ? '' : 'none';
        } else if (tipo === 'con') {
            fila.style.display = fila.classList.contains('tiene-sesion') ? '' : 'none';
        }
    });
    // Toggle botón activo
    document.querySelectorAll('.btn[onclick^="filtrarAcampantes"]').forEach(function (b) {
        b.classList.remove('active');
    });
    btn.classList.add('active');

    // Resetear búsqueda
    document.getElementById('busqueda-acampante').value = '';
}

// ── Búsqueda en tiempo real ───────────────────────────────────
function buscarAcampante(valor) {
    const v = valor.toLowerCase().trim();
    document.querySelectorAll('.fila-acampante').forEach(function (fila) {
        const nombre  = fila.dataset.nombre  || '';
        const cabana  = fila.dataset.cabana  || '';
        const iglesia = fila.dataset.iglesia || '';
        fila.style.display = (nombre.includes(v) || cabana.includes(v) || iglesia.includes(v))
            ? '' : 'none';
    });
    // Quitar filtro activo
    if (v.length > 0) {
        document.querySelectorAll('.btn[onclick^="filtrarAcampantes"]').forEach(function (b) {
            b.classList.remove('active');
        });
    }
}
</script>

<?php include '../includes/footer.php'; ?>