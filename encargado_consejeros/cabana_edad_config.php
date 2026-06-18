<?php
// encargado_consejeros/cabana_edad_config.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esEncargadoConsejeros()) {
    header('Location: ../consejero/dashboard.php'); exit();
}

$titulo    = "Límites de Edad por Cabaña";
$semana_id = (int)($_GET['semana_id'] ?? 0);
$message   = '';
$error     = '';

// Semanas disponibles
$semanas = $pdo->query("
    SELECT * FROM semanas_campamento ORDER BY year_campamento DESC, fecha_inicio ASC
")->fetchAll();

// Semana activa por defecto
if (!$semana_id && !empty($semanas)) {
    foreach ($semanas as $s) {
        if ($s['activa']) { $semana_id = (int)$s['id']; break; }
    }
    if (!$semana_id) $semana_id = (int)$semanas[0]['id'];
}

$semana = null;
foreach ($semanas as $s) {
    if ((int)$s['id'] === $semana_id) { $semana = $s; break; }
}

// ── Guardar configuración ────────────────────────────────────────────────────
if ($_POST && ($_POST['accion'] ?? '') === 'guardar') {
    try {
        $pdo->beginTransaction();

        foreach (($_POST['cabanas'] ?? []) as $cabana_id => $cfg) {
            $cabana_id = (int)$cabana_id;
            $emin = ($cfg['edad_min'] !== '') ? (int)$cfg['edad_min'] : null;
            $emax = ($cfg['edad_max'] !== '') ? (int)$cfg['edad_max'] : null;

            if ($emin !== null && $emax !== null && $emin > $emax)
                throw new Exception(
                    "Cabaña ID {$cabana_id}: edad mínima no puede superar la máxima."
                );

            if ($emin === null && $emax === null) {
                // Sin config propia: eliminar si existía
                $pdo->prepare("
                    DELETE FROM cabana_semana_config
                    WHERE cabana_id = ? AND semana_id = ?
                ")->execute([$cabana_id, $semana_id]);
            } else {
                // UPSERT
                $pdo->prepare("
                    INSERT INTO cabana_semana_config
                        (cabana_id, semana_id, edad_min, edad_max)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        edad_min = VALUES(edad_min),
                        edad_max = VALUES(edad_max)
                ")->execute([$cabana_id, $semana_id, $emin, $emax]);
            }
        }

        $pdo->commit();
        $message = "✅ Configuración guardada correctamente.";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Cargar cabañas con config actual
$cabanas = [];
if ($semana_id) {
    $stmt = $pdo->prepare("
        SELECT c.*,
               csc.edad_min AS cfg_min,
               csc.edad_max AS cfg_max
        FROM cabanas c
        LEFT JOIN cabana_semana_config csc
               ON csc.cabana_id = c.id AND csc.semana_id = ?
        WHERE c.activa = 1
        ORDER BY c.genero, c.nombre_cabana
    ");
    $stmt->execute([$semana_id]);
    $cabanas = $stmt->fetchAll();
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-id-badge text-warning"></i> <?php echo $titulo; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="semanas.php">Semanas</a></li>
                <li class="breadcrumb-item active">Edades por Cabaña</li>
            </ol>
        </nav>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
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
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="fw-bold text-muted small">
                <i class="fas fa-calendar-week"></i> Semana:
            </span>
            <?php foreach ($semanas as $s): ?>
            <a href="?semana_id=<?php echo $s['id']; ?>"
               class="btn btn-sm <?php echo $semana_id == $s['id'] ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                <?php echo htmlspecialchars($s['nombre']); ?>
                <?php echo $s['activa'] ? ' ✓' : ''; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($semana): ?>

<!-- Info semana seleccionada -->
<div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2 py-2 mb-3">
    <div>
        <i class="fas fa-calendar-week me-1"></i>
        <strong><?php echo htmlspecialchars($semana['nombre']); ?></strong>
        <?php if ($semana['edad_min'] || $semana['edad_max']): ?>
        — Límite general de la semana:
        <span class="badge bg-primary ms-1">
            <?php echo ($semana['edad_min'] ?? '—') . ' a ' . ($semana['edad_max'] ?? '—'); ?> años
        </span>
        <small class="text-muted d-block d-md-inline ms-md-1">
            (los límites por cabaña sobreescriben este valor)
        </small>
        <?php else: ?>
        <span class="text-muted ms-1">— Sin límite de edad general</span>
        <?php endif; ?>
    </div>
    <a href="semanas.php?action=edit&id=<?php echo $semana_id; ?>"
       class="btn btn-sm btn-outline-primary">
        <i class="fas fa-edit fa-xs"></i> Editar límite general
    </a>
</div>

<?php if (empty($cabanas)): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    No hay cabañas activas. Crea cabañas primero.
</div>
<?php else: ?>

<form method="POST">
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="semana_id" value="<?php echo $semana_id; ?>">

    <?php
    // Agrupar por género
    $grupos = [];
    foreach ($cabanas as $c) {
        $g = $c['genero'] ?? 'otro';
        $grupos[$g][] = $c;
    }
    $gl_map = [
        'masculino' => ['label' => 'Cabañas Masculinas', 'color' => 'primary',   'icon' => 'mars'],
        'femenino'  => ['label' => 'Cabañas Femeninas',  'color' => 'danger',    'icon' => 'venus'],
        'mixto'     => ['label' => 'Cabañas Mixtas',     'color' => 'secondary', 'icon' => 'users'],
        'otro'      => ['label' => 'Otras Cabañas',      'color' => 'dark',      'icon' => 'home'],
    ];
    ?>

    <?php foreach ($grupos as $genero => $cabs):
        $gl = $gl_map[$genero] ?? $gl_map['otro'];
    ?>
    <div class="card mb-4">
        <div class="card-header bg-<?php echo $gl['color']; ?> text-white">
            <h6 class="mb-0">
                <i class="fas fa-<?php echo $gl['icon']; ?>"></i>
                <?php echo $gl['label']; ?>
                <span class="badge bg-white text-dark ms-1"><?php echo count($cabs); ?></span>
            </h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Cabaña</th>
                        <th class="text-center">Capacidad</th>
                        <th style="min-width:170px;">Edad mínima</th>
                        <th style="min-width:170px;">Edad máxima</th>
                        <th class="text-center" style="min-width:130px;">Rango efectivo</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cabs as $cab):
                    $tiene_propia    = ($cab['cfg_min'] !== null || $cab['cfg_max'] !== null);
                    $emin_efectivo   = $cab['cfg_min'] ?? $semana['edad_min'];
                    $emax_efectivo   = $cab['cfg_max'] ?? $semana['edad_max'];
                ?>
                <tr>
                    <td class="ps-3">
                        <div class="fw-bold">
                            <?php echo htmlspecialchars($cab['nombre_cabana']); ?>
                        </div>
                        <small class="<?php echo $tiene_propia ? 'text-success' : 'text-muted'; ?>">
                            <i class="fas fa-<?php echo $tiene_propia ? 'check-circle' : 'arrow-up'; ?> fa-xs"></i>
                            <?php echo $tiene_propia
                                ? 'Configuración propia'
                                : ($emin_efectivo || $emax_efectivo ? 'Hereda de la semana' : 'Sin límite'); ?>
                        </small>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-secondary">
                            <?php echo $cab['capacidad_maxima'] ?? '—'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">
                                <i class="fas fa-child fa-xs text-muted"></i>
                            </span>
                            <input type="number"
                                   class="form-control edad-input"
                                   name="cabanas[<?php echo $cab['id']; ?>][edad_min]"
                                   min="0" max="99"
                                   placeholder="<?php echo $semana['edad_min']
                                       ? 'Semana: ' . $semana['edad_min']
                                       : 'Sin límite'; ?>"
                                   value="<?php echo $cab['cfg_min'] ?? ''; ?>"
                                   data-id="<?php echo $cab['id']; ?>"
                                   data-tipo="min"
                                   data-sem-min="<?php echo $semana['edad_min'] ?? ''; ?>"
                                   data-sem-max="<?php echo $semana['edad_max'] ?? ''; ?>">
                            <span class="input-group-text text-muted">años</span>
                        </div>
                    </td>
                    <td>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">
                                <i class="fas fa-user fa-xs text-muted"></i>
                            </span>
                            <input type="number"
                                   class="form-control edad-input"
                                   name="cabanas[<?php echo $cab['id']; ?>][edad_max]"
                                   min="0" max="99"
                                   placeholder="<?php echo $semana['edad_max']
                                       ? 'Semana: ' . $semana['edad_max']
                                       : 'Sin límite'; ?>"
                                   value="<?php echo $cab['cfg_max'] ?? ''; ?>"
                                   data-id="<?php echo $cab['id']; ?>"
                                   data-tipo="max"
                                   data-sem-min="<?php echo $semana['edad_min'] ?? ''; ?>"
                                   data-sem-max="<?php echo $semana['edad_max'] ?? ''; ?>">
                            <span class="input-group-text text-muted">años</span>
                        </div>
                    </td>
                    <td class="text-center" id="rango-<?php echo $cab['id']; ?>">
                        <?php if ($emin_efectivo !== null || $emax_efectivo !== null): ?>
                        <span class="badge bg-<?php echo $tiene_propia ? 'success' : 'secondary'; ?>">
                            <?php echo ($emin_efectivo ?? '—') . ' a ' . ($emax_efectivo ?? '—'); ?> años
                        </span>
                        <?php else: ?>
                        <span class="badge bg-light text-muted border">Sin límite</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Leyenda -->
    <div class="alert alert-light border small mb-3">
        <i class="fas fa-info-circle text-muted me-1"></i>
        <strong>Prioridad:</strong>
        config de cabaña (verde) sobreescribe el límite general de la semana (gris).
        Si una cabaña no tiene config propia, hereda el rango de la semana.
        Deja los campos en blanco para quitar el límite de una cabaña específica.
    </div>

    <div class="d-flex justify-content-between mb-4">
        <a href="semanas.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver a Semanas
        </a>
        <button type="submit" class="btn btn-success px-4">
            <i class="fas fa-save"></i> Guardar Configuración
        </button>
    </div>
</form>

<?php endif; ?>
<?php endif; ?>

<script>
document.querySelectorAll('.edad-input').forEach(input => {
    input.addEventListener('input', () => {
        const id      = input.dataset.id;
        const row     = input.closest('tr');
        const eminEl  = row.querySelector('[data-tipo="min"]');
        const emaxEl  = row.querySelector('[data-tipo="max"]');
        const semMin  = eminEl.dataset.semMin;
        const semMax  = eminEl.dataset.semMax;
        const emin    = eminEl.value !== '' ? parseInt(eminEl.value) : null;
        const emax    = emaxEl.value !== '' ? parseInt(emaxEl.value) : null;
        const rangoEl = document.getElementById('rango-' + id);

        // Validación visual
        if (emin !== null && emax !== null && emin > emax) {
            eminEl.classList.add('is-invalid');
            emaxEl.classList.add('is-invalid');
            rangoEl.innerHTML = '<span class="badge bg-danger">Min > Max</span>';
            return;
        }
        eminEl.classList.remove('is-invalid');
        emaxEl.classList.remove('is-invalid');

        // Calcular rango efectivo
        const efectMin = emin ?? (semMin !== '' ? parseInt(semMin) : null);
        const efectMax = emax ?? (semMax !== '' ? parseInt(semMax) : null);
        const tieneProp = emin !== null || emax !== null;

        if (efectMin !== null || efectMax !== null) {
            const color = tieneProp ? 'success' : 'secondary';
            rangoEl.innerHTML = `<span class="badge bg-${color}">
                ${efectMin ?? '—'} a ${efectMax ?? '—'} años
            </span>`;
        } else {
            rangoEl.innerHTML =
                '<span class="badge bg-light text-muted border">Sin límite</span>';
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>