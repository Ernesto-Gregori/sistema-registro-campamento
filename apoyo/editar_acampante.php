<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esApoyo()) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$titulo = "Editar Acampante";
$id = $_GET['id'] ?? null;
$message = '';
$error = '';

if (!$id) {
    header('Location: lista_acampantes.php');
    exit();
}

// Lista de estados/departamentos
$estados = obtenerEstados();

// Obtener semana activa
$stmt_sem = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
$semana_activa = $stmt_sem->fetch();
$semana_id_activa = $semana_activa['id'] ?? null;

// Obtener acampante
try {
    $stmt = $pdo->prepare("SELECT * FROM acampantes WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$id]);
    $acampante = $stmt->fetch();

    if (!$acampante) {
        header('Location: lista_acampantes.php');
        exit();
    }
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}

// Obtener cabañas con ocupación por semana
$stmt = $pdo->query("SELECT c.*,
                     (SELECT COUNT(*) FROM acampantes a 
                      WHERE a.cabana_id = c.id AND a.estado = 'activo') as ocupados_total
                     FROM cabanas c WHERE c.activa = 1 ORDER BY c.nombre_cabana");
$cabanas = $stmt->fetchAll();

// Conteos por semana para JS
$stmt = $pdo->query("SELECT cabana_id, semana_id, COUNT(*) as ocupados 
                     FROM acampantes 
                     WHERE estado = 'activo' AND semana_id IS NOT NULL
                     GROUP BY cabana_id, semana_id");
$conteoPorSemana = $stmt->fetchAll();
$ocupadosPorSemana = [];
foreach ($conteoPorSemana as $row) {
    $ocupadosPorSemana[$row['semana_id']][$row['cabana_id']] = $row['ocupados'];
}

// Procesar formulario
if ($_POST) {
    try {
        $nombre = limpiarDatos($_POST['nombre']);
        $edad = !empty($_POST['edad']) ? (int)$_POST['edad'] : null;
        $sexo = $_POST['sexo'] ?? null;
        $iglesia = limpiarDatos($_POST['iglesia']);
        $estado_origen = limpiarDatos($_POST['estado_origen']);
        $contacto_emergencia_nombre = limpiarDatos($_POST['contacto_emergencia_nombre']);
        $contacto_emergencia_telefono = limpiarDatos($_POST['contacto_emergencia_telefono']);
        $alergias_enfermedades = limpiarDatos($_POST['alergias_enfermedades']);
        $observaciones = limpiarDatos($_POST['observaciones']);
        $cabana_id = !empty($_POST['cabana_id']) ? (int)$_POST['cabana_id'] : null;

        // Validaciones
        if (empty($nombre)) throw new Exception("El nombre es obligatorio");
        if (empty($sexo) || !in_array($sexo, ['masculino', 'femenino']))
            throw new Exception("Debes seleccionar el sexo");
        if (empty($iglesia)) throw new Exception("La iglesia es obligatoria");
        if (empty($cabana_id)) throw new Exception("Debes asignar una cabaña");

        // Validar género con cabaña
        if ($cabana_id) {
            $stmt = $pdo->prepare("SELECT genero, capacidad_maxima FROM cabanas WHERE id = ?");
            $stmt->execute([$cabana_id]);
            $cabana_data = $stmt->fetch();

            if ($cabana_data && $cabana_data['genero'] !== $sexo) {
                throw new Exception("No puedes asignar un acampante {$sexo} a una cabaña {$cabana_data['genero']}");
            }

            // Validar capacidad (excluyendo el acampante actual)
            $semana_check = $acampante['semana_id'] ?? $semana_id_activa;
            if ($semana_check && $cabana_id != $acampante['cabana_id']) {
                $stmt = $pdo->prepare("SELECT COUNT(*) as ocupados FROM acampantes 
                                       WHERE cabana_id = ? AND semana_id = ? AND estado = 'activo' AND id != ?");
                $stmt->execute([$cabana_id, $semana_check, $id]);
                $ocupados = $stmt->fetch()['ocupados'];
                if ($ocupados >= $cabana_data['capacidad_maxima']) {
                    throw new Exception("La cabaña seleccionada está llena para esta semana");
                }
            }
        }

        // UPDATE
        $stmt = $pdo->prepare("UPDATE acampantes SET
                              nombre = ?, edad = ?, sexo = ?, iglesia = ?,
                              estado_origen = ?,
                              contacto_emergencia_nombre = ?,
                              contacto_emergencia_telefono = ?,
                              alergias_enfermedades = ?,
                              observaciones = ?,
                              cabana_id = ?
                              WHERE id = ?");
        $stmt->execute([
            $nombre, $edad, $sexo, $iglesia, $estado_origen,
            $contacto_emergencia_nombre, $contacto_emergencia_telefono,
            $alergias_enfermedades, $observaciones,
            $cabana_id, $id
        ]);

        // Recargar datos actualizados
        $stmt = $pdo->prepare("SELECT * FROM acampantes WHERE id = ?");
        $stmt->execute([$id]);
        $acampante = $stmt->fetch();

        $message = "Acampante actualizado exitosamente";

    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-edit"></i> <?php echo $titulo; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="lista_acampantes.php">Lista de Acampantes</a></li>
                <li class="breadcrumb-item"><a href="ver_acampante.php?id=<?php echo $id; ?>">
                    <?php echo htmlspecialchars($acampante['nombre']); ?>
                </a></li>
                <li class="breadcrumb-item active">Editar</li>
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
    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Banner semana -->
<?php if ($semana_activa): ?>
<div class="alert alert-info border-0 mb-4">
    <i class="fas fa-broadcast-tower"></i>
    <strong>Semana activa:</strong> <?php echo htmlspecialchars($semana_activa['nombre']); ?>
    — La cabaña mostrará ocupación de esta semana.
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-edit"></i> Editando: <strong><?php echo htmlspecialchars($acampante['nombre']); ?></strong></h5>
        <a href="ver_acampante.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-eye"></i> Ver Detalle
        </a>
    </div>
    <div class="card-body">
        <form method="POST" id="formEditar">
            <div class="row">
                <!-- Columna 1: Datos personales -->
                <div class="col-md-6">
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-user"></i> Datos Personales
                    </h6>

                    <div class="mb-3">
                        <label class="form-label"><strong>Nombre Completo *</strong></label>
                        <input type="text" class="form-control" name="nombre" required
                               value="<?php echo htmlspecialchars($acampante['nombre']); ?>">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><strong>Edad</strong></label>
                            <input type="number" class="form-control" name="edad"
                                   value="<?php echo $acampante['edad'] ?? ''; ?>"
                                   min="1" max="100">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><strong>Sexo *</strong></label>
                            <select class="form-select" name="sexo" id="sexo" required>
                                <option value="">-- Seleccionar --</option>
                                <option value="masculino" <?php echo $acampante['sexo'] === 'masculino' ? 'selected' : ''; ?>>
                                    ♂ Masculino
                                </option>
                                <option value="femenino" <?php echo $acampante['sexo'] === 'femenino' ? 'selected' : ''; ?>>
                                    ♀ Femenino
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong>Iglesia *</strong></label>
                        <input type="text" class="form-control" name="iglesia" required
                               value="<?php echo htmlspecialchars($acampante['iglesia'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong><?php echo etiquetaDivision(); ?></strong></label>
                        <select class="form-select" name="estado_origen">
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($estados as $estado): ?>
                            <option value="<?php echo $estado; ?>"
                                    <?php echo ($acampante['estado_origen'] === $estado) ? 'selected' : ''; ?>>
                                <?php echo $estado; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <h6 class="border-bottom pb-2 mb-3 mt-4">
                        <i class="fas fa-phone-alt"></i> Contacto de Emergencia
                    </h6>

                    <div class="mb-3">
                        <label class="form-label"><strong>Nombre del contacto</strong></label>
                        <input type="text" class="form-control" name="contacto_emergencia_nombre"
                               value="<?php echo htmlspecialchars($acampante['contacto_emergencia_nombre'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong>Teléfono de emergencia</strong></label>
                        <input type="text" class="form-control" name="contacto_emergencia_telefono"
                               value="<?php echo htmlspecialchars($acampante['contacto_emergencia_telefono'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Columna 2: Salud y cabaña -->
                <div class="col-md-6">
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-notes-medical"></i> Salud y Observaciones
                    </h6>

                    <div class="mb-3">
                        <label class="form-label"><strong>Alergias o Enfermedades</strong></label>
                        <textarea class="form-control" name="alergias_enfermedades" rows="3"
                                  placeholder="Describe alergias, enfermedades o condiciones médicas..."><?php echo htmlspecialchars($acampante['alergias_enfermedades'] ?? ''); ?></textarea>
                        <small class="text-muted">Deja en blanco si no tiene ninguna</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong>Observaciones</strong></label>
                        <textarea class="form-control" name="observaciones" rows="3"><?php echo htmlspecialchars($acampante['observaciones'] ?? ''); ?></textarea>
                    </div>

                    <h6 class="border-bottom pb-2 mb-3 mt-4">
                        <i class="fas fa-home"></i> Asignación de Cabaña
                    </h6>

                    <div class="mb-3">
                        <label class="form-label"><strong>Cabaña Asignada *</strong></label>
                        <select class="form-select" name="cabana_id" id="cabana_id" required>
                            <option value="">-- Seleccionar cabaña --</option>
                            <?php foreach ($cabanas as $cab):
                                $semana_check = $acampante['semana_id'] ?? $semana_id_activa;
                                $ocupados_sem = $semana_check
                                    ? ($ocupadosPorSemana[$semana_check][$cab['id']] ?? 0)
                                    : 0;
                                // Si este es el acampante actual en esta cabaña, restar 1
                                if ($acampante['cabana_id'] == $cab['id'] && $semana_check == $acampante['semana_id']) {
                                    $ocupados_sem = max(0, $ocupados_sem);
                                }
                                $disponibles = $cab['capacidad_maxima'] - $ocupados_sem;
                                $llena = $disponibles <= 0 && $acampante['cabana_id'] != $cab['id'];
                                $icono = $cab['genero'] === 'masculino' ? '♂' : '♀';
                            ?>
                            <option value="<?php echo $cab['id']; ?>"
                                    data-genero="<?php echo $cab['genero']; ?>"
                                    data-capacidad="<?php echo $cab['capacidad_maxima']; ?>"
                                    data-ocupados="<?php echo $ocupados_sem; ?>"
                                    data-nombre-base="<?php echo htmlspecialchars($cab['nombre_cabana']); ?>"
                                    <?php echo ($acampante['cabana_id'] == $cab['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cab['nombre_cabana']); ?>
                                <?php echo $icono; ?> -
                                <?php echo $ocupados_sem; ?>/<?php echo $cab['capacidad_maxima']; ?>
                                <?php if ($acampante['cabana_id'] == $cab['id']): ?>
                                    ← Actual
                                <?php elseif ($llena): ?>
                                    ⚠ LLENA
                                <?php else: ?>
                                    (<?php echo $disponibles; ?> disponibles)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">La ocupación mostrada es para la semana del acampante</small>
                    </div>

                    <!-- Barra de ocupación -->
                    <div id="info_cabana" class="mb-3" style="display:none;">
                        <div class="progress mb-1" style="height:10px;">
                            <div id="barra_cabana" class="progress-bar" style="width:0%"></div>
                        </div>
                        <small id="texto_cabana" class="text-muted"></small>
                    </div>

                    <!-- Alerta género -->
                    <div id="alerta_genero" class="alert alert-warning" style="display:none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Atención:</strong> El sexo del acampante no coincide con el género de esta cabaña.
                    </div>

                    <!-- Info semana del acampante -->
                    <?php
                    $stmt = $pdo->prepare("SELECT nombre FROM semanas_campamento WHERE id = ?");
                    $stmt->execute([$acampante['semana_id']]);
                    $semana_acampante = $stmt->fetch();
                    ?>
                    <?php if ($semana_acampante): ?>
                    <div class="alert alert-secondary mt-3">
                        <i class="fas fa-calendar-week"></i>
                        <strong>Semana del acampante:</strong>
                        <?php echo htmlspecialchars($semana_acampante['nombre']); ?>
                        <br>
                        <small class="text-muted">Para cambiar la semana, contacta al administrador</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="ver_acampante.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Cancelar
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sexoSelect = document.getElementById('sexo');
    const cabanaSelect = document.getElementById('cabana_id');
    const infoCabana = document.getElementById('info_cabana');
    const barraCabana = document.getElementById('barra_cabana');
    const textoCabana = document.getElementById('texto_cabana');
    const alertaGenero = document.getElementById('alerta_genero');

    function actualizarInfoCabana() {
        const selected = cabanaSelect.options[cabanaSelect.selectedIndex];
        if (!selected || !selected.value) {
            infoCabana.style.display = 'none';
            alertaGenero.style.display = 'none';
            return;
        }

        const capacidad = parseInt(selected.dataset.capacidad) || 0;
        const ocupados = parseInt(selected.dataset.ocupados) || 0;
        const disponibles = capacidad - ocupados;
        const pct = capacidad > 0 ? (ocupados / capacidad) * 100 : 0;
        const generoCabana = selected.dataset.genero;
        const sexoActual = sexoSelect.value;

        let color = 'bg-success';
        if (pct >= 90) color = 'bg-danger';
        else if (pct >= 70) color = 'bg-warning';

        barraCabana.style.width = Math.min(100, pct) + '%';
        barraCabana.className = 'progress-bar ' + color;
        textoCabana.textContent = `${ocupados}/${capacidad} acampantes · ${disponibles} lugar(es) disponible(s)`;
        textoCabana.className = disponibles <= 0 ? 'text-danger small fw-bold' : 'text-muted small';
        infoCabana.style.display = 'block';

        if (sexoActual && generoCabana && sexoActual !== generoCabana) {
            alertaGenero.style.display = 'block';
        } else {
            alertaGenero.style.display = 'none';
        }
    }

    cabanaSelect.addEventListener('change', actualizarInfoCabana);
    sexoSelect.addEventListener('change', actualizarInfoCabana);

    // Ejecutar al cargar
    if (cabanaSelect.value) actualizarInfoCabana();
});
</script>

<?php include '../includes/footer.php'; ?>