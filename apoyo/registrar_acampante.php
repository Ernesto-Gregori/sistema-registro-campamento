<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esApoyo()) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$titulo = "Registrar Acampante";
$message = '';
$error = '';

// Lista de estados/departamentos
$estados = obtenerEstados();

// Obtener semana activa
$stmt = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
$semana_activa = $stmt->fetch();
$semana_id_activa = $semana_activa['id'] ?? null;

if (!$semana_id_activa) {
    $error = "No hay semana activa. El administrador debe activar una semana antes de registrar acampantes.";
}

// Obtener genero_acceso del usuario actual
$stmt = $pdo->prepare("SELECT genero_acceso FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$usuario_actual = $stmt->fetch();
$genero_acceso = $usuario_actual['genero_acceso'] ?? 'ambos';

// Obtener cabañas según género permitido
if ($genero_acceso === 'ambos') {
    $stmt = $pdo->query("SELECT * FROM cabanas WHERE activa = 1 ORDER BY nombre_cabana");
} else {
    $stmt = $pdo->prepare("SELECT * FROM cabanas WHERE activa = 1 AND genero = ? ORDER BY nombre_cabana");
    $stmt->execute([$genero_acceso]);
}
$cabanas = $stmt->fetchAll();

// Obtener conteos por semana para JS
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
if ($_POST && $semana_id_activa) {
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
            throw new Exception("Debes seleccionar el sexo del acampante");
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
        
            // Validar que el usuario tenga permiso sobre este género de cabaña
            if ($genero_acceso !== 'ambos' && $cabana_data['genero'] !== $genero_acceso) {
                throw new Exception("No tienes permiso para registrar en cabañas de género {$cabana_data['genero']}");
            }

            // Validar capacidad en esta semana
            $stmt = $pdo->prepare("SELECT COUNT(*) as ocupados FROM acampantes 
                                   WHERE cabana_id = ? AND semana_id = ? AND estado = 'activo'");
            $stmt->execute([$cabana_id, $semana_id_activa]);
            $ocupados_cab = $stmt->fetch()['ocupados'];
    
            if ($ocupados_cab >= $cabana_data['capacidad_maxima']) {
                throw new Exception("La cabaña seleccionada está llena para esta semana");
            }
    
            // Validar rango de edad
            $edad_autorizada = isset($_POST['edad_autorizada']) ? 1 : 0;
            if (!empty($edad)) {
                $val_edad = validarEdadAcampante($pdo, (int)$edad, $semana_id_activa, $cabana_id);
                if (!$val_edad['valido'] && !$edad_autorizada) {
                    throw new Exception(
                        $val_edad['mensaje'] .
                        " Marca la casilla de autorización si deseas registrarlo de todas formas."
                    );
                }
            }
        }

        // Si llegamos aquí sin haber pasado por validación de cabaña, inicializar
        if (!isset($edad_autorizada)) $edad_autorizada = isset($_POST['edad_autorizada']) ? 1 : 0;

        // INSERT
        $stmt = $pdo->prepare("INSERT INTO acampantes 
                              (nombre, edad, edad_autorizada, sexo, iglesia, estado_origen, 
                               contacto_emergencia_nombre, contacto_emergencia_telefono,
                               alergias_enfermedades, observaciones,
                               cabana_id, semana_id, year_campamento, estado)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo')");
        $stmt->execute([
            $nombre, $edad, $edad_autorizada, $sexo, $iglesia, $estado_origen,
            $contacto_emergencia_nombre, $contacto_emergencia_telefono,
            $alergias_enfermedades, $observaciones,
            $cabana_id, $semana_id_activa, obtenerAnioCampamento()
        ]);

        $message = "Acampante registrado exitosamente";
        // Limpiar para nuevo registro
        unset($_POST);

    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Rangos de edad por cabaña para la semana activa → JavaScript
$rangosEdadJS = [];
try {
    if ($semana_id_activa) {
        // Límite base de la semana
        $stmt_sem_lim = $pdo->prepare("
            SELECT edad_min, edad_max FROM semanas_campamento WHERE id = ?
        ");
        $stmt_sem_lim->execute([$semana_id_activa]);
        $limSem = $stmt_sem_lim->fetch();
        $emin_sem = $limSem['edad_min'] ?? null;
        $emax_sem = $limSem['edad_max'] ?? null;

        // Config específica por cabaña para esta semana
        $stmt_csc = $pdo->prepare("
            SELECT cabana_id, edad_min, edad_max
            FROM cabana_semana_config
            WHERE semana_id = ?
        ");
        $stmt_csc->execute([$semana_id_activa]);
        $cfgCabana = [];
        foreach ($stmt_csc->fetchAll() as $row) {
            $cfgCabana[$row['cabana_id']] = [
                'min' => $row['edad_min'],
                'max' => $row['edad_max'],
            ];
        }

        // Construir mapa final para cada cabaña visible
        foreach ($cabanas as $cab) {
            $cabId = $cab['id'];
            $emin  = $emin_sem;
            $emax  = $emax_sem;

            // Config propia sobreescribe
            if (isset($cfgCabana[$cabId])) {
                if ($cfgCabana[$cabId]['min'] !== null) $emin = $cfgCabana[$cabId]['min'];
                if ($cfgCabana[$cabId]['max'] !== null) $emax = $cfgCabana[$cabId]['max'];
            }

            if ($emin !== null || $emax !== null) {
                $rangosEdadJS[$cabId] = [
                    'min'  => $emin,
                    'max'  => $emax,
                    'desc' => ''
                ];
            }
        }
    }
} catch (Exception $e) {
    $rangosEdadJS = [];
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-user-plus"></i> <?php echo $titulo; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Registrar Acampante</li>
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

<!-- Banner semana activa -->
<?php if ($semana_activa): ?>
<div class="alert alert-success border-0 mb-4">
    <i class="fas fa-broadcast-tower"></i>
    <strong>Semana activa:</strong> <?php echo htmlspecialchars($semana_activa['nombre']); ?>
    <span class="ms-2 text-muted">
        (<?php echo date('d/m/Y', strtotime($semana_activa['fecha_inicio'])); ?> -
        <?php echo date('d/m/Y', strtotime($semana_activa['fecha_fin'])); ?>)
    </span>
    — Los acampantes se registrarán automáticamente en esta semana.
</div>
<?php endif; ?>

<?php if (!$semana_id_activa): ?>
<div class="text-center py-5">
    <i class="fas fa-calendar-times fa-4x text-warning mb-3"></i>
    <h4>Sin semana activa</h4>
    <p class="text-muted">El administrador debe activar una semana para poder registrar acampantes.</p>
    <a href="dashboard.php" class="btn btn-secondary">Volver al Dashboard</a>
</div>
<?php else: ?>

<div class="card">
    <div class="card-header">
        <h5><i class="fas fa-user-plus"></i> Formulario de Registro</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="formRegistro" action="registrar_acampante.php">
            <div class="row">
                <!-- Columna 1: Datos personales -->
                <div class="col-md-6">
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-user"></i> Datos Personales
                    </h6>

                    <div class="mb-3">
                        <label class="form-label"><strong>Nombre Completo *</strong></label>
                        <input type="text" class="form-control" name="nombre" required
                               value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                               placeholder="Nombre y apellidos">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><strong>Edad</strong></label>
                            <input type="number" class="form-control" name="edad"
                                   id="campo_edad"
                                   value="<?php echo htmlspecialchars($_POST['edad'] ?? ''); ?>"
                                   min="1" max="100" placeholder="Años">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><strong>Sexo *</strong></label>
                            <select class="form-select" name="sexo" id="sexo" required>
                                <option value="">-- Seleccionar --</option>
                                <option value="masculino" <?php echo (($_POST['sexo'] ?? '') === 'masculino') ? 'selected' : ''; ?>>
                                    ♂ Masculino
                                </option>
                                <option value="femenino" <?php echo (($_POST['sexo'] ?? '') === 'femenino') ? 'selected' : ''; ?>>
                                    ♀ Femenino
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong>Iglesia *</strong></label>
                        <input type="text" class="form-control" name="iglesia" required
                               value="<?php echo htmlspecialchars($_POST['iglesia'] ?? ''); ?>"
                               placeholder="Nombre de la iglesia">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong><?php echo etiquetaDivision(); ?></strong></label>
                        <select class="form-select" name="estado_origen">
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($estados as $estado): ?>
                            <option value="<?php echo $estado; ?>"
                                    <?php echo (($_POST['estado_origen'] ?? '') === $estado) ? 'selected' : ''; ?>>
                                <?php echo $estado; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Alerta de rango de edad + autorización -->
                    <div id="bloque_rango_edad" class="mb-3" style="display:none;">
                        <div class="alert alert-warning py-2 small mb-2">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span id="texto_rango">La edad no está en el rango configurado.</span>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="edad_autorizada"
                                   name="edad_autorizada" value="1"
                                   <?php echo !empty($_POST['edad_autorizada']) ? 'checked' : ''; ?>>
                            <label class="form-check-label small fw-bold text-danger" for="edad_autorizada">
                                <i class="fas fa-user-check"></i>
                                Autorizo el ingreso aunque la edad esté fuera del rango
                            </label>
                        </div>
                    </div>

                    <h6 class="border-bottom pb-2 mb-3 mt-4">
                        <i class="fas fa-phone-alt"></i> Contacto de Emergencia
                    </h6>

                    <div class="mb-3">
                        <label class="form-label"><strong>Nombre del contacto</strong></label>
                        <input type="text" class="form-control" name="contacto_emergencia_nombre"
                               value="<?php echo htmlspecialchars($_POST['contacto_emergencia_nombre'] ?? ''); ?>"
                               placeholder="Nombre de la persona a contactar">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong>Teléfono de emergencia</strong></label>
                        <input type="text" class="form-control" name="contacto_emergencia_telefono"
                               value="<?php echo htmlspecialchars($_POST['contacto_emergencia_telefono'] ?? ''); ?>"
                               placeholder="Número de teléfono">
                    </div>
                </div>

                <!-- Columna 2: Salud y asignación -->
                <div class="col-md-6">
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-notes-medical"></i> Salud y Observaciones
                    </h6>

                    <div class="mb-3">
                        <label class="form-label"><strong>Alergias o Enfermedades</strong></label>
                        <textarea class="form-control" name="alergias_enfermedades" rows="3"
                                  placeholder="Describe cualquier alergia, enfermedad o condición médica relevante..."><?php echo htmlspecialchars($_POST['alergias_enfermedades'] ?? ''); ?></textarea>
                        <small class="text-muted">Deja en blanco si no tiene ninguna</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong>Observaciones</strong></label>
                        <textarea class="form-control" name="observaciones" rows="3"
                                  placeholder="Cualquier observación adicional..."><?php echo htmlspecialchars($_POST['observaciones'] ?? ''); ?></textarea>
                    </div>

                    <h6 class="border-bottom pb-2 mb-3 mt-4">
                        <i class="fas fa-home"></i> Asignación de Cabaña
                    </h6>

                    <div class="mb-3">
                        <label class="form-label"><strong>Cabaña Asignada *</strong></label>
                        <select class="form-select" name="cabana_id" id="cabana_id" required>
                            <option value="">-- Seleccionar cabaña --</option>
                            <?php foreach ($cabanas as $cab):
                                $ocupados_sem = $ocupadosPorSemana[$semana_id_activa][$cab['id']] ?? 0;
                                $disponibles = $cab['capacidad_maxima'] - $ocupados_sem;
                                $llena = $disponibles <= 0;
                                $icono = $cab['genero'] === 'masculino' ? '♂' : '♀';
                            ?>
                            <option value="<?php echo $cab['id']; ?>"
                                    data-genero="<?php echo $cab['genero']; ?>"
                                    data-capacidad="<?php echo $cab['capacidad_maxima']; ?>"
                                    data-ocupados="<?php echo $ocupados_sem; ?>"
                                    <?php echo (($_POST['cabana_id'] ?? '') == $cab['id']) ? 'selected' : ''; ?>
                                    <?php echo $llena ? 'class="text-danger"' : ''; ?>>
                                <?php echo htmlspecialchars($cab['nombre_cabana']); ?>
                                <?php echo $icono; ?> -
                                <?php echo $ocupados_sem; ?>/<?php echo $cab['capacidad_maxima']; ?>
                                <?php echo $llena ? '⚠ LLENA' : "($disponibles disponibles)"; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Solo se muestran cabañas activas. La ocupación es para esta semana.</small>
                    </div>

                    <!-- Barra de ocupación de cabaña -->
                    <div id="info_cabana" style="display:none;" class="mb-3">
                        <div class="progress mb-1" style="height:10px;">
                            <div id="barra_cabana" class="progress-bar" style="width:0%"></div>
                        </div>
                        <small id="texto_cabana" class="text-muted"></small>
                    </div>

                    <!-- Alerta de género -->
                    <div id="alerta_genero" class="alert alert-warning" style="display:none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Atención:</strong> El sexo del acampante no coincide con el género de esta cabaña.
                    </div>

                    <div class="alert alert-info mt-3">
                        <h6><i class="fas fa-info-circle"></i> Información</h6>
                        <ul class="small mb-0">
                            <li>El acampante se registrará en la semana activa automáticamente</li>
                            <li>Las cabañas muestran la ocupación de esta semana</li>
                            <li>No se puede asignar a una cabaña del género contrario</li>
                        </ul>
                    </div>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
                <div class="d-flex gap-2">
                    <button type="reset" class="btn btn-outline-secondary">
                        <i class="fas fa-eraser"></i> Limpiar
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Registrar Acampante
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<script>
window._formHasOwnHandler = true;
const rangosEdad = <?php echo json_encode($rangosEdadJS ?? []); ?>;

document.addEventListener('DOMContentLoaded', function() {
    const sexoSelect    = document.getElementById('sexo');
    const cabanaSelect  = document.getElementById('cabana_id');
    const infoCabana    = document.getElementById('info_cabana');
    const barraCabana   = document.getElementById('barra_cabana');
    const textoCabana   = document.getElementById('texto_cabana');
    const alertaGenero  = document.getElementById('alerta_genero');
    const campoEdad     = document.getElementById('campo_edad');
    const bloqueRango   = document.getElementById('bloque_rango_edad');
    const textoRango    = document.getElementById('texto_rango');
    const chkAutorizado = document.getElementById('edad_autorizada');

    function actualizarInfoCabana() {
        const selected = cabanaSelect.options[cabanaSelect.selectedIndex];
        if (!selected || !selected.value) {
            infoCabana.style.display = 'none';
            alertaGenero.style.display = 'none';
            return;
        }

        const capacidad    = parseInt(selected.dataset.capacidad) || 0;
        const ocupados     = parseInt(selected.dataset.ocupados)  || 0;
        const disponibles  = capacidad - ocupados;
        const pct          = capacidad > 0 ? (ocupados / capacidad) * 100 : 0;
        const generoCabana = selected.dataset.genero;
        const sexoActual   = sexoSelect.value;

        // Barra de ocupación
        let color = 'bg-success';
        if (pct >= 90) color = 'bg-danger';
        else if (pct >= 70) color = 'bg-warning';
        barraCabana.style.width   = Math.min(100, pct) + '%';
        barraCabana.className     = 'progress-bar ' + color;
        textoCabana.textContent   = `${ocupados}/${capacidad} acampantes esta semana · ${disponibles} lugar(es) disponible(s)`;
        textoCabana.className     = disponibles <= 0 ? 'text-danger small fw-bold' : 'text-muted small';
        infoCabana.style.display  = 'block';

        // Alerta género
        if (sexoActual && generoCabana && sexoActual !== generoCabana) {
            alertaGenero.style.display = 'block';
        } else {
            alertaGenero.style.display = 'none';
        }

        // Validar rango de edad al cambiar cabaña
        validarRangoEdad();
    }

    function validarRangoEdad() {
        if (!campoEdad || !bloqueRango) return;

        const cabanaId = cabanaSelect ? cabanaSelect.value : '';
        const edad     = parseInt(campoEdad.value) || 0;

        if (!cabanaId || !edad) {
            bloqueRango.style.display = 'none';
            return;
        }

        const rango = rangosEdad[cabanaId] || null;

        if (!rango) {
            bloqueRango.style.display = 'none';
            return;
        }

        const fueraDeRango = edad < rango.min || edad > rango.max;

        if (fueraDeRango) {
            const desc = rango.desc ? ` (${rango.desc})` : '';
            textoRango.textContent =
                `⚠️ La edad (${edad} años) está fuera del rango de esta cabaña: ` +
                `${rango.min}–${rango.max} años${desc}.`;
            bloqueRango.style.display = 'block';
        } else {
            bloqueRango.style.display = 'none';
            if (chkAutorizado) chkAutorizado.checked = false;
        }
    }

    cabanaSelect.addEventListener('change', actualizarInfoCabana);
    sexoSelect.addEventListener('change', actualizarInfoCabana);
    if (campoEdad) campoEdad.addEventListener('input', validarRangoEdad);

    // Ejecutar al cargar si ya hay valores (error de validación)
    if (cabanaSelect.value) actualizarInfoCabana();
    validarRangoEdad();
});

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('formRegistro');
    if (!form) return;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();

        // 1. Validaciones visuales básicas
        const nombre   = form.querySelector('[name="nombre"]').value.trim();
        const sexo     = form.querySelector('[name="sexo"]').value;
        const iglesia  = form.querySelector('[name="iglesia"]').value.trim();
        const cabana   = form.querySelector('[name="cabana_id"]').value;

        if (!nombre || !sexo || !iglesia || !cabana) {
            // Dejar que HTML5 validation muestre los errores
            form.reportValidity();
            return;
        }

        // 2. Spinner en botón
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registrando...';
        }

        // 3. Limpiar protección beforeunload
        if (typeof formChanged !== 'undefined') formChanged = false;
        if (typeof beforeUnloadHandler !== 'undefined') {
            window.removeEventListener('beforeunload', beforeUnloadHandler);
        }

        // 4. Enviar via fetch
        try {
            // Usar siempre la URL actual como destino del POST
            const actionUrl = window.location.pathname; // /apoyo/registrar_acampante.php
            const fetchResponse = await fetch(actionUrl, {
                method:      'POST',
                body:        new FormData(form),
                credentials: 'include'
            });

            const contentType = fetchResponse.headers.get('content-type') || '';

            // ── Respuesta JSON → offline o error API ──
            if (contentType.includes('application/json')) {
                const data = await fetchResponse.json();

                if (data.offline === true) {
                    // SW guardó offline → offline-sync.js mostrará el modal
                    console.log('[Form] Acampante guardado offline por SW');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-save"></i> Registrar Acampante';
                    }
                    return;
                }

                if (data.ok === false) {
                    throw new Error(data.error || 'Error del servidor');
                }
            }

            // ── Respuesta HTML exitosa → recargar para mostrar mensaje ──
            if (fetchResponse.ok || fetchResponse.redirected) {
                // Si hubo redirect → ir a esa URL
                if (fetchResponse.redirected) {
                    window.location.href = fetchResponse.url;
                    return;
                }

                // Si no hubo redirect → el mensaje de éxito está en el HTML
                // Recargar la página para mostrar el alert de PHP
                const html = await fetchResponse.text();
                document.open();
                document.write(html);
                document.close();
                return;
            }

            throw new Error(`Error HTTP ${fetchResponse.status}`);

        } catch (err) {
            console.error('[Form] Error en fetch:', err);

            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Registrar Acampante';
            }

            // Esperar brevemente por si el SW envía POST_FAILED_OFFLINE
            await new Promise(r => setTimeout(r, 500));

            // Solo mostrar error si sigue online (no fue problema de red)
            if (navigator.onLine && err.name !== 'TypeError') {
                if (typeof OfflineSync !== 'undefined') {
                    OfflineSync.mostrarToast('❌ Error al registrar. Intenta de nuevo.', 'error');
                }
            }
            // TypeError = Failed to fetch = sin red → SW manejará POST_FAILED_OFFLINE
        }

    }, true); // capture phase
});
</script>

<?php include '../includes/footer.php'; ?>