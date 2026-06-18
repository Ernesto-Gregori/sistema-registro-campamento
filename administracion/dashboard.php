<?php
// administracion/dashboard.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministracion() && !esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$titulo    = "Administración — Caja";
$semana_id = isset($_GET['semana_id']) ? (int)$_GET['semana_id'] : 0;

// Semana activa por defecto
$stmt_sems = $pdo->query("SELECT * FROM semanas_campamento ORDER BY fecha_inicio DESC");
$semanas   = $stmt_sems->fetchAll(PDO::FETCH_ASSOC);

if (!$semana_id) {
    foreach ($semanas as $s) {
        if ($s['activa']) { $semana_id = (int)$s['id']; break; }
    }
}
$semana_actual = null;
foreach ($semanas as $s) {
    if ((int)$s['id'] === $semana_id) { $semana_actual = $s; break; }
}

// ── Estadísticas del día ─────────────────────────────────────────────────────
$hoy = date('Y-m-d');

// Pendientes de pago (docs revisados, no han llegado aún)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM acampantes
    WHERE semana_id = ? AND estado = 'activo'
      AND documentos_revisados = 1 AND llego = 0
");
$stmt->execute([$semana_id]);
$pendientes_pago = (int)$stmt->fetchColumn();

// Cobrado hoy
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(p.monto), 0)
    FROM pagos_acampante p
    INNER JOIN acampantes a ON p.acampante_id = a.id
    WHERE a.semana_id = ? AND DATE(p.fecha_pago) = ?
");
$stmt->execute([$semana_id, $hoy]);
$cobrado_hoy = (float)$stmt->fetchColumn();

// Check-ins de hoy
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM acampantes
    WHERE semana_id = ? AND estado = 'activo'
      AND llego = 1 AND DATE(fecha_llegada) = ?
");
$stmt->execute([$semana_id, $hoy]);
$checkins_hoy = (int)$stmt->fetchColumn();

// Pendientes sin documentos revisados (esperando inscripción)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM acampantes
    WHERE semana_id = ? AND estado = 'activo'
      AND documentos_revisados = 0 AND llego = 0
");
$stmt->execute([$semana_id]);
$esperando_inscripcion = (int)$stmt->fetchColumn();

// Últimos pagos del día
$stmt = $pdo->prepare("
    SELECT a.nombre, a.sexo, p.monto, p.modo_pago, p.fecha_pago,
           u.username AS cajero
    FROM pagos_acampante p
    INNER JOIN acampantes a ON p.acampante_id = a.id
    LEFT JOIN  usuarios u ON p.registrado_por = u.id
    WHERE a.semana_id = ? AND DATE(p.fecha_pago) = ?
    ORDER BY p.fecha_pago DESC
    LIMIT 10
");
$stmt->execute([$semana_id, $hoy]);
$ultimos_pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$base_path = '../';
include '../includes/header.php';
?>

<!-- Selector de semana -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <h1 class="mb-1"><i class="fas fa-cash-register"></i> <?= $titulo ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item active">Dashboard</li>
            </ol>
        </nav>
    </div>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <select name="semana_id" class="form-select" onchange="this.form.submit()" style="min-width:220px;">
            <?php foreach ($semanas as $s): ?>
            <option value="<?= $s['id'] ?>" <?= ((int)$s['id'] === $semana_id) ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['nombre']) ?><?= $s['activa'] ? ' ✓' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($semana_actual): ?>
<div class="alert alert-info border-0 d-flex align-items-center gap-2 mb-4">
    <i class="fas fa-calendar-week"></i>
    <span>
        <strong><?= htmlspecialchars($semana_actual['nombre']) ?></strong>
        &nbsp;·&nbsp; <?= date('d/m/Y', strtotime($semana_actual['fecha_inicio'])) ?>
        — <?= date('d/m/Y', strtotime($semana_actual['fecha_fin'])) ?>
    </span>
</div>
<?php endif; ?>

<!-- Tarjetas de estado -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-warning text-dark h-100">
            <div class="card-body text-center py-4">
                <i class="fas fa-clock fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0"><?= $pendientes_pago ?></h2>
                <div class="small opacity-75 fw-bold">Listos para Pagar</div>
                <a href="lista_pagos.php?semana_id=<?= $semana_id ?>"
                   class="btn btn-sm btn-dark mt-2">
                    <i class="fas fa-arrow-right"></i> Ver lista
                </a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-success text-white h-100">
            <div class="card-body text-center py-4">
                <i class="fas fa-dollar-sign fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0">$<?= number_format($cobrado_hoy, 0) ?></h2>
                <div class="small opacity-75">Cobrado Hoy</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-primary text-white h-100">
            <div class="card-body text-center py-4">
                <i class="fas fa-sign-in-alt fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0"><?= $checkins_hoy ?></h2>
                <div class="small opacity-75">Check-ins Hoy</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 bg-secondary text-white h-100">
            <div class="card-body text-center py-4">
                <i class="fas fa-hourglass-half fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0"><?= $esperando_inscripcion ?></h2>
                <div class="small opacity-75">Esperando Revisión</div>
                <small class="opacity-75">(en inscripción)</small>
            </div>
        </div>
    </div>
</div>

<!-- Últimos pagos del día -->
<div class="card">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-history"></i> Últimos pagos del día</h6>
        <a href="lista_pagos.php?semana_id=<?= $semana_id ?>" class="btn btn-sm btn-warning">
            <i class="fas fa-cash-register"></i> Ir a Caja
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($ultimos_pagos)): ?>
            <div class="text-center text-muted py-4">
                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                Sin pagos registrados hoy
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Acampante</th>
                        <th class="text-end">Monto</th>
                        <th>Modo</th>
                        <th>Hora</th>
                        <th>Cajero</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ultimos_pagos as $p):
                    $modos_color = ['efectivo'=>'success','banco'=>'primary','transferencia'=>'info'];
                    $color = $modos_color[$p['modo_pago']] ?? 'secondary';
                ?>
                <tr>
                    <td class="fw-bold"><?= htmlspecialchars($p['nombre']) ?></td>
                    <td class="text-end fw-bold text-success">$<?= number_format($p['monto'], 2) ?></td>
                    <td>
                        <span class="badge bg-<?= $color ?>">
                            <?= ucfirst($p['modo_pago']) ?>
                        </span>
                    </td>
                    <td class="text-muted small"><?= date('H:i', strtotime($p['fecha_pago'])) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($p['cajero'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>