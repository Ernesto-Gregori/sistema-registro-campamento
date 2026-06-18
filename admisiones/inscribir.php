<?php
// admisiones/inscribir.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador() && !esEncargadoConsejeros()) {
    header('Location: ../login.php');
    exit();
}

$titulo    = "Nueva Inscripción";
$year      = obtenerAnioCampamento();
$semana_id = $_GET['semana_id'] ?? null;
$error     = '';
$message   = '';

// Semanas activas
$stmt_sem = $pdo->prepare("
    SELECT * FROM semanas_campamento
    WHERE year_campamento = ? AND activa = 1
    ORDER BY fecha_inicio
");
$stmt_sem->execute([$year]);
$semanas = $stmt_sem->fetchAll();

// Costo de la semana preseleccionada
$costo_semana = 0;
if ($semana_id) {
    $stmt_c = $pdo->prepare("SELECT costo_campamento FROM semanas_campamento WHERE id = ?");
    $stmt_c->execute([$semana_id]);
    $costo_semana = (float)($stmt_c->fetchColumn() ?? 0);
}

// ── Procesar formulario ────────────────────────────────────────────────────
if ($_POST) {
    try {
        $nombre     = trim($_POST['nombre'] ?? '');
        $edad       = (int)($_POST['edad'] ?? 0);
        $sexo       = $_POST['sexo'] ?? '';
        $iglesia    = trim($_POST['iglesia'] ?? '');
        $asiste     = isset($_POST['asiste_iglesia']) ? 1 : 0;
        $primera    = isset($_POST['primera_vez_campamento']) ? 1 : 0;
        $contacto_n = trim($_POST['contacto_emergencia_nombre']   ?? '');
        $contacto_t = trim($_POST['contacto_emergencia_telefono'] ?? '');
        $alergias   = trim($_POST['alergias_enfermedades'] ?? '');
        $obs        = trim($_POST['observaciones'] ?? '');
        $sid        = (int)($_POST['semana_id'] ?? 0);
        $costo      = (float)($_POST['costo_total'] ?? 0);

        // ── CURP ──────────────────────────────────────────────────────
        // Misma lógica de sanitización que importar.php y editar.php
        $curp_raw = trim($_POST['curp'] ?? '');
        $curp     = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $curp_raw));
        if (strlen($curp) > 18) $curp = substr($curp, 0, 18);
        $curp = strlen($curp) >= 10 ? $curp : null; // null si inválido/vacío

        // ── Pago inicial ───────────────────────────────────────────────
        $monto_pago = (float)($_POST['monto_pago'] ?? 0);
        $modo_pago  = $_POST['modo_pago'] ?? 'efectivo';
        $notas_pago = trim($_POST['notas_pago'] ?? '');

        // ── Validaciones ───────────────────────────────────────────────
        if (empty($nombre)) throw new Exception("El nombre es obligatorio");
        if ($edad < 1)      throw new Exception("La edad es obligatoria");
        if (empty($sexo))   throw new Exception("El sexo es obligatorio");
        if ($sid < 1)       throw new Exception("Selecciona una semana");

        $pdo->beginTransaction();

        // ── INSERT acampante ───────────────────────────────────────────
        $pdo->prepare("
            INSERT INTO acampantes
                (nombre, curp, edad, sexo, iglesia,
                 asiste_iglesia, primera_vez_campamento,
                 contacto_emergencia_nombre, contacto_emergencia_telefono,
                 alergias_enfermedades, observaciones,
                 semana_id, year_campamento, costo_total,
                 estado, registrado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'activo',?)
        ")->execute([
            $nombre,
            $curp,          // null si vacío o inválido
            $edad,
            $sexo,
            $iglesia,
            $asiste,
            $primera,
            $contacto_n,
            $contacto_t,
            $alergias,
            $obs,
            $sid,
            $year,
            $costo,
            $_SESSION['user_id']
        ]);
        $acampante_id = $pdo->lastInsertId();

        // ── Pago inicial (opcional) ────────────────────────────────────
        if ($monto_pago > 0) {
            $pdo->prepare("
                INSERT INTO pagos_acampante
                    (acampante_id, monto, modo_pago, es_pago_registro, notas, registrado_por)
                VALUES (?, ?, ?, 1, ?, ?)
            ")->execute([$acampante_id, $monto_pago, $modo_pago, $notas_pago, $_SESSION['user_id']]);
        }

        $pdo->commit(); // ← commit ANTES de verificar (necesita datos confirmados en BD)

        // ── Check-in automático si el pago quedó completo ─────────────
        $checkin_auto = verificarYActivarCheckin($pdo, $acampante_id);

        registrarLog($pdo, 'acampante_inscrito',
            "Inscripción: {$nombre} (ID {$acampante_id})" .
            ($curp         ? " | CURP: {$curp}"          : ' | Sin CURP') .
            ($checkin_auto ? ' | ✅ Check-in automático'  : ''),
            'admisiones', 'success');

        $msg = "✅ {$nombre} inscrito correctamente";
        if ($checkin_auto) {
            $msg .= " — Check-in registrado automáticamente 🎉";
        }

        header("Location: lista_acampantes.php?semana_id={$sid}&message=" . urlencode($msg));
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

include '../includes/header.php';
?>

<!-- ── Cabecera ─────────────────────────────────────────────────────────── -->
<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-user-plus"></i> <?= $titulo ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item">
                        <a href="lista_acampantes.php<?= $semana_id ? "?semana_id=$semana_id" : '' ?>">
                            Lista
                        </a>
                    </li>
                    <li class="breadcrumb-item active">Nueva Inscripción</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<!-- ── Alerta error ──────────────────────────────────────────────────────── -->
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Formulario ───────────────────────────────────────────────────────── -->
<form method="POST" id="formInscribir">
<div class="row g-4">

    <!-- ── Columna izquierda — datos personales ────────────────────────── -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0"><i class="fas fa-user"></i> Datos Personales</h6>
            </div>
            <div class="card-body">

                <!-- Nombre -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre Completo *</label>
                    <input type="text" class="form-control" name="nombre" required
                           value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>"
                           placeholder="Nombre y apellidos">
                </div>

                <!-- CURP ────────────────────────────────────────────────── -->
                <div class="mb-3">
                    <label class="form-label fw-bold">
                        <i class="fas fa-id-card me-1"></i>CURP
                        <span class="text-muted fw-normal small ms-1">
                            (opcional — recomendado para verificación)
                        </span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text bg-light">
                            <i class="fas fa-id-card text-muted"></i>
                        </span>
                        <input type="text"
                               class="form-control font-monospace text-uppercase"
                               name="curp"
                               id="curpInput"
                               maxlength="18"
                               placeholder="Ej: HEPA071114MGTRRNA1"
                               value="<?= htmlspecialchars($_POST['curp'] ?? '') ?>"
                               autocomplete="off">
                        <button type="button"
                                class="btn btn-outline-secondary"
                                onclick="validarCurpVisual()"
                                title="Verificar formato">
                            <i class="fas fa-check-circle"></i>
                        </button>
                    </div>
                    <div class="form-text text-muted">
                        18 caracteres · Solo letras y números · Se guarda en mayúsculas automáticamente
                    </div>
                    <!-- Feedback visual inline -->
                    <div id="curpFeedback" class="mt-1 small d-none"></div>
                </div>
                <!-- ── fin CURP ─────────────────────────────────────────── -->

                <!-- Edad / Sexo -->
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Edad *</label>
                        <input type="number" class="form-control" name="edad"
                               min="5" max="99" required
                               value="<?= htmlspecialchars($_POST['edad'] ?? '') ?>">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Sexo *</label>
                        <select class="form-select" name="sexo" required>
                            <option value="">Seleccionar...</option>
                            <option value="masculino"
                                <?= ($_POST['sexo'] ?? '') === 'masculino' ? 'selected' : '' ?>>
                                ♂ Masculino
                            </option>
                            <option value="femenino"
                                <?= ($_POST['sexo'] ?? '') === 'femenino' ? 'selected' : '' ?>>
                                ♀ Femenino
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Switches iglesia / primera vez -->
                <div class="row">
                    <div class="col-6 mb-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox"
                                   name="asiste_iglesia" id="asiste_iglesia"
                                   <?= isset($_POST['asiste_iglesia']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="asiste_iglesia">
                                ¿Asiste a iglesia?
                            </label>
                        </div>
                    </div>
                    <div class="col-6 mb-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox"
                                   name="primera_vez_campamento" id="primera_vez"
                                   <?= isset($_POST['primera_vez_campamento']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="primera_vez">
                                ¿Primera vez?
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Iglesia -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Iglesia</label>
                    <input type="text" class="form-control" name="iglesia"
                           value="<?= htmlspecialchars($_POST['iglesia'] ?? '') ?>"
                           placeholder="Nombre de la iglesia">
                </div>

                <!-- Contacto emergencia -->
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Contacto de Emergencia</label>
                        <input type="text" class="form-control"
                               name="contacto_emergencia_nombre"
                               value="<?= htmlspecialchars($_POST['contacto_emergencia_nombre'] ?? '') ?>"
                               placeholder="Nombre del contacto">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Teléfono Emergencia</label>
                        <input type="text" class="form-control"
                               name="contacto_emergencia_telefono"
                               value="<?= htmlspecialchars($_POST['contacto_emergencia_telefono'] ?? '') ?>"
                               placeholder="Número telefónico">
                    </div>
                </div>

                <!-- Alergias -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Alergias / Enfermedades</label>
                    <textarea class="form-control" name="alergias_enfermedades" rows="2"
                              placeholder="Indicar si tiene alguna alergia o condición médica"><?=
                        htmlspecialchars($_POST['alergias_enfermedades'] ?? '')
                    ?></textarea>
                </div>

                <!-- Observaciones -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Observaciones</label>
                    <textarea class="form-control" name="observaciones" rows="2"
                              placeholder="Notas adicionales"><?=
                        htmlspecialchars($_POST['observaciones'] ?? '')
                    ?></textarea>
                </div>

            </div>
        </div>
    </div>

    <!-- ── Columna derecha — semana y pago ─────────────────────────────── -->
    <div class="col-md-5">

        <!-- Semana y costo -->
        <div class="card mb-3">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="fas fa-calendar-week"></i> Semana de Campamento</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Semana *</label>
                    <select class="form-select" name="semana_id" id="semana_id" required
                            onchange="actualizarCosto()">
                        <option value="">Seleccionar semana...</option>
                        <?php foreach ($semanas as $s): ?>
                        <option value="<?= $s['id'] ?>"
                                data-costo="<?= $s['costo_campamento'] ?>"
                                <?= ($semana_id == $s['id'] || ($_POST['semana_id'] ?? '') == $s['id'])
                                    ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['nombre']) ?>
                            — $<?= number_format($s['costo_campamento'], 0) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Costo Total *</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" class="form-control" name="costo_total"
                               id="costo_total" step="0.01" min="0" required
                               value="<?= $_POST['costo_total'] ?? $costo_semana ?>">
                    </div>
                    <small class="text-muted">
                        Se llena automático según la semana, editable si hay descuento
                    </small>
                </div>
            </div>
        </div>

        <!-- Pago en inscripción -->
        <div class="card mb-3">
            <div class="card-header bg-dark text-white">
                <h6 class="mb-0"><i class="fas fa-dollar-sign"></i> Pago en Inscripción</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Monto pagado ahora</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" class="form-control" name="monto_pago"
                               id="monto_pago" step="0.01" min="0" value="0"
                               oninput="actualizarSaldo()">
                    </div>
                    <small class="text-muted">Dejar en 0 si no paga en este momento</small>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Modo de Pago</label>
                    <select class="form-select" name="modo_pago">
                        <option value="efectivo">💵 Efectivo</option>
                        <option value="banco">🏦 Banco</option>
                        <option value="transferencia">📱 Transferencia</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Notas del pago</label>
                    <input type="text" class="form-control" name="notas_pago"
                           placeholder="Referencia, número de comprobante, etc.">
                </div>

                <!-- Resumen saldo -->
                <div class="p-3 rounded" style="background:#f8f9fa; border:1px solid #dee2e6;">
                    <div class="d-flex justify-content-between mb-1">
                        <small class="text-muted">Costo total:</small>
                        <small class="fw-bold" id="resumen_costo">$0</small>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <small class="text-muted">Pago hoy:</small>
                        <small class="fw-bold text-success" id="resumen_pago">$0</small>
                    </div>
                    <hr class="my-1">
                    <div class="d-flex justify-content-between">
                        <small class="fw-bold">Saldo pendiente:</small>
                        <small class="fw-bold text-danger" id="resumen_saldo">$0</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Botones -->
        <div class="d-flex gap-2">
            <a href="lista_acampantes.php<?= $semana_id ? "?semana_id=$semana_id" : '' ?>"
               class="btn btn-secondary flex-grow-1">
                <i class="fas fa-times"></i> Cancelar
            </a>
            <button type="submit" class="btn btn-success flex-grow-1">
                <i class="fas fa-save"></i> Inscribir
            </button>
        </div>

    </div>
</div>
</form>

<!-- ── JavaScript ───────────────────────────────────────────────────────── -->
<script>
// ── Costo automático por semana ────────────────────────────────────────────
const costosSemanas = {};
document.querySelectorAll('#semana_id option[data-costo]').forEach(opt => {
    costosSemanas[opt.value] = parseFloat(opt.dataset.costo) || 0;
});

function actualizarCosto() {
    const sid   = document.getElementById('semana_id').value;
    const costo = costosSemanas[sid] || 0;
    document.getElementById('costo_total').value = costo.toFixed(2);
    actualizarSaldo();
}

function actualizarSaldo() {
    const costo = parseFloat(document.getElementById('costo_total').value) || 0;
    const pago  = parseFloat(document.getElementById('monto_pago').value)  || 0;
    const saldo = Math.max(0, costo - pago);

    document.getElementById('resumen_costo').textContent = '$' + costo.toLocaleString('es-MX');
    document.getElementById('resumen_pago').textContent  = '$' + pago.toLocaleString('es-MX');
    document.getElementById('resumen_saldo').textContent = '$' + saldo.toLocaleString('es-MX');

    // Colorear el saldo dinámicamente
    const el = document.getElementById('resumen_saldo');
    el.className = 'fw-bold ' + (saldo === 0 ? 'text-success' : 'text-danger');
}

document.getElementById('costo_total').addEventListener('input', actualizarSaldo);

// ── Validación visual del CURP ─────────────────────────────────────────────
const curpInput    = document.getElementById('curpInput');
const curpFeedback = document.getElementById('curpFeedback');

// Forzar mayúsculas y filtrar caracteres inválidos al escribir
curpInput.addEventListener('input', () => {
    const pos = curpInput.selectionStart;
    curpInput.value = curpInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    curpInput.setSelectionRange(pos, pos);
    // Limpiar feedback mientras escribe
    curpFeedback.className = 'mt-1 small d-none';
    curpFeedback.textContent = '';
});

// Validar al salir del campo
curpInput.addEventListener('blur', validarCurpVisual);

function validarCurpVisual() {
    const val = curpInput.value.trim();
    curpFeedback.classList.remove('d-none');

    if (val === '') {
        curpFeedback.className = 'mt-1 small text-muted';
        curpFeedback.innerHTML = '<i class="fas fa-info-circle me-1"></i>Sin CURP — se registrará sin este dato.';
        return;
    }

    // Patrón oficial CURP mexicano: 4 letras + 6 dígitos + 6 alfanum + 2 alfanum
    const patron = /^[A-Z]{4}[0-9]{6}[A-Z0-9]{6}[A-Z0-9]{2}$/;

    if (val.length < 10) {
        curpFeedback.className = 'mt-1 small text-danger';
        curpFeedback.innerHTML = '<i class="fas fa-times-circle me-1"></i>Muy corto — mínimo 10 caracteres.';
    } else if (val.length === 18 && patron.test(val)) {
        curpFeedback.className = 'mt-1 small text-success';
        curpFeedback.innerHTML = '<i class="fas fa-check-circle me-1"></i>Formato válido ✓';
    } else if (val.length === 18) {
        curpFeedback.className = 'mt-1 small text-warning';
        curpFeedback.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Longitud correcta, pero el formato no coincide con el patrón estándar. Se guardará igual.';
    } else {
        curpFeedback.className = 'mt-1 small text-warning';
        curpFeedback.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i>${val.length}/18 caracteres — el CURP debe tener exactamente 18.`;
    }
}

// ── Init ───────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    actualizarCosto();
    actualizarSaldo();
});
</script>

<?php include '../includes/footer.php'; ?>