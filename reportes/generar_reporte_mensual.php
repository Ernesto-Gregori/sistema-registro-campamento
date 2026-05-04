<?php
/**
 * Reporte Mensual Automático
 * Puede ejecutarse manualmente o via CRON job
 * CRON: 0 8 1 * * php /ruta/reportes/generar_reporte_mensual.php
 */

require_once '../config/database.php';
require_once '../includes/functions.php';

// Permitir ejecución desde CLI o desde admin
$esCLI = php_sapi_name() === 'cli';

if (!$esCLI) {
    verificarLogin();
    if (!esAdministrador()) {
        http_response_code(403);
        exit('Sin acceso');
    }
}

// Período: mes anterior por defecto
$mes  = $_GET['mes']  ?? date('m', strtotime('first day of last month'));
$anio = $_GET['anio'] ?? date('Y', strtotime('first day of last month'));
$mesNombre = strftime('%B', mktime(0, 0, 0, $mes, 1, $anio)) 
             ?? date('F', mktime(0, 0, 0, $mes, 1, $anio));

$inicio = "$anio-$mes-01";
$fin    = date('Y-m-t', strtotime($inicio));

try {
    // ── 1. Semanas del período ─────────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM semanas_campamento
                           WHERE fecha_inicio BETWEEN ? AND ?
                           ORDER BY fecha_inicio");
    $stmt->execute([$inicio, $fin]);
    $semanas = $stmt->fetchAll();

    // ── 2. Total acampantes por semana ─────────────────────
    $stmt = $pdo->prepare("SELECT s.nombre as semana, s.tipo_acampante,
                           COUNT(a.id) as total,
                           SUM(CASE WHEN a.sexo='masculino' THEN 1 ELSE 0 END) as masculinos,
                           SUM(CASE WHEN a.sexo='femenino'  THEN 1 ELSE 0 END) as femeninos
                           FROM semanas_campamento s
                           LEFT JOIN acampantes a ON a.semana_id = s.id AND a.estado = 'activo'
                           WHERE s.fecha_inicio BETWEEN ? AND ?
                           GROUP BY s.id ORDER BY s.fecha_inicio");
    $stmt->execute([$inicio, $fin]);
    $acampantesPorSemana = $stmt->fetchAll();

    // ── 3. Acampantes por equipo ───────────────────────────
    $stmt = $pdo->prepare("SELECT c.equipo,
                           COUNT(DISTINCT c.id) as cabanas,
                           COUNT(a.id) as acampantes
                           FROM cabanas c
                           LEFT JOIN acampantes a ON a.cabana_id = c.id
                               AND a.estado = 'activo'
                           LEFT JOIN semanas_campamento s ON a.semana_id = s.id
                               AND s.fecha_inicio BETWEEN ? AND ?
                           WHERE c.equipo IS NOT NULL
                           GROUP BY c.equipo");
    $stmt->execute([$inicio, $fin]);
    $porEquipo = $stmt->fetchAll();

    // ── 4. Decisiones espirituales ────────────────────────
    $stmt = $pdo->prepare("SELECT
                           COUNT(*) as total,
                           SUM(recibio_cristo_semana) as recibio_cristo,
                           SUM(consagro_vida_fogata)  as consagro_vida,
                           SUM(era_creyente_antes)    as era_creyente,
                           SUM(asiste_iglesia)        as asiste_iglesia
                           FROM acampantes a
                           JOIN semanas_campamento s ON a.semana_id = s.id
                           WHERE s.fecha_inicio BETWEEN ? AND ? AND a.estado = 'activo'");
    $stmt->execute([$inicio, $fin]);
    $espiritual = $stmt->fetch();

    // ── 5. Sesiones de consejería ─────────────────────────
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT sc.acampante_id) as acampantes_con_consejeria,
                           COUNT(DISTINCT sc.numero_sesion) as total_sesiones_unicas,
                           COUNT(*) as total_registros
                           FROM sesiones_consejeria sc
                           JOIN acampantes a ON sc.acampante_id = a.id
                           JOIN semanas_campamento s ON a.semana_id = s.id
                           WHERE s.fecha_inicio BETWEEN ? AND ?");
    $stmt->execute([$inicio, $fin]);
    $consejerias = $stmt->fetch();

    // ── 6. Top iglesias ───────────────────────────────────
    $stmt = $pdo->prepare("SELECT a.iglesia, COUNT(*) as total
                           FROM acampantes a
                           JOIN semanas_campamento s ON a.semana_id = s.id
                           WHERE s.fecha_inicio BETWEEN ? AND ? AND a.estado = 'activo'
                           GROUP BY a.iglesia ORDER BY total DESC LIMIT 10");
    $stmt->execute([$inicio, $fin]);
    $topIglesias = $stmt->fetchAll();

    // ── 7. Top departamentos ──────────────────────────────
    $stmt = $pdo->prepare("SELECT a.estado_origen, COUNT(*) as total
                           FROM acampantes a
                           JOIN semanas_campamento s ON a.semana_id = s.id
                           WHERE s.fecha_inicio BETWEEN ? AND ?
                             AND a.estado = 'activo'
                             AND a.estado_origen IS NOT NULL
                           GROUP BY a.estado_origen ORDER BY total DESC LIMIT 10");
    $stmt->execute([$inicio, $fin]);
    $topDepartamentos = $stmt->fetchAll();

    // ── Guardar log del reporte ───────────────────────────
    $logDir  = __DIR__ . '/logs/';
    $logFile = $logDir . "reporte_{$anio}_{$mes}.json";
    if (!file_exists($logDir)) mkdir($logDir, 0755, true);

    $datosReporte = compact(
        'semanas', 'acampantesPorSemana', 'porEquipo',
        'espiritual', 'consejerias', 'topIglesias', 'topDepartamentos'
    );
    file_put_contents($logFile, json_encode($datosReporte, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

} catch (Exception $e) {
    if ($esCLI) {
        echo "ERROR: " . $e->getMessage() . "\n";
    } else {
        die('<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>');
    }
    exit(1);
}

// Salida CLI
if ($esCLI) {
    echo "════════════════════════════════════════\n";
    echo "  REPORTE MENSUAL — {$mesNombre} {$anio}\n";
    echo "════════════════════════════════════════\n\n";

    echo "📅 SEMANAS DEL PERÍODO:\n";
    foreach ($semanas as $s) {
        echo "  • {$s['nombre']} ({$s['tipo_acampante']})\n";
    }

    echo "\n👥 ACAMPANTES POR SEMANA:\n";
    foreach ($acampantesPorSemana as $row) {
        echo "  • {$row['semana']}: {$row['total']} total ({$row['masculinos']}M / {$row['femeninos']}F)\n";
    }

    echo "\n🛡️ POR EQUIPO:\n";
    foreach ($porEquipo as $eq) {
        echo "  • Equipo {$eq['equipo']}: {$eq['acampantes']} acampantes en {$eq['cabanas']} cabañas\n";
    }

    echo "\n✝️  ESPIRITUAL:\n";
    echo "  • Recibieron a Cristo: {$espiritual['recibio_cristo']}\n";
    echo "  • Consagraron vida:    {$espiritual['consagro_vida']}\n";
    echo "  • Eran creyentes:      {$espiritual['era_creyente']}\n";

    echo "\n💬 CONSEJERÍAS:\n";
    echo "  • Acampantes atendidos: {$consejerias['acampantes_con_consejeria']}\n";
    echo "  • Total sesiones:       {$consejerias['total_sesiones_unicas']}\n";

    echo "\n⛪ TOP IGLESIAS:\n";
    foreach ($topIglesias as $ig) {
        echo "  • {$ig['iglesia']}: {$ig['total']}\n";
    }

    echo "\nReporte guardado en: $logFile\n";
    echo "════════════════════════════════════════\n";
    exit(0);
}

// Salida HTML (desde el navegador)
include '../includes/header.php';
?>
<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <h1><i class="fas fa-chart-line"></i> Reporte Mensual — <?php echo ucfirst($mesNombre) . ' ' . $anio; ?></h1>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-secondary">
                <i class="fas fa-print"></i> Imprimir
            </button>
            <a href="../admin/dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
</div>

<!-- Selector de período -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label">Mes</label>
                <select name="mes" class="form-select">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo str_pad($m,2,'0',STR_PAD_LEFT); ?>"
                            <?php echo $mes == $m ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label">Año</label>
                <select name="anio" class="form-select">
                    <?php for ($y = date('Y'); $y >= date('Y')-3; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $anio == $y ? 'selected' : ''; ?>>
                        <?php echo $y; ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Generar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Tarjetas resumen -->
<div class="row mb-4">
    <?php $totalAcamp = array_sum(array_column($acampantesPorSemana, 'total')); ?>
    <div class="col-md-3 mb-3">
        <div class="card card-stat text-center">
            <div class="card-body">
                <i class="fas fa-users mb-2"></i>
                <h2><?php echo $totalAcamp; ?></h2>
                <p class="mb-0">Total Acampantes</p>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card card-stat text-center">
            <div class="card-body">
                <i class="fas fa-calendar-week mb-2"></i>
                <h2><?php echo count($semanas); ?></h2>
                <p class="mb-0">Semanas</p>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card card-stat text-center">
            <div class="card-body">
                <i class="fas fa-cross mb-2"></i>
                <h2><?php echo $espiritual['recibio_cristo']; ?></h2>
                <p class="mb-0">Recibieron a Cristo</p>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card card-stat text-center">
            <div class="card-body">
                <i class="fas fa-comments mb-2"></i>
                <h2><?php echo $consejerias['acampantes_con_consejeria']; ?></h2>
                <p class="mb-0">Con Consejería</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Por semana -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header"><h5><i class="fas fa-calendar"></i> Por Semana</h5></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-dark"><tr><th>Semana</th><th>Tipo</th><th>♂</th><th>♀</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($acampantesPorSemana as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['semana']); ?></td>
                        <td><span class="badge bg-secondary"><?php echo $row['tipo_acampante']; ?></span></td>
                        <td><span class="text-primary"><?php echo $row['masculinos']; ?></span></td>
                        <td><span class="text-danger"><?php echo $row['femeninos']; ?></span></td>
                        <td><strong><?php echo $row['total']; ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Espiritual -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header"><h5><i class="fas fa-cross"></i> Impacto Espiritual</h5></div>
            <div class="card-body">
                <?php
                $items = [
                    ['label' => 'Recibieron a Cristo',    'val' => $espiritual['recibio_cristo'], 'color' => 'success'],
                    ['label' => 'Consagraron su vida',    'val' => $espiritual['consagro_vida'],  'color' => 'warning'],
                    ['label' => 'Eran creyentes antes',   'val' => $espiritual['era_creyente'],   'color' => 'primary'],
                    ['label' => 'Asisten a iglesia',      'val' => $espiritual['asiste_iglesia'], 'color' => 'info'],
                ];
                foreach ($items as $item):
                    $pct = $totalAcamp > 0 ? round(($item['val'] / $totalAcamp) * 100) : 0;
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span><?php echo $item['label']; ?></span>
                        <strong><?php echo $item['val']; ?> (<?php echo $pct; ?>%)</strong>
                    </div>
                    <div class="progress" style="height:10px;">
                        <div class="progress-bar bg-<?php echo $item['color']; ?>"
                             style="width:<?php echo $pct; ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Top iglesias -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header"><h5><i class="fas fa-church"></i> Top 10 Iglesias</h5></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-dark"><tr><th>#</th><th>Iglesia</th><th>Acampantes</th></tr></thead>
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

    <!-- Top departamentos -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-map-marker-alt"></i> Top <?php echo PAIS_DIVISION; ?>s</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr><th>#</th><th><?php echo PAIS_DIVISION; ?></th><th>Acampantes</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($topDepartamentos as $i => $dep): ?>
                    <tr>
                        <td><span class="badge bg-secondary"><?php echo $i+1; ?></span></td>
                        <td><?php echo htmlspecialchars($dep['estado_origen'] ?? '—'); ?></td>
                        <td><strong><?php echo $dep['total']; ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>