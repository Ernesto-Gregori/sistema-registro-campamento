<?php
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

// Semanas
$semanas = $pdo->prepare("SELECT * FROM semanas_campamento WHERE year_campamento = ? AND activa = 1 ORDER BY fecha_inicio");
$semanas->execute([$year]);
$semanas = $semanas->fetchAll();

// Costo de semana seleccionada
$costo_semana = 0;
if ($semana_id) {
    $stmt = $pdo->prepare("SELECT costo_campamento FROM semanas_campamento WHERE id = ?");
    $stmt->execute([$semana_id]);
    $costo_semana = (float)($stmt->fetchColumn() ?? 0);
}

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

        // Pago inicial
        $monto_pago  = (float)($_POST['monto_pago'] ?? 0);
        $modo_pago   = $_POST['modo_pago'] ?? 'efectivo';
        $notas_pago  = trim($_POST['notas_pago'] ?? '');

        if (empty($nombre))  throw new Exception("El nombre es obligatorio");
        if ($edad < 1)       throw new Exception("La edad es obligatoria");
        if (empty($sexo))    throw new Exception("El sexo es obligatorio");
        if ($sid < 1)        throw new Exception("Selecciona una semana");

        $pdo->beginTransaction();

        // Insertar acampante
        $stmt = $pdo->prepare("
            INSERT INTO acampantes 
                (nombre, edad, sexo, iglesia, asiste_iglesia, primera_vez_campamento,
                 contacto_emergencia_nombre, contacto_emergencia_telefono,
                 alergias_enfermedades, observaciones,
                 semana_id, year_campamento, costo_total, estado, registrado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'activo',?)
        ");
        $stmt->execute([
            $nombre, $edad, $sexo, $iglesia, $asiste, $primera,
            $contacto_n, $contacto_t, $alergias, $obs,
            $sid, $year, $costo, $_SESSION['user_id']
        ]);
        $acampante_id = $pdo->lastInsertId();

        // Registrar pago inicial si hay monto
        if ($monto_pago > 0) {
            $pdo->prepare("
                INSERT INTO pagos_acampante 
                    (acampante_id, monto, modo_pago, es_pago_registro, notas, registrado_por)
                VALUES (?, ?, ?, 1, ?, ?)
            ")->execute([$acampante_id, $monto_pago, $modo_pago, $notas_pago, $_SESSION['user_id']]);
        }

        $pdo->commit();

        registrarLog($pdo, 'acampante_inscrito',
            "Inscripción: {$nombre} — semana ID {$sid}",
            'admisiones', 'success');

        header("Location: lista_acampantes.php?semana_id={$sid}&message=" .
               urlencode("✅ {$nombre} inscrito correctamente"));
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h1><i class="fas fa-user-plus"></i> <?php echo $titulo; ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Nueva Inscripción</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<form method="POST" id="formInscribir">
<div class="row g-4">

    <!-- Columna izquierda — datos personales -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0"><i class="fas fa-user"></i> Datos Personales</h6>
            </div>
            <div class="card-body">

                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre Completo *</label>
                    <input type="text" class="form-control" name="nombre" required
                           value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                           placeholder="Nombre y apellidos">
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Edad *</label>
                        <input type="number" class="form-control" name="edad"
                               min="5" max="99" required
                               value="<?php echo htmlspecialchars($_POST['edad'] ?? ''); ?>">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Sexo *</label>
                        <select class="form-select" name="sexo" required>
                            <option value="">Seleccionar...</option>
                            <option value="masculino" <?php echo ($_POST['sexo']??'')==='masculino'?'selected':''; ?>>
                                ♂ Masculino
                            </option>
                            <option value="femenino" <?php echo ($_POST['sexo']??'')==='femenino'?'selected':''; ?>>
                                ♀ Femenino
                            </option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox"
                                   name="asiste_iglesia" id="asiste_iglesia"
                                   <?php echo isset($_POST['asiste_iglesia']) ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="asiste_iglesia">
                                ¿Asiste a iglesia?
                            </label>
                        </div>
                    </div>
                    <div class="col-6 mb-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox"
                                   name="primera_vez_campamento" id="primera_vez"
                                   <?php echo isset($_POST['primera_vez_campamento']) ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="primera_vez">
                                ¿Primera vez en el campamento?
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Iglesia</label>
                    <input type="text" class="form-control" name="iglesia"
                           value="<?php echo htmlspecialchars($_POST['iglesia'] ?? ''); ?>"
                           placeholder="Nombre de la iglesia">
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Contacto de Emergencia</label>
                        <input type="text" class="form-control" name="contacto_emergencia_nombre"
                               value="<?php echo htmlspecialchars($_POST['contacto_emergencia_nombre'] ?? ''); ?>"
                               placeholder="Nombre del contacto">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Teléfono Emergencia</label>
                        <input type="text" class="form-control" name="contacto_emergencia_telefono"
                               value="<?php echo htmlspecialchars($_POST['contacto_emergencia_telefono'] ?? ''); ?>"
                               placeholder="Número telefónico">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Alergias / Enfermedades</label>
                    <textarea class="form-control" name="alergias_enfermedades" rows="2"
                              placeholder="Indicar si tiene alguna alergia o condición médica"><?php
                        echo htmlspecialchars($_POST['alergias_enfermedades'] ?? '');
                    ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Observaciones</label>
                    <textarea class="form-control" name="observaciones" rows="2"
                              placeholder="Notas adicionales"><?php
                        echo htmlspecialchars($_POST['observaciones'] ?? '');
                    ?></textarea>
                </div>

            </div>
        </div>
    </div>

    <!-- Columna derecha — semana y pago -->
    <div class="col-md-5">

        <!-- Semana -->
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
                        <option value="<?php echo $s['id']; ?>"
                                data-costo="<?php echo $s['costo_campamento']; ?>"
                                <?php echo ($semana_id == $s['id'] || ($_POST['semana_id']??'') == $s['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['nombre']); ?>
                            — $<?php echo number_format($s['costo_campamento'],0); ?>
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
                               value="<?php echo $_POST['costo_total'] ?? $costo_semana; ?>">
                    </div>
                    <small class="text-muted">Se llena automático según la semana, editable si hay descuento</small>
                </div>
            </div>
        </div>

        <!-- Pago inicial -->
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
                <div class="p-3 rounded" style="background:#f8f9fa;border:1px solid #dee2e6;">
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
            <a href="lista_acampantes.php<?php echo $semana_id ? "?semana_id=$semana_id" : ''; ?>"
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

<script>
// Costos por semana
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

    document.getElementById('resumen_costo').textContent = '$' + costo.toLocaleString('es');
    document.getElementById('resumen_pago').textContent  = '$' + pago.toLocaleString('es');
    document.getElementById('resumen_saldo').textContent = '$' + saldo.toLocaleString('es');
}

document.getElementById('costo_total').addEventListener('input', actualizarSaldo);
document.addEventListener('DOMContentLoaded', () => {
    actualizarCosto();
    actualizarSaldo();
});
</script>

<?php include '../includes/footer.php'; ?>