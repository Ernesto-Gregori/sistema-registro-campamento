<?php  
require_once 'generar_pdf.php';
  
$tipo = $_GET['tipo'] ?? 'general';  
$year = $_GET['year'] ?? obtenerAnioCampamento();  
$cabana_id = $_GET['cabana_id'] ?? null;
$semana_id = $_GET['semana_id'] ?? null;

// Si no viene semana_id, usar la semana activa
if (!$semana_id) {
    $stmt_sem = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
    $semana_activa = $stmt_sem->fetch();
    $semana_id = $semana_activa['id'] ?? null;
} else {
    $stmt_sem = $pdo->prepare("SELECT * FROM semanas_campamento WHERE id = ?");
    $stmt_sem->execute([$semana_id]);
    $semana_activa = $stmt_sem->fetch();
}

// Nombre de la semana para el título
$nombre_semana = $semana_activa ? $semana_activa['nombre'] : "Campamento $year";
  
try {  
    switch ($tipo) {  
        case 'general':  
            $datos = obtenerReporteGeneral($pdo, $year, $semana_id);  
            $titulo = "Reporte General - $nombre_semana";  
            break;  
              
        case 'cabanas':  
            $datos = obtenerReporteCabanas($pdo, $year, $semana_id);  
            $titulo = "Reporte por Cabañas - $nombre_semana";  
            break;  
              
        case 'cabana':  
            $datos = obtenerReporteCabana($pdo, $cabana_id, $year, $semana_id);  
            $titulo = "Reporte de Cabaña - $nombre_semana";  
            break;  
              
        case 'completo':  
            $datos = obtenerReporteCompleto($pdo, $year, $semana_id);  
            $titulo = "Reporte Completo - $nombre_semana";  
            break;  
              
        default:  
            throw new Exception("Tipo de reporte no válido");  
    }  
} catch (Exception $e) {  
    die("Error: " . $e->getMessage());  
}  
?>  
  
<!DOCTYPE html>  
<html lang="es">  
<head>  
    <meta charset="UTF-8">  
    <meta name="viewport" content="width=device-width, initial-scale=1.0">  
    <title><?php echo $titulo; ?></title>  
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">  
    <style>  
        @media print {  
            .no-print { display: none !important; }  
            .page-break { page-break-before: always; }  
            body { font-size: 12px; }  
        }  
        @media screen { body { padding: 20px; } }  
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }  
        .stats-card { background-color: #f8f9fa; padding: 20px; text-align: center; border: 1px solid #dee2e6; border-radius: 8px; }  
        .stat-number { font-size: 2rem; font-weight: bold; color: #0d6efd; }  
    </style>  
</head>  
<body>  
    <!-- Botones -->  
    <div class="no-print mb-3">  
        <button onclick="window.print()" class="btn btn-primary">Imprimir</button>  
        <button onclick="window.close()" class="btn btn-secondary">Cerrar</button>  
    </div>  
  
    <!-- Header -->  
    <div class="header">  
        <h1><?php echo $titulo; ?></h1>
        <?php if ($semana_activa): ?>
        <div class="mb-2">
            <span style="background:#198754;color:white;padding:4px 10px;border-radius:4px;font-size:14px;">
                <?php
                $tipos = ['mayores' => '👔 Mayores', 'ninos' => '🧒 Niños', 'adolescentes' => '🎓 Adolescentes'];
                echo $tipos[$semana_activa['tipo_acampante']] ?? '';
                ?> |
                <?php echo date('d/m/Y', strtotime($semana_activa['fecha_inicio'])); ?> -
                <?php echo date('d/m/Y', strtotime($semana_activa['fecha_fin'])); ?>
            </span>
        </div>
        <?php endif; ?>
        <p class="text-muted">Generado el: <?php echo date('d/m/Y H:i'); ?> | Campamento Palabra de Vida</p>  
    </div>  
  
    <?php if ($tipo === 'general'): ?>  
        <div class="row mb-4">  
            <div class="col-md-3">  
                <div class="stats-card">  
                    <div class="stat-number"><?php echo $datos['total_acampantes']; ?></div>  
                    <div>Total Acampantes</div>  
                </div>  
            </div>  
            <div class="col-md-3">  
                <div class="stats-card">  
                    <div class="stat-number"><?php echo $datos['total_cabanas']; ?></div>  
                    <div>Cabañas Activas</div>  
                </div>  
            </div>  
            <div class="col-md-3">  
                <div class="stats-card">  
                    <div class="stat-number"><?php echo $datos['total_consejerias']; ?></div>  
                    <div>Consejerías</div>  
                </div>  
            </div>  
            <div class="col-md-3">  
                <div class="stats-card">  
                    <div class="stat-number"><?php echo $datos['total_acampantes'] > 0 ? round(($datos['total_consejerias'] / ($datos['total_acampantes'] * 3)) * 100, 1) : 0; ?>%</div>  
                    <div>Progreso</div>  
                </div>  
            </div>  
        </div>  
  
        <h3>Estadísticas Espirituales</h3>  
        <div class="row mb-4">  
            <div class="col-md-3">  
                <div class="stats-card">  
                    <div class="stat-number"><?php echo $datos['estadisticas_espirituales']['asisten_iglesia']; ?></div>  
                    <div>Asisten a Iglesia</div>  
                </div>  
            </div>  
            <div class="col-md-3">  
                <div class="stats-card">  
                    <div class="stat-number"><?php echo $datos['estadisticas_espirituales']['eran_creyentes']; ?></div>  
                    <div>Eran Creyentes</div>  
                </div>  
            </div>  
            <div class="col-md-3">  
                <div class="stats-card">  
                    <div class="stat-number"><?php echo $datos['estadisticas_espirituales']['nuevos_creyentes']; ?></div>  
                    <div>Nuevos Creyentes</div>  
                </div>  
            </div>  
            <div class="col-md-3">  
                <div class="stats-card">  
                    <div class="stat-number"><?php echo $datos['estadisticas_espirituales']['consagraciones']; ?></div>  
                    <div>Consagraciones</div>  
                </div>  
            </div>  
        </div>  
  
    <?php elseif ($tipo === 'cabanas'): ?>  
        <h3>Estadísticas por Cabaña</h3>  
        <div class="table-responsive">  
            <table class="table table-bordered">  
                <thead class="table-dark">  
                    <tr>  
                        <th>Cabaña</th>  
                        <th>Consejero</th>  
                        <th>Acampantes</th>  
                        <th>Nuevos Creyentes</th>  
                        <th>Consagraciones</th>  
                        <th>Asisten Iglesia</th>  
                    </tr>  
                </thead>  
                <tbody>  
                    <?php foreach ($datos as $cabana): ?>  
                    <tr>  
                        <td><strong><?php echo htmlspecialchars($cabana['nombre_cabana']); ?></strong></td>  
                        <td><?php echo htmlspecialchars($cabana['consejero_principal'] ?: 'No asignado'); ?></td>  
                        <td><?php echo $cabana['total_acampantes']; ?>/<?php echo $cabana['capacidad_maxima']; ?></td>  
                        <td><span class="badge bg-success"><?php echo $cabana['nuevos_creyentes']; ?></span></td>  
                        <td><span class="badge bg-warning"><?php echo $cabana['consagraciones']; ?></span></td>  
                        <td><span class="badge bg-info"><?php echo $cabana['asisten_iglesia']; ?></span></td>  
                    </tr>  
                    <?php endforeach; ?>  
                </tbody>  
            </table>  
        </div>  
  
    <?php elseif ($tipo === 'cabana'): ?>  
        <h3>Información de la Cabaña</h3>  
        <div class="row mb-4">  
            <div class="col-md-6">  
                <p><strong>Nombre:</strong> <?php echo htmlspecialchars($datos['cabana']['nombre_cabana']); ?></p>  
                <p><strong>Consejero Principal:</strong> <?php echo htmlspecialchars($datos['cabana']['consejero_principal'] ?: 'No asignado'); ?></p>  
                <p><strong>Capacidad:</strong> <?php echo $datos['cabana']['capacidad_maxima']; ?> acampantes</p>  
            </div>  
        </div>  
        <h4>Acampantes</h4>  
        <div class="table-responsive">  
            <table class="table table-bordered">  
                <thead class="table-dark">  
                    <tr>  
                        <th>Nombre</th>  
                        <th>Iglesia</th>  
                        <th>Consejerías</th>  
                        <th>Estado Espiritual</th>  
                    </tr>  
                </thead>  
                <tbody>  
                    <?php foreach ($datos['acampantes'] as $acampante): ?>  
                    <tr>  
                        <td><strong><?php echo htmlspecialchars($acampante['nombre']); ?></strong></td>  
                        <td><?php echo htmlspecialchars($acampante['iglesia']); ?></td>  
                        <td><?php echo $acampante['consejerias_realizadas']; ?>/3</td>  
                        <td>  
                            <?php if ($acampante['recibio_cristo_semana']): ?>  
                                <span class="badge bg-success">Nuevo Creyente</span>  
                            <?php endif; ?>  
                            <?php if ($acampante['consagro_vida_fogata']): ?>  
                                <span class="badge bg-warning">Consagró</span>  
                            <?php endif; ?>  
                            <?php if ($acampante['asiste_iglesia']): ?>  
                                <span class="badge bg-info">Asiste Iglesia</span>  
                            <?php endif; ?>  
                        </td>  
                    </tr>  
                    <?php endforeach; ?>  
                </tbody>  
            </table>  
        </div>  
  
    <?php elseif ($tipo === 'completo'): ?>  
        <h3>Resumen General</h3>  
        <div class="row mb-4">  
            <div class="col-md-4">  
                <div class="stats-card">  
                    <div class="stat-number"><?php echo $datos['general']['total_acampantes']; ?></div>  
                    <div>Total Acampantes</div>  
                </div>  
            </div>  
            <div class="col-md-4">  
                <div class="stats-card">  
                    <div class="stat-number"><?php echo $datos['general']['total_consejerias']; ?></div>  
                    <div>Consejerías</div>  
                </div>  
            </div>  
            <div class="col-md-4">  
                <div class="stats-card">  
                    <div class="stat-number"><?php echo $datos['general']['estadisticas_espirituales']['nuevos_creyentes']; ?></div>  
                    <div>Nuevos Creyentes</div>  
                </div>  
            </div>  
        </div>  
        <div class="page-break"></div>  
        <h4>Estadísticas por Cabaña</h4>  
        <div class="table-responsive">  
            <table class="table table-bordered">  
                <thead class="table-dark">  
                    <tr>  
                        <th>Cabaña</th>  
                        <th>Consejero</th>  
                        <th>Acampantes</th>  
                        <th>Nuevos Creyentes</th>  
                        <th>Consagraciones</th>  
                    </tr>  
                </thead>  
                <tbody>  
                    <?php foreach ($datos['cabanas'] as $cabana): ?>  
                    <tr>  
                        <td><?php echo htmlspecialchars($cabana['nombre_cabana']); ?></td>  
                        <td><?php echo htmlspecialchars($cabana['consejero_principal'] ?: 'No asignado'); ?></td>  
                        <td><?php echo $cabana['total_acampantes']; ?></td>  
                        <td><?php echo $cabana['nuevos_creyentes']; ?></td>  
                        <td><?php echo $cabana['consagraciones']; ?></td>  
                    </tr>  
                    <?php endforeach; ?>  
                </tbody>  
            </table>  
        </div>  
    <?php endif; ?>  
  
    <div class="text-center mt-5" style="border-top: 1px solid #dee2e6; padding-top: 20px; color: #6c757d;">  
        <small>  
            Campamento Palabra de Vida - Reporte generado automáticamente<br>  
            Fecha de generación: <?php echo date('d/m/Y H:i:s'); ?>  
        </small>  
    </div>  
  
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>  
</body>  
</html>