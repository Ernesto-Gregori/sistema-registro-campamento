<?php  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
if (!esEncargadoConsejeros()) { 
    header('Location: ../consejero/dashboard.php');  
    exit();  
}  
  
$titulo = "Reportes y Estadísticas";  
$tipo_reporte = $_GET['tipo'] ?? 'general';  
$acampante_id = $_GET['acampante_id'] ?? null;  
$cabana_id = $_GET['cabana_id'] ?? null;  
$year = $_GET['year'] ?? obtenerAnioCampamento();
$semana_id = $_GET['semana_id'] ?? null;  
  
// Procesar generación de PDF  
if (isset($_GET['generar_pdf'])) {  
    require_once 'generar_pdf.php';  
    exit();  
}  
  
try {
    // Obtener semana activa si no se especificó una
    $stmt_sem = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
    $semana_activa = $stmt_sem->fetch();

    // Si no se pasó semana_id, usar la semana activa
    if (!$semana_id && $semana_activa) {
        $semana_id = $semana_activa['id'];
    }

    // Obtener todas las semanas disponibles para el filtro
    $stmt = $pdo->query("SELECT * FROM semanas_campamento ORDER BY fecha_inicio DESC");
    $semanasDisponibles = $stmt->fetchAll();

    // Obtener datos de la semana seleccionada
    $semana_seleccionada = null;
    if ($semana_id) {
        $stmt = $pdo->prepare("SELECT * FROM semanas_campamento WHERE id = ?");
        $stmt->execute([$semana_id]);
        $semana_seleccionada = $stmt->fetch();
    }

    // Total acampantes
    if ($semana_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM acampantes 
                               WHERE semana_id = ? AND estado = 'activo'");
        $stmt->execute([$semana_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM acampantes 
                               WHERE year_campamento = ? AND estado = 'activo'");
        $stmt->execute([$year]);
    }
    $totalAcampantes = $stmt->fetch()['total'];

    // Total cabañas
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cabanas WHERE activa = 1");
    $totalCabanas = $stmt->fetch()['total'];

    // Total consejerías
    if ($semana_id) {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT sc.acampante_id, sc.numero_sesion) as total   
                               FROM sesiones_consejeria sc   
                               JOIN acampantes a ON sc.acampante_id = a.id   
                               WHERE a.semana_id = ?");
        $stmt->execute([$semana_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT sc.acampante_id, sc.numero_sesion) as total   
                               FROM sesiones_consejeria sc   
                               JOIN acampantes a ON sc.acampante_id = a.id   
                               WHERE a.year_campamento = ?");
        $stmt->execute([$year]);
    }
    $totalConsejerias = $stmt->fetch()['total'];

    // Estadísticas por cabaña
    if ($semana_id) {
        $stmt = $pdo->prepare("SELECT c.id, c.nombre_cabana, c.consejero_principal, c.capacidad_maxima,  
                               COUNT(a.id) as total_acampantes,  
                               COUNT(CASE WHEN a.recibio_cristo_semana = 1 THEN 1 END) as nuevos_creyentes,  
                               COUNT(CASE WHEN a.consagro_vida_fogata = 1 THEN 1 END) as consagraciones  
                               FROM cabanas c   
                               LEFT JOIN acampantes a ON c.id = a.cabana_id 
                                   AND a.semana_id = ? 
                                   AND a.estado = 'activo'  
                               WHERE c.activa = 1  
                               GROUP BY c.id, c.nombre_cabana, c.consejero_principal, c.capacidad_maxima  
                               ORDER BY c.nombre_cabana");
        $stmt->execute([$semana_id]);
    } else {
        $stmt = $pdo->prepare("SELECT c.id, c.nombre_cabana, c.consejero_principal, c.capacidad_maxima,  
                               COUNT(a.id) as total_acampantes,  
                               COUNT(CASE WHEN a.recibio_cristo_semana = 1 THEN 1 END) as nuevos_creyentes,  
                               COUNT(CASE WHEN a.consagro_vida_fogata = 1 THEN 1 END) as consagraciones  
                               FROM cabanas c   
                               LEFT JOIN acampantes a ON c.id = a.cabana_id 
                                   AND a.year_campamento = ? 
                                   AND a.estado = 'activo'  
                               WHERE c.activa = 1  
                               GROUP BY c.id, c.nombre_cabana, c.consejero_principal, c.capacidad_maxima  
                               ORDER BY c.nombre_cabana");
        $stmt->execute([$year]);
    }
    $estadisticasCabanas = $stmt->fetchAll();

    // Top temas más tratados
    if ($semana_id) {
        $stmt = $pdo->prepare("SELECT tc.categoria, tc.tema, COUNT(*) as veces_tratado  
                               FROM sesiones_consejeria sc  
                               JOIN acampantes a ON sc.acampante_id = a.id  
                               LEFT JOIN temas_consejeria tc ON sc.tema_id = tc.id  
                               WHERE a.semana_id = ? AND tc.tema IS NOT NULL  
                               GROUP BY tc.id  
                               ORDER BY veces_tratado DESC  
                               LIMIT 10");
        $stmt->execute([$semana_id]);
    } else {
        $stmt = $pdo->prepare("SELECT tc.categoria, tc.tema, COUNT(*) as veces_tratado  
                               FROM sesiones_consejeria sc  
                               JOIN acampantes a ON sc.acampante_id = a.id  
                               LEFT JOIN temas_consejeria tc ON sc.tema_id = tc.id  
                               WHERE a.year_campamento = ? AND tc.tema IS NOT NULL  
                               GROUP BY tc.id  
                               ORDER BY veces_tratado DESC  
                               LIMIT 10");
        $stmt->execute([$year]);
    }
    $topTemas = $stmt->fetchAll();

    // Años disponibles
    $stmt = $pdo->query("SELECT DISTINCT year_campamento FROM acampantes ORDER BY year_campamento DESC");
    $yearsDisponibles = $stmt->fetchAll();

    // ── Impacto espiritual ─────────────────────────────────────
    $where_imp  = $semana_id ? "a.semana_id = ?" : "a.year_campamento = ?";
    $param_imp  = $semana_id ? $semana_id : $year;
    $stmt = $pdo->prepare("SELECT
                           COUNT(*)                                              as total,
                           COUNT(CASE WHEN a.recibio_cristo_semana = 1 THEN 1 END) as recibio_cristo,
                           COUNT(CASE WHEN a.consagro_vida_fogata  = 1 THEN 1 END) as consagro_vida,
                           COUNT(CASE WHEN a.era_creyente_antes    = 1 THEN 1 END) as era_creyente,
                           COUNT(CASE WHEN a.asiste_iglesia        = 1 THEN 1 END) as asiste_iglesia
                           FROM acampantes a
                           WHERE $where_imp AND a.estado = 'activo'");
    $stmt->execute([$param_imp]);
    $impacto = $stmt->fetch();

    // ── Por género ─────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT a.sexo, COUNT(*) as total
                           FROM acampantes a
                           WHERE $where_imp AND a.estado = 'activo'
                           GROUP BY a.sexo");
    $stmt->execute([$param_imp]);
    $porGenero = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // ── Consejerías detalle ────────────────────────────────────
    $stmt = $pdo->prepare("SELECT
                           COUNT(DISTINCT a.id)                                          as total_acampantes,
                           COUNT(DISTINCT CASE WHEN sc.id IS NOT NULL THEN a.id END)     as con_consejeria,
                           COUNT(DISTINCT sc.id)                                         as total_sesiones,
                           ROUND(COUNT(DISTINCT sc.id) /
                               NULLIF(COUNT(DISTINCT a.id), 0), 1)                       as prom_sesiones
                           FROM acampantes a
                           LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
                           WHERE $where_imp AND a.estado = 'activo'");
    $stmt->execute([$param_imp]);
    $detalle_cons = $stmt->fetch();

    // ── Consejerías por cabaña (con responsables) ──────────────
    $where_cab = $semana_id ? "AND a.semana_id = ?" : "AND a.year_campamento = ?";
    $stmt = $pdo->prepare("SELECT c.id, c.nombre_cabana, c.capacidad_maxima, c.equipo,
                           c.consejero_principal, c.genero,
                           COUNT(DISTINCT a.id)                                           as total_acampantes,
                           COUNT(DISTINCT sc.id)                                          as total_sesiones,
                           COUNT(DISTINCT CASE WHEN sc.id IS NOT NULL THEN a.id END)      as con_consejeria,
                           COUNT(DISTINCT CASE WHEN a.recibio_cristo_semana=1 THEN a.id END) as recibio_cristo,
                           COUNT(DISTINCT CASE WHEN a.consagro_vida_fogata =1 THEN a.id END) as consagro_vida,
                           COUNT(DISTINCT CASE WHEN a.era_creyente_antes   =1 THEN a.id END) as era_creyente,
                           COUNT(DISTINCT CASE WHEN a.asiste_iglesia       =1 THEN a.id END) as asiste_iglesia
                           FROM cabanas c
                           LEFT JOIN acampantes a ON c.id = a.cabana_id
                               AND a.estado = 'activo' $where_cab
                           LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
                           WHERE c.activa = 1
                           GROUP BY c.id
                           ORDER BY c.equipo, c.genero, c.nombre_cabana");
    $stmt->execute([$semana_id ?: $year]);
    $estadisticasCabanas = $stmt->fetchAll();

    // ── Equipos ────────────────────────────────────────────────
    $equipos_config = obtenerEquipos($pdo);
    $stmt = $pdo->prepare("SELECT c.equipo,
                           COUNT(DISTINCT a.id)  as total_acampantes,
                           SUM(c.capacidad_maxima) as capacidad,
                           COUNT(DISTINCT sc.id) as total_sesiones,
                           COUNT(DISTINCT CASE WHEN a.recibio_cristo_semana=1 THEN a.id END) as recibio_cristo,
                           COUNT(DISTINCT CASE WHEN a.consagro_vida_fogata =1 THEN a.id END) as consagro_vida
                           FROM cabanas c
                           LEFT JOIN acampantes a ON c.id = a.cabana_id
                               AND a.estado = 'activo' $where_cab
                           LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
                           WHERE c.activa = 1 AND c.equipo IS NOT NULL
                           GROUP BY c.equipo
                           ORDER BY c.equipo");
    $stmt->execute([$semana_id ?: $year]);
    $porEquipo = $stmt->fetchAll();

    // ── Ocupación general ──────────────────────────────────────
    $capacidad_total = array_sum(array_column($estadisticasCabanas, 'capacidad_maxima'));

    // ── Por iglesia ────────────────────────────────────────────
    $where_igl = $semana_id ? "a.semana_id = ?" : "a.year_campamento = ?";
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(a.iglesia), ''), 'Sin iglesia registrada') AS iglesia,
            COUNT(DISTINCT a.id)                                               AS total,
            COUNT(DISTINCT CASE WHEN a.sexo='masculino' THEN a.id END)        AS masculino,
            COUNT(DISTINCT CASE WHEN a.sexo='femenino'  THEN a.id END)        AS femenino,
            COUNT(DISTINCT CASE WHEN a.recibio_cristo_semana=1 THEN a.id END) AS recibio_cristo,
            COUNT(DISTINCT CASE WHEN a.consagro_vida_fogata =1 THEN a.id END) AS consagro_vida,
            COUNT(DISTINCT CASE WHEN a.primera_vez_campamento=1 THEN a.id END) AS primera_vez,
            COUNT(DISTINCT sc.id)                                              AS total_sesiones
        FROM acampantes a
        LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
        WHERE $where_igl AND a.estado = 'activo'
        GROUP BY iglesia
        ORDER BY total DESC
    ");
    $stmt->execute([$semana_id ?: $year]);
    $porIglesia = $stmt->fetchAll();

    // ── Lista cabañas para filtro individual ──────────────────
    $stmt = $pdo->query("SELECT id, nombre_cabana FROM cabanas WHERE activa = 1 ORDER BY nombre_cabana");
    $cabanasLista = $stmt->fetchAll();

    // ── Acampantes para reporte individual ────────────────────
    $cabana_id_individual = (int)($_GET['cabana_id_ind'] ?? 0);
    $search_individual    = trim($_GET['search_ind'] ?? '');
    $acampantesIndividual = [];

    if ($tipo_reporte === 'individual') {
        $sql_ind = "
            SELECT a.*, c.nombre_cabana, c.equipo,
                   COUNT(DISTINCT sc.id)  AS total_sesiones,
                   MAX(sc.fecha_sesion)   AS ultima_sesion,
                   ee.observaciones_generales
            FROM acampantes a
            LEFT JOIN cabanas c ON a.cabana_id = c.id
            LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
            LEFT JOIN evaluacion_espiritual ee ON ee.acampante_id = a.id
            WHERE " . ($semana_id ? "a.semana_id = ?" : "a.year_campamento = ?") . "
              AND a.estado = 'activo'
        ";
        $params_ind = [$semana_id ?: $year];

        if ($cabana_id_individual) {
            $sql_ind .= " AND a.cabana_id = ?";
            $params_ind[] = $cabana_id_individual;
        }
        if ($search_individual) {
            $sql_ind .= " AND (a.nombre LIKE ? OR a.iglesia LIKE ?)";
            $params_ind[] = "%$search_individual%";
            $params_ind[] = "%$search_individual%";
        }

        $sql_ind .= " GROUP BY a.id ORDER BY c.nombre_cabana, a.nombre";
        $stmt = $pdo->prepare($sql_ind);
        $stmt->execute($params_ind);
        $acampantesIndividual = $stmt->fetchAll();
    }
    
        // ── Lista de iglesias disponibles para el selector ────────
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            COALESCE(NULLIF(TRIM(iglesia), ''), 'Sin iglesia registrada') AS iglesia
        FROM acampantes
        WHERE " . ($semana_id ? "semana_id = ?" : "year_campamento = ?") . "
          AND estado = 'activo'
        ORDER BY iglesia ASC
    ");
    $stmt->execute([$semana_id ?: $year]);
    $iglesiasLista = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // ── Detalle de acampantes de una iglesia específica ───────
    $iglesia_filtro      = trim($_GET['iglesia_filtro'] ?? '');
    $acampantesPorIglesia = [];

    if ($tipo_reporte === 'iglesia' && $iglesia_filtro) {
        $iglesia_cond = $iglesia_filtro === 'Sin iglesia registrada'
            ? "(a.iglesia IS NULL OR TRIM(a.iglesia) = '')"
            : "TRIM(a.iglesia) = ?";

        $params_igl = [$semana_id ?: $year];
        if ($iglesia_filtro !== 'Sin iglesia registrada') {
            $params_igl[] = $iglesia_filtro;
        }

        $stmt = $pdo->prepare("
            SELECT
                a.id, a.nombre, a.edad, a.sexo, a.iglesia,
                a.recibio_cristo_semana, a.consagro_vida_fogata,
                a.era_creyente_antes, a.primera_vez_campamento,
                c.nombre_cabana,
                COUNT(DISTINCT sc.id)        AS total_sesiones,
                GROUP_CONCAT(
                    DISTINCT COALESCE(tc.tema, sc.tema_personalizado)
                    ORDER BY sc.numero_sesion
                    SEPARATOR ' · '
                )                            AS temas_tratados
            FROM acampantes a
            LEFT JOIN cabanas c             ON a.cabana_id   = c.id
            LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
            LEFT JOIN temas_consejeria tc   ON sc.tema_id    = tc.id
            WHERE " . ($semana_id ? "a.semana_id = ?" : "a.year_campamento = ?") . "
              AND a.estado = 'activo'
              AND $iglesia_cond
            GROUP BY a.id
            ORDER BY a.nombre
        ");
        $stmt->execute($params_igl);
        $acampantesPorIglesia = $stmt->fetchAll();
    }

} catch (Exception $e) {
    $error = "Error al cargar estadísticas: " . $e->getMessage();
}
  
include '../includes/header.php';  
?>  
  
<div class="row mb-4">  
    <div class="col-12">  
        <h1><i class="fas fa-chart-bar"></i> <?php echo $titulo; ?></h1>  
        <nav aria-label="breadcrumb">  
            <ol class="breadcrumb">  
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>  
                <li class="breadcrumb-item active">Reportes</li>  
            </ol>  
        </nav>  
    </div>  
</div>  
  
<?php if (isset($error)): ?>  
    <div class="alert alert-danger">  
        <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>  
    </div>  
<?php endif; ?>  
  
<!-- Filtros y controles -->  
<div class="row mb-4">  
    <div class="col-md-6">  
        <div class="card">  
            <div class="card-header">  
                <h6><i class="fas fa-filter"></i> Filtros de Reporte</h6>  
            </div>  
            <div class="card-body">  
                <form method="GET">
        
                    <!-- Filtro por Semana -->
                    <div class="mb-3">
                        <label class="form-label"><strong>Semana de Campamento</strong></label>
                        <select class="form-select" name="semana_id">
                            <option value="">-- Todas las semanas --</option>
                            <?php foreach ($semanasDisponibles as $sem): ?>
                            <option value="<?php echo $sem['id']; ?>"
                                    <?php echo $semana_id == $sem['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sem['nombre']); ?>
                                (<?php echo date('d/m/Y', strtotime($sem['fecha_inicio'])); ?>)
                                <?php echo $sem['activa'] ? '✓ ACTIVA' : ''; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Por defecto muestra la semana activa</small>
                    </div>
        
                    <div class="mb-3">  
                        <label for="year" class="form-label">Año del Campamento</label>  
                        <select class="form-select" name="year" id="year">  
                            <?php foreach ($yearsDisponibles as $yearItem): ?>  
                            <option value="<?php echo $yearItem['year_campamento']; ?>"  
                                    <?php echo $year == $yearItem['year_campamento'] ? 'selected' : ''; ?>>  
                                Campamento <?php echo $yearItem['year_campamento']; ?>  
                            </option>  
                            <?php endforeach; ?>  
                        </select>  
                    </div>  
                      
                    <div class="mb-3">  
                        <label for="tipo" class="form-label">Tipo de Reporte</label>  
                        <select class="form-select" name="tipo" id="tipo">  
                            <option value="general" <?php echo $tipo_reporte == 'general' ? 'selected' : ''; ?>>Reporte General</option>  
                            <option value="cabana" <?php echo $tipo_reporte == 'cabana' ? 'selected' : ''; ?>>Por Cabaña</option>  
                            <option value="iglesia"    <?php echo $tipo_reporte == 'iglesia'    ? 'selected' : ''; ?>>Por Iglesia</option>
                            <option value="individual" <?php echo $tipo_reporte == 'individual' ? 'selected' : ''; ?>>Individual</option>  
                        </select>  
                    </div>  
                      
                    <button type="submit" class="btn btn-primary w-100">  
                        <i class="fas fa-sync"></i> Actualizar Vista  
                    </button>  
                </form>  
            </div>  
        </div>  
    </div>  
      
    <div class="col-md-6">  
        <div class="card">  
            <div class="card-header">  
                <h6><i class="fas fa-download"></i> Generar PDFs</h6>  
            </div>  
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="imprimir_reporte.php?tipo=general&year=<?= $year ?>&semana_id=<?= $semana_id ?>"
                       class="btn btn-success" target="_blank">
                        <i class="fas fa-print"></i> Reporte General
                    </a>
                    <a href="imprimir_reporte.php?tipo=cabanas&year=<?= $year ?>&semana_id=<?= $semana_id ?>"
                       class="btn btn-info" target="_blank">
                        <i class="fas fa-print"></i> Reporte por Cabañas
                    </a>
                    <a href="imprimir_reporte.php?tipo=iglesia&year=<?= $year ?>&semana_id=<?= $semana_id ?>"
                       class="btn btn-primary" target="_blank">
                        <i class="fas fa-print"></i> Reporte por Iglesia
                    </a>
                    <a href="imprimir_reporte.php?tipo=individual&year=<?= $year ?>&semana_id=<?= $semana_id ?>"
                       class="btn btn-warning" target="_blank">
                        <i class="fas fa-print"></i> Reporte Individual
                    </a>
                    <a href="imprimir_reporte.php?tipo=completo&year=<?= $year ?>&semana_id=<?= $semana_id ?>"
                       class="btn btn-secondary" target="_blank">
                        <i class="fas fa-print"></i> Reporte Completo
                    </a>
                </div>
            </div> 
        </div>  
    </div>  
</div>  
  
<?php
// Cálculos reutilizables
$sin_consejeria   = $totalAcampantes - ($detalle_cons['con_consejeria'] ?? 0);
$pct_consejeria   = $totalAcampantes > 0
    ? round(($detalle_cons['con_consejeria'] / $totalAcampantes) * 100, 1) : 0;
$pct_ocupacion    = $capacidad_total > 0
    ? round(($totalAcampantes / $capacidad_total) * 100, 1) : 0;
$total_m = $porGenero['masculino'] ?? 0;
$total_f = $porGenero['femenino']  ?? 0;
$pct_m   = $totalAcampantes > 0 ? round(($total_m / $totalAcampantes) * 100) : 0;
$pct_f   = $totalAcampantes > 0 ? round(($total_f / $totalAcampantes) * 100) : 0;
?>

<!-- ══ TARJETAS RESUMEN ══ -->
<div class="row g-3 mb-4">

    <div class="col-6 col-md-2">
        <div class="card text-center border-primary h-100">
            <div class="card-body py-3">
                <i class="fas fa-users fa-2x text-primary mb-1"></i>
                <h3 class="mb-0 text-primary"><?php echo $totalAcampantes; ?></h3>
                <small class="text-muted">Acampantes</small>
                <div class="mt-1">
                    <small class="text-muted"><?php echo $pct_ocupacion; ?>% ocupación</small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-2">
        <div class="card text-center border-info h-100">
            <div class="card-body py-3">
                <i class="fas fa-home fa-2x text-info mb-1"></i>
                <h3 class="mb-0 text-info"><?php echo $totalCabanas; ?></h3>
                <small class="text-muted">Cabañas</small>
                <div class="mt-1">
                    <small class="text-muted">Cap. <?php echo $capacidad_total; ?></small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-2">
        <?php $colorCons = $pct_consejeria >= 80 ? 'success' : ($pct_consejeria >= 50 ? 'warning' : 'danger'); ?>
        <div class="card text-center border-<?php echo $colorCons; ?> h-100">
            <div class="card-body py-3">
                <i class="fas fa-comments fa-2x text-<?php echo $colorCons; ?> mb-1"></i>
                <h3 class="mb-0"><?php echo $detalle_cons['con_consejeria'] ?? 0; ?></h3>
                <small class="text-muted">Con Consejería</small>
                <div class="mt-1">
                    <small class="text-<?php echo $colorCons; ?> fw-bold"><?php echo $pct_consejeria; ?>%</small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-2">
        <div class="card text-center border-danger h-100">
            <div class="card-body py-3">
                <i class="fas fa-user-clock fa-2x text-danger mb-1"></i>
                <h3 class="mb-0 text-danger"><?php echo $sin_consejeria; ?></h3>
                <small class="text-muted">Sin Consejería</small>
                <div class="mt-1">
                    <small class="text-muted">pendientes</small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-2">
        <div class="card text-center border-success h-100">
            <div class="card-body py-3">
                <i class="fas fa-cross fa-2x text-success mb-1"></i>
                <h3 class="mb-0 text-success"><?php echo $impacto['recibio_cristo'] ?? 0; ?></h3>
                <small class="text-muted">Recibieron Cristo</small>
                <div class="mt-1">
                    <small class="text-muted">
                        <?php echo $totalAcampantes > 0
                            ? round(($impacto['recibio_cristo'] / $totalAcampantes) * 100, 1) : 0; ?>%
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-2">
        <div class="card text-center border-warning h-100">
            <div class="card-body py-3">
                <i class="fas fa-fire fa-2x text-warning mb-1"></i>
                <h3 class="mb-0 text-warning"><?php echo $impacto['consagro_vida'] ?? 0; ?></h3>
                <small class="text-muted">Consagraciones</small>
                <div class="mt-1">
                    <small class="text-muted">
                        <?php echo $totalAcampantes > 0
                            ? round(($impacto['consagro_vida'] / $totalAcampantes) * 100, 1) : 0; ?>%
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ FILA 2: Género + Impacto + Equipos ══ -->
<div class="row g-3 mb-4">

    <!-- Género -->
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="fas fa-venus-mars"></i> Por Género</h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-around text-center mb-3">
                    <div>
                        <i class="fas fa-mars fa-2x text-primary"></i>
                        <h4 class="mb-0 text-primary"><?php echo $total_m; ?></h4>
                        <small class="text-muted">Masculino</small>
                        <div class="badge bg-primary mt-1"><?php echo $pct_m; ?>%</div>
                    </div>
                    <div class="vr"></div>
                    <div>
                        <i class="fas fa-venus fa-2x text-danger"></i>
                        <h4 class="mb-0 text-danger"><?php echo $total_f; ?></h4>
                        <small class="text-muted">Femenino</small>
                        <div class="badge bg-danger mt-1"><?php echo $pct_f; ?>%</div>
                    </div>
                </div>
                <div class="progress" style="height:18px; border-radius:8px;">
                    <div class="progress-bar bg-primary"
                         style="width:<?php echo $pct_m; ?>%"
                         title="Masculino <?php echo $total_m; ?>">
                        <?php echo $pct_m > 10 ? $pct_m.'%' : ''; ?>
                    </div>
                    <div class="progress-bar bg-danger"
                         style="width:<?php echo $pct_f; ?>%"
                         title="Femenino <?php echo $total_f; ?>">
                        <?php echo $pct_f > 10 ? $pct_f.'%' : ''; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Impacto Espiritual -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="fas fa-cross"></i> Impacto Espiritual</h6>
            </div>
            <div class="card-body">
                <?php
                $base   = max($totalAcampantes, 1);
                $items_imp = [
                    ['✝️', 'Recibieron a Cristo',   $impacto['recibio_cristo'] ?? 0, '#198754'],
                    ['🙏', 'Consagraron su vida',   $impacto['consagro_vida']  ?? 0, '#ffc107'],
                    ['📖', 'Eran creyentes antes',  $impacto['era_creyente']   ?? 0, '#0d6efd'],
                    ['⛪', 'Asisten a iglesia',     $impacto['asiste_iglesia'] ?? 0, '#0dcaf0'],
                ];
                foreach ($items_imp as [$emoji, $label, $num, $hex]):
                    $pct = round(($num / $base) * 100, 1);
                ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small><?php echo $emoji; ?> <?php echo $label; ?></small>
                        <span class="fw-bold"><?php echo $num; ?>
                            <small class="text-muted">(<?php echo $pct; ?>%)</small>
                        </span>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar"
                             style="width:<?php echo $pct; ?>%; background-color:<?php echo $hex; ?>;">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Consejerías resumen -->
    <div class="col-md-2">
        <div class="card h-100">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="fas fa-comments"></i> Consejerías</h6>
            </div>
            <div class="card-body text-center">
                <div class="mb-3">
                    <h2 class="text-success mb-0"><?php echo $detalle_cons['total_sesiones'] ?? 0; ?></h2>
                    <small class="text-muted">Sesiones totales</small>
                </div>
                <div class="mb-3">
                    <h4 class="mb-0"><?php echo $detalle_cons['prom_sesiones'] ?? 0; ?></h4>
                    <small class="text-muted">Prom. por acampante</small>
                </div>
                <div class="progress mb-1" style="height:12px;">
                    <div class="progress-bar bg-<?php echo $colorCons; ?>"
                         style="width:<?php echo $pct_consejeria; ?>%;">
                    </div>
                </div>
                <small class="text-muted">
                    <?php echo $detalle_cons['con_consejeria'] ?? 0; ?>/<?php echo $totalAcampantes; ?> atendidos
                </small>
            </div>
        </div>
    </div>

    <!-- Equipos -->
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="fas fa-shield-alt"></i> Por Equipo</h6>
            </div>
            <div class="card-body">
                <?php if (empty($porEquipo)): ?>
                <p class="text-muted text-center small">Sin datos de equipos</p>
                <?php else: ?>
                <?php foreach ($porEquipo as $eq):
                    $eqData  = $equipos_config[$eq['equipo']] ?? null;
                    $hexEq   = $eqData['color_hex'] ?? '#6c757d';
                    $emojiEq = $eqData['emoji']     ?? '⚪';
                    $labelEq = $eqData['nombre']    ?? ucfirst($eq['equipo']);
                    $pctEq   = $eq['capacidad'] > 0
                        ? round(($eq['total_acampantes'] / $eq['capacidad']) * 100, 1) : 0;
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="badge" style="background-color:<?php echo $hexEq; ?>;">
                            <?php echo $emojiEq; ?> <?php echo htmlspecialchars($labelEq); ?>
                        </span>
                        <small class="fw-bold"><?php echo $eq['total_acampantes']; ?>
                            <span class="text-muted">/ <?php echo $eq['capacidad']; ?></span>
                        </small>
                    </div>
                    <div class="progress mb-1" style="height:8px;">
                        <div class="progress-bar"
                             style="width:<?php echo $pctEq; ?>%; background-color:<?php echo $hexEq; ?>;">
                        </div>
                    </div>
                    <div class="d-flex justify-content-between">
                        <small class="text-muted">
                            ✝️ <?php echo $eq['recibio_cristo']; ?>
                            &nbsp; 🙏 <?php echo $eq['consagro_vida']; ?>
                        </small>
                        <small class="text-muted"><?php echo $pctEq; ?>%</small>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══ FILA 3: Tabla cabañas + Top temas ══ -->
<div class="row g-3 mb-4">

    <!-- Tabla cabañas detallada -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <h6 class="mb-0">
                    <i class="fas fa-home"></i> Estadísticas por Cabaña
                </h6>
                <small class="text-muted">
                    <?php if ($semana_seleccionada): ?>
                    <span class="badge bg-<?php echo $semana_seleccionada['activa'] ? 'success' : 'secondary'; ?>">
                        <i class="fas fa-calendar-week"></i>
                        <?php echo htmlspecialchars($semana_seleccionada['nombre']); ?>
                    </span>
                    <?php else: ?>
                    Campamento <?php echo $year; ?>
                    <?php endif; ?>
                </small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Cabaña</th>
                                <th class="text-center">Acamp.</th>
                                <th style="min-width:90px;">Ocupación</th>
                                <th class="text-center">Sesiones</th>
                                <th style="min-width:80px;">% Cons.</th>
                                <th class="text-center">✝️</th>
                                <th class="text-center">🙏</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estadisticasCabanas as $cab):
                                $eqData  = $equipos_config[$cab['equipo'] ?? ''] ?? null;
                                $hexEq   = $eqData['color_hex'] ?? null;
                                $emojiEq = $eqData['emoji']     ?? null;
                                $pctOcup = $cab['capacidad_maxima'] > 0
                                    ? round(($cab['total_acampantes'] / $cab['capacidad_maxima']) * 100) : 0;
                                $pctCons = $cab['total_acampantes'] > 0
                                    ? round(($cab['con_consejeria'] / $cab['total_acampantes']) * 100) : 0;
                                $barOcup = $pctOcup >= 90 ? '#dc3545' : ($pctOcup >= 70 ? '#ffc107' : '#198754');
                                $barCons = $pctCons >= 80 ? '#198754' : ($pctCons >= 50 ? '#ffc107' : '#dc3545');
                                $llena   = $cab['total_acampantes'] >= $cab['capacidad_maxima'];
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-1">
                                        <?php if ($hexEq): ?>
                                        <span style="font-size:10px;"><?php echo $emojiEq; ?></span>
                                        <?php endif; ?>
                                        <div>
                                            <strong class="small">
                                                <?php echo htmlspecialchars($cab['nombre_cabana']); ?>
                                            </strong>
                                            <?php if ($llena): ?>
                                            <span class="badge bg-danger ms-1" style="font-size:9px;">Llena</span>
                                            <?php endif; ?>
                                            <div class="text-muted" style="font-size:10px;">
                                                <i class="fas fa-<?php echo $cab['genero']==='masculino' ? 'mars text-primary' : 'venus text-danger'; ?>"></i>
                                                <?php echo htmlspecialchars($cab['consejero_principal'] ?: 'Sin consejero'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="fw-bold"><?php echo $cab['total_acampantes']; ?></span>
                                    <small class="text-muted">/<?php echo $cab['capacidad_maxima']; ?></small>
                                </td>
                                <td>
                                    <div class="progress" style="height:10px;">
                                        <div class="progress-bar"
                                             style="width:<?php echo $pctOcup; ?>%;
                                                    background-color:<?php echo $barOcup; ?>;">
                                        </div>
                                    </div>
                                    <small class="text-muted"><?php echo $pctOcup; ?>%</small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success"><?php echo $cab['total_sesiones']; ?></span>
                                </td>
                                <td>
                                    <div class="progress" style="height:10px;">
                                        <div class="progress-bar"
                                             style="width:<?php echo $pctCons; ?>%;
                                                    background-color:<?php echo $barCons; ?>;">
                                        </div>
                                    </div>
                                    <small class="text-muted"><?php echo $pctCons; ?>%</small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success" style="font-size:11px;">
                                        <?php echo $cab['recibio_cristo']; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-warning text-dark" style="font-size:11px;">
                                        <?php echo $cab['consagro_vida']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="imprimir_reporte.php?tipo=cabana&cabana_id=<?php echo $cab['id']; ?>&year=<?php echo $year; ?>&semana_id=<?php echo $semana_id; ?>"
                                           class="btn btn-sm btn-outline-primary" target="_blank" title="Imprimir">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        <a href="acampantes.php?cabana=<?php echo $cab['id']; ?>&semana_id=<?php echo $semana_id; ?>"
                                           class="btn btn-sm btn-outline-info" title="Ver acampantes">
                                            <i class="fas fa-users"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <!-- Totales -->
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td>TOTAL</td>
                                <td class="text-center">
                                    <?php echo $totalAcampantes; ?>
                                    <small class="text-muted fw-normal">/<?php echo $capacidad_total; ?></small>
                                </td>
                                <td><small class="text-muted"><?php echo $pct_ocupacion; ?>%</small></td>
                                <td class="text-center">
                                    <?php echo $detalle_cons['total_sesiones'] ?? 0; ?>
                                </td>
                                <td><small class="text-muted"><?php echo $pct_consejeria; ?>%</small></td>
                                <td class="text-center"><?php echo $impacto['recibio_cristo'] ?? 0; ?></td>
                                <td class="text-center"><?php echo $impacto['consagro_vida'] ?? 0; ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Top temas -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header py-2">
                <h6 class="mb-0">
                    <i class="fas fa-list-ol"></i> Top Temas de Consejería
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($topTemas)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-comments fa-2x opacity-25 d-block mb-2"></i>
                    Sin datos de temas aún
                </div>
                <?php else:
                    $max_tema = $topTemas[0]['veces_tratado'];
                ?>
                <?php foreach ($topTemas as $i => $tema):
                    $pctTema = $max_tema > 0 ? round(($tema['veces_tratado'] / $max_tema) * 100) : 0;
                    $medalColor = $i === 0 ? '#ffd700' : ($i === 1 ? '#c0c0c0' : ($i === 2 ? '#cd7f32' : '#6c757d'));
                ?>
                <div class="mb-2">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge rounded-circle"
                                  style="background-color:<?php echo $medalColor; ?>;
                                         width:22px; height:22px; font-size:10px;
                                         display:flex; align-items:center; justify-content:center;">
                                <?php echo $i + 1; ?>
                            </span>
                            <div>
                                <div class="small fw-bold lh-1">
                                    <?php echo htmlspecialchars($tema['tema']); ?>
                                </div>
                                <div class="text-muted" style="font-size:10px;">
                                    <?php echo htmlspecialchars($tema['categoria']); ?>
                                </div>
                            </div>
                        </div>
                        <span class="badge bg-secondary"><?php echo $tema['veces_tratado']; ?></span>
                    </div>
                    <div class="progress" style="height:5px;">
                        <div class="progress-bar bg-primary" style="width:<?php echo $pctTema; ?>%;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══ REPORTE POR IGLESIA ══ -->
<?php if ($tipo_reporte === 'iglesia'): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center py-2 flex-wrap gap-2">
        <h6 class="mb-0">
            <i class="fas fa-church"></i> Distribución por Iglesia
            <span class="badge bg-secondary ms-2"><?= count($porIglesia) ?> iglesias</span>
        </h6>
        <small class="text-muted">
            <?= $semana_seleccionada
                ? htmlspecialchars($semana_seleccionada['nombre'])
                : "Campamento $year" ?>
        </small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Iglesia</th>
                        <th class="text-center">Total</th>
                        <th class="text-center"><i class="fas fa-mars text-primary"></i></th>
                        <th class="text-center"><i class="fas fa-venus text-danger"></i></th>
                        <th class="text-center">1ra vez</th>
                        <th class="text-center">✝️</th>
                        <th class="text-center">🙏</th>
                        <th class="text-center">Sesiones</th>
                        <th>Participación</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $max_igl = $porIglesia[0]['total'] ?? 1;
                foreach ($porIglesia as $i => $igl):
                    $pct_igl = $totalAcampantes > 0
                        ? round(($igl['total'] / $totalAcampantes) * 100, 1) : 0;
                    $pct_bar = $max_igl > 0
                        ? round(($igl['total'] / $max_igl) * 100) : 0;
                    $es_activa = $iglesia_filtro === $igl['iglesia'];
                ?>
                <tr class="<?= $es_activa ? 'table-primary' : '' ?>">
                    <td class="text-muted small"><?= $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($igl['iglesia']) ?></strong></td>
                    <td class="text-center fw-bold"><?= $igl['total'] ?></td>
                    <td class="text-center text-primary"><?= $igl['masculino'] ?></td>
                    <td class="text-center text-danger"><?= $igl['femenino'] ?></td>
                    <td class="text-center">
                        <?= $igl['primera_vez'] > 0
                            ? "<span class='badge bg-info'>{$igl['primera_vez']}</span>"
                            : '<span class="text-muted">0</span>' ?>
                    </td>
                    <td class="text-center">
                        <?= $igl['recibio_cristo'] > 0
                            ? "<span class='badge bg-success'>{$igl['recibio_cristo']}</span>"
                            : '<span class="text-muted">0</span>' ?>
                    </td>
                    <td class="text-center">
                        <?= $igl['consagro_vida'] > 0
                            ? "<span class='badge bg-warning text-dark'>{$igl['consagro_vida']}</span>"
                            : '<span class="text-muted">0</span>' ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-secondary"><?= $igl['total_sesiones'] ?></span>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <div class="progress flex-grow-1" style="height:8px;">
                                <div class="progress-bar bg-primary"
                                     style="width:<?= $pct_bar ?>%;"></div>
                            </div>
                            <small class="text-muted"><?= $pct_igl ?>%</small>
                        </div>
                    </td>
                    <td>
                        <!-- Ver detalle de esta iglesia -->
                        <a href="?tipo=iglesia&semana_id=<?= $semana_id ?>&year=<?= $year ?>&iglesia_filtro=<?= urlencode($igl['iglesia']) ?>#detalle-iglesia"
                           class="btn btn-sm <?= $es_activa ? 'btn-primary' : 'btn-outline-primary' ?>"
                           title="Ver detalle">
                            <i class="fas fa-users"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="2">TOTAL</td>
                        <td class="text-center"><?= $totalAcampantes ?></td>
                        <td class="text-center text-primary"><?= $total_m ?></td>
                        <td class="text-center text-danger"><?= $total_f ?></td>
                        <td class="text-center">
                            <?= array_sum(array_column($porIglesia, 'primera_vez')) ?>
                        </td>
                        <td class="text-center"><?= $impacto['recibio_cristo'] ?? 0 ?></td>
                        <td class="text-center"><?= $impacto['consagro_vida'] ?? 0 ?></td>
                        <td class="text-center"><?= $detalle_cons['total_sesiones'] ?? 0 ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Detalle de iglesia seleccionada -->
<?php if ($iglesia_filtro && !empty($acampantesPorIglesia)): ?>
<div id="detalle-iglesia"></div>
<div class="card mb-4 border-primary">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0">
            <i class="fas fa-church"></i>
            Detalle: <?= htmlspecialchars($iglesia_filtro) ?>
            <span class="badge bg-light text-primary ms-2"><?= count($acampantesPorIglesia) ?> acampantes</span>
        </h6>
        <div class="d-flex gap-2">
            <a href="imprimir_reporte.php?tipo=iglesia_detalle&semana_id=<?= $semana_id ?>&year=<?= $year ?>&iglesia_filtro=<?= urlencode($iglesia_filtro) ?>"
               class="btn btn-sm btn-light" target="_blank">
                <i class="fas fa-print"></i> Imprimir
            </a>
            <a href="?tipo=iglesia&semana_id=<?= $semana_id ?>&year=<?= $year ?>"
               class="btn btn-sm btn-outline-light">
                <i class="fas fa-times"></i> Cerrar
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Nombre</th>
                        <th class="text-center">Edad</th>
                        <th class="text-center">Sexo</th>
                        <th>Cabaña</th>
                        <th>Temas tratados</th>
                        <th class="text-center">¿Creyó/ya creía?</th>
                        <th class="text-center">Consagración</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($acampantesPorIglesia as $ac): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($ac['nombre']) ?></strong>
                        <?php if ($ac['primera_vez_campamento']): ?>
                            <span class="badge bg-info ms-1" style="font-size:9px;">1ra vez</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= $ac['edad'] ?? '-' ?></td>
                    <td class="text-center">
                        <?php if ($ac['sexo'] === 'masculino'): ?>
                            <i class="fas fa-mars text-primary"></i>
                        <?php else: ?>
                            <i class="fas fa-venus text-danger"></i>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($ac['nombre_cabana']): ?>
                            <span class="badge bg-secondary">
                                <?= htmlspecialchars($ac['nombre_cabana']) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">Sin asignar</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($ac['temas_tratados']): ?>
                            <small class="text-muted">
                                <?= htmlspecialchars($ac['temas_tratados']) ?>
                            </small>
                        <?php else: ?>
                            <span class="text-muted small">
                                <i class="fas fa-minus"></i> Sin sesiones
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($ac['recibio_cristo_semana']): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-cross"></i> Creyó esta semana
                            </span>
                        <?php elseif ($ac['era_creyente_antes']): ?>
                            <span class="badge bg-primary">
                                <i class="fas fa-check"></i> Ya creía
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">No registrado</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($ac['consagro_vida_fogata']): ?>
                            <span class="badge bg-warning text-dark">
                                <i class="fas fa-fire"></i> Sí
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">No</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif ($iglesia_filtro && empty($acampantesPorIglesia)): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    No se encontraron acampantes para <strong><?= htmlspecialchars($iglesia_filtro) ?></strong>.
</div>
<?php endif; ?>

<!-- ══ REPORTE INDIVIDUAL ══ -->
<?php elseif ($tipo_reporte === 'individual'): ?>
<div class="card mb-4">
    <div class="card-header py-2">
        <h6 class="mb-0">
            <i class="fas fa-user"></i> Reporte Individual de Acampantes
        </h6>
    </div>
    <div class="card-body border-bottom">
        <form method="GET" class="row g-2">
            <input type="hidden" name="tipo"      value="individual">
            <input type="hidden" name="semana_id" value="<?= $semana_id ?>">
            <input type="hidden" name="year"      value="<?= $year ?>">
            <div class="col-12 col-md-4">
                <input type="text" class="form-control" name="search_ind"
                       placeholder="Buscar por nombre o iglesia..."
                       value="<?= htmlspecialchars($search_individual) ?>">
            </div>
            <div class="col-12 col-md-3">
                <select name="cabana_id_ind" class="form-select">
                    <option value="">Todas las cabañas</option>
                    <?php foreach ($cabanasLista as $cab): ?>
                    <option value="<?= $cab['id'] ?>"
                            <?= $cabana_id_individual == $cab['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cab['nombre_cabana']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Buscar
                </button>
            </div>
            <div class="col-6 col-md-2">
                <a href="reportes.php?tipo=individual&semana_id=<?= $semana_id ?>"
                   class="btn btn-secondary w-100">
                    <i class="fas fa-times"></i> Limpiar
                </a>
            </div>
        </form>
    </div>
    <?php if (empty($acampantesIndividual)): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="fas fa-user fa-3x opacity-25 d-block mb-2"></i>
        Usa los filtros para buscar acampantes.
    </div>
    <?php else: ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Acampante</th>
                        <th>Edad</th>
                        <th>Iglesia</th>
                        <th>Cabaña</th>
                        <th class="text-center">Sesiones</th>
                        <th class="text-center">✝️</th>
                        <th class="text-center">🙏</th>
                        <th class="text-center">1ra vez</th>
                        <th>Última sesión</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($acampantesIndividual as $ac):
                    $eqData   = $equipos_config[$ac['equipo'] ?? ''] ?? null;
                    $hexEq    = $eqData['color_hex'] ?? '#6c757d';
                    $sesColor = $ac['total_sesiones'] >= 3 ? 'success'
                        : ($ac['total_sesiones'] >= 1 ? 'warning' : 'danger');
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($ac['nombre']) ?></div>
                        <small class="text-muted">
                            <i class="fas fa-<?= $ac['sexo'] === 'masculino'
                                ? 'mars text-primary' : 'venus text-danger' ?>"></i>
                            <?= ucfirst($ac['sexo'] ?? '') ?>
                        </small>
                    </td>
                    <td><?= $ac['edad'] ?? '-' ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($ac['iglesia'] ?? '-') ?></td>
                    <td>
                        <?php if ($ac['nombre_cabana']): ?>
                            <span class="badge text-white"
                                  style="background-color:<?= $hexEq ?>; font-size:11px;">
                                <?= htmlspecialchars($ac['nombre_cabana']) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">Sin asignar</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $sesColor ?>"><?= $ac['total_sesiones'] ?>/3</span>
                    </td>
                    <td class="text-center">
                        <?= $ac['recibio_cristo_semana']
                            ? '<i class="fas fa-check text-success"></i>'
                            : '<span class="text-muted">-</span>' ?>
                    </td>
                    <td class="text-center">
                        <?= $ac['consagro_vida_fogata']
                            ? '<i class="fas fa-check text-warning"></i>'
                            : '<span class="text-muted">-</span>' ?>
                    </td>
                    <td class="text-center">
                        <?= $ac['primera_vez_campamento']
                            ? '<span class="badge bg-info">Sí</span>'
                            : '<span class="text-muted small">No</span>' ?>
                    </td>
                    <td class="small text-muted">
                        <?= $ac['ultima_sesion']
                            ? date('d/m/Y', strtotime($ac['ultima_sesion']))
                            : '<span class="text-danger small">Sin sesiones</span>' ?>
                    </td>
                    <td>
                        <a href="ver_acampante.php?id=<?= $ac['id'] ?>&semana_id=<?= $semana_id ?>"
                           class="btn btn-sm btn-outline-info">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="p-2 text-muted small border-top">
            <?= count($acampantesIndividual) ?> acampante(s) encontrado(s)
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>