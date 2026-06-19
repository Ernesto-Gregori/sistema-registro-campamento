<?php
// apoyo/sala_espera.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
verificarMantenimiento($pdo);
if (!esApoyo()) {
    header('Location: ../login.php');
    exit();
}

$titulo = "Sala de Espera - Apoyo de Consejeros";

// Obtener genero_acceso del usuario actual
$stmt = $pdo->prepare("SELECT genero_acceso FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$usuario_actual = $stmt->fetch();
$genero_acceso = $usuario_actual['genero_acceso'] ?? 'ambos';

// Filtro de género sobre cabañas
$where_genero = $genero_acceso !== 'ambos'
    ? "AND c.genero = " . $pdo->quote($genero_acceso)
    : "";

// Semana activa
$stmt = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
$semana_activa = $stmt->fetch();
$semana_id_activa = $semana_activa['id'] ?? null;

// Mensaje de acción
$message = $_GET['message'] ?? '';

// Acampantes en sala de espera:
// - llego = 1 (check-in hecho por Administración)
// - enviado_cabana = 0 (Apoyo aún no los ha voceado)
if ($semana_id_activa) {
    $sql = "SELECT a.id, a.nombre, a.edad, a.sexo, a.iglesia,
                   a.fecha_llegada,
                   c.nombre_cabana, c.genero AS genero_cabana, c.equipo
            FROM acampantes a
            JOIN cabanas c ON a.cabana_id = c.id
            WHERE a.semana_id = ?
              AND a.estado   = 'activo'
              AND a.llego    = 1
              AND a.enviado_cabana = 0
              $where_genero
            ORDER BY a.fecha_llegada ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$semana_id_activa]);
} else {
    $sql = "SELECT a.id, a.nombre, a.edad, a.sexo, a.iglesia,
                   a.fecha_llegada,
                   c.nombre_cabana, c.genero AS genero_cabana, c.equipo
            FROM acampantes a
            JOIN cabanas c ON a.cabana_id = c.id
            WHERE a.year_campamento = ?
              AND a.estado          = 'activo'
              AND a.llego           = 1
              AND a.enviado_cabana  = 0
              $where_genero
            ORDER BY a.fecha_llegada ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([obtenerAnioCampamento()]);
}
$en_espera = $stmt->fetchAll();

// Conteo de ya enviados hoy (para feedback motivador)
$hoy = date('Y-m-d');
if ($semana_id_activa) {
    $stmt2 = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM acampantes a
        JOIN cabanas c ON a.cabana_id = c.id
        WHERE a.semana_id = ?
          AND a.enviado_cabana = 1
          AND DATE(a.fecha_llegada) = ?
          $where_genero
    ");
    $stmt2->execute([$semana_id_activa, $hoy]);
} else {
    $stmt2 = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM acampantes a
        JOIN cabanas c ON a.cabana_id = c.id
        WHERE a.year_campamento = ?
          AND a.enviado_cabana = 1
          AND DATE(a.fecha_llegada) = ?
          $where_genero
    ");
    $stmt2->execute([obtenerAnioCampamento(), $hoy]);
}
$enviados_hoy = $stmt2->fetch()['total'];

// Config equipos para colores
$equipos_config = obtenerEquipos($pdo);

include '../includes/header.php';
?>

<!-- Auto-refresh cada 20 segundos si hay acampantes en espera -->
<?php if (count($en_espera) > 0): ?>
<script>
    // Refresca la página cada 20s para mostrar nuevos check-ins
    let autoRefresh = setTimeout(() => location.reload(), 20000);
    // Si el usuario interactúa, reinicia el contador
    document.addEventListener('click', () => {
        clearTimeout(autoRefresh);
        autoRefresh = setTimeout(() => location.reload(), 20000);
    });
</script>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="mb-1">
            <i class="fas fa-bullhorn"></i> Sala de Espera
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Sala de Espera</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <?php if ($genero_acceso === 'masculino'): ?>
            <span class="badge bg-primary fs-6"><i class="fas fa-mars"></i> Masculino</span>
        <?php elseif ($genero_acceso === 'femenino'): ?>
            <span class="badge bg-danger fs-6"><i class="fas fa-venus"></i> Femenino</span>
        <?php else: ?>
            <span class="badge bg-secondary fs-6"><i class="fas fa-venus-mars"></i> Ambos</span>
        <?php endif; ?>
        <button onclick="location.reload()" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-sync-alt"></i> Actualizar
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!$semana_id_activa): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i>
        No hay ninguna semana de campamento activa en este momento.
    </div>
    <?php include '../includes/footer.php'; exit(); ?>
<?php endif; ?>

<!-- Tarjetas resumen -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 text-white h-100
            <?= count($en_espera) > 0 ? 'bg-warning' : 'bg-success' ?>">
            <div class="card-body text-center py-3">
                <i class="fas fa-hourglass-half fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0"><?= count($en_espera) ?></h2>
                <div class="small opacity-90 fw-semibold">En espera</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 bg-success text-white h-100">
            <div class="card-body text-center py-3">
                <i class="fas fa-check-double fa-2x mb-2 opacity-75"></i>
                <h2 class="fw-bold mb-0"><?= $enviados_hoy ?></h2>
                <div class="small opacity-90 fw-semibold">Enviados hoy</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card border-0 bg-light h-100">
            <div class="card-body d-flex align-items-center gap-3 py-3">
                <i class="fas fa-calendar-week fa-2x text-primary opacity-75"></i>
                <div>
                    <div class="fw-bold"><?= htmlspecialchars($semana_activa['nombre']) ?></div>
                    <div class="small text-muted">
                        <?= date('d/m/Y', strtotime($semana_activa['fecha_inicio'])) ?>
                        al
                        <?= date('d/m/Y', strtotime($semana_activa['fecha_fin'])) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Lista principal -->
<?php if (count($en_espera) === 0): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="fas fa-check-circle fa-4x text-success mb-3 opacity-75"></i>
            <h4 class="text-success">¡Todo en orden!</h4>
            <p class="text-muted mb-0">No hay acampantes esperando ser dirigidos a su cabaña.</p>
            <?php if ($enviados_hoy > 0): ?>
                <p class="text-muted small mt-2">
                    <i class="fas fa-star text-warning"></i>
                    Hoy ya enviaste a <strong><?= $enviados_hoy ?></strong> acampantes.
                </p>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 py-2">
        <i class="fas fa-bullhorn"></i>
        <span>
            <strong><?= count($en_espera) ?> acampante<?= count($en_espera) > 1 ? 's' : '' ?></strong>
            <?= count($en_espera) > 1 ? 'están listos' : 'está listo' ?> para ser dirigido<?= count($en_espera) > 1 ? 's' : '' ?> a su cabaña.
            <small class="text-muted">— Se actualiza automáticamente cada 20 segundos</small>
        </span>
    </div>

    <!-- Vista móvil: cards -->
    <div class="d-md-none">
        <?php foreach ($en_espera as $a):
            $equipo = $a['equipo'] ?? null;
            $eq_cfg = $equipo ? ($equipos_config[$equipo] ?? null) : null;
            $eq_color = $eq_cfg['color_hex'] ?? '#6c757d';
            $eq_nombre = $eq_cfg['nombre'] ?? ucfirst($equipo ?? '');
        ?>
        <div class="card mb-3 shadow-sm border-start border-4"
             style="border-color: <?= htmlspecialchars($eq_color) ?> !important;">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h6 class="mb-0 fw-bold"><?= htmlspecialchars($a['nombre']) ?></h6>
                        <small class="text-muted">
                            <?= $a['edad'] ?> años ·
                            <?= $a['sexo'] === 'masculino' ? '<i class="fas fa-mars text-primary"></i>' : '<i class="fas fa-venus text-danger"></i>' ?>
                        </small>
                    </div>
                </div>
                <div class="mb-1">
                    <span class="badge text-white" style="background-color: <?= htmlspecialchars($eq_color) ?>">
                        <i class="fas fa-home"></i>
                        <?= htmlspecialchars($a['nombre_cabana']) ?>
                    </span>
                    <?php if ($eq_nombre): ?>
                        <span class="badge bg-light text-dark border">
                            <?= htmlspecialchars($eq_cfg['emoji'] ?? '') ?>
                            Equipo <?= htmlspecialchars($eq_nombre) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ($a['iglesia']): ?>
                <div class="small text-muted mb-2">
                    <i class="fas fa-church"></i> <?= htmlspecialchars($a['iglesia']) ?>
                </div>
                <?php endif; ?>
                <!-- Botón voceado -->
                <form method="POST" action="marcar_voceado.php">
                    <input type="hidden" name="acampante_id" value="<?= $a['id'] ?>">
                    <input type="hidden" name="semana_id" value="<?= $semana_id_activa ?>">
                    <button type="submit" class="btn btn-success w-100"
                            onclick="return confirm('¿Confirmar que <?= htmlspecialchars(addslashes($a['nombre'])) ?> fue enviado a <?= htmlspecialchars(addslashes($a['nombre_cabana'])) ?>?')">
                        <i class="fas fa-bullhorn"></i>
                        Voceado · Enviado a cabaña
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Vista escritorio: tabla -->
    <div class="d-none d-md-block">
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Acampante</th>
                                <th>Edad / Sexo</th>
                                <th>Iglesia</th>
                                <th>Cabaña</th>
                                <th>Equipo</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($en_espera as $a):
                            $equipo = $a['equipo'] ?? null;
                            $eq_cfg = $equipo ? ($equipos_config[$equipo] ?? null) : null;
                            $eq_color  = $eq_cfg['color_hex'] ?? '#6c757d';
                            $eq_nombre = $eq_cfg['nombre'] ?? ucfirst($equipo ?? '');
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($a['nombre']) ?></td>
                            <td>
                                <?= $a['edad'] ?> años
                                <?= $a['sexo'] === 'masculino'
                                    ? '<i class="fas fa-mars text-primary ms-1"></i>'
                                    : '<i class="fas fa-venus text-danger ms-1"></i>' ?>
                            </td>
                            <td class="text-muted small"><?= htmlspecialchars($a['iglesia'] ?? '—') ?></td>
                            <td>
                                <span class="badge text-white"
                                      style="background-color: <?= htmlspecialchars($eq_color) ?>">
                                    <i class="fas fa-home me-1"></i>
                                    <?= htmlspecialchars($a['nombre_cabana']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($eq_nombre): ?>
                                <span class="badge bg-light text-dark border">
                                    <?= htmlspecialchars($eq_cfg['emoji'] ?? '') ?>
                                    <?= htmlspecialchars($eq_nombre) ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <form method="POST" action="marcar_voceado.php" class="d-inline">
                                    <input type="hidden" name="acampante_id" value="<?= $a['id'] ?>">
                                    <input type="hidden" name="semana_id" value="<?= $semana_id_activa ?>">
                                    <button type="submit" class="btn btn-success btn-sm"
                                            onclick="return confirm('¿<?= htmlspecialchars(addslashes($a['nombre'])) ?> fue enviado a <?= htmlspecialchars(addslashes($a['nombre_cabana'])) ?>?')">
                                        <i class="fas fa-bullhorn"></i> Voceado
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>