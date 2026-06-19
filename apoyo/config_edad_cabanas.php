<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esApoyo()) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$titulo   = "Configurar Edades por Cabaña";
$message  = '';
$error    = '';

// Género de acceso del usuario actual
$stmt = $pdo->prepare("SELECT genero_acceso FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$genero_acceso = $stmt->fetch()['genero_acceso'] ?? 'ambos';

// Semana activa
$stmt       = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
$semana_act = $stmt->fetch();
$semana_id  = (int)($_GET['semana_id'] ?? $semana_act['id'] ?? 0) ?: null;

// Todas las semanas para el selector
$stmt            = $pdo->query("SELECT * FROM semanas_campamento ORDER BY fecha_inicio DESC");
$todasSemanas    = $stmt->fetchAll();

// Semana seleccionada
$semana_sel = null;
if ($semana_id) {
    $stmt = $pdo->prepare("SELECT * FROM semanas_campamento WHERE id = ?");
    $stmt->execute([$semana_id]);
    $semana_sel = $stmt->fetch();
}

// ── Procesar POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $semana_id) {
    try {
        $pdo->beginTransaction();

        $cabanas_post = $_POST['cabanas'] ?? [];

        foreach ($cabanas_post as $cabana_id => $vals) {
            $cabana_id = (int)$cabana_id;

            // Verificar que la cabaña pertenece al género de acceso del usuario
            if ($genero_acceso !== 'ambos') {
                $stmt = $pdo->prepare(
                    "SELECT id FROM cabanas WHERE id = ? AND genero = ? AND activa = 1"
                );
                $stmt->execute([$cabana_id, $genero_acceso]);
                if (!$stmt->fetch()) continue; // Saltar si no tiene acceso
            }

            $edad_min = $vals['edad_min'] !== '' ? (int)$vals['edad_min'] : null;
            $edad_max = $vals['edad_max'] !== '' ? (int)$vals['edad_max'] : null;
            $limpiar  = isset($vals['limpiar']) && $vals['limpiar'] == '1';

            if ($limpiar || ($edad_min === null && $edad_max === null)) {
                // Eliminar config específica: usará el rango base de la semana
                $stmt = $pdo->prepare("
                    DELETE FROM cabana_semana_config
                    WHERE cabana_id = ? AND semana_id = ?
                ");
                $stmt->execute([$cabana_id, $semana_id]);
            } else {
                // Validar que min <= max
                if ($edad_min !== null && $edad_max !== null && $edad_min > $edad_max) {
                    throw new Exception(
                        "La edad mínima no puede ser mayor que la máxima."
                    );
                }

                // UPSERT: insertar o actualizar config
                $stmt = $pdo->prepare("
                    INSERT INTO cabana_semana_config (cabana_id, semana_id, edad_min, edad_max)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        edad_min = VALUES(edad_min),
                        edad_max = VALUES(edad_max)
                ");
                $stmt->execute([$cabana_id, $semana_id, $edad_min, $edad_max]);
            }
        }

        $pdo->commit();
        $message = "Configuración de edades guardada correctamente.";

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// ── Cargar cabañas con su config actual ───────────────────────────────────
$cabanas = [];
if ($semana_id) {
    // Rango base de la semana
    $edad_min_sem = $semana_sel['edad_min'] ?? null;
    $edad_max_sem = $semana_sel['edad_max'] ?? null;

    // Config específica existente por cabaña para esta semana
    $stmt = $pdo->prepare("
        SELECT cabana_id, edad_min, edad_max
        FROM cabana_semana_config
        WHERE semana_id = ?
    ");
    $stmt->execute([$semana_id]);
    $config_existente = [];
    foreach ($stmt->fetchAll() as $row) {
        $config_existente[$row['cabana_id']] = $row;
    }

    // Cabañas filtradas por género de acceso
    if ($genero_acceso === 'ambos') {
        $stmt = $pdo->query("
            SELECT c.*,
                   COUNT(a.id) as ocupados
            FROM cabanas c
            LEFT JOIN acampantes a ON a.cabana_id = c.id
                AND a.semana_id = $semana_id
                AND a.estado = 'activo'
            WHERE c.activa = 1
            GROUP BY c.id
            ORDER BY c.genero, c.nombre_cabana
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT c.*,
                   COUNT(a.id) as ocupados
            FROM cabanas c
            LEFT JOIN acampantes a ON a.cabana_id = c.id
                AND a.semana_id = ?
                AND a.estado = 'activo'
            WHERE c.activa = 1 AND c.genero = ?
            GROUP BY c.id
            ORDER BY c.nombre_cabana
        ");
        $stmt->execute([$semana_id, $genero_acceso]);
    }

    foreach ($stmt->fetchAll() as $cab) {
        $cfg = $config_existente[$cab['id']] ?? null;
        $cab['config_propia']    = $cfg !== null;
        $cab['cfg_edad_min']     = $cfg['edad_min']     ?? null;
        $cab['cfg_edad_max']     = $cfg['edad_max']     ?? null;
        $cab['efectivo_edad_min'] = $cfg['edad_min']     ?? $edad_min_sem;
        $cab['efectivo_edad_max'] = $cfg['edad_max']     ?? $edad_max_sem;
        $cab['sem_edad_min']     = $edad_min_sem;
        $cab['sem_edad_max']     = $edad_max_sem;
        $cabanas[] = $cab;
    }
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-sliders-h"></i> <?= $titulo ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Edades por Cabaña</li>
            </ol>
        </nav>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> <?= $message ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?= $error ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Selector de semana -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="GET" class="d-flex align-items-center gap-3 flex-wrap">
            <label class="fw-semibold mb-0">
                <i class="fas fa-calendar-week"></i> Semana:
            </label>
            <select name="semana_id" class="form-select form-select-sm"
                    style="max-width:300px;" onchange="this.form.submit()">
                <option value="">-- Seleccionar semana --</option>
                <?php foreach ($todasSemanas as $sem): ?>
                <option value="<?= $sem['id'] ?>"
                        <?= $semana_id == $sem['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sem['nombre']) ?>
                    <?= $sem['activa'] ? ' ✓ ACTIVA' : '' ?>
                    (<?= date('d/m/Y', strtotime($sem['fecha_inicio'])) ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($semana_sel): ?>
            <span class="text-muted small">
                Rango base de esta semana:
                <strong>
                    <?= $semana_sel['edad_min'] ?? '—' ?>
                    –
                    <?= $semana_sel['edad_max'] ?? '—' ?>
                    años
                </strong>
            </span>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (!$semana_id): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    Selecciona una semana para ver y editar los rangos de edad de las cabañas.
</div>
<?php elseif (empty($cabanas)): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    No hay cabañas disponibles para tu acceso en esta semana.
</div>
<?php else: ?>

<!-- Explicación -->
<div class="alert alert-info border-0 py-2 mb-3">
    <i class="fas fa-info-circle"></i>
    <strong>Cómo funciona:</strong>
    Si una cabaña tiene un rango específico configurado, ese sobreescribe el rango base de la semana.
    Si dejas los campos en blanco o marcas "Usar base", se eliminará la config específica
    y se usará el rango de la semana.
</div>

<form method="POST" id="formEdades">
    <input type="hidden" name="semana_id" value="<?= $semana_id ?>">

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">
                <i class="fas fa-home"></i> Cabañas
                <?php if ($genero_acceso !== 'ambos'): ?>
                <span class="badge bg-<?= $genero_acceso === 'masculino' ? 'primary' : 'danger' ?> ms-2">
                    <i class="fas fa-<?= $genero_acceso === 'masculino' ? 'mars' : 'venus' ?>"></i>
                    <?= ucfirst($genero_acceso) ?>
                </span>
                <?php endif; ?>
                <span class="badge bg-secondary ms-1"><?= count($cabanas) ?></span>
            </h6>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-save"></i> Guardar Cambios
            </button>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Cabaña</th>
                            <th class="text-center">Ocupación</th>
                            <th class="text-center" style="min-width:110px;">
                                Rango base semana
                            </th>
                            <th class="text-center" style="min-width:120px;">
                                Edad mínima
                                <div class="fw-normal" style="font-size:10px;">
                                    (específica)
                                </div>
                            </th>
                            <th class="text-center" style="min-width:120px;">
                                Edad máxima
                                <div class="fw-normal" style="font-size:10px;">
                                    (específica)
                                </div>
                            </th>
                            <th class="text-center" style="min-width:100px;">
                                Rango efectivo
                            </th>
                            <th class="text-center">
                                Usar base
                                <div class="fw-normal" style="font-size:10px;">
                                    (borrar config)
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cabanas as $cab):
                        $icono_gen  = $cab['genero'] === 'masculino'
                            ? '<i class="fas fa-mars text-primary"></i>'
                            : '<i class="fas fa-venus text-danger"></i>';
                        $pct_ocup   = $cab['capacidad_maxima'] > 0
                            ? round(($cab['ocupados'] / $cab['capacidad_maxima']) * 100) : 0;
                        $color_barra = $pct_ocup >= 90 ? 'bg-danger'
                            : ($pct_ocup >= 70 ? 'bg-warning' : 'bg-success');
                        $tiene_cfg  = $cab['config_propia'];
                    ?>
                    <tr class="<?= $tiene_cfg ? 'table-warning' : '' ?>"
                        id="fila-<?= $cab['id'] ?>">

                        <!-- Nombre cabaña -->
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?= $icono_gen ?>
                                <div>
                                    <strong><?= htmlspecialchars($cab['nombre_cabana']) ?></strong>
                                    <?php if ($tiene_cfg): ?>
                                    <span class="badge bg-warning text-dark ms-1"
                                          style="font-size:9px;">
                                        Config propia
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($cab['consejero_principal']): ?>
                                    <div class="text-muted" style="font-size:11px;">
                                        <?= htmlspecialchars($cab['consejero_principal']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>

                        <!-- Ocupación -->
                        <td class="text-center">
                            <div><?= $cab['ocupados'] ?>/<?= $cab['capacidad_maxima'] ?></div>
                            <div class="progress mt-1" style="height:6px; width:80px; margin:auto;">
                                <div class="progress-bar <?= $color_barra ?>"
                                     style="width:<?= $pct_ocup ?>%;"></div>
                            </div>
                        </td>

                        <!-- Rango base semana -->
                        <td class="text-center">
                            <span class="badge bg-secondary">
                                <?= $cab['sem_edad_min'] ?? '—' ?>
                                –
                                <?= $cab['sem_edad_max'] ?? '—' ?>
                            </span>
                        </td>

                        <!-- Edad mínima específica -->
                        <td class="text-center">
                            <input type="number"
                                   class="form-control form-control-sm text-center edad-input"
                                   style="width:80px; margin:auto;"
                                   name="cabanas[<?= $cab['id'] ?>][edad_min]"
                                   id="min_<?= $cab['id'] ?>"
                                   value="<?= $cab['cfg_edad_min'] ?? '' ?>"
                                   min="1" max="99"
                                   placeholder="<?= $cab['sem_edad_min'] ?? '—' ?>">
                        </td>

                        <!-- Edad máxima específica -->
                        <td class="text-center">
                            <input type="number"
                                   class="form-control form-control-sm text-center edad-input"
                                   style="width:80px; margin:auto;"
                                   name="cabanas[<?= $cab['id'] ?>][edad_max]"
                                   id="max_<?= $cab['id'] ?>"
                                   value="<?= $cab['cfg_edad_max'] ?? '' ?>"
                                   min="1" max="99"
                                   placeholder="<?= $cab['sem_edad_max'] ?? '—' ?>">
                        </td>

                        <!-- Rango efectivo -->
                        <td class="text-center">
                            <span class="badge <?= $tiene_cfg ? 'bg-primary' : 'bg-secondary' ?>"
                                  id="efectivo_<?= $cab['id'] ?>">
                                <?= $cab['efectivo_edad_min'] ?? '—' ?>
                                –
                                <?= $cab['efectivo_edad_max'] ?? '—' ?>
                            </span>
                            <?php if ($tiene_cfg): ?>
                            <div style="font-size:9px;" class="text-primary mt-1">
                                sobreescrito
                            </div>
                            <?php else: ?>
                            <div style="font-size:9px;" class="text-muted mt-1">
                                base
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Limpiar config -->
                        <td class="text-center">
                            <div class="form-check d-flex justify-content-center">
                                <input type="checkbox"
                                       class="form-check-input chk-limpiar"
                                       name="cabanas[<?= $cab['id'] ?>][limpiar]"
                                       value="1"
                                       id="limpiar_<?= $cab['id'] ?>"
                                       data-cabana="<?= $cab['id'] ?>">
                            </div>
                            <?php if (!$tiene_cfg): ?>
                            <small class="text-muted" style="font-size:9px;">ya usa base</small>
                            <?php endif; ?>
                        </td>

                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer d-flex justify-content-between align-items-center">
            <small class="text-muted">
                <span class="badge bg-warning text-dark me-1">Config propia</span>
                La fila en amarillo indica que la cabaña tiene un rango específico.
                Las filas blancas usan el rango base de la semana.
            </small>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Guardar Cambios
            </button>
        </div>
    </div>
</form>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // Al marcar "Usar base", deshabilita los inputs de esa fila
    document.querySelectorAll('.chk-limpiar').forEach(function (chk) {
        chk.addEventListener('change', function () {
            const cabId  = this.dataset.cabana;
            const minInp = document.getElementById('min_' + cabId);
            const maxInp = document.getElementById('max_' + cabId);
            if (this.checked) {
                minInp.value    = '';
                maxInp.value    = '';
                minInp.disabled = true;
                maxInp.disabled = true;
            } else {
                minInp.disabled = false;
                maxInp.disabled = false;
            }
        });
    });

    // Validación al enviar: min <= max
    document.getElementById('formEdades').addEventListener('submit', function (e) {
        const filas = document.querySelectorAll('tbody tr');
        let hayError = false;

        filas.forEach(function (fila) {
            const id = fila.id.replace('fila-', '');
            if (!id) return;

            const minInp = document.getElementById('min_' + id);
            const maxInp = document.getElementById('max_' + id);
            if (!minInp || !maxInp) return;

            const min = parseInt(minInp.value);
            const max = parseInt(maxInp.value);

            minInp.classList.remove('is-invalid');
            maxInp.classList.remove('is-invalid');

            if (!isNaN(min) && !isNaN(max) && min > max) {
                minInp.classList.add('is-invalid');
                maxInp.classList.add('is-invalid');
                hayError = true;
            }
        });

        if (hayError) {
            e.preventDefault();
            const alerta = document.createElement('div');
            alerta.className = 'alert alert-danger mt-2';
            alerta.innerHTML =
                '<i class="fas fa-exclamation-triangle"></i> ' +
                'Hay rangos inválidos: la edad mínima no puede ser mayor que la máxima.';
            document.getElementById('formEdades').prepend(alerta);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>