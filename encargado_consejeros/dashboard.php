<?php  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
verificarMantenimiento($pdo);
if (!esEncargadoConsejeros()) { 
    header('Location: ../consejero/dashboard.php');  
    exit();  
}  
  
$titulo = "Dashboard Encargado de consejeros";  
  
try {  
    // ⭐ OBTENER SEMANA ACTIVA
    $stmt = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
    $semana_activa = $stmt->fetch();
    $semana_id_activa = $semana_activa['id'] ?? null;

    // Total de acampantes de la semana activa
    if ($semana_id_activa) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM acampantes 
                               WHERE semana_id = ? AND estado = 'activo'");
        $stmt->execute([$semana_id_activa]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM acampantes 
                               WHERE year_campamento = ? AND estado = 'activo'");
        $stmt->execute([obtenerAnioCampamento()]);
    }
    $totalAcampantes = $stmt->fetch()['total'];

    // Total de cabañas activas  
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cabanas WHERE activa = 1");
    $totalCabanas = $stmt->fetch()['total'];

    // Total de sesiones de la semana activa
    if ($semana_id_activa) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM sesiones_consejeria sc   
                               JOIN acampantes a ON sc.acampante_id = a.id   
                               WHERE a.semana_id = ?");
        $stmt->execute([$semana_id_activa]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM sesiones_consejeria sc   
                               JOIN acampantes a ON sc.acampante_id = a.id   
                               WHERE a.year_campamento = ?");
        $stmt->execute([obtenerAnioCampamento()]);
    }
    $totalSesiones = $stmt->fetch()['total'];

    // Acampantes por cabaña de la semana activa
    if ($semana_id_activa) {
        $stmt = $pdo->prepare("SELECT c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                               COUNT(a.id) as total_acampantes  
                               FROM cabanas c   
                               LEFT JOIN acampantes a ON c.id = a.cabana_id 
                                   AND a.semana_id = ? 
                                   AND a.estado = 'activo'  
                               WHERE c.activa = 1  
                               GROUP BY c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo
                               ORDER BY c.nombre_cabana");
        $stmt->execute([$semana_id_activa]);
    } else {
        $stmt = $pdo->prepare("SELECT c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                               COUNT(a.id) as total_acampantes  
                               FROM cabanas c   
                               LEFT JOIN acampantes a ON c.id = a.cabana_id 
                                   AND a.year_campamento = ? 
                                   AND a.estado = 'activo'  
                               WHERE c.activa = 1  
                               GROUP BY c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo
                               ORDER BY c.nombre_cabana");
        $stmt->execute([obtenerAnioCampamento()]);
    }
    $acampantesPorCabana = $stmt->fetchAll();

    // Últimos acampantes registrados de la semana activa
    if ($semana_id_activa) {
        $stmt = $pdo->prepare("SELECT a.*, c.nombre_cabana   
                               FROM acampantes a   
                               LEFT JOIN cabanas c ON a.cabana_id = c.id  
                               WHERE a.semana_id = ? AND a.estado = 'activo'  
                               ORDER BY a.fecha_registro DESC   
                               LIMIT 5");
        $stmt->execute([$semana_id_activa]);
    } else {
        $stmt = $pdo->prepare("SELECT a.*, c.nombre_cabana   
                               FROM acampantes a   
                               LEFT JOIN cabanas c ON a.cabana_id = c.id  
                               WHERE a.year_campamento = ? AND a.estado = 'activo'  
                               ORDER BY a.fecha_registro DESC   
                               LIMIT 5");
        $stmt->execute([obtenerAnioCampamento()]);
    }
        $ultimosAcampantes = $stmt->fetchAll();
        
    // Acampantes que necesitan apoyo en hora silenciosa
    try {
        if ($semana_id_activa) {
            $stmt = $pdo->prepare("
                SELECT a.id, a.nombre, a.sexo,
                       c.nombre_cabana, c.equipo
                FROM acampantes a
                LEFT JOIN cabanas c ON a.cabana_id = c.id
                WHERE a.semana_id = ?
                  AND a.estado = 'activo'
                  AND a.necesita_apoyo_silenciosa = 1
                ORDER BY a.nombre
            ");
            $stmt->execute([$semana_id_activa]);
        } else {
            $stmt = $pdo->prepare("
                SELECT a.id, a.nombre, a.sexo,
                       c.nombre_cabana, c.equipo
                FROM acampantes a
                LEFT JOIN cabanas c ON a.cabana_id = c.id
                WHERE a.year_campamento = ?
                  AND a.estado = 'activo'
                  AND a.necesita_apoyo_silenciosa = 1
                ORDER BY a.nombre
            ");
            $stmt->execute([obtenerAnioCampamento()]);
        }
        $acampantesNecesitanApoyo = $stmt->fetchAll();
    } catch (Exception $eApoyo) {
        $acampantesNecesitanApoyo = [];
    }

    // Cabañas con id para agrupar por equipo — incluye consejeros y rango de edad
    if ($semana_id_activa) {
        $stmt = $pdo->prepare("
            SELECT c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                   MAX(CASE WHEN cs.rol = 'principal' THEN cs.nombre_consejero END) AS consejero_principal,
                   MAX(CASE WHEN cs.rol = 'asistente' THEN cs.nombre_consejero END) AS consejero_asistente,
                   COALESCE(csc.edad_min, s.edad_min) AS edad_min_efectiva,
                   COALESCE(csc.edad_max, s.edad_max) AS edad_max_efectiva,
                   COUNT(DISTINCT a.id) AS total_acampantes
            FROM cabanas c
            LEFT JOIN acampantes a
                   ON c.id = a.cabana_id AND a.semana_id = ? AND a.estado = 'activo'
            LEFT JOIN consejeros_semana cs
                   ON cs.cabana_id = c.id AND cs.semana_id = ?
            LEFT JOIN cabana_semana_config csc
                   ON csc.cabana_id = c.id AND csc.semana_id = ?
            LEFT JOIN semanas_campamento s ON s.id = ?
            WHERE c.activa = 1
            GROUP BY c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                     edad_min_efectiva, edad_max_efectiva
            ORDER BY c.equipo ASC, c.nombre_cabana ASC
        ");
        $stmt->execute([
            $semana_id_activa,
            $semana_id_activa,
            $semana_id_activa,
            $semana_id_activa
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                   c.consejero_principal,
                   c.consejero_asistente,
                   NULL AS edad_min_efectiva,
                   NULL AS edad_max_efectiva,
                   COUNT(DISTINCT a.id) AS total_acampantes
            FROM cabanas c
            LEFT JOIN acampantes a
                   ON c.id = a.cabana_id AND a.year_campamento = ? AND a.estado = 'activo'
            WHERE c.activa = 1
            GROUP BY c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                     c.consejero_principal, c.consejero_asistente
            ORDER BY c.equipo ASC, c.nombre_cabana ASC
        ");
        $stmt->execute([obtenerAnioCampamento()]);
    }
    $cabanasPorEquipoDetalle = $stmt->fetchAll();

    // Estadísticas por equipo - subconsulta para evitar duplicar capacidad
    try {
        if ($semana_id_activa) {
            $stmt = $pdo->prepare("
                SELECT
                    eq.equipo,
                    eq.total_cabanas,
                    eq.capacidad_total,
                    COALESCE(ac.total_acampantes, 0) as total_acampantes
                FROM (
                    SELECT equipo,
                           COUNT(id)            as total_cabanas,
                           SUM(capacidad_maxima) as capacidad_total
                    FROM cabanas
                    WHERE activa = 1 AND equipo IS NOT NULL
                    GROUP BY equipo
                ) eq
                LEFT JOIN (
                    SELECT c2.equipo, COUNT(a.id) as total_acampantes
                    FROM acampantes a
                    JOIN cabanas c2 ON a.cabana_id = c2.id
                    WHERE a.semana_id = ? AND a.estado = 'activo'
                    GROUP BY c2.equipo
                ) ac ON eq.equipo = ac.equipo
                ORDER BY eq.equipo
            ");
            $stmt->execute([$semana_id_activa]);
        } else {
            $stmt = $pdo->prepare("
                SELECT
                    eq.equipo,
                    eq.total_cabanas,
                    eq.capacidad_total,
                    COALESCE(ac.total_acampantes, 0) as total_acampantes
                FROM (
                    SELECT equipo,
                           COUNT(id)            as total_cabanas,
                           SUM(capacidad_maxima) as capacidad_total
                    FROM cabanas
                    WHERE activa = 1 AND equipo IS NOT NULL
                    GROUP BY equipo
                ) eq
                LEFT JOIN (
                    SELECT c2.equipo, COUNT(a.id) as total_acampantes
                    FROM acampantes a
                    JOIN cabanas c2 ON a.cabana_id = c2.id
                    WHERE a.year_campamento = ? AND a.estado = 'activo'
                    GROUP BY c2.equipo
                ) ac ON eq.equipo = ac.equipo
                ORDER BY eq.equipo
            ");
            $stmt->execute([obtenerAnioCampamento()]);
        }
        $stats_equipos = $stmt->fetchAll();
    } catch (Exception $e) {
        $stats_equipos = [];
    }

} catch (Exception $e) {  
    $error = "Error al cargar estadísticas: " . $e->getMessage();  
} 

// Config de equipos — siempre disponible
$equipos_config = obtenerEquipos($pdo);
  
include '../includes/header.php';  
?>  
  
<div class="row mb-4">  
    <div class="col-12">  
        <h1><i class="fas fa-tachometer-alt"></i> Encargado de consejeros</h1>  
        <p class="text-muted">Campamento <?php echo obtenerAnioCampamento(); ?></p>  
    </div>  
</div>  

<?php if (isset($error)): ?>  
    <div class="alert alert-danger">  
        <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>  
    </div>  
<?php endif; ?>

<!-- ⭐ BANNER SEMANA ACTIVA -->
<?php if ($semana_activa): ?>
<div class="alert alert-success border-0 shadow-sm mb-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <i class="fas fa-broadcast-tower fa-2x text-success"></i>
            <div>
                <h5 class="mb-0">
                    <span class="badge bg-success me-2">ACTIVA</span>
                    <?php echo htmlspecialchars($semana_activa['nombre']); ?>
                </h5>
                <small class="text-muted">
                    <?php
                    $tipo_labels = ['mayores' => 'Mayores', 'ninos' => 'Niños', 'adolescentes' => 'Adolescentes'];
                    echo $tipo_labels[$semana_activa['tipo_acampante']] ?? $semana_activa['tipo_acampante'];
                    ?> |
                    <?php echo date('d/m/Y', strtotime($semana_activa['fecha_inicio'])); ?> -
                    <?php echo date('d/m/Y', strtotime($semana_activa['fecha_fin'])); ?>
                </small>
            </div>
        </div>
        <a href="semanas.php" class="btn btn-outline-success btn-sm">
            <i class="fas fa-calendar-week"></i> Gestionar Semanas
        </a>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning border-0 shadow-sm mb-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
            <div>
                <h5 class="mb-0">Sin semana activa</h5>
                <small class="text-muted">
                    Los consejeros no pueden ver acampantes. Activa una semana para comenzar.
                </small>
            </div>
        </div>
        <a href="semanas.php" class="btn btn-warning btn-sm">
            <i class="fas fa-play"></i> Activar Semana
        </a>
    </div>
</div>
<?php endif; ?>
  
<!-- Tarjetas de estadísticas -->  
<div class="row mb-4">  
    <div class="col-md-3 mb-3">  
        <div class="card card-stat text-center">  
            <div class="card-body">  
                <i class="fas fa-users mb-2"></i>  
                <h3><?php echo $totalAcampantes; ?></h3>  
                <p class="mb-0">Acampantes</p>
                <?php if ($semana_activa): ?>
                <small class=".text-muted-w">Semana activa</small>
                <?php endif; ?>
            </div>  
        </div>  
    </div>  
    <div class="col-md-3 mb-3">  
        <div class="card card-stat text-center">  
            <div class="card-body">  
                <i class="fas fa-home mb-2"></i>  
                <h3><?php echo $totalCabanas; ?></h3>  
                <p class="mb-0">Cabañas</p>
                <small class="text-muted-w">Activas</small>
            </div>  
        </div>  
    </div>  
    <div class="col-md-3 mb-3">  
        <div class="card card-stat text-center">  
            <div class="card-body">  
                <i class="fas fa-comments mb-2"></i>  
                <h3><?php echo $totalSesiones; ?></h3>  
                <p class="mb-0">Consejerías</p>
                <?php if ($semana_activa): ?>
                <small class="text-muted-w">Semana activa</small>
                <?php endif; ?>
            </div>  
        </div>  
    </div>  
    <div class="col-md-3 mb-3">  
        <div class="card card-stat text-center">  
            <div class="card-body">  
                <i class="fas fa-percentage mb-2"></i>  
                <h3><?php echo $totalAcampantes > 0 ? round(($totalSesiones / $totalAcampantes) * 100, 1) : 0; ?>%</h3>  
                <p class="mb-0">Progreso</p>
                <small class="text-muted-w">Consejerías/Acampantes</small>
            </div>  
        </div>  
    </div>  
</div>

<!-- ═══ BALANCE POR EQUIPO ═══ -->
<?php if (!empty($stats_equipos)): ?>
<div class="row mb-4">
    <div class="col-12">
        <h5 class="mb-3">
            <i class="fas fa-shield-alt"></i> Balance por Equipo
            <small class="text-muted fs-6"> — distribución actual</small>
        </h5>
    </div>

    <?php
    $totalesEq = array_column($stats_equipos, 'total_acampantes');
    $maxEq     = !empty($totalesEq) ? max($totalesEq) : 0;
    $minEq     = !empty($totalesEq) ? min($totalesEq) : 0;
    $difEq     = $maxEq - $minEq;
    ?>

    <?php if (count($stats_equipos) > 1): ?>
    <div class="col-12 mb-3">
        <?php if ($difEq > 2): ?>
        <div class="alert alert-warning border-0 mb-0">
            <i class="fas fa-balance-scale"></i>
            <strong>Equipos desbalanceados:</strong>
            Diferencia de <strong><?php echo $difEq; ?> acampantes</strong> entre equipos.
        </div>
        <?php else: ?>
        <div class="alert alert-success border-0 mb-0">
            <i class="fas fa-check-circle"></i>
            <strong>Equipos balanceados.</strong>
            Diferencia de solo <?php echo $difEq; ?> acampante(s).
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($stats_equipos as $eq):
        $eqData  = $equipos_config[$eq['equipo']] ?? null;
        $hexEq   = $eqData['color_hex'] ?? '#6c757d';
        $emojiEq = $eqData['emoji']     ?? '⚪';
        $labelEq = $eqData['nombre']    ?? ucfirst($eq['equipo']);
        $pctEq   = $eq['capacidad_total'] > 0
            ? round(($eq['total_acampantes'] / $eq['capacidad_total']) * 100, 1)
            : 0;
        $barHex  = $pctEq >= 90 ? '#dc3545' : ($pctEq >= 70 ? '#ffc107' : $hexEq);
        $esMenor = count($totalesEq) > 1
            && $eq['total_acampantes'] == $minEq
            && $difEq > 0;
    ?>
    <div class="col-md-6 mb-3">
        <div class="card h-100" style="border: 2px solid <?php echo $hexEq; ?>;">
            <div class="card-header text-white d-flex justify-content-between align-items-center"
                 style="background-color: <?php echo $hexEq; ?>;">
                <h5 class="mb-0"><?php echo $emojiEq; ?> <?php echo htmlspecialchars($labelEq); ?></h5>
                <?php if ($esMenor && $difEq > 2): ?>
                <span class="badge bg-warning text-dark">
                    <i class="fas fa-arrow-up"></i> Necesita más acampantes
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
                         style="width:<?php echo min(100,$pctEq); ?>%;
                                background-color: <?php echo $barHex; ?>;">
                        <?php echo $pctEq; ?>%
                    </div>
                </div>
                <small class="text-muted">
                    <?php echo $eq['capacidad_total'] - $eq['total_acampantes']; ?> lugar(es) disponibles
                </small>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ═══ DISTRIBUCIÓN DETALLADA POR CABAÑA ═══ -->
<?php if (!empty($cabanasPorEquipoDetalle)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-home"></i> Distribución por Cabaña
                    <?php if ($semana_activa): ?>
                    <small class="text-muted fs-6"> — <?php echo htmlspecialchars($semana_activa['nombre']); ?></small>
                    <?php endif; ?>
                </h5>
                <a href="acampantes.php?semana_id=<?php echo $semana_id_activa ?? ''; ?>" class="btn btn-primary btn-sm">
                    <i class="fas fa-users"></i> Ver todos
                </a>
            </div>
            <div class="card-body">
                <?php
                // Agrupar por equipo
                $grupos = [];
                foreach ($cabanasPorEquipoDetalle as $cab) {
                    $key = $cab['equipo'] ?? 'sin_equipo';
                    $grupos[$key][] = $cab;
                }

                // Separar masculino/femenino dentro de cada equipo
                foreach ($grupos as $equipo => $cabanas_grupo):
                    $eqGData    = $equipos_config[$equipo] ?? null;
                    $hexGrupo   = $eqGData['color_hex'] ?? '#6c757d';
                    $emojiGrupo = $eqGData['emoji']     ?? '⚪';
                    $labelGrupo = $equipo === 'sin_equipo'
                        ? 'Sin equipo asignado'
                        : ($eqGData['nombre'] ?? 'Equipo ' . ucfirst($equipo));
                    $totalGrupo = array_sum(array_column($cabanas_grupo, 'total_acampantes'));
                    $capGrupo   = array_sum(array_column($cabanas_grupo, 'capacidad_maxima'));

                    // Sub-agrupar por género
                    $masc = array_filter($cabanas_grupo, fn($c) => $c['genero'] === 'masculino');
                    $fem  = array_filter($cabanas_grupo, fn($c) => $c['genero'] === 'femenino');
                ?>
                <div class="mb-4">
                    <!-- Encabezado del equipo -->
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="mb-0">
                            <span class="badge fs-6 px-3 py-2"
                                  style="background-color: <?php echo $hexGrupo; ?>;">
                                <?php echo $emojiGrupo; ?> <?php echo htmlspecialchars($labelGrupo); ?>
                            </span>
                        </h6>
                        <small class="text-muted">
                            Total: <strong><?php echo $totalGrupo; ?></strong>
                            / <?php echo $capGrupo; ?> acampantes
                        </small>
                    </div>

                    <div class="row g-0">
                        <!-- Columna Masculino -->
                        <?php if (!empty($masc)): ?>
                        <div class="<?php echo !empty($fem) ? 'col-md-6' : 'col-12'; ?> pe-md-2">
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge bg-primary me-2">
                                    <i class="fas fa-mars"></i> Masculino
                                </span>
                                <small class="text-muted">
                                    <?php echo array_sum(array_column(array_values($masc), 'total_acampantes')); ?>
                                    /<?php echo array_sum(array_column(array_values($masc), 'capacidad_maxima')); ?>
                                </small>
                            </div>
                            <div class="row g-2">
                                <?php foreach ($masc as $cab):
                                    $pct        = $cab['capacidad_maxima'] > 0
                                        ? ($cab['total_acampantes'] / $cab['capacidad_maxima']) * 100
                                        : 0;
                                    $disponibles = $cab['capacidad_maxima'] - $cab['total_acampantes'];
                                    $barColor    = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
                                    $borderColor = $pct >= 90 ? 'border-danger' : ($pct >= 70 ? 'border-warning' : 'border-success');
                                ?>
                                <div class="col-12">
                                    <div class="card border <?php echo $borderColor; ?> mb-0">
                                        <div class="card-body py-2 px-3">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <strong class="small"><?php echo htmlspecialchars($cab['nombre_cabana']); ?></strong>
                                                <span class="fw-bold text-<?php echo $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success'); ?>">
                                                    <?php echo $cab['total_acampantes']; ?>
                                                    <small class="text-muted fw-normal">/ <?php echo $cab['capacidad_maxima']; ?></small>
                                                </span>
                                            </div>
                                            <div class="progress mb-1" style="height:6px;">
                                                <div class="progress-bar <?php echo $barColor; ?>"
                                                     style="width:<?php echo min(100,$pct); ?>%"></div>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <small class="<?php echo $disponibles <= 0 ? 'text-danger fw-bold' : 'text-muted'; ?>">
                                                    <?php echo $disponibles <= 0
                                                        ? '<i class="fas fa-lock"></i> Llena'
                                                        : $disponibles . ' disponible(s)'; ?>
                                                </small>
                                                <a href="acampantes.php?cabana=<?php echo $cab['id']; ?>&semana_id=<?php echo $semana_id_activa ?? ''; ?>"
                                                   class="small text-primary">
                                                    <i class="fas fa-eye"></i> Ver
                                                </a>
                                            </div>
                                            <!-- Consejeros y rango de edad -->
                                            <?php if ($cab['consejero_principal'] || $cab['consejero_asistente'] || $cab['edad_min_efectiva'] !== null || $cab['edad_max_efectiva'] !== null): ?>
                                            <div class="mt-2 pt-1 border-top">
                                                <?php if ($cab['consejero_principal']): ?>
                                                <div class="d-flex align-items-center gap-1">
                                                    <i class="fas fa-user-tie text-secondary"
                                                       style="font-size:10px; width:13px;"></i>
                                                    <small class="text-muted" style="font-size:10px;">
                                                        <?= htmlspecialchars($cab['consejero_principal']) ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($cab['consejero_asistente']): ?>
                                                <div class="d-flex align-items-center gap-1">
                                                    <i class="fas fa-user text-secondary"
                                                       style="font-size:10px; width:13px;"></i>
                                                    <small class="text-muted" style="font-size:10px;">
                                                        <?= htmlspecialchars($cab['consejero_asistente']) ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($cab['edad_min_efectiva'] !== null || $cab['edad_max_efectiva'] !== null): ?>
                                                <div class="d-flex align-items-center gap-1">
                                                    <i class="fas fa-birthday-cake text-info"
                                                       style="font-size:10px; width:13px;"></i>
                                                    <small class="text-info fw-semibold"
                                                           style="font-size:10px;">
                                                        <?= $cab['edad_min_efectiva'] ?? '—' ?>
                                                        –
                                                        <?= $cab['edad_max_efectiva'] ?? '—' ?>
                                                        años
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Divisor vertical si hay ambos géneros -->
                        <?php if (!empty($masc) && !empty($fem)): ?>
                        <div class="col-md-0 d-none d-md-block" style="width:1px; background:#dee2e6; margin:0 8px;"></div>
                        <?php endif; ?>

                        <!-- Columna Femenino -->
                        <?php if (!empty($fem)): ?>
                        <div class="<?php echo !empty($masc) ? 'col-md-6' : 'col-12'; ?> ps-md-2">
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge bg-danger me-2">
                                    <i class="fas fa-venus"></i> Femenino
                                </span>
                                <small class="text-muted">
                                    <?php echo array_sum(array_column(array_values($fem), 'total_acampantes')); ?>
                                    /<?php echo array_sum(array_column(array_values($fem), 'capacidad_maxima')); ?>
                                </small>
                            </div>
                            <div class="row g-2">
                                <?php foreach ($fem as $cab):
                                    $pct        = $cab['capacidad_maxima'] > 0
                                        ? ($cab['total_acampantes'] / $cab['capacidad_maxima']) * 100
                                        : 0;
                                    $disponibles = $cab['capacidad_maxima'] - $cab['total_acampantes'];
                                    $barColor    = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
                                    $borderColor = $pct >= 90 ? 'border-danger' : ($pct >= 70 ? 'border-warning' : 'border-success');
                                ?>
                                <div class="col-12">
                                    <div class="card border <?php echo $borderColor; ?> mb-0">
                                        <div class="card-body py-2 px-3">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <strong class="small"><?php echo htmlspecialchars($cab['nombre_cabana']); ?></strong>
                                                <span class="fw-bold text-<?php echo $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success'); ?>">
                                                    <?php echo $cab['total_acampantes']; ?>
                                                    <small class="text-muted fw-normal">/ <?php echo $cab['capacidad_maxima']; ?></small>
                                                </span>
                                            </div>
                                            <div class="progress mb-1" style="height:6px;">
                                                <div class="progress-bar <?php echo $barColor; ?>"
                                                     style="width:<?php echo min(100,$pct); ?>%"></div>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <small class="<?php echo $disponibles <= 0 ? 'text-danger fw-bold' : 'text-muted'; ?>">
                                                    <?php echo $disponibles <= 0
                                                        ? '<i class="fas fa-lock"></i> Llena'
                                                        : $disponibles . ' disponible(s)'; ?>
                                                </small>
                                                <a href="acampantes.php?cabana=<?php echo $cab['id']; ?>&semana_id=<?php echo $semana_id_activa ?? ''; ?>"
                                                   class="small text-primary">
                                                    <i class="fas fa-eye"></i> Ver
                                                </a>
                                            </div>
                                            <!-- Consejeros y rango de edad -->
                                            <?php if ($cab['consejero_principal'] || $cab['consejero_asistente'] || $cab['edad_min_efectiva'] !== null || $cab['edad_max_efectiva'] !== null): ?>
                                            <div class="mt-2 pt-1 border-top">
                                                <?php if ($cab['consejero_principal']): ?>
                                                <div class="d-flex align-items-center gap-1">
                                                    <i class="fas fa-user-tie text-secondary"
                                                       style="font-size:10px; width:13px;"></i>
                                                    <small class="text-muted" style="font-size:10px;">
                                                        <?= htmlspecialchars($cab['consejero_principal']) ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($cab['consejero_asistente']): ?>
                                                <div class="d-flex align-items-center gap-1">
                                                    <i class="fas fa-user text-secondary"
                                                       style="font-size:10px; width:13px;"></i>
                                                    <small class="text-muted" style="font-size:10px;">
                                                        <?= htmlspecialchars($cab['consejero_asistente']) ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($cab['edad_min_efectiva'] !== null || $cab['edad_max_efectiva'] !== null): ?>
                                                <div class="d-flex align-items-center gap-1">
                                                    <i class="fas fa-birthday-cake text-info"
                                                       style="font-size:10px; width:13px;"></i>
                                                    <small class="text-info fw-semibold"
                                                           style="font-size:10px;">
                                                        <?= $cab['edad_min_efectiva'] ?? '—' ?>
                                                        –
                                                        <?= $cab['edad_max_efectiva'] ?? '—' ?>
                                                        años
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ ALERTA: APOYO EN HORA SILENCIOSA ═══ -->
<?php if (!empty($acampantesNecesitanApoyo)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-warning shadow-sm">
            <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-bell"></i> Solicitudes de Apoyo en Hora Silenciosa
                </h5>
                <span class="badge bg-dark rounded-pill">
                    <?php echo count($acampantesNecesitanApoyo); ?> pendiente(s)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Acampante</th>
                                <th>Cabaña</th>
                                <th class="text-center">Equipo</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($acampantesNecesitanApoyo as $apoyo):
                                $eqData  = $equipos_config[$apoyo['equipo']] ?? null;
                                $hexEq   = $eqData['color_hex'] ?? '#6c757d';
                                $emojiEq = $eqData['emoji']     ?? '⚪';
                                $labelEq = $eqData['nombre']    ?? ucfirst($apoyo['equipo'] ?? '—');
                            ?>
                            <tr>
                                <td>
                                    <i class="fas fa-<?php echo $apoyo['sexo'] === 'masculino' ? 'mars text-primary' : 'venus text-danger'; ?>"></i>
                                    <strong><?php echo htmlspecialchars($apoyo['nombre']); ?></strong>
                                </td>
                                <td>
                                    <i class="fas fa-home text-muted"></i>
                                    <?php echo htmlspecialchars($apoyo['nombre_cabana'] ?? 'Sin asignar'); ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($apoyo['equipo']): ?>
                                    <span class="badge"
                                          style="background-color: <?php echo $hexEq; ?>;">
                                        <?php echo $emojiEq; ?> <?php echo htmlspecialchars($labelEq); ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="acampantes.php?action=view&id=<?php echo $apoyo['id']; ?>"
                                       class="btn btn-sm btn-outline-warning"
                                       title="Ver información del acampante">
                                        <i class="fas fa-eye"></i> Ver
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-light small text-muted">
                <i class="fas fa-info-circle"></i>
                El consejero puede cancelar la solicitud desde su vista de acampantes.
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
  
<div class="row">  
    <!-- Resumen rápido por cabaña -->
    <div class="col-md-8 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-chart-bar"></i> Resumen por Cabaña
                </h5>
                <a href="acampantes.php?semana_id=<?php echo $semana_id_activa ?? ''; ?>" class="btn btn-sm btn-primary">Ver todos</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead class="table-dark">
                            <tr>
                                <th>Cabaña</th>
                                <th>Género</th>
                                <th>Equipo</th>
                                <th>Acampantes</th>
                                <th>Ocupación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($acampantesPorCabana as $cabana):
                                $pct = $cabana['capacidad_maxima'] > 0
                                    ? ($cabana['total_acampantes'] / $cabana['capacidad_maxima']) * 100
                                    : 0;
                                $color = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($cabana['nombre_cabana']); ?></strong></td>
                                <td>
                                    <span class="badge bg-<?php echo $cabana['genero'] === 'masculino' ? 'primary' : 'danger'; ?>">
                                        <i class="fas fa-<?php echo $cabana['genero'] === 'masculino' ? 'mars' : 'venus'; ?>"></i>
                                        <?php echo ucfirst($cabana['genero']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($cabana['equipo']): 
                                        $eqTabla  = $equipos_config[$cabana['equipo']] ?? null;
                                        $hexTabla = $eqTabla['color_hex'] ?? '#6c757d';
                                        $emojiTabla  = $eqTabla['emoji']  ?? '⚪';
                                        $nombreTabla = $eqTabla['nombre'] ?? ucfirst($cabana['equipo']);
                                    ?>
                                    <span class="badge"
                                          style="background-color: <?php echo $hexTabla; ?>;">
                                        <?php echo $emojiTabla; ?>
                                        <?php echo htmlspecialchars($nombreTabla); ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo $cabana['total_acampantes']; ?></strong>
                                    / <?php echo $cabana['capacidad_maxima']; ?>
                                </td>
                                <td style="min-width:100px;">
                                    <div class="progress" style="height:16px;">
                                        <div class="progress-bar <?php echo $color; ?>"
                                             style="width:<?php echo min(100,$pct); ?>%">
                                            <?php echo round($pct,1); ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>  
  
    <!-- Acciones rápidas y últimos registros -->  
    <div class="col-md-4 mb-4">  
        <div class="card">  
            <div class="card-header">  
                <h5><i class="fas fa-bolt"></i> Acciones Rápidas</h5>  
            </div>  
            <div class="card-body">  
                <div class="d-grid gap-2">  
                    <a href="acampantes.php?action=add" class="btn btn-success">  
                        <i class="fas fa-plus"></i> Nuevo Acampante  
                    </a>
                    <a href="semanas.php" class="btn btn-secondary">  
                        <i class="fas fa-calendar-week"></i> Gestionar Semanas  
                    </a>
                    <a href="cabanas.php" class="btn btn-info">  
                        <i class="fas fa-home"></i> Gestionar Cabañas  
                    </a>  
                    <a href="recursos.php" class="btn btn-warning">  
                        <i class="fas fa-upload"></i> Subir Recursos  
                    </a>  
                    <a href="reportes.php" class="btn btn-primary">  
                        <i class="fas fa-file-pdf"></i> Generar Reportes  
                    </a>  
                </div>  
            </div>  
        </div>  
  
        <!-- Últimos acampantes -->  
        <div class="card mt-3">  
            <div class="card-header">  
                <h5><i class="fas fa-clock"></i> Últimos Registros</h5>  
            </div>  
            <div class="card-body p-0">  
                <div class="list-group list-group-flush">  
                    <?php if (empty($ultimosAcampantes)): ?>
                    <div class="list-group-item text-center text-muted py-3">
                        <i class="fas fa-users"></i> Sin acampantes registrados
                    </div>
                    <?php else: ?>
                    <?php foreach ($ultimosAcampantes as $acampante): ?>  
                    <div class="list-group-item">  
                        <div class="d-flex w-100 justify-content-between">  
                            <h6 class="mb-1"><?php echo htmlspecialchars($acampante['nombre']); ?></h6>  
                            <small><?php echo formatearFecha($acampante['fecha_registro']); ?></small>  
                        </div>  
                        <p class="mb-0">  
                            <small class="text-muted">  
                                <i class="fas fa-home"></i> <?php echo htmlspecialchars($acampante['nombre_cabana'] ?? 'Sin asignar'); ?>
                            </small>  
                        </p>  
                    </div>  
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>  
            </div>  
        </div>  
    </div>  
</div>  
  
<?php include '../includes/footer.php'; ?>