<?php
// equipo/area_detalle.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
verificarMantenimiento($pdo);

if (!esEquipoOAdmin()) {
    header('Location: ../default.php');
    exit();
}

$year     = obtenerAnioCampamento();
$semanaId = (int)($_GET['semana_id'] ?? 0);
$areaId   = (int)($_GET['area_id'] ?? 0);

if ($semanaId <= 0 || $areaId <= 0) {
    die('Faltan parametros.');
}

// Datos de la semana
$semana = [];
try {
    $stmt = $pdo->prepare("SELECT nombre, fecha_inicio, fecha_fin FROM semanas_campamento WHERE id = ?");
    $stmt->execute([$semanaId]);
    $semana = $stmt->fetch();
} catch (Exception $e) {}

// Datos del area
$area = [];
try {
    $stmt = $pdo->prepare("SELECT nombre, descripcion FROM areas_servicio WHERE id = ?");
    $stmt->execute([$areaId]);
    $area = $stmt->fetch();
} catch (Exception $e) {}

// Equipantes del area en esta semana (incluye los que NO tienen area asignada si area_id=0)
// Equipantes: solo aceptado/consejero
// Alumnos, misioneros, invitados, cocina: cualquier estado
$equipantes = [];
$error_sql = '';
try {
    if ($areaId > 0) {
        $stmt = $pdo->prepare("
            SELECT e.nombre, e.sexo, e.edad, e.iglesia, e.telefono_whatsapp, e.observaciones
            FROM distribucion_equipantes de
            INNER JOIN equipantes e ON de.equipante_id = e.id
            WHERE de.semana_id = ? AND de.area_id = ? AND e.activo = 1
            ORDER BY e.nombre ASC
        ");
        $stmt->execute([$semanaId, $areaId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT e.nombre, e.sexo, e.edad, e.iglesia, e.telefono_whatsapp, e.observaciones
            FROM distribucion_equipantes de
            INNER JOIN equipantes e ON de.equipante_id = e.id
            WHERE de.semana_id = ? AND e.activo = 1
            ORDER BY e.nombre ASC
        ");
        $stmt->execute([$semanaId]);
    }
    $equipantes = $stmt->fetchAll();
} catch (Exception $e) {
    
}

$total = count($equipantes);
$hombres = 0;
$mujeres = 0;
foreach ($equipantes as $e) {
    if ($e['sexo'] === 'masculino') $hombres++;
    if ($e['sexo'] === 'femenino')  $mujeres++;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Area: <?php echo htmlspecialchars($area['nombre'] ?? 'Todas'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; }
        }
    </style>
</head>
<body class="p-4">
    <div class="container">
        <div class="row mb-4">
            <div class="col-8">
                <h3><i class="fas fa-clipboard-list"></i> Area: <?php echo htmlspecialchars($area['nombre'] ?? 'Todas'); ?></h3>
                <p class="mb-0">
                    <strong>Semana:</strong> <?php echo htmlspecialchars($semana['nombre'] ?? ''); ?><br>
                    <small class="text-muted"><?php echo $semana ? date('d/m/Y', strtotime($semana['fecha_inicio'])) . ' - ' . date('d/m/Y', strtotime($semana['fecha_fin'])) : ''; ?></small>
                </p>
            </div>
            <div class="col-4 text-end no-print">
                <button onclick="window.print()" class="btn btn-primary btn-sm">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <a href="distribucion.php?semana_id=<?php echo $semanaId; ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold"><?php echo $total; ?></div>
                        <small class="text-muted">Total personas</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-primary">H <?php echo $hombres; ?></div>
                        <small class="text-muted">Hombres</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-danger">M <?php echo $mujeres; ?></div>
                        <small class="text-muted">Mujeres</small>
                    </div>
                </div>
            </div>
        </div>

        <table class="table table-bordered table-sm">
            <thead class="table-dark">
                <tr>
                    <th style="width:40px">#</th>
                    <th>Nombre</th>
                    <th>Sexo</th>
                    <th>Edad</th>
                    <th>Iglesia</th>
                    <th>WhatsApp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($equipantes)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">Sin personas en esta area</td></tr>
                <?php else: $i = 1; foreach ($equipantes as $e): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><strong><?php echo htmlspecialchars($e['nombre']); ?></strong></td>
                    <td class="text-center"><?php echo $e['sexo']==='masculino'?'H':'M'; ?></td>
                    <td class="text-center"><?php echo (int)$e['edad']; ?></td>
                    <td><?php echo htmlspecialchars($e['iglesia'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($e['telefono_whatsapp'] ?: '-'); ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <div class="mt-4 text-muted small">
            <p>Documento generado el <?php echo date('d/m/Y H:i'); ?> para el responsable del area de
            <strong><?php echo htmlspecialchars($area['nombre'] ?? 'Todas'); ?></strong>.</p>
        </div>
    </div>
</body>
</html>