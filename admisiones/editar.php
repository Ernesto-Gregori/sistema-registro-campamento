<?php
// admisiones/editar.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador() && !esEncargadoConsejeros()) {
    header('Location: ../login.php');
    exit();
}

$titulo    = "Editar Acampante";
$id        = (int)($_GET['id'] ?? 0);
$semana_id = $_GET['semana_id'] ?? null;
$error     = '';
$message   = '';

if (!$id) { header('Location: lista_acampantes.php'); exit(); }

$stmt = $pdo->prepare("SELECT * FROM acampantes WHERE id = ?");
$stmt->execute([$id]);
$acampante = $stmt->fetch();
if (!$acampante) { header('Location: lista_acampantes.php'); exit(); }

// Semanas disponibles
$stmt_sem = $pdo->prepare("SELECT * FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio");
$stmt_sem->execute([obtenerAnioCampamento()]);
$semanas = $stmt_sem->fetchAll();

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
        $costo      = (float)($_POST['costo_total'] ?? 0);
        $sid        = (int)($_POST['semana_id'] ?? $acampante['semana_id']);
        $estado     = $_POST['estado'] ?? 'activo';

        // ── CURP: sanitizar igual que en importar.php ──────────────
        $curp_raw = trim($_POST['curp'] ?? '');
        $curp     = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $curp_raw));
        if (strlen($curp) > 18) $curp = substr($curp, 0, 18);
        // Si tiene menos de 10 chars es inválido → guardar null
        $curp = strlen($curp) >= 10 ? $curp : null;

        // ── Validaciones ───────────────────────────────────────────
        if (empty($nombre)) throw new Exception("El nombre es obligatorio");
        if ($edad < 1)      throw new Exception("La edad es obligatoria");
        if (empty($sexo))   throw new Exception("El sexo es obligatorio");

        // Validar formato CURP si se proporcionó
        // Patrón oficial mexicano: 4 letras + 6 dígitos (fecha) + 6 alfanum
        if ($curp !== null && !preg_match('/^[A-Z]{4}[0-9]{6}[A-Z0-9]{6}[A-Z0-9]{2}$/', $curp)) {
            // No lanzar excepción: solo advertir y guardar igual
            // (hay CURPs de transición que no siguen el patrón estricto)
            $curp_advertencia = true;
        }

        $pdo->prepare("
            UPDATE acampantes SET
                nombre                       = ?,
                curp                         = ?,
                edad                         = ?,
                sexo                         = ?,
                iglesia                      = ?,
                asiste_iglesia               = ?,
                primera_vez_campamento       = ?,
                contacto_emergencia_nombre   = ?,
                contacto_emergencia_telefono = ?,
                alergias_enfermedades        = ?,
                observaciones                = ?,
                costo_total                  = ?,
                semana_id                    = ?,
                estado                       = ?
            WHERE id = ?
        ")->execute([
            $nombre,
            $curp,          // null si vacío/inválido
            $edad,
            $sexo,
            $iglesia,
            $asiste,
            $primera,
            $contacto_n,
            $contacto_t,
            $alergias,
            $obs,
            $costo,
            $sid,
            $estado,
            $id
        ]);

        registrarLog($pdo, 'acampante_editado',
            "Edición: {$nombre} (ID {$id})" . ($curp ? " | CURP: {$curp}" : ''),
            'admisiones', 'info');

        $message = "✅ Datos actualizados correctamente";

        // Recargar datos frescos del acampante
        $stmt = $pdo->prepare("SELECT * FROM acampantes WHERE id = ?");
        $stmt->execute([$id]);
        $acampante = $stmt->fetch();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$saldo_info = calcularSaldoAcampante($pdo, $id);

include '../includes/header.php';
?>

<!-- ── Cabecera ─────────────────────────────────────────────────────────── -->
<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-user-edit"></i> <?= $titulo ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item">
                        <a href="lista_acampantes.php?semana_id=<?= $semana_id ?>">Lista</a>
                    </li>
                    <li class="breadcrumb-item active">Editar</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            <?php if (!$acampante['documentos_revisados']): ?>
            <a href="marcar_docs.php?id=<?= $id ?>&semana_id=<?= $semana_id ?>"
               class="btn btn-outline-success btn-sm"
               onclick="return confirm('¿Confirmar que los documentos fueron verificados?')">
                <i class="fas fa-clipboard-check"></i> Marcar Docs OK
            </a>
            <?php else: ?>
            <span class="btn btn-success btn-sm disabled">
                <i class="fas fa-check-double"></i> Docs verificados
            </span>
            <?php endif; ?>
            <a href="lista_acampantes.php?semana_id=<?= $semana_id ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
</div>

<!-- ── Alertas ──────────────────────────────────────────────────────────── -->
<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <?= $message ?>
    <?php if (!empty($curp_advertencia)): ?>
    <br><small><i class="fas fa-exclamation-triangle"></i>
        El CURP ingresado no sigue el formato estándar mexicano, pero fue guardado.
        Verifícalo manualmente.
    </small>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Formulario ───────────────────────────────────────────────────────── -->
<form method="POST">
<div class="row g-4">

    <!-- ── Columna principal (datos personales) ────────────────────────── -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-user"></i> Datos Personales</h6>
            </div>
            <div class="card-body">

                <!-- Nombre -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre Completo *</label>
                    <input type="text" class="form-control" name="nombre" required
                           value="<?= htmlspecialchars($acampante['nombre']) ?>">
                </div>

                <!-- CURP ── campo nuevo ─────────────────────────────────── -->
                <div class="mb-3">
                    <label class="form-label fw-bold">
                        <i class="fas fa-id-card me-1"></i>CURP
                        <?php if (empty($acampante['curp'])): ?>
                        <span class="badge bg-warning text-dark ms-1">Sin registrar</span>
                        <?php else: ?>
                        <span class="badge bg-success ms-1">Registrado</span>
                        <?php endif; ?>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text bg-light">
                            <i class="fas fa-id-card text-muted"></i>
                        </span>
                        <input type="text"
                               class="form-control font-monospace text-uppercase"
                               name="curp"
                               maxlength="18"
                               placeholder="Ej: HEPA071114MGTRRNA1"
                               value="<?= htmlspecialchars($acampante['curp'] ?? '') ?>"
                               id="curpInput"
                               autocomplete="off">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="validarCurpVisual()"
                                title="Verificar formato">
                            <i class="fas fa-check-circle"></i>
                        </button>
                    </div>
                    <div class="form-text text-muted">
                        18 caracteres · Solo letras y números · Se guarda en mayúsculas automáticamente
                    </div>
                    <!-- Feedback visual CURP -->
                    <div id="curpFeedback" class="mt-1 small d-none"></div>
                </div>
                <!-- ── fin CURP ─────────────────────────────────────────── -->

                <!-- Edad / Sexo -->
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Edad *</label>
                        <input type="number" class="form-control" name="edad"
                               min="5" max="99" required
                               value="<?= $acampante['edad'] ?>">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Sexo *</label>
                        <select class="form-select" name="sexo" required>
                            <option value="masculino" <?= $acampante['sexo']==='masculino'?'selected':'' ?>>♂ Masculino</option>
                            <option value="femenino"  <?= $acampante['sexo']==='femenino' ?'selected':'' ?>>♀ Femenino</option>
                        </select>
                    </div>
                </div>

                <!-- Switches -->
                <div class="row">
                    <div class="col-6 mb-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox"
                                   name="asiste_iglesia" id="asiste_iglesia"
                                   <?= $acampante['asiste_iglesia'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="asiste_iglesia">
                                ¿Asiste a iglesia?
                            </label>
                        </div>
                    </div>
                    <div class="col-6 mb-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox"
                                   name="primera_vez_campamento" id="primera_vez"
                                   <?= $acampante['primera_vez_campamento'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="primera_vez">
                                ¿Primera vez?
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Iglesia -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Iglesia</label>
                    <input type="text" class="form-control" name="iglesia"
                           value="<?= htmlspecialchars($acampante['iglesia'] ?? '') ?>">
                </div>

                <!-- Contacto emergencia -->
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Contacto Emergencia</label>
                        <input type="text" class="form-control"
                               name="contacto_emergencia_nombre"
                               value="<?= htmlspecialchars($acampante['contacto_emergencia_nombre'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Teléfono</label>
                        <input type="text" class="form-control"
                               name="contacto_emergencia_telefono"
                               value="<?= htmlspecialchars($acampante['contacto_emergencia_telefono'] ?? '') ?>">
                    </div>
                </div>

                <!-- Alergias -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Alergias / Enfermedades</label>
                    <textarea class="form-control" name="alergias_enfermedades" rows="2"><?=
                        htmlspecialchars($acampante['alergias_enfermedades'] ?? '')
                    ?></textarea>
                </div>

                <!-- Observaciones -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Observaciones</label>
                    <textarea class="form-control" name="observaciones" rows="2"><?=
                        htmlspecialchars($acampante['observaciones'] ?? '')
                    ?></textarea>
                </div>

            </div>
        </div>
    </div>

    <!-- ── Columna derecha ─────────────────────────────────────────────── -->
    <div class="col-md-4">

        <!-- Semana y costo -->
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-calendar-week"></i> Semana y Costo</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Semana</label>
                    <select class="form-select" name="semana_id">
                        <?php foreach ($semanas as $s): ?>
                        <option value="<?= $s['id'] ?>"
                                <?= $acampante['semana_id'] == $s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Costo Total</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" class="form-control" name="costo_total"
                               step="0.01" min="0"
                               value="<?= $acampante['costo_total'] ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Estado</label>
                    <select class="form-select" name="estado">
                        <option value="activo"   <?= $acampante['estado']==='activo'  ?'selected':'' ?>>Activo</option>
                        <option value="inactivo" <?= $acampante['estado']==='inactivo'?'selected':'' ?>>Inactivo</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Estado de pago -->
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-dollar-sign"></i> Estado de Pago</h6>
            </div>
            <div class="card-body">
                <?php
                $pct = $saldo_info['costo_total'] > 0
                    ? min(100, round($saldo_info['total_pagado'] / $saldo_info['costo_total'] * 100))
                    : 0;
                $color = $saldo_info['pagado_100'] ? 'success'
                       : ($saldo_info['total_pagado'] > 0 ? 'warning' : 'danger');
                ?>
                <div class="progress mb-2" style="height:8px;">
                    <div class="progress-bar bg-<?= $color ?>"
                         style="width:<?= $pct ?>%"></div>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                    <span>Pagado:</span>
                    <span class="fw-bold text-success">
                        $<?= number_format($saldo_info['total_pagado'], 2) ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between small">
                    <span>Saldo:</span>
                    <span class="fw-bold text-<?= $color ?>">
                        $<?= number_format($saldo_info['saldo'], 2) ?>
                    </span>
                </div>
                <a href="pagos.php?id=<?= $id ?>&semana_id=<?= $semana_id ?>"
                   class="btn btn-outline-success btn-sm w-100 mt-3">
                    <i class="fas fa-history"></i> Ver historial de pagos
                </a>
            </div>
        </div>
        
        <!-- Documentos + Estado llegada -->
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fas fa-clipboard-check"></i> Documentos y Llegada
                </h6>
            </div>
            <div class="card-body text-center">
        
                <!-- Estado documentos -->
                <?php if ($acampante['documentos_revisados']): ?>
                    <span class="badge bg-success fs-6 px-3 py-2 d-block mb-1">
                        <i class="fas fa-check-double"></i> Documentos verificados
                    </span>
                    <small class="text-muted d-block mb-3">
                        <i class="fas fa-clock fa-xs me-1"></i>
                        <?= date('d/m/Y H:i', strtotime($acampante['documentos_revisados_at'])) ?>
                    </small>
                <?php else: ?>
                    <span class="badge bg-warning text-dark fs-6 px-3 py-2 d-block mb-2">
                        <i class="fas fa-hourglass-start"></i> Documentos pendientes
                    </span>
                    <a href="marcar_docs.php?id=<?= $id ?>&semana_id=<?= $semana_id ?>"
                       class="btn btn-outline-success btn-sm w-100 mb-3"
                       onclick="return confirm('¿Confirmar que los documentos de <?= htmlspecialchars($acampante['nombre'], ENT_QUOTES) ?> fueron verificados?')">
                        <i class="fas fa-clipboard-check"></i> Marcar documentos OK
                    </a>
                <?php endif; ?>
        
                <hr class="my-2">
        
                <!-- Estado llegada (solo lectura — check-in lo hace Administración) -->
                <?php if ($acampante['llego']): ?>
                    <span class="badge bg-primary fs-6 px-3 py-2 d-block mb-1">
                        <i class="fas fa-sign-in-alt"></i> Check-in realizado ✓
                    </span>
                    <?php if ($acampante['fecha_llegada']): ?>
                    <small class="text-muted">
                        <i class="fas fa-clock fa-xs me-1"></i>
                        <?= date('d/m/Y H:i', strtotime($acampante['fecha_llegada'])) ?>
                    </small>
                    <?php endif; ?>
                <?php elseif ($acampante['documentos_revisados']): ?>
                    <span class="badge bg-warning text-dark px-3 py-2 d-block">
                        <i class="fas fa-cash-register fa-xs"></i> En espera de pago (Administración)
                    </span>
                <?php else: ?>
                    <span class="badge bg-light text-muted border px-3 py-2 d-block">
                        <i class="fas fa-hourglass-start fa-xs"></i> Esperando revisión de docs
                    </span>
                <?php endif; ?>
        
            </div>
        </div>

        <!-- Guardar / Cancelar -->
        <div class="d-flex gap-2">
            <a href="lista_acampantes.php?semana_id=<?= $semana_id ?>"
               class="btn btn-secondary flex-grow-1">
                <i class="fas fa-times"></i> Cancelar
            </a>
            <button type="submit" class="btn btn-primary flex-grow-1">
                <i class="fas fa-save"></i> Guardar
            </button>
        </div>

    </div>
</div>
</form>

<!-- ── JS: validación visual del CURP en tiempo real ────────────────────── -->
<script>
const curpInput = document.getElementById('curpInput');
const curpFeedback = document.getElementById('curpFeedback');

// Forzar mayúsculas mientras escribe
curpInput.addEventListener('input', () => {
    const pos = curpInput.selectionStart;
    curpInput.value = curpInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    curpInput.setSelectionRange(pos, pos);
    // Limpiar feedback al escribir
    curpFeedback.className = 'mt-1 small d-none';
    curpFeedback.textContent = '';
});

// Validar al salir del campo
curpInput.addEventListener('blur', () => validarCurpVisual());

function validarCurpVisual() {
    const val = curpInput.value.trim();

    if (val === '') {
        curpFeedback.className = 'mt-1 small text-muted';
        curpFeedback.textContent = 'Sin CURP — se guardará vacío.';
        return;
    }

    // Patrón oficial CURP mexicano
    const patron = /^[A-Z]{4}[0-9]{6}[A-Z0-9]{6}[A-Z0-9]{2}$/;

    if (val.length < 10) {
        curpFeedback.className = 'mt-1 small text-danger';
        curpFeedback.innerHTML = '<i class="fas fa-times-circle me-1"></i>Muy corto — mínimo 10 caracteres válidos.';
    } else if (val.length === 18 && patron.test(val)) {
        curpFeedback.className = 'mt-1 small text-success';
        curpFeedback.innerHTML = '<i class="fas fa-check-circle me-1"></i>Formato válido ✓';
    } else if (val.length === 18) {
        curpFeedback.className = 'mt-1 small text-warning';
        curpFeedback.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Longitud correcta pero el formato no coincide con el patrón estándar. Se guardará igual.';
    } else {
        curpFeedback.className = 'mt-1 small text-warning';
        curpFeedback.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i>${val.length}/18 caracteres — el CURP debe tener exactamente 18.`;
    }

    curpFeedback.classList.remove('d-none');
}
</script>

<?php include '../includes/footer.php'; ?>