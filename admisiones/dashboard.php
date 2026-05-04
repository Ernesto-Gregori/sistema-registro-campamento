<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador() && !esEncargadoConsejeros()) {
    header('Location: ../login.php');
    exit();
}

$titulo     = "Panel de Admisiones";
$year       = obtenerAnioCampamento();

// Semanas del año activo
$semanas = $pdo->prepare("
    SELECT * FROM semanas_campamento 
    WHERE year_campamento = ? 
    ORDER BY fecha_inicio
");
$semanas->execute([$year]);
$semanas = $semanas->fetchAll();

// Semana activa seleccionada
$semana_id = $_GET['semana_id'] ?? null;
if (!$semana_id && !empty($semanas)) {
    // Preferir semana activa, si no la primera
    foreach ($semanas as $s) {
        if ($s['activa']) { $semana_id = $s['id']; break; }
    }
    if (!$semana_id) $semana_id = $semanas[0]['id'];
}

// Stats de la semana
$stats = $semana_id ? resumenPagosSemana($pdo, (int)$semana_id) : [];

// Últimos inscritos
$ultimos = [];
if ($semana_id) {
    $stmt = $pdo->prepare("
        SELECT a.id, a.nombre, a.sexo, a.edad, a.llego, a.costo_total,
               COALESCE(SUM(p.monto),0) AS pagado,
               a.fecha_registro
        FROM acampantes a
        LEFT JOIN pagos_acampante p ON p.acampante_id = a.id
        WHERE a.semana_id = ? AND a.estado = 'activo'
        GROUP BY a.id
        ORDER BY a.fecha_registro DESC
        LIMIT 10
    ");
    $stmt->execute([$semana_id]);
    $ultimos = $stmt->fetchAll();
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-clipboard-list"></i> <?php echo $titulo; ?></h1>
            <p class="text-muted mb-0">Año <?php echo $year; ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="inscribir.php<?php echo $semana_id ? "?semana_id=$semana_id" : ''; ?>"
               class="btn btn-success">
                <i class="fas fa-user-plus"></i> Nueva Inscripción
            </a>
            <a href="checkin.php<?php echo $semana_id ? "?semana_id=$semana_id" : ''; ?>"
               class="btn btn-primary">
                <i class="fas fa-qrcode"></i> Check-in
            </a>
            <a href="importar.php" class="btn btn-outline-secondary">
                <i class="fas fa-file-csv"></i> Importar CSV
            </a>
        </div>
    </div>
</div>

<!-- Selector de semana -->
<?php if (!empty($semanas)): ?>
<div class="card mb-4">
    <div class="card-body py-2">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="fw-bold text-muted small">
                <i class="fas fa-calendar-week"></i> Semana:
            </span>
            <?php foreach ($semanas as $s): ?>
            <a href="?semana_id=<?php echo $s['id']; ?>"
               class="btn btn-sm <?php echo $semana_id == $s['id'] ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                <?php echo htmlspecialchars($s['nombre']); ?>
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
<!-- Stats cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="display-6 fw-bold text-primary">
                    <?php echo $stats['total_inscritos'] ?? 0; ?>
                </div>
                <small class="text-muted">Inscritos</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="display-6 fw-bold text-success">
                    <?php echo $stats['pagados_completo'] ?? 0; ?>
                </div>
                <small class="text-muted">Pago completo</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="display-6 fw-bold text-warning">
                    <?php echo $stats['total_llegaron'] ?? 0; ?>
                </div>
                <small class="text-muted">Check-in ✓</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="display-6 fw-bold text-danger" style="font-size:1.6rem!important;">
                    $<?php echo number_format(
                        ($stats['recaudacion_esperada'] ?? 0) - ($stats['recaudacion_real'] ?? 0),
                        0); ?>
                </div>
                <small class="text-muted">Saldo pendiente</small>
            </div>
        </div>
    </div>
</div>

<!-- Barra de progreso pagos -->
<?php
$esp  = (float)($stats['recaudacion_esperada'] ?? 0);
$real = (float)($stats['recaudacion_real']     ?? 0);
$pct  = $esp > 0 ? min(100, round($real / $esp * 100)) : 0;
?>
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between mb-1">
            <small class="fw-bold">Recaudación</small>
            <small class="text-muted">
                $<?php echo number_format($real,0); ?> /
                $<?php echo number_format($esp, 0); ?>
                (<?php echo $pct; ?>%)
            </small>
        </div>
        <div class="progress" style="height:10px;">
            <div class="progress-bar bg-success"
                 style="width:<?php echo $pct; ?>%"></div>
        </div>
    </div>
</div>

<!-- Accesos rápidos -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <a href="lista_acampantes.php?semana_id=<?php echo $semana_id; ?>"
           class="card text-decoration-none h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;background:#e8f4fd;border-radius:10px;
                            display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-list text-primary"></i>
                </div>
                <div>
                    <div class="fw-bold">Lista de Acampantes</div>
                    <small class="text-muted">Ver todos con filtros</small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="pagos.php?semana_id=<?php echo $semana_id; ?>"
           class="card text-decoration-none h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;background:#e8f9ef;border-radius:10px;
                            display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-dollar-sign text-success"></i>
                </div>
                <div>
                    <div class="fw-bold">Gestión de Pagos</div>
                    <small class="text-muted">Ver saldos y registrar abonos</small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="checkin.php?semana_id=<?php echo $semana_id; ?>"
           class="card text-decoration-none h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;background:#fff3e0;border-radius:10px;
                            display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-check-circle text-warning"></i>
                </div>
                <div>
                    <div class="fw-bold">Check-in</div>
                    <small class="text-muted">Registrar llegada + pulsera</small>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Últimas inscripciones -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-clock"></i> Últimas Inscripciones</h6>
        <a href="lista_acampantes.php?semana_id=<?php echo $semana_id; ?>"
           class="btn btn-sm btn-outline-secondary">Ver todos</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nombre</th>
                        <th>Sexo</th>
                        <th>Edad</th>
                        <th>Pago</th>
                        <th>Check-in</th>
                        <th>Registrado</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ultimos as $a):
                    $saldo   = $a['costo_total'] - $a['pagado'];
                    $pct_pag = $a['costo_total'] > 0
                        ? min(100, round($a['pagado'] / $a['costo_total'] * 100))
                        : 0;
                ?>
                <tr>
                    <td>
                        <a href="editar.php?id=<?php echo $a['id']; ?>"
                           class="text-decoration-none fw-bold">
                            <?php echo htmlspecialchars($a['nombre']); ?>
                        </a>
                    </td>
                    <td>
                        <?php echo $a['sexo'] === 'masculino'
                            ? '<span class="badge bg-info">♂ M</span>'
                            : '<span class="badge bg-danger">♀ F</span>'; ?>
                    </td>
                    <td><?php echo $a['edad'] ?? '—'; ?></td>
                    <td style="min-width:120px;">
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:6px;">
                                <div class="progress-bar <?php
                                    echo $pct_pag >= 100 ? 'bg-success' :
                                        ($pct_pag > 0 ? 'bg-warning' : 'bg-danger');
                                ?>" style="width:<?php echo $pct_pag; ?>%"></div>
                            </div>
                            <small class="text-muted"><?php echo $pct_pag; ?>%</small>
                        </div>
                    </td>
                    <td>
                        <?php if ($a['llego']): ?>
                        <span class="badge bg-success">
                            <i class="fas fa-check"></i> Llegó
                        </span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Pendiente</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?php echo date('d/m H:i', strtotime($a['fecha_registro'])); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($ultimos)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        No hay inscritos en esta semana aún
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (empty($semanas)): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    No hay semanas configuradas para <?php echo $year; ?>.
    Pide al encargado de consejeros que las cree primero.
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>