<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador() && !esEncargadoConsejeros()) {
    header('Location: ../login.php');
    exit();
}

$titulo    = "Check-in";
$semana_id = $_GET['semana_id'] ?? null;
$message   = '';
$error     = '';

// Confirmar llegada directamente desde lista
if (isset($_GET['accion']) && $_GET['accion'] === 'confirmar' && isset($_GET['id'])) {
    $acampante_id = (int)$_GET['id'];
    try {
        $saldo_info = calcularSaldoAcampante($pdo, $acampante_id);
        if (!$saldo_info['pagado_100']) {
            throw new Exception("No se puede hacer check-in: tiene saldo pendiente de $" .
                                number_format($saldo_info['saldo'], 2));
        }

        $stmt = $pdo->prepare("SELECT nombre, llego FROM acampantes WHERE id = ?");
        $stmt->execute([$acampante_id]);
        $a = $stmt->fetch();

        if ($a['llego']) {
            $message = "ℹ️ {$a['nombre']} ya realizó check-in anteriormente";
        } else {
            $pdo->prepare("UPDATE acampantes SET llego = 1, fecha_llegada = NOW() WHERE id = ?")
                ->execute([$acampante_id]);

            registrarLog($pdo, 'checkin_realizado',
                "Check-in: {$a['nombre']}",
                'admisiones', 'success');

            $message = "✅ Check-in de {$a['nombre']} confirmado — pulsera entregada";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Búsqueda de acampante para check-in
$resultado_busqueda = [];
$busqueda = trim($_GET['buscar'] ?? '');

if ($busqueda && $semana_id) {
    $stmt = $pdo->prepare("
        SELECT a.id, a.nombre, a.edad, a.sexo, a.iglesia,
               a.llego, a.fecha_llegada, a.costo_total,
               COALESCE(SUM(p.monto), 0) AS total_pagado,
               c.nombre_cabana
        FROM acampantes a
        LEFT JOIN pagos_acampante p ON p.acampante_id = a.id
        LEFT JOIN cabanas c         ON c.id = a.cabana_id
        WHERE a.semana_id = ? AND a.estado = 'activo'
          AND a.nombre LIKE ?
        GROUP BY a.id
        ORDER BY a.nombre
        LIMIT 20
    ");
    $stmt->execute([(int)$semana_id, "%$busqueda%"]);
    $resultado_busqueda = $stmt->fetchAll();
}

// Stats del día
$stats_dia = [];
if ($semana_id) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(llego) AS llegaron,
            SUM(CASE WHEN DATE(fecha_llegada) = CURDATE() THEN 1 ELSE 0 END) AS hoy
        FROM acampantes
        WHERE semana_id = ? AND estado = 'activo'
    ");
    $stmt->execute([(int)$semana_id]);
    $stats_dia = $stmt->fetch();
}

// Semanas disponibles
$semanas = $pdo->prepare("SELECT * FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio");
$semanas->execute([obtenerAnioCampamento()]);
$semanas = $semanas->fetchAll();

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-qrcode"></i> <?php echo $titulo; ?></h1>
            <p class="text-muted mb-0">Registro de llegada y entrega de pulsera</p>
        </div>
        <a href="lista_acampantes.php?semana_id=<?php echo $semana_id; ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-list"></i> Ver lista
        </a>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <?php echo $message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Selector de semana -->
<div class="card mb-4">
    <div class="card-body py-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="fw-bold text-muted small"><i class="fas fa-calendar-week"></i> Semana:</span>
            <?php foreach ($semanas as $s): ?>
            <a href="?semana_id=<?php echo $s['id']; ?>"
               class="btn btn-sm <?php echo $semana_id == $s['id'] ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                <?php echo htmlspecialchars($s['nombre']); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Stats del día -->
<?php if (!empty($stats_dia)): ?>
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-primary"><?php echo $stats_dia['total']; ?></div>
                <small class="text-muted">Total inscritos</small>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-success"><?php echo $stats_dia['llegaron']; ?></div>
                <small class="text-muted">Check-in total</small>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-warning"><?php echo $stats_dia['hoy']; ?></div>
                <small class="text-muted">Check-in hoy</small>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Buscador -->
<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="fas fa-search"></i> Buscar Acampante</h5>
    </div>
    <div class="card-body">
        <form method="GET" class="d-flex gap-2">
            <input type="hidden" name="semana_id" value="<?php echo $semana_id; ?>">
            <input type="text" class="form-control form-control-lg"
                   name="buscar" id="campoBuscar"
                   value="<?php echo htmlspecialchars($busqueda); ?>"
                   placeholder="Escribe el nombre del acampante..."
                   autofocus autocomplete="off">
            <button type="submit" class="btn btn-primary btn-lg px-4">
                <i class="fas fa-search"></i>
            </button>
        </form>
        <small class="text-muted mt-2 d-block">
            <i class="fas fa-info-circle"></i>
            Solo se puede hacer check-in si el pago está completo y se entregó la pulsera.
        </small>
    </div>
</div>

<!-- Resultados -->
<?php if ($busqueda && !empty($resultado_busqueda)): ?>
<div class="card">
    <div class="card-header">
        <h6 class="mb-0">
            Resultados — <?php echo count($resultado_busqueda); ?> encontrado(s) para
            "<strong><?php echo htmlspecialchars($busqueda); ?></strong>"
        </h6>
    </div>
    <div class="card-body p-0">
        <?php foreach ($resultado_busqueda as $a):
            $saldo   = $a['costo_total'] - $a['total_pagado'];
            $pagado  = $saldo <= 0 && $a['costo_total'] > 0;
            $pct     = $a['costo_total'] > 0
                       ? min(100, round($a['total_pagado'] / $a['costo_total'] * 100)) : 0;
        ?>
        <div class="p-3 border-bottom">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">

                <!-- Info acampante -->
                <div>
                    <div class="fw-bold fs-5">
                        <?php echo htmlspecialchars($a['nombre']); ?>
                        <?php echo $a['sexo'] === 'masculino'
                            ? '<span class="badge bg-info ms-1">♂</span>'
                            : '<span class="badge bg-danger ms-1">♀</span>'; ?>
                    </div>
                    <div class="text-muted small">
                        <?php echo $a['edad']; ?> años
                        <?php if ($a['iglesia']): ?>
                        · <?php echo htmlspecialchars($a['iglesia']); ?>
                        <?php endif; ?>
                        <?php if ($a['nombre_cabana']): ?>
                        · <span class="badge bg-secondary"><?php echo htmlspecialchars($a['nombre_cabana']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Estado pago y check-in -->
                <div class="text-end">
                    <!-- Barra de pago -->
                    <div class="progress mb-1" style="height:6px;width:150px;margin-left:auto;">
                        <div class="progress-bar bg-<?php echo $pagado ? 'success' : ($a['total_pagado'] > 0 ? 'warning' : 'danger'); ?>"
                             style="width:<?php echo $pct; ?>%"></div>
                    </div>
                    <small class="text-muted d-block mb-2">
                        $<?php echo number_format($a['total_pagado'],0); ?>
                        / $<?php echo number_format($a['costo_total'],0); ?>
                        <?php if ($saldo > 0): ?>
                        <span class="text-danger fw-bold">
                            (Saldo: $<?php echo number_format($saldo,0); ?>)
                        </span>
                        <?php endif; ?>
                    </small>

                    <?php if ($a['llego']): ?>
                    <!-- Ya hizo check-in -->
                    <span class="badge bg-success fs-6 px-3 py-2">
                        <i class="fas fa-check-circle"></i> Check-in realizado
                        <br><small class="fw-normal">
                            <?php echo date('d/m H:i', strtotime($a['fecha_llegada'])); ?>
                        </small>
                    </span>

                    <?php elseif ($pagado): ?>
                    <!-- Pago completo → puede hacer check-in -->
                    <a href="?semana_id=<?php echo $semana_id; ?>&accion=confirmar&id=<?php echo $a['id']; ?>&buscar=<?php echo urlencode($busqueda); ?>"
                       class="btn btn-success btn-lg px-4"
                       onclick="return confirm('¿Confirmar llegada de <?php echo htmlspecialchars($a['nombre'], ENT_QUOTES); ?>?\n\nEsto indica que recibió su pulsera.')">
                        <i class="fas fa-id-badge"></i> Hacer Check-in
                    </a>

                    <?php else: ?>
                    <!-- Saldo pendiente → no puede hacer check-in -->
                    <div class="d-flex flex-column gap-1 align-items-end">
                        <span class="badge bg-warning text-dark px-3 py-2">
                            <i class="fas fa-exclamation-triangle"></i>
                            Pago incompleto — no puede ingresar
                        </span>
                        <a href="pagos.php?id=<?php echo $a['id']; ?>&semana_id=<?php echo $semana_id; ?>"
                           class="btn btn-outline-success btn-sm">
                            <i class="fas fa-dollar-sign"></i> Registrar pago
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php elseif ($busqueda && empty($resultado_busqueda)): ?>
<div class="alert alert-warning">
    <i class="fas fa-search"></i>
    No se encontró ningún acampante con "<strong><?php echo htmlspecialchars($busqueda); ?></strong>"
    en esta semana.
    <a href="inscribir.php?semana_id=<?php echo $semana_id; ?>" class="btn btn-success btn-sm ms-2">
        <i class="fas fa-user-plus"></i> Inscribir nuevo
    </a>
</div>
<?php endif; ?>

<script>
// Auto-submit al pausar escritura (300ms)
let timerBuscar;
document.getElementById('campoBuscar').addEventListener('input', function() {
    clearTimeout(timerBuscar);
    timerBuscar = setTimeout(() => {
        if (this.value.length >= 2) this.form.submit();
    }, 400);
});
</script>

<?php include '../includes/footer.php'; ?>