<?php
// administracion/lista_pagos.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdministracion() && !esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$titulo    = "Caja — Pendientes de Pago";
$semana_id = isset($_GET['semana_id']) ? (int)$_GET['semana_id'] : 0;
$search    = trim($_GET['search'] ?? '');
$message   = $_GET['message'] ?? '';
$error     = $_GET['error'] ?? '';

$stmt_sems = $pdo->query("SELECT * FROM semanas_campamento ORDER BY fecha_inicio DESC");
$semanas   = $stmt_sems->fetchAll(PDO::FETCH_ASSOC);

if (!$semana_id) {
    foreach ($semanas as $s) {
        if ($s['activa']) { $semana_id = (int)$s['id']; break; }
    }
}

// Acampantes con documentos revisados, pendientes de check-in
$sql = "
    SELECT
        a.id, a.nombre, a.sexo, a.edad, a.iglesia,
        a.costo_total, a.llego, a.documentos_revisados,
        a.documentos_revisados_at,
        COALESCE(SUM(p.monto), 0)       AS pagado,
        a.costo_total - COALESCE(SUM(p.monto), 0) AS saldo,
        g.encargado_nombre              AS grupo_encargado
    FROM acampantes a
    LEFT JOIN pagos_acampante p ON p.acampante_id = a.id
    LEFT JOIN grupos_campamento g ON g.id = a.grupo_id
    WHERE a.semana_id = ? AND a.estado = 'activo'
      AND a.documentos_revisados = 1
      AND a.llego = 0
";
$params = [$semana_id];
if ($search !== '') {
    $sql    .= " AND a.nombre LIKE ?";
    $params[] = "%$search%";
}
$sql .= " GROUP BY a.id ORDER BY a.documentos_revisados_at ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$acampantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$base_path = '../';
include '../includes/header.php';
?>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
    <div>
        <h1 class="mb-1"><i class="fas fa-cash-register"></i> <?= $titulo ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Caja</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <form method="GET" class="d-flex gap-2">
            <select name="semana_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:200px;">
                <?php foreach ($semanas as $s): ?>
                <option value="<?= $s['id'] ?>" <?= ((int)$s['id'] === $semana_id) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['nombre']) ?><?= $s['activa'] ? ' ✓' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="🔍 Buscar nombre..."
                   value="<?= htmlspecialchars($search) ?>" style="min-width:180px;">
            <button class="btn btn-sm btn-primary" type="submit">
                <i class="fas fa-search"></i>
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
        <h6 class="mb-0">
            <i class="fas fa-clock"></i> Acampantes listos para pagar
            <span class="badge bg-dark ms-1"><?= count($acampantes) ?></span>
        </h6>
        <small>Documentos verificados por Inscripción · pendientes de check-in</small>
    </div>
    <div class="card-body p-0">
        <?php if (empty($acampantes)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-check-circle fa-3x mb-3 d-block text-success opacity-50"></i>
                <strong>Sin pendientes</strong><br>
                <small>Todos los acampantes verificados ya realizaron su pago</small>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-secondary">
                    <tr>
                        <th>Nombre</th>
                        <th class="text-center">Sexo</th>
                        <th class="text-center">Edad</th>
                        <th>Iglesia / Grupo</th>
                        <th class="text-end">Costo</th>
                        <th class="text-end">Pagado</th>
                        <th class="text-end">Saldo</th>
                        <th class="text-center">Docs revisados</th>
                        <th class="text-center">Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($acampantes as $a):
                    $saldo  = max(0, (float)$a['saldo']);
                    $pagado = (float)$a['pagado'];
                    $costo  = (float)$a['costo_total'];
                    $pct    = $costo > 0 ? min(100, round($pagado / $costo * 100)) : 100;
                    $es_beca = ($costo == 0);
                ?>
                <tr>
                    <td>
                        <span class="fw-bold"><?= htmlspecialchars($a['nombre']) ?></span>
                    </td>
                    <td class="text-center">
                        <?= $a['sexo'] === 'masculino'
                            ? '<span class="text-primary">♂</span>'
                            : '<span class="text-danger">♀</span>' ?>
                    </td>
                    <td class="text-center small text-muted"><?= $a['edad'] ?? '—' ?></td>
                    <td class="small text-muted">
                        <?= htmlspecialchars($a['iglesia'] ?? '') ?>
                        <?php if ($a['grupo_encargado']): ?>
                            <br><span class="badge bg-secondary">
                                <i class="fas fa-users fa-xs"></i>
                                <?= htmlspecialchars($a['grupo_encargado']) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end small">
                        <?= $es_beca
                            ? '<span class="badge bg-info">Beca</span>'
                            : '$' . number_format($costo, 2) ?>
                    </td>
                    <td class="text-end small text-success fw-bold">
                        $<?= number_format($pagado, 2) ?>
                    </td>
                    <td class="text-end">
                        <?php if ($es_beca || $saldo <= 0): ?>
                            <span class="badge bg-success">Pagado</span>
                        <?php else: ?>
                            <span class="fw-bold text-danger">$<?= number_format($saldo, 2) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <small class="text-success">
                            <i class="fas fa-check-circle"></i>
                            <?= $a['documentos_revisados_at']
                                ? date('d/m H:i', strtotime($a['documentos_revisados_at']))
                                : '—' ?>
                        </small>
                    </td>
                    <td class="text-center">
                        <a href="registrar_pago.php?id=<?= $a['id'] ?>&semana_id=<?= $semana_id ?>"
                           class="btn btn-sm btn-<?= ($es_beca || $saldo <= 0) ? 'success' : 'warning' ?>">
                            <i class="fas fa-<?= ($es_beca || $saldo <= 0) ? 'sign-in-alt' : 'dollar-sign' ?>"></i>
                            <?= ($es_beca || $saldo <= 0) ? 'Check-in' : 'Cobrar' ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>