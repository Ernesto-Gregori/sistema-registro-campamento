<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador() && !esEncargadoConsejeros()) {
    header('Location: ../login.php');
    exit();
}

$titulo    = "Lista de Acampantes";
$year      = obtenerAnioCampamento();
$semana_id = $_GET['semana_id'] ?? null;

// Filtros
$filtro_pago    = $_GET['pago']    ?? '';  // completo|parcial|sin_pago
$filtro_checkin = $_GET['checkin'] ?? '';  // llegaron|pendientes
$filtro_sexo    = $_GET['sexo']    ?? '';
$search         = trim($_GET['search'] ?? '');

// Semanas disponibles
$semanas = $pdo->prepare("SELECT * FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio");
$semanas->execute([$year]);
$semanas = $semanas->fetchAll();

if (!$semana_id && !empty($semanas)) {
    foreach ($semanas as $s) {
        if ($s['activa']) { $semana_id = $s['id']; break; }
    }
    if (!$semana_id) $semana_id = $semanas[0]['id'];
}

// Query principal
$sql = "
    SELECT a.*,
           COALESCE(SUM(p.monto), 0)   AS total_pagado,
           a.costo_total - COALESCE(SUM(p.monto), 0) AS saldo,
           c.nombre_cabana,
           u.username AS registrado_por_nombre
    FROM acampantes a
    LEFT JOIN pagos_acampante p  ON p.acampante_id = a.id
    LEFT JOIN cabanas c          ON c.id = a.cabana_id
    LEFT JOIN usuarios u         ON u.id = a.registrado_por
    WHERE a.semana_id = ? AND a.estado = 'activo'
";
$params = [(int)$semana_id];

if ($filtro_sexo) {
    $sql .= " AND a.sexo = ?";
    $params[] = $filtro_sexo;
}
if ($search) {
    $sql .= " AND a.nombre LIKE ?";
    $params[] = "%$search%";
}

$sql .= " GROUP BY a.id";

// Filtros de pago post-agrupación (HAVING)
if ($filtro_pago === 'completo') {
    $sql .= " HAVING saldo <= 0";
} elseif ($filtro_pago === 'parcial') {
    $sql .= " HAVING total_pagado > 0 AND saldo > 0";
} elseif ($filtro_pago === 'sin_pago') {
    $sql .= " HAVING total_pagado = 0";
}

if ($filtro_checkin === 'llegaron') {
    $sql .= ($filtro_pago ? ' AND' : ' HAVING') . " a.llego = 1";
} elseif ($filtro_checkin === 'pendientes') {
    $sql .= ($filtro_pago ? ' AND' : ' HAVING') . " a.llego = 0";
}

$sql .= " ORDER BY a.nombre";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$acampantes = $stmt->fetchAll();

// Stats rápidos
$stats = $semana_id ? resumenPagosSemana($pdo, (int)$semana_id) : [];

include '../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-users"></i> <?php echo $titulo; ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Lista</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="inscribir.php<?php echo $semana_id ? "?semana_id=$semana_id" : ''; ?>"
               class="btn btn-success btn-sm">
                <i class="fas fa-plus"></i> Inscribir
            </a>
            <a href="importar.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-file-csv"></i> Importar CSV
            </a>
            <a href="exportar.php?semana_id=<?php echo $semana_id; ?>"
               class="btn btn-outline-dark btn-sm">
                <i class="fas fa-download"></i> Exportar
            </a>
        </div>
    </div>
</div>

<!-- Selector semana -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="fw-bold text-muted small"><i class="fas fa-calendar-week"></i></span>
            <?php foreach ($semanas as $s): ?>
            <a href="?semana_id=<?php echo $s['id']; ?>"
               class="btn btn-sm <?php echo $semana_id == $s['id'] ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                <?php echo htmlspecialchars($s['nombre']); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Mini stats -->
<?php if (!empty($stats)): ?>
<div class="row g-2 mb-3">
    <?php
    $mini = [
        ['label'=>'Total',        'val'=>$stats['total_inscritos'],  'color'=>'primary'],
        ['label'=>'Pago completo','val'=>$stats['pagados_completo'], 'color'=>'success'],
        ['label'=>'Llegaron',     'val'=>$stats['total_llegaron'],   'color'=>'warning'],
        ['label'=>'Mostrando',    'val'=>count($acampantes),         'color'=>'secondary'],
    ];
    foreach ($mini as $m):
    ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 text-center">
                <div class="fw-bold fs-5 text-<?php echo $m['color']; ?>">
                    <?php echo $m['val'] ?? 0; ?>
                </div>
                <small class="text-muted"><?php echo $m['label']; ?></small>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Filtros -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="semana_id" value="<?php echo $semana_id; ?>">

            <div class="col-12 col-md-3">
                <input type="text" class="form-control form-control-sm"
                       name="search" placeholder="🔍 Buscar por nombre..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="pago" class="form-select form-select-sm">
                    <option value="">💰 Todos los pagos</option>
                    <option value="completo"  <?php echo $filtro_pago==='completo'  ?'selected':''; ?>>✅ Pago completo</option>
                    <option value="parcial"   <?php echo $filtro_pago==='parcial'   ?'selected':''; ?>>⚠️ Pago parcial</option>
                    <option value="sin_pago"  <?php echo $filtro_pago==='sin_pago'  ?'selected':''; ?>>❌ Sin pago</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="checkin" class="form-select form-select-sm">
                    <option value="">🎫 Todos</option>
                    <option value="llegaron"  <?php echo $filtro_checkin==='llegaron'  ?'selected':''; ?>>✅ Llegaron</option>
                    <option value="pendientes"<?php echo $filtro_checkin==='pendientes'?'selected':''; ?>>⏳ Pendientes</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="sexo" class="form-select form-select-sm">
                    <option value="">⚥ Todos</option>
                    <option value="masculino" <?php echo $filtro_sexo==='masculino'?'selected':''; ?>>♂ Hombres</option>
                    <option value="femenino"  <?php echo $filtro_sexo==='femenino' ?'selected':''; ?>>♀ Mujeres</option>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="fas fa-search"></i>
                </button>
            </div>
            <div class="col-6 col-md-1">
                <a href="?semana_id=<?php echo $semana_id; ?>"
                   class="btn btn-outline-secondary btn-sm w-100">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Tabla -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nombre</th>
                        <th>Sexo / Edad</th>
                        <th>Iglesia</th>
                        <th>Cabaña</th>
                        <th>Pago</th>
                        <th>Saldo</th>
                        <th>Check-in</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($acampantes as $a):
                    $pct = $a['costo_total'] > 0
                        ? min(100, round($a['total_pagado'] / $a['costo_total'] * 100))
                        : 0;
                    $saldo_color = $a['saldo'] <= 0 ? 'success' :
                                  ($a['total_pagado'] > 0 ? 'warning' : 'danger');
                ?>
                <tr>
                    <td>
                        <div class="fw-bold"><?php echo htmlspecialchars($a['nombre']); ?></div>
                        <small class="text-muted">
                            <i class="fas fa-church fa-xs"></i>
                            <?php echo htmlspecialchars($a['iglesia'] ?? '—'); ?>
                        </small>
                    </td>
                    <td class="small">
                        <?php echo $a['sexo'] === 'masculino'
                            ? '<span class="badge bg-info">♂</span>'
                            : '<span class="badge bg-danger">♀</span>'; ?>
                        <?php echo $a['edad'] ?? '—'; ?>
                    </td>
                    <td class="small">
                        <?php echo htmlspecialchars($a['iglesia'] ?? '—'); ?>
                    </td>
                    <td class="small">
                        <?php echo $a['nombre_cabana']
                            ? '<span class="badge bg-secondary">' . htmlspecialchars($a['nombre_cabana']) . '</span>'
                            : '<span class="text-muted">Sin asignar</span>'; ?>
                    </td>
                    <td style="min-width:100px;">
                        <div class="progress mb-1" style="height:5px;">
                            <div class="progress-bar bg-<?php echo $saldo_color; ?>"
                                 style="width:<?php echo $pct; ?>%"></div>
                        </div>
                        <small class="text-muted">
                            $<?php echo number_format($a['total_pagado'],0); ?>
                            / $<?php echo number_format($a['costo_total'],0); ?>
                        </small>
                    </td>
                    <td>
                        <span class="badge bg-<?php echo $saldo_color; ?>">
                            $<?php echo number_format(max(0,$a['saldo']),0); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($a['llego']): ?>
                        <span class="badge bg-success">
                            <i class="fas fa-check"></i> Sí
                        </span>
                        <?php else: ?>
                        <a href="checkin.php?id=<?php echo $a['id']; ?>&semana_id=<?php echo $semana_id; ?>"
                           class="badge bg-secondary text-decoration-none">
                            Pendiente
                        </a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="editar.php?id=<?php echo $a['id']; ?>&semana_id=<?php echo $semana_id; ?>"
                               class="btn btn-sm btn-outline-primary" title="Editar">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="pagos.php?id=<?php echo $a['id']; ?>&semana_id=<?php echo $semana_id; ?>"
                               class="btn btn-sm btn-outline-success" title="Pagos">
                                <i class="fas fa-dollar-sign"></i>
                            </a>
                            <?php if (!$a['llego'] && $a['saldo'] <= 0 && $a['costo_total'] > 0): ?>
                            <a href="checkin.php?accion=confirmar&id=<?php echo $a['id']; ?>&semana_id=<?php echo $semana_id; ?>"
                               class="btn btn-sm btn-outline-warning" title="Check-in"
                               onclick="return confirm('¿Confirmar llegada de <?php echo htmlspecialchars($a['nombre'], ENT_QUOTES); ?>?')">
                                <i class="fas fa-qrcode"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($acampantes)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        No se encontraron acampantes con los filtros aplicados
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>