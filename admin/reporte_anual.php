<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$anio  = (int)($_GET['anio'] ?? date('Y'));
$titulo = "Reporte Anual $anio";

try {
    // ── 1. Resumen por mes ──────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT 
            MONTH(s.fecha_inicio) as mes,
            COUNT(DISTINCT s.id)  as semanas,
            COUNT(a.id)           as acampantes,
            SUM(CASE WHEN a.sexo='masculino' THEN 1 ELSE 0 END) as masculinos,
            SUM(CASE WHEN a.sexo='femenino'  THEN 1 ELSE 0 END) as femeninos,
            SUM(a.recibio_cristo_semana) as recibio_cristo,
            SUM(a.consagro_vida_fogata)  as consagro_vida
        FROM semanas_campamento s
        LEFT JOIN acampantes a 
            ON a.semana_id = s.id AND a.estado = 'activo'
        WHERE YEAR(s.fecha_inicio) = ?
        GROUP BY MONTH(s.fecha_inicio)
        ORDER BY mes
    ");
    $stmt->execute([$anio]);
    $porMes = $stmt->fetchAll();

    // ── 2. Totales del año ──────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT s.id)         as total_semanas,
            COUNT(a.id)                  as total_acampantes,
            SUM(a.recibio_cristo_semana) as total_cristo,
            SUM(a.consagro_vida_fogata)  as total_fogata,
            SUM(a.era_creyente_antes)    as total_creyentes,
            SUM(a.asiste_iglesia)        as total_iglesia,
            COUNT(DISTINCT sc.id)        as total_consejerias
        FROM semanas_campamento s
        LEFT JOIN acampantes a 
            ON a.semana_id = s.id AND a.estado = 'activo'
        LEFT JOIN sesiones_consejeria sc 
            ON sc.acampante_id = a.id
        WHERE YEAR(s.fecha_inicio) = ?
    ");
    $stmt->execute([$anio]);
    $totales = $stmt->fetch();

    // ── 3. Top iglesias del año ─────────────────────────────
    $stmt = $pdo->prepare("
        SELECT a.iglesia, COUNT(*) as total
        FROM acampantes a
        JOIN semanas_campamento s ON a.semana_id = s.id
        WHERE YEAR(s.fecha_inicio) = ? AND a.estado = 'activo'
        GROUP BY a.iglesia
        ORDER BY total DESC LIMIT 10
    ");
    $stmt->execute([$anio]);
    $topIglesias = $stmt->fetchAll();

    // ── 4. Por semana (detalle) ─────────────────────────────
    $stmt = $pdo->prepare("
        SELECT s.nombre, s.tipo_acampante,
               s.fecha_inicio, s.fecha_fin,
               COUNT(a.id) as acampantes,
               SUM(a.recibio_cristo_semana) as cristo
        FROM semanas_campamento s
        LEFT JOIN acampantes a 
            ON a.semana_id = s.id AND a.estado = 'activo'
        WHERE YEAR(s.fecha_inicio) = ?
        GROUP BY s.id
        ORDER BY s.fecha_inicio
    ");
    $stmt->execute([$anio]);
    $porSemana = $stmt->fetchAll();

    // ── 5. Años disponibles para el selector ────────────────
    $stmt = $pdo->query("SELECT DISTINCT YEAR(fecha_inicio) as anio 
                         FROM semanas_campamento 
                         ORDER BY anio DESC");
    $aniosDisponibles = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (Exception $e) {
    die('<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>');
}

$meses = ['', 'Enero','Febrero','Marzo','Abril','Mayo','Junio',
          'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-chart-line"></i> <?php echo $titulo; ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Reporte Anual</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-secondary">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
</div>

<!-- Selector de año -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label fw-bold">Año</label>
                <select name="anio" class="form-select">
                    <?php foreach ($aniosDisponibles as $a): ?>
                    <option value="<?php echo $a; ?>" <?php echo $anio == $a ? 'selected' : ''; ?>>
                        <?php echo $a; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Ver Año
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Totales del año -->
<div class="row mb-4">
    <div class="col-6 col-md-3 mb-3">
        <div class="card border-primary text-center h-100">
            <div class="card-body">
                <i class="fas fa-users fa-2x text-primary mb-2"></i>
                <h2 class="text-primary"><?php echo $totales['total_acampantes']; ?></h2>
                <p class="mb-0 small">Acampantes Total</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="card border-success text-center h-100">
            <div class="card-body">
                <i class="fas fa-calendar-week fa-2x text-success mb-2"></i>
                <h2 class="text-success"><?php echo $totales['total_semanas']; ?></h2>
                <p class="mb-0 small">Semanas</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="card border-warning text-center h-100">
            <div class="card-body">
                <i class="fas fa-cross fa-2x text-warning mb-2"></i>
                <h2 class="text-warning"><?php echo $totales['total_cristo']; ?></h2>
                <p class="mb-0 small">Recibieron a Cristo</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="card border-info text-center h-100">
            <div class="card-body">
                <i class="fas fa-comments fa-2x text-info mb-2"></i>
                <h2 class="text-info"><?php echo $totales['total_consejerias']; ?></h2>
                <p class="mb-0 small">Sesiones Consejería</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Resumen por mes -->
    <div class="col-md-7 mb-4">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-calendar"></i> Resumen por Mes</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Mes</th>
                            <th>Semanas</th>
                            <th>♂</th><th>♀</th>
                            <th>Total</th>
                            <th>✝ Cristo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($porMes)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Sin datos para <?php echo $anio; ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($porMes as $row): ?>
                    <tr>
                        <td><strong><?php echo $meses[(int)$row['mes']]; ?></strong></td>
                        <td><?php echo $row['semanas']; ?></td>
                        <td class="text-primary"><?php echo $row['masculinos']; ?></td>
                        <td class="text-danger"><?php echo $row['femeninos']; ?></td>
                        <td><strong><?php echo $row['acampantes']; ?></strong></td>
                        <td>
                            <span class="badge bg-success"><?php echo $row['recibio_cristo']; ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <!-- Total -->
                    <tr class="table-dark fw-bold">
                        <td>TOTAL</td>
                        <td><?php echo $totales['total_semanas']; ?></td>
                        <td colspan="2"><?php echo $totales['total_acampantes']; ?></td>
                        <td><?php echo $totales['total_acampantes']; ?></td>
                        <td><?php echo $totales['total_cristo']; ?></td>
                    </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Top iglesias -->
    <div class="col-md-5 mb-4">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-church"></i> Top 10 Iglesias</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr><th>#</th><th>Iglesia</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($topIglesias as $i => $ig): ?>
                    <tr>
                        <td><span class="badge bg-secondary"><?php echo $i+1; ?></span></td>
                        <td><?php echo htmlspecialchars($ig['iglesia']); ?></td>
                        <td><strong><?php echo $ig['total']; ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Detalle por semana -->
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-list"></i> Detalle por Semana</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Semana</th>
                            <th>Tipo</th>
                            <th>Fechas</th>
                            <th>Acampantes</th>
                            <th>Recibieron a Cristo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($porSemana as $s): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($s['nombre']); ?></strong></td>
                        <td><span class="badge bg-secondary"><?php echo $s['tipo_acampante']; ?></span></td>
                        <td class="small text-muted">
                            <?php echo formatearFecha($s['fecha_inicio']); ?> —
                            <?php echo formatearFecha($s['fecha_fin']); ?>
                        </td>
                        <td><strong><?php echo $s['acampantes']; ?></strong></td>
                        <td>
                            <span class="badge bg-success"><?php echo $s['cristo']; ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php include '../includes/footer.php'; ?>