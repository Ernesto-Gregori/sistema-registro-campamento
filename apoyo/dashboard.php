<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
verificarMantenimiento($pdo);
if (!esApoyo()) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$titulo = "Dashboard - Apoyo de Consejeros";

try {
    // Obtener semana activa
    $stmt = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
    $semana_activa = $stmt->fetch();
    $semana_id_activa = $semana_activa['id'] ?? null;

    // Obtener genero_acceso del usuario actual
    $stmt = $pdo->prepare("SELECT genero_acceso FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $usuario_actual = $stmt->fetch();
    $genero_acceso = $usuario_actual['genero_acceso'] ?? 'ambos';

    // Filtro de género para queries
    $where_genero_cab = $genero_acceso !== 'ambos' ? "AND c.genero = " . $pdo->quote($genero_acceso) : "";

    // Total acampantes (según género de acceso)
    if ($semana_id_activa) {
        $sql_total = "SELECT COUNT(*) as total FROM acampantes a
                      JOIN cabanas c ON a.cabana_id = c.id
                      WHERE a.semana_id = ? AND a.estado = 'activo' $where_genero_cab";
        $stmt = $pdo->prepare($sql_total);
        $stmt->execute([$semana_id_activa]);
    } else {
        $sql_total = "SELECT COUNT(*) as total FROM acampantes a
                      JOIN cabanas c ON a.cabana_id = c.id
                      WHERE a.year_campamento = ? AND a.estado = 'activo' $where_genero_cab";
        $stmt = $pdo->prepare($sql_total);
        $stmt->execute([obtenerAnioCampamento()]);
    }
    $totalAcampantes = $stmt->fetch()['total'];

    // Total cabañas según género
    if ($genero_acceso !== 'ambos') {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM cabanas WHERE activa = 1 AND genero = ?");
        $stmt->execute([$genero_acceso]);
    } else {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM cabanas WHERE activa = 1");
    }
    $totalCabanas = $stmt->fetch()['total'];

    // Últimos acampantes registrados según género
    if ($semana_id_activa) {
        $sql_ult = "SELECT a.*, c.nombre_cabana 
                    FROM acampantes a 
                    LEFT JOIN cabanas c ON a.cabana_id = c.id
                    WHERE a.semana_id = ? AND a.estado = 'activo' $where_genero_cab
                    ORDER BY a.fecha_registro DESC LIMIT 5";
        $stmt = $pdo->prepare($sql_ult);
        $stmt->execute([$semana_id_activa]);
    } else {
        $sql_ult = "SELECT a.*, c.nombre_cabana 
                    FROM acampantes a 
                    LEFT JOIN cabanas c ON a.cabana_id = c.id
                    WHERE a.year_campamento = ? AND a.estado = 'activo' $where_genero_cab
                    ORDER BY a.fecha_registro DESC LIMIT 5";
        $stmt = $pdo->prepare($sql_ult);
        $stmt->execute([obtenerAnioCampamento()]);
    }
    $ultimosAcampantes = $stmt->fetchAll();

    // ⭐ Acampantes por cabaña con equipo - filtrado por género
    if ($semana_id_activa) {
        $sql_cab = "SELECT c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                    MAX(CASE WHEN cs.rol = 'principal'  THEN cs.nombre_consejero END) AS consejero_principal,
                    MAX(CASE WHEN cs.rol = 'asistente'  THEN cs.nombre_consejero END) AS consejero_asistente,
                    COALESCE(csc.edad_min, s.edad_min) AS edad_min_efectiva,
                    COALESCE(csc.edad_max, s.edad_max) AS edad_max_efectiva,
                    COUNT(DISTINCT a.id) as total_acampantes
                    FROM cabanas c
                    LEFT JOIN acampantes a ON c.id = a.cabana_id 
                        AND a.semana_id = ? AND a.estado = 'activo'
                    LEFT JOIN consejeros_semana cs
                        ON cs.cabana_id = c.id AND cs.semana_id = ?
                    LEFT JOIN cabana_semana_config csc 
                        ON csc.cabana_id = c.id AND csc.semana_id = ?
                    LEFT JOIN semanas_campamento s ON s.id = ?
                    WHERE c.activa = 1 $where_genero_cab
                    GROUP BY c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                             edad_min_efectiva, edad_max_efectiva
                    ORDER BY c.equipo ASC, c.nombre_cabana ASC";
        $stmt = $pdo->prepare($sql_cab);
        $stmt->execute([
            $semana_id_activa,
            $semana_id_activa,
            $semana_id_activa,
            $semana_id_activa
        ]);
    } else {
        $sql_cab = "SELECT c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                    c.consejero_principal,
                    c.consejero_asistente,
                    NULL AS edad_min_efectiva,
                    NULL AS edad_max_efectiva,
                    COUNT(DISTINCT a.id) as total_acampantes
                    FROM cabanas c
                    LEFT JOIN acampantes a ON c.id = a.cabana_id 
                        AND a.year_campamento = ? AND a.estado = 'activo'
                    WHERE c.activa = 1 $where_genero_cab
                    GROUP BY c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                             c.consejero_principal, c.consejero_asistente
                    ORDER BY c.equipo ASC, c.nombre_cabana ASC";
        $stmt = $pdo->prepare($sql_cab);
        $stmt->execute([obtenerAnioCampamento()]);
    }
    $acampantesPorCabana = $stmt->fetchAll();

    // ⭐ Estadísticas por equipo - SUM en subconsulta para evitar duplicados
    if ($semana_id_activa) {
        $sql_eq = "SELECT 
                       eq.equipo,
                       eq.total_cabanas,
                       eq.capacidad_total,
                       COALESCE(ac.total_acampantes, 0) as total_acampantes
                   FROM (
                       SELECT equipo,
                              COUNT(id) as total_cabanas,
                              SUM(capacidad_maxima) as capacidad_total
                       FROM cabanas
                       WHERE activa = 1 AND equipo IS NOT NULL
                       " . ($genero_acceso !== 'ambos' ? "AND genero = " . $pdo->quote($genero_acceso) : "") . "
                       GROUP BY equipo
                   ) eq
                   LEFT JOIN (
                       SELECT c2.equipo, COUNT(a.id) as total_acampantes
                       FROM acampantes a
                       JOIN cabanas c2 ON a.cabana_id = c2.id
                       WHERE a.semana_id = ? AND a.estado = 'activo'
                       " . ($genero_acceso !== 'ambos' ? "AND c2.genero = " . $pdo->quote($genero_acceso) : "") . "
                       GROUP BY c2.equipo
                   ) ac ON eq.equipo = ac.equipo
                   ORDER BY eq.equipo";
        $stmt = $pdo->prepare($sql_eq);
        $stmt->execute([$semana_id_activa]);
    } else {
        $sql_eq = "SELECT 
                       eq.equipo,
                       eq.total_cabanas,
                       eq.capacidad_total,
                       COALESCE(ac.total_acampantes, 0) as total_acampantes
                   FROM (
                       SELECT equipo,
                              COUNT(id) as total_cabanas,
                              SUM(capacidad_maxima) as capacidad_total
                       FROM cabanas
                       WHERE activa = 1 AND equipo IS NOT NULL
                       " . ($genero_acceso !== 'ambos' ? "AND genero = " . $pdo->quote($genero_acceso) : "") . "
                       GROUP BY equipo
                   ) eq
                   LEFT JOIN (
                       SELECT c2.equipo, COUNT(a.id) as total_acampantes
                       FROM acampantes a
                       JOIN cabanas c2 ON a.cabana_id = c2.id
                       WHERE a.year_campamento = ? AND a.estado = 'activo'
                       " . ($genero_acceso !== 'ambos' ? "AND c2.genero = " . $pdo->quote($genero_acceso) : "") . "
                       GROUP BY c2.equipo
                   ) ac ON eq.equipo = ac.equipo
                   ORDER BY eq.equipo";
        $stmt = $pdo->prepare($sql_eq);
        $stmt->execute([obtenerAnioCampamento()]);
    }
    $statsPorEquipo = $stmt->fetchAll();
    
    // Acampantes en sala de espera (check-in hecho, pendientes de ser voceados)
    $en_espera_count = 0;
    if ($semana_id_activa) {
        $sql_espera = "SELECT COUNT(*) as total
                       FROM acampantes a
                       JOIN cabanas c ON a.cabana_id = c.id
                       WHERE a.semana_id = ?
                         AND a.estado = 'activo'
                         AND a.llego = 1
                         AND a.enviado_cabana = 0
                         $where_genero_cab";
        $stmt = $pdo->prepare($sql_espera);
        $stmt->execute([$semana_id_activa]);
        $en_espera_count = $stmt->fetch()['total'];
    }

} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}

// Config de equipos — siempre disponible
$equipos_config = obtenerEquipos($pdo);

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-clipboard-list"></i> Dashboard - Apoyo de Consejeros</h1>
        <p class="text-muted">
            Registro y asignación de acampantes |
            <?php if ($genero_acceso === 'masculino'): ?>
                <span class="badge bg-primary"><i class="fas fa-mars"></i> Acceso: Solo Masculino</span>
            <?php elseif ($genero_acceso === 'femenino'): ?>
                <span class="badge bg-danger"><i class="fas fa-venus"></i> Acceso: Solo Femenino</span>
            <?php else: ?>
                <span class="badge bg-secondary"><i class="fas fa-venus-mars"></i> Acceso: Todos los géneros</span>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<!-- Banner semana activa -->
<?php if ($semana_activa): ?>
<div class="alert alert-success border-0 mb-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <i class="fas fa-broadcast-tower fa-2x text-success"></i>
            <div>
                <h5 class="mb-0">
                    <span class="badge bg-success me-2">SEMANA ACTIVA</span>
                    <?php echo htmlspecialchars($semana_activa['nombre']); ?>
                </h5>
                <small class="text-muted">
                    <?php
                    $tipos = ['mayores' => '👔 Mayores', 'ninos' => '🧒 Niños', 'adolescentes' => '🎓 Adolescentes'];
                    echo $tipos[$semana_activa['tipo_acampante']] ?? $semana_activa['tipo_acampante'];
                    ?> |
                    <?php echo date('d/m/Y', strtotime($semana_activa['fecha_inicio'])); ?> -
                    <?php echo date('d/m/Y', strtotime($semana_activa['fecha_fin'])); ?>
                </small>
            </div>
        </div>
        <a href="registrar_acampante.php" class="btn btn-success">
            <i class="fas fa-plus"></i> Registrar Acampante
        </a>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning mb-4">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Sin semana activa.</strong> El administrador debe activar una semana antes de registrar acampantes.
</div>
<?php endif; ?>

<!-- Tarjetas estadísticas -->
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card card-stat text-center">
            <div class="card-body">
                <i class="fas fa-users mb-2"></i>
                <h3 id="total-acampantes"><?php echo $totalAcampantes; ?></h3>
                <p class="mb-0">Acampantes Registrados</p>
                <small class=".text-muted-w"><?php echo $semana_activa ? 'Esta semana' : 'Este año'; ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card card-stat text-center">
            <div class="card-body">
                <i class="fas fa-home mb-2"></i>
                <h3><?php echo $totalCabanas; ?></h3>
                <p class="mb-0">Cabañas Activas</p>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card card-stat text-center">
            <div class="card-body">
                <i class="fas fa-user-plus mb-2"></i>
                <h3><?php echo count($ultimosAcampantes); ?></h3>
                <p class="mb-0">Últimos Registros</p>
                <small class=".text-muted-w">Recientes</small>
            </div>
        </div>
    </div>
</div>

<!-- Barra de estado inteligente -->
<div class="d-flex justify-content-end align-items-center mb-3 gap-2 flex-wrap">
    <span id="indicador-carga" style="opacity:0; transition:opacity 0.3s;">
        <i class="fas fa-circle-notch fa-spin text-primary"></i>
    </span>
    <span id="estado-cambio" class="badge bg-primary">En vivo</span>
    <small class="text-muted">
        Próxima revisión en <span id="countdown"><?php echo 30; ?></span>s
        (cada <span id="intervalo-actual">30</span>s)
    </small>
    <small class="text-muted">| Última: <span id="ultima-actualizacion">--:--:--</span></small>
    <button class="btn btn-outline-secondary btn-sm"
            onclick="actualizarDashboard(true)">
        <i class="fas fa-sync-alt"></i> Ahora
    </button>
</div>

<!-- ⭐ RESUMEN POR EQUIPO -->
<?php if (!empty($statsPorEquipo)): ?>
<div class="row mb-4" id="seccion-equipos-wrapper">
    <div class="col-12">
        <h5 class="mb-3">
            <i class="fas fa-shield-alt"></i> Balance por Equipo
            <small class="text-muted fs-6"> — distribución actual</small>
        </h5>
    </div>
    <div class="row w-100" id="seccion-equipos">
    <?php
        // Calcular diferencia entre equipos para mostrar alerta
        $totalesEquipo = array_column($statsPorEquipo, 'total_acampantes');
        $maxEq = !empty($totalesEquipo) ? max($totalesEquipo) : 0;
        $minEq = !empty($totalesEquipo) ? min($totalesEquipo) : 0;
        $diferencia = $maxEq - $minEq;
        ?>
        <?php if ($diferencia > 2 && count($statsPorEquipo) > 1): ?>
        <div class="col-12 mb-3">
            <div class="alert alert-warning border-0">
                <i class="fas fa-balance-scale"></i>
                <strong>Equipos desbalanceados:</strong>
                Hay una diferencia de <strong><?php echo $diferencia; ?> acampantes</strong> entre equipos.
                Procura asignar a las cabañas del equipo con menos acampantes.
            </div>
        </div>
        <?php elseif (count($statsPorEquipo) > 1): ?>
        <div class="col-12 mb-3">
            <div class="alert alert-success border-0">
                <i class="fas fa-check-circle"></i>
                <strong>Equipos balanceados.</strong>
                La diferencia entre equipos es de solo <?php echo $diferencia; ?> acampante(s).
            </div>
        </div>
        <?php endif; ?>
    
        <?php foreach ($statsPorEquipo as $eq):
            $eqData  = $equipos_config[$eq['equipo']] ?? null;
            $hexEq   = $eqData['color_hex'] ?? '#6c757d';
            $emojiEq = $eqData['emoji']     ?? '⚪';
            $labelEq = $eqData['nombre']    ?? ucfirst($eq['equipo']);
            $pct_eq  = $eq['capacidad_total'] > 0
                ? round(($eq['total_acampantes'] / $eq['capacidad_total']) * 100, 1)
                : 0;
            $bar_hex  = $pct_eq >= 90 ? '#dc3545' : ($pct_eq >= 70 ? '#ffc107' : $hexEq);
            $es_menor = count($totalesEquipo) > 1
                && $eq['total_acampantes'] == $minEq
                && $diferencia > 0;
        ?>
        <div class="col-md-6 mb-3">
            <div class="card h-100" style="border: 2px solid <?php echo $hexEq; ?>;">
                <div class="card-header text-white d-flex justify-content-between align-items-center"
                     style="background-color: <?php echo $hexEq; ?>;">
                    <h5 class="mb-0">
                        <?php echo $emojiEq; ?> <?php echo htmlspecialchars($labelEq); ?>
                    </h5>
                    <?php if ($es_menor && $diferencia > 2): ?>
                    <span class="badge bg-warning text-dark">
                        <i class="fas fa-arrow-up"></i> Asignar aquí
                    </span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="row text-center mb-3">
                        <div class="col-4">
                            <h3 class="mb-0" style="color: <?php echo $hexEq; ?>;">
                                <?php echo $eq['total_acampantes']; ?>
                            </h3>
                            <small class="text-muted">Acampantes</small>
                        </div>
                        <div class="col-4">
                            <h3 class="mb-0"><?php echo $eq['total_cabanas']; ?></h3>
                            <small class="text-muted">Cabañas</small>
                        </div>
                        <div class="col-4">
                            <h3 class="mb-0"><?php echo $eq['capacidad_total']; ?></h3>
                            <small class="text-muted">Capacidad</small>
                        </div>
                    </div>
                    <div class="progress mb-1" style="height:12px;">
                        <div class="progress-bar"
                             style="width:<?php echo min(100,$pct_eq); ?>%;
                                    background-color: <?php echo $bar_hex; ?>;">
                            <?php echo $pct_eq; ?>%
                        </div>
                    </div>
                    <small class="text-muted">
                        <?php echo $eq['capacidad_total'] - $eq['total_acampantes']; ?> lugar(es) disponibles en el equipo
                    </small>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div><!-- /seccion-equipos -->
</div>
<?php endif; ?>

<!-- ⭐ DETALLE POR CABAÑAS -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">
                    <i class="fas fa-home"></i> Distribución por Cabaña
                    <?php if ($semana_activa): ?>
                        <small class="text-muted fs-6"> — <?php echo htmlspecialchars($semana_activa['nombre']); ?></small>
                    <?php endif; ?>
                </h5>
                <a href="registrar_acampante.php" class="btn btn-success btn-sm <?php echo !$semana_activa ? 'disabled' : ''; ?>">
                    <i class="fas fa-plus"></i> Registrar Acampante
                </a>
            </div>
            <div class="card-body">
                <div id="seccion-cabanas">
                <?php if (empty($acampantesPorCabana)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-home fa-3x mb-3"></i>
                    <p>No hay cabañas disponibles</p>
                </div>
                <?php else: ?>

                <?php
                // Agrupar por equipo para mostrar secciones
                $cabanasPorEquipo = [];
                foreach ($acampantesPorCabana as $cab) {
                    $eq = $cab['equipo'] ?? 'sin_equipo';
                    $cabanasPorEquipo[$eq][] = $cab;
                }
                ?>

                <?php foreach ($cabanasPorEquipo as $equipo => $cabanas_grupo): ?>
                <?php
                                $eqGData     = $equipos_config[$equipo] ?? null;
                $hexGrupo    = $eqGData['color_hex'] ?? '#6c757d';
                $emoji_grupo = $eqGData['emoji']     ?? '⚪';
                $label_grupo = $equipo === 'sin_equipo'
                    ? 'Sin equipo asignado'
                    : ($eqGData['nombre'] ?? 'Equipo ' . ucfirst($equipo));
                // Conservar color_grupo para el botón "Ver acampantes"
                $color_grupo = $eqGData['color'] ?? 'secondary';
                $total_grupo     = array_sum(array_column($cabanas_grupo, 'total_acampantes'));
                $capacidad_grupo = array_sum(array_column($cabanas_grupo, 'capacidad_maxima'));
                ?>
                <div class="mb-4">
                    <!-- Encabezado del grupo -->
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h6 class="mb-0">
                            <span class="badge fs-6 px-3 py-2"
                                  style="background-color: <?php echo $hexGrupo; ?>;">
                                <?php echo $emoji_grupo; ?> <?php echo htmlspecialchars($label_grupo); ?>
                            </span>
                        </h6>
                        <small class="text-muted">
                            Total: <strong><?php echo $total_grupo; ?></strong> / <?php echo $capacidad_grupo; ?> acampantes
                        </small>
                    </div>

                    <!-- Cabañas del grupo -->
                    <div class="row g-3">
                        <?php foreach ($cabanas_grupo as $cab):
                            $pct = $cab['capacidad_maxima'] > 0
                                ? ($cab['total_acampantes'] / $cab['capacidad_maxima']) * 100
                                : 0;
                            $disponibles = $cab['capacidad_maxima'] - $cab['total_acampantes'];
                            $bar_color = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
                            $border_color = $pct >= 90 ? 'border-danger' : ($pct >= 70 ? 'border-warning' : 'border-success');
                            $icono_genero = $cab['genero'] === 'masculino' ? 'mars' : 'venus';
                            $color_genero = $cab['genero'] === 'masculino' ? 'primary' : 'danger';
                        ?>
                        <div class="col-md-4 col-sm-6">
                            <div class="card h-100 border <?php echo $border_color; ?>">
                                <div class="card-body py-3 px-3">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="mb-0">
                                                <?php echo htmlspecialchars($cab['nombre_cabana']); ?>
                                            </h6>
                                            <span class="badge bg-<?php echo $color_genero; ?> mt-1" style="font-size:10px;">
                                                <i class="fas fa-<?php echo $icono_genero; ?>"></i>
                                                <?php echo ucfirst($cab['genero']); ?>
                                            </span>
                                        </div>
                                        <div class="text-end">
                                            <span class="fs-4 fw-bold text-<?php echo $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success'); ?>">
                                                <?php echo $cab['total_acampantes']; ?>
                                            </span>
                                            <small class="text-muted d-block" style="font-size:11px;">
                                                / <?php echo $cab['capacidad_maxima']; ?>
                                            </small>
                                        </div>
                                    </div>

                                    <div class="progress mb-1" style="height:8px;">
                                        <div class="progress-bar <?php echo $bar_color; ?>"
                                             style="width:<?php echo min(100, $pct); ?>%"></div>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <small class="<?php echo $disponibles <= 0 ? 'text-danger fw-bold' : 'text-muted'; ?>">
                                            <?php if ($disponibles <= 0): ?>
                                                <i class="fas fa-lock"></i> Cabaña llena
                                            <?php else: ?>
                                                <i class="fas fa-user-plus"></i> <?php echo $disponibles; ?> disponible(s)
                                            <?php endif; ?>
                                        </small>
                                        <small class="text-muted"><?php echo round($pct, 0); ?>%</small>
                                    </div>
                                    <!-- Consejeros -->
                                    <div class="mt-2 pt-2 border-top">
                                        <?php if ($cab['consejero_principal']): ?>
                                        <div class="d-flex align-items-center gap-1 mb-1">
                                            <i class="fas fa-user-tie text-secondary"
                                               style="font-size:11px; width:14px;"></i>
                                            <small class="text-muted" style="font-size:11px;">
                                                <?= htmlspecialchars($cab['consejero_principal']) ?>
                                            </small>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($cab['consejero_asistente']): ?>
                                        <div class="d-flex align-items-center gap-1 mb-1">
                                            <i class="fas fa-user text-secondary"
                                               style="font-size:11px; width:14px;"></i>
                                            <small class="text-muted" style="font-size:11px;">
                                                <?= htmlspecialchars($cab['consejero_asistente']) ?>
                                            </small>
                                        </div>
                                        <?php endif; ?>
                                        <!-- Rango de edad -->
                                        <?php
                                        $emin = $cab['edad_min_efectiva'];
                                        $emax = $cab['edad_max_efectiva'];
                                        if ($emin !== null || $emax !== null):
                                        ?>
                                        <div class="d-flex align-items-center gap-1">
                                            <i class="fas fa-birthday-cake text-info"
                                               style="font-size:11px; width:14px;"></i>
                                            <small class="text-info fw-semibold"
                                                   style="font-size:11px;">
                                                <?= $emin ?? '—' ?> – <?= $emax ?? '—' ?> años
                                            </small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-footer py-1 px-3 bg-transparent">
                                    <a href="lista_acampantes.php?cabana_id=<?php echo $cab['id']; ?>"
                                       class="btn btn-outline-<?php echo $color_grupo; ?> btn-sm w-100">
                                        <i class="fas fa-users"></i> Ver acampantes
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php endif; ?>
                </div><!-- /seccion-cabanas -->
            </div>
        </div>
    </div>
</div>

<!-- Panel inferior: últimos registros + acciones -->
<div class="row">
    <div class="col-md-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-clock"></i> Últimos Registros</h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush" id="seccion-ultimos">
                    <?php if (empty($ultimosAcampantes)): ?>
                    <div class="list-group-item text-center text-muted py-3">
                        <i class="fas fa-users"></i> Sin registros aún
                    </div>
                    <?php else: ?>
                    <?php foreach ($ultimosAcampantes as $ac): ?>
                    <div class="list-group-item">
                        <div class="d-flex w-100 justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1">
                                    <i class="fas fa-<?php echo $ac['sexo'] === 'masculino' ? 'mars text-primary' : 'venus text-danger'; ?>"></i>
                                    <?php echo htmlspecialchars($ac['nombre']); ?>
                                </h6>
                                <small class="text-muted">
                                    <i class="fas fa-home"></i> <?php echo htmlspecialchars($ac['nombre_cabana'] ?? 'Sin asignar'); ?>
                                    &nbsp;|&nbsp;
                                    <i class="fas fa-church"></i> <?php echo htmlspecialchars($ac['iglesia'] ?? ''); ?>
                                </small>
                            </div>
                            <small class="text-muted text-nowrap ms-2">
                                <?php echo formatearFecha($ac['fecha_registro']); ?>
                            </small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-bolt"></i> Acciones Rápidas</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="registrar_acampante.php"
                       class="btn btn-success <?php echo !$semana_activa ? 'disabled' : ''; ?>">
                        <i class="fas fa-user-plus"></i> Registrar Acampante
                    </a>
                    <a href="lista_acampantes.php" class="btn btn-primary">
                        <i class="fas fa-list"></i> Ver Lista Completa
                    </a>
                    <a href="estadisticas.php" class="btn btn-info">
                        <i class="fas fa-chart-bar"></i> Ver Estadísticas
                    </a>
                    <a href="sala_espera.php"
                       class="btn <?= $en_espera_count > 0 ? 'btn-warning' : 'btn-outline-secondary' ?>
                                  d-flex justify-content-between align-items-center">
                        <span>
                            <i class="fas fa-bullhorn"></i> Sala de Espera
                        </span>
                        <?php if ($en_espera_count > 0): ?>
                            <span class="badge bg-dark ms-2"><?= $en_espera_count ?></span>
                        <?php else: ?>
                            <span class="badge bg-success ms-2">
                                <i class="fas fa-check"></i>
                            </span>
                        <?php endif; ?>
                    </a>
                    
                </div>

                <!-- Resumen numérico -->
                <hr>
                <div class="text-center">
                    <h6 class="text-muted mb-3">Resumen General</h6>
                    <div class="row">
                        <div class="col-6">
                            <h3 class="text-primary mb-0"><?php echo $totalAcampantes; ?></h3>
                            <small class="text-muted">Registrados</small>
                        </div>
                        <div class="col-6">
                            <h3 class="text-success mb-0"><?php echo $totalCabanas; ?></h3>
                            <small class="text-muted">Cabañas</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ── Config de equipos desde PHP ───────────────────────────────
const EQUIPOS_CONFIG = <?php echo json_encode([
    'verde' => [
        'hex'    => $equipos_config['verde']['color_hex'] ?? '#198754',
        'emoji'  => $equipos_config['verde']['emoji']     ?? '🟢',
        'nombre' => $equipos_config['verde']['nombre']    ?? 'Verde',
        'color'  => $equipos_config['verde']['color']     ?? 'success',
    ],
    'azul' => [
        'hex'    => $equipos_config['azul']['color_hex'] ?? '#0d6efd',
        'emoji'  => $equipos_config['azul']['emoji']     ?? '🔵',
        'nombre' => $equipos_config['azul']['nombre']    ?? 'Azul',
        'color'  => $equipos_config['azul']['color']     ?? 'primary',
    ],
]); ?>;

// ── Configuración ─────────────────────────────────────────────
const INTERVALO_NORMAL  = 30;   // segundos entre checks normales
const INTERVALO_ACTIVO  = 10;   // segundos si hubo cambio reciente
const INTERVALO_MAX     = 60;   // segundos máximo sin actividad

let intervaloActual = INTERVALO_NORMAL;
let hashActual      = '';
let sinCambiosCount = 0;        // Cuántas veces seguidas no hubo cambio
let countdownVal    = intervaloActual;
let timerInterval   = null;
let countdownTimer  = null;
let ultimoRegistro  = null;     // Para detectar nuevo registro

// ── Render equipos ────────────────────────────────────────────
function renderEquipos(equipos) {
    const container = document.getElementById('seccion-equipos');
    if (!container) return;
    if (!equipos || equipos.length === 0) { container.innerHTML = ''; return; }

    const totales   = equipos.map(e => parseInt(e.total_acampantes));
    const maxEq     = Math.max(...totales);
    const minEq     = Math.min(...totales);
    const diferencia = maxEq - minEq;

    let alertaHtml = '';
    if (equipos.length > 1) {
        alertaHtml = diferencia > 2
            ? `<div class="col-12 mb-3"><div class="alert alert-warning border-0">
                <i class="fas fa-balance-scale"></i>
                <strong>Equipos desbalanceados:</strong>
                Diferencia de <strong>${diferencia} acampantes</strong>.
                Asigna al equipo con menos acampantes.
               </div></div>`
            : `<div class="col-12 mb-3"><div class="alert alert-success border-0">
                <i class="fas fa-check-circle"></i>
                <strong>Equipos balanceados.</strong>
                Diferencia de solo ${diferencia} acampante(s).
               </div></div>`;
    }

    const equiposHtml = equipos.map(eq => {
        const cfg       = EQUIPOS_CONFIG[eq.equipo] ?? { hex:'#6c757d', emoji:'⚪', nombre: eq.equipo, color:'secondary' };
        const hexEq     = cfg.hex;
        const emojiEq   = cfg.emoji;
        const labelEq   = cfg.nombre;
        const total     = parseInt(eq.total_acampantes);
        const capacidad = parseInt(eq.capacidad_total);
        const pctEq     = capacidad > 0 ? ((total / capacidad) * 100).toFixed(1) : 0;
        const esMenor   = equipos.length > 1 && total === minEq && diferencia > 0;
        const barHex    = pctEq >= 90 ? '#dc3545' : (pctEq >= 70 ? '#ffc107' : hexEq);
        const disponibles = capacidad - total;

        return `<div class="col-md-6 mb-3">
            <div class="card h-100" style="border: 2px solid ${hexEq};">
                <div class="card-header text-white d-flex justify-content-between align-items-center"
                     style="background-color: ${hexEq};">
                    <h5 class="mb-0">${emojiEq} ${labelEq}</h5>
                    ${esMenor && diferencia > 2
                        ? '<span class="badge bg-warning text-dark"><i class="fas fa-arrow-up"></i> Asignar aquí</span>'
                        : ''}
                </div>
                <div class="card-body">
                    <div class="row text-center mb-3">
                        <div class="col-4">
                            <h3 class="mb-0" style="color:${hexEq};">${total}</h3>
                            <small class="text-muted">Acampantes</small>
                        </div>
                        <div class="col-4">
                            <h3 class="mb-0">${eq.total_cabanas}</h3>
                            <small class="text-muted">Cabañas</small>
                        </div>
                        <div class="col-4">
                            <h3 class="mb-0">${capacidad}</h3>
                            <small class="text-muted">Capacidad</small>
                        </div>
                    </div>
                    <div class="progress mb-1" style="height:12px;">
                        <div class="progress-bar"
                             style="width:${Math.min(100,pctEq)}%; background-color:${barHex};">
                            ${pctEq}%
                        </div>
                    </div>
                    <small class="text-muted">${disponibles} lugar(es) disponibles</small>
                </div>
            </div>
        </div>`;
    }).join('');

    container.innerHTML = alertaHtml + equiposHtml;
}

// ── Render cabañas ────────────────────────────────────────────
function renderCabanas(cabanas) {
    const container = document.getElementById('seccion-cabanas');
    if (!container) return;
    if (!cabanas || cabanas.length === 0) {
        container.innerHTML = `<div class="text-center text-muted py-4">
            <i class="fas fa-home fa-3x mb-3"></i><p>No hay cabañas disponibles</p></div>`;
        return;
    }

    const grupos = {};
    cabanas.forEach(cab => {
        const eq = cab.equipo || 'sin_equipo';
        if (!grupos[eq]) grupos[eq] = [];
        grupos[eq].push(cab);
    });

    let html = '';
    for (const [equipo, grupo] of Object.entries(grupos)) {
        const cfg        = EQUIPOS_CONFIG[equipo] ?? { hex:'#6c757d', emoji:'⚪', nombre:'Sin equipo', color:'secondary' };
        const hexGrupo   = cfg.hex;
        const emojiGrupo = cfg.emoji;
        const colorGrupo = cfg.color;   // Para el botón "Ver acampantes"
        const labelGrupo = equipo === 'sin_equipo'
            ? 'Sin equipo asignado'
            : cfg.nombre;
        const totalGrupo = grupo.reduce((s, c) => s + parseInt(c.total_acampantes), 0);
        const capGrupo   = grupo.reduce((s, c) => s + parseInt(c.capacidad_maxima), 0);

        const cabHtml = grupo.map(cab => {
            const total      = parseInt(cab.total_acampantes);
            const cap        = parseInt(cab.capacidad_maxima);
            const pct        = cap > 0 ? (total / cap) * 100 : 0;
            const disponibles = cap - total;
            const barColor   = pct >= 90 ? 'bg-danger' : (pct >= 70 ? 'bg-warning' : 'bg-success');
            const borderColor = pct >= 90 ? 'border-danger' : (pct >= 70 ? 'border-warning' : 'border-success');
            const numColor   = pct >= 90 ? 'danger' : (pct >= 70 ? 'warning' : 'success');
            const iconoGenero = cab.genero === 'masculino' ? 'mars' : 'venus';
            const colorGenero = cab.genero === 'masculino' ? 'primary' : 'danger';

            return `<div class="col-md-4 col-sm-6">
                <div class="card h-100 border ${borderColor}">
                    <div class="card-body py-3 px-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h6 class="mb-0">${cab.nombre_cabana}</h6>
                                <span class="badge bg-${colorGenero} mt-1" style="font-size:10px;">
                                    <i class="fas fa-${iconoGenero}"></i>
                                    ${cab.genero.charAt(0).toUpperCase() + cab.genero.slice(1)}
                                </span>
                            </div>
                            <div class="text-end">
                                <span class="fs-4 fw-bold text-${numColor}">${total}</span>
                                <small class="text-muted d-block" style="font-size:11px;">/ ${cap}</small>
                            </div>
                        </div>
                        <div class="progress mb-1" style="height:8px;">
                            <div class="progress-bar ${barColor}"
                                 style="width:${Math.min(100,pct)}%"></div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <small class="${disponibles <= 0 ? 'text-danger fw-bold' : 'text-muted'}">
                                ${disponibles <= 0
                                    ? '<i class="fas fa-lock"></i> Cabaña llena'
                                    : `<i class="fas fa-user-plus"></i> ${disponibles} disponible(s)`}
                            </small>
                            <small class="text-muted">${Math.round(pct)}%</small>
                        </div>
                        ${(cab.consejero_principal || cab.consejero_asistente || cab.edad_min_efectiva != null || cab.edad_max_efectiva != null)
                            ? `<div class="mt-2 pt-2 border-top">
                                ${cab.consejero_principal
                                    ? `<div class="d-flex align-items-center gap-1 mb-1">
                                        <i class="fas fa-user-tie text-secondary" style="font-size:11px; width:14px;"></i>
                                        <small class="text-muted" style="font-size:11px;">${cab.consejero_principal}</small>
                                       </div>` : ''}
                                ${cab.consejero_asistente
                                    ? `<div class="d-flex align-items-center gap-1 mb-1">
                                        <i class="fas fa-user text-secondary" style="font-size:11px; width:14px;"></i>
                                        <small class="text-muted" style="font-size:11px;">${cab.consejero_asistente}</small>
                                       </div>` : ''}
                                ${(cab.edad_min_efectiva != null || cab.edad_max_efectiva != null)
                                    ? `<div class="d-flex align-items-center gap-1">
                                        <i class="fas fa-birthday-cake text-info" style="font-size:11px; width:14px;"></i>
                                        <small class="text-info fw-semibold" style="font-size:11px;">
                                            ${cab.edad_min_efectiva ?? '—'} – ${cab.edad_max_efectiva ?? '—'} años
                                        </small>
                                       </div>` : ''}
                               </div>` : ''}
                    </div>
                    <div class="card-footer py-1 px-3 bg-transparent">
                        <a href="lista_acampantes.php?cabana_id=${cab.id}"
                           class="btn btn-outline-${colorGrupo} btn-sm w-100">
                            <i class="fas fa-users"></i> Ver acampantes
                        </a>
                    </div>
                </div>
            </div>`;
        }).join('');

        html += `<div class="mb-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h6 class="mb-0">
                    <span class="badge fs-6 px-3 py-2"
                          style="background-color:${hexGrupo};">
                        ${emojiGrupo} ${labelGrupo}
                    </span>
                </h6>
                <small class="text-muted">
                    Total: <strong>${totalGrupo}</strong> / ${capGrupo} acampantes
                </small>
            </div>
            <div class="row g-3">${cabHtml}</div>
        </div>`;
    }
    container.innerHTML = html;
}

// ── Render últimos ────────────────────────────────────────────
function renderUltimos(ultimos) {
    const container = document.getElementById('seccion-ultimos');
    if (!container) return;
    if (!ultimos || ultimos.length === 0) {
        container.innerHTML = `<div class="list-group-item text-center text-muted py-3">
            <i class="fas fa-users"></i> Sin registros aún</div>`;
        return;
    }
    container.innerHTML = ultimos.map(ac => {
        const iconoSexo = ac.sexo === 'masculino' ? 'mars text-primary' : 'venus text-danger';
        const fecha = ac.fecha_registro ? ac.fecha_registro.substring(0, 10) : '';
        return `<div class="list-group-item">
            <div class="d-flex w-100 justify-content-between align-items-start">
                <div>
                    <h6 class="mb-1">
                        <i class="fas fa-${iconoSexo}"></i> ${ac.nombre}
                    </h6>
                    <small class="text-muted">
                        <i class="fas fa-home"></i> ${ac.nombre_cabana || 'Sin asignar'}
                        &nbsp;|&nbsp;
                        <i class="fas fa-church"></i> ${ac.iglesia || ''}
                    </small>
                </div>
                <small class="text-muted text-nowrap ms-2">${fecha}</small>
            </div>
        </div>`;
    }).join('');

    // Detectar si llegó un nuevo registro para acelerar el intervalo
    if (ultimoRegistro && ultimos[0]?.nombre !== ultimoRegistro) {
        sinCambiosCount = 0;
        ajustarIntervalo(INTERVALO_ACTIVO);
    }
    ultimoRegistro = ultimos[0]?.nombre ?? null;
}

// ── Ajustar intervalo dinámicamente ──────────────────────────
function ajustarIntervalo(nuevoIntervalo) {
    if (nuevoIntervalo === intervaloActual) return;
    intervaloActual = nuevoIntervalo;
    countdownVal    = nuevoIntervalo;

    clearInterval(timerInterval);
    clearInterval(countdownTimer);

    timerInterval  = setInterval(actualizarDashboard, intervaloActual * 1000);
    countdownTimer = setInterval(tickCountdown, 1000);

    const elIntervalo = document.getElementById('intervalo-actual');
    if (elIntervalo) elIntervalo.textContent = intervaloActual;
}

// ── Tick del countdown ────────────────────────────────────────
function tickCountdown() {
    countdownVal--;
    if (countdownVal < 0) countdownVal = intervaloActual;
    const el = document.getElementById('countdown');
    if (el) el.textContent = countdownVal;
}

// ── Llamada al API con hash ───────────────────────────────────
function actualizarDashboard(forzar = false) {
    const url = `api_dashboard.php?hash=${forzar ? '' : hashActual}`;

    // Mostrar indicador de carga
    const elIndicador = document.getElementById('indicador-carga');
    if (elIndicador) elIndicador.style.opacity = '1';

    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (elIndicador) elIndicador.style.opacity = '0';
            if (data.error) return;

            const elHora = document.getElementById('ultima-actualizacion');
            if (elHora) elHora.textContent = data.timestamp;

            // Si no hubo cambio, solo actualizar hora y ajustar intervalo
            if (data.sin_cambio) {
                sinCambiosCount++;

                // Después de 3 checks sin cambio, bajar frecuencia gradualmente
                if (sinCambiosCount >= 3 && intervaloActual < INTERVALO_MAX) {
                    const nuevoInt = Math.min(intervaloActual + 10, INTERVALO_MAX);
                    ajustarIntervalo(nuevoInt);
                }

                // Actualizar indicador visual
                const elEstado = document.getElementById('estado-cambio');
                if (elEstado) {
                    elEstado.textContent = 'Sin cambios';
                    elEstado.className   = 'badge bg-secondary';
                }
                return;
            }

            // Hubo cambios — actualizar todo y acelerar intervalo
            sinCambiosCount = 0;
            hashActual = data.hash;

            const elTotal = document.getElementById('total-acampantes');
            if (elTotal) elTotal.textContent = data.totalAcampantes;

            renderEquipos(data.equipos);
            renderCabanas(data.cabanas);
            renderUltimos(data.ultimos);

            // Mostrar notificación de nuevo registro
            const elEstado = document.getElementById('estado-cambio');
            if (elEstado) {
                elEstado.textContent = '¡Actualizado!';
                elEstado.className   = 'badge bg-success';
                setTimeout(() => {
                    elEstado.textContent = 'En vivo';
                    elEstado.className   = 'badge bg-primary';
                }, 3000);
            }

            // Volver a intervalo normal si estaba lento
            if (intervaloActual > INTERVALO_NORMAL) {
                ajustarIntervalo(INTERVALO_NORMAL);
            }
            countdownVal = intervaloActual;
        })
        .catch(() => {
            if (elIndicador) elIndicador.style.opacity = '0';
        });
}

// ── Iniciar ───────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    timerInterval  = setInterval(actualizarDashboard, intervaloActual * 1000);
    countdownTimer = setInterval(tickCountdown, 1000);

    // Pausar cuando el usuario cambia de pestaña
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearInterval(timerInterval);
            clearInterval(countdownTimer);
        } else {
            // Al volver a la pestaña, actualizar inmediatamente
            actualizarDashboard(true);
            timerInterval  = setInterval(actualizarDashboard, intervaloActual * 1000);
            countdownTimer = setInterval(tickCountdown, 1000);
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>