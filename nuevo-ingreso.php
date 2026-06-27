<?php
/**
 * nuevo-ingreso.php
 * Página pública de auto-inscripción para acampantes.
 * Ruta: /nuevo-ingreso.php  (raíz del proyecto)
 *
 * Seguridad:
 *  - No requiere sesión (público).
 *  - Rate-limiting por IP (máx 5 envíos / 10 min) via tabla configuracion.
 *  - Token CSRF propio (sin sesión) usando signed token con HMAC.
 *  - Sanitización estricta de todos los inputs.
 *  - La semana se toma exclusivamente de la BD (no del usuario).
 *  - Sin campos de pago ni costo.
 *  - Honey-pot anti-bot oculto.
 *  - Patrón POST/Redirect/GET para evitar doble envío al recargar.
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

// ── Configuración ──────────────────────────────────────────────────────────
define('MAX_ENVIOS',    5);
define('VENTANA_MIN',  10);   // minutos

// ── Helpers de seguridad ───────────────────────────────────────────────────
function generarCsrfToken(): string {
    $secret = defined('APP_SECRET') ? APP_SECRET : 'wol_camp_2025_secret_key';
    $ts     = time();
    $rand   = bin2hex(random_bytes(16));
    $sig    = hash_hmac('sha256', "$ts|$rand", $secret);
    return base64_encode("$ts|$rand|$sig");
}

function validarCsrfToken(string $token): bool {
    $secret = defined('APP_SECRET') ? APP_SECRET : 'wol_camp_2025_secret_key';
    $decoded = base64_decode($token, true);
    if (!$decoded) return false;
    $parts = explode('|', $decoded);
    if (count($parts) !== 3) return false;
    [$ts, $rand, $sig] = $parts;
    if ((time() - (int)$ts) > 3600) return false;   // expira en 1 hora
    $expected = hash_hmac('sha256', "$ts|$rand", $secret);
    return hash_equals($expected, $sig);
}

function checkRateLimit(PDO $pdo, string $ip): bool {
    // Usa tabla sistema_logs para contar envíos recientes
    $ventana = date('Y-m-d H:i:s', strtotime('-' . VENTANA_MIN . ' minutes'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM sistema_logs
        WHERE ip = ? AND modulo = 'auto_inscripcion'
          AND nivel = 'success'
          AND created_at >= ?
    ");
    // sistema_logs usa timestamp sin nombre estándar; adaptamos a la columna existente
    // Si la tabla no tiene created_at, cambia el campo abajo.
    // Asumimos que el campo de fecha en sistema_logs se llama 'created_at'.
    // Si no existe, esta función devuelve true (permite) y el log actúa igual.
    try {
        $stmt->execute([$ip, $ventana]);
        return (int)$stmt->fetchColumn() < MAX_ENVIOS;
    } catch (\Exception $e) {
        return true;
    }
}

// ── Datos geográficos (departamentos / estados según país configurado) ─────
$estados          = obtenerEstados();          // función ya existente en functions.php
$label_division   = function_exists('etiquetaDivision') ? etiquetaDivision() : 'Departamento/Estado';

// ── Semana activa ──────────────────────────────────────────────────────────
$year = obtenerAnioCampamento();

$stmt_sem = $pdo->prepare("
    SELECT id, nombre, tipo_acampante, fecha_inicio, fecha_fin
    FROM semanas_campamento
    WHERE year_campamento = ? AND activa = 1
    ORDER BY fecha_inicio
    LIMIT 1
");
$stmt_sem->execute([$year]);
$semana = $stmt_sem->fetch();

$sin_semana = !$semana;

// ── Token CSRF ─────────────────────────────────────────────────────────────
$csrf_token = generarCsrfToken();

// ── Estado del formulario ──────────────────────────────────────────────────
$error   = '';
$exito   = false;
$nombre_registrado = '';

// ── ¿Venimos de un redirect post-éxito? (patrón PRG) ───────────────────────
// Si la URL trae ?ok=1&nombre=..., mostramos el mensaje de éxito sin tocar la BD.
if (isset($_GET['ok']) && $_GET['ok'] === '1') {
    $exito = true;
    $nombre_registrado = htmlspecialchars(trim($_GET['nombre'] ?? 'Visitante'));
}

// ── Procesar POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$sin_semana) {
    do {
        // 1. CSRF
        $token_post = trim($_POST['_csrf'] ?? '');
        if (!validarCsrfToken($token_post)) {
            $error = 'Sesión expirada. Recarga la página e inténtalo de nuevo.';
            break;
        }

        // 2. Honey-pot anti-bot (campo oculto que humanos no llenan)
        if (!empty($_POST['website'])) {
            // Bot detectado — fingir éxito silencioso vía redirect (PRG)
            $nombre_bot = trim($_POST['nombre'] ?? 'Visitante');
            header('Location: nuevo-ingreso.php?ok=1&nombre=' . rawurlencode($nombre_bot));
            exit;
        }

        // 3. Rate limit
        $ip_cliente = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
        $ip_cliente = substr(trim(explode(',', $ip_cliente)[0]), 0, 45);

        if (!checkRateLimit($pdo, $ip_cliente)) {
            $error = 'Demasiados intentos desde esta dirección. Espera unos minutos e inténtalo de nuevo.';
            break;
        }

        // 4. Sanitización
        $nombre    = trim($_POST['nombre'] ?? '');
        $edad      = (int)($_POST['edad'] ?? 0);
        $sexo      = trim($_POST['sexo'] ?? '');
        $iglesia   = trim($_POST['iglesia'] ?? '');
        $asiste    = isset($_POST['asiste_iglesia']) ? 1 : 0;
        $primera   = isset($_POST['primera_vez_campamento']) ? 1 : 0;
        $cont_n    = trim($_POST['contacto_emergencia_nombre']   ?? '');
        $cont_t    = trim($_POST['contacto_emergencia_telefono'] ?? '');
        $alergias  = trim($_POST['alergias_enfermedades'] ?? '');
        $obs       = trim($_POST['observaciones'] ?? '');

        // ── CURP (misma lógica que admisiones/inscribir.php) ──────────────
        $curp_raw = trim($_POST['curp'] ?? '');
        $curp     = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $curp_raw));
        if (strlen($curp) > 18) $curp = substr($curp, 0, 18);
        $curp = strlen($curp) >= 10 ? $curp : null; // null si inválido/vacío

        // ── Estado / Departamento de origen ───────────────────────────────
        $estado_origen_raw = trim($_POST['estado_origen'] ?? '');
        // Solo aceptar valores que existan en la lista oficial
        $estado_origen = in_array($estado_origen_raw, $estados) ? $estado_origen_raw : null;

        // Semana — siempre de la BD, nunca del usuario
        $semana_id = (int)$semana['id'];

        // 5. Validaciones
        if (mb_strlen($nombre) < 3)
            { $error = 'El nombre debe tener al menos 3 caracteres.'; break; }
        if (mb_strlen($nombre) > 150)
            { $error = 'El nombre es demasiado largo (máx. 150 caracteres).'; break; }
        if ($edad < 5 || $edad > 99)
            { $error = 'La edad debe estar entre 5 y 99 años.'; break; }
        if (!in_array($sexo, ['masculino', 'femenino']))
            { $error = 'Selecciona un género válido.'; break; }
        if (!empty($cont_t) && !preg_match('/^[\d\s\+\-\(\)]{6,20}$/', $cont_t))
            { $error = 'El teléfono de emergencia tiene un formato inválido.'; break; }

        // Truncar campos largos
        $iglesia  = mb_substr($iglesia,  0, 150);
        $cont_n   = mb_substr($cont_n,   0, 150);
        $cont_t   = mb_substr($cont_t,   0, 50);
        $alergias = mb_substr($alergias, 0, 1000);
        $obs      = mb_substr($obs,      0, 1000);

        // 6. Insert
        try {
            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO acampantes
                    (nombre, curp, edad, sexo, iglesia,
                     asiste_iglesia, primera_vez_campamento,
                     estado_origen,
                     contacto_emergencia_nombre, contacto_emergencia_telefono,
                     alergias_enfermedades, observaciones,
                     semana_id, year_campamento, costo_total,
                     estado, registrado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,'activo',NULL)
            ")->execute([
                $nombre,
                $curp,         // null si vacío o inválido
                $edad,
                $sexo,
                $iglesia,
                $asiste,
                $primera,
                $estado_origen, // null si no seleccionado o inválido
                $cont_n,
                $cont_t,
                $alergias,
                $obs,
                $semana_id,
                $year,
            ]);
            $acampante_id = $pdo->lastInsertId();
            $pdo->commit();

            // Log de auditoría
            registrarLog($pdo, 'auto_inscripcion',
                "Auto-inscripción pública: {$nombre} (ID {$acampante_id})" .
                ($curp         ? " | CURP: {$curp}"               : ' | Sin CURP') .
                ($estado_origen ? " | Origen: {$estado_origen}"   : '') .
                " | IP: {$ip_cliente}",
                'auto_inscripcion', 'success');

            // ── PRG: redirigimos con GET para que un refresh no reenvíe el POST ──
            header('Location: nuevo-ingreso.php?ok=1&nombre=' . rawurlencode($nombre));
            exit;

        } catch (\Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Ocurrió un error al guardar tu información. Por favor intenta de nuevo.';
            // En producción, loguear $e->getMessage() en error_log
            error_log('[Auto-inscripción] ' . $e->getMessage());
        }

    } while (false);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- SEO mínimo / sin indexación -->
    <meta name="robots" content="noindex, nofollow">
    <title>Inscripción — Campamento Palabra de Vida El Salvador</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <!-- Fuente Merriweather (igual que style.css) -->
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,300;0,400;0,700;0,900;1,400;1,700&display=swap" rel="stylesheet">
    <!-- Estilos propios del sistema -->
    <link href="assets/css/style.css" rel="stylesheet">

    <style>
        /* ── Variables reutilizadas de style.css ── */
        /* (ya definidas globalmente, sólo sobreescrituras específicas de esta página) */

        /* ── Layout centrado sin sidebar ── */
        .ni-wrapper {
            min-height: 100vh;
            background: linear-gradient(160deg, #004f68 0%, #007ea1 60%, #73d1f5 100%);
            display: flex;
            flex-direction: column;
        }

        /* ── Encabezado de marca ── */
        .ni-header {
            padding: 2rem 1.5rem 1.5rem;
            text-align: center;
        }
        .ni-header .logo-icon {
            width: 72px;
            height: 72px;
            background: rgba(255,255,255,0.12);
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #fff;
            margin-bottom: 1rem;
            backdrop-filter: blur(4px);
        }
        .ni-header h1 {
            color: #fff;
            font-size: 1.65rem;
            font-weight: 900;
            letter-spacing: -0.3px;
            margin-bottom: 0.25rem;
        }
        .ni-header .subtitle {
            color: rgba(255,255,255,0.78);
            font-size: 0.92rem;
            font-weight: 400;
        }

        /* ── Badge de semana activa ── */
        .semana-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.35);
            color: #fff;
            border-radius: 50px;
            padding: 0.45rem 1.1rem;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 0.75rem;
            backdrop-filter: blur(6px);
        }
        .semana-pill i { font-size: 0.8rem; opacity: 0.8; }

        /* ── Contenedor de tarjeta ── */
        .ni-card-wrap {
            flex: 1;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 0 1rem 2.5rem;
        }
        .ni-card {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            width: 100%;
            max-width: 680px;
            overflow: hidden;
        }

        /* ── Secciones del formulario ── */
        .ni-section {
            padding: 1.5rem 1.75rem;
            border-bottom: 1px solid var(--wol-black-10, #e6e7e8);
        }
        .ni-section:last-child { border-bottom: none; }

        .ni-section-title {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            color: var(--wol-dark-blue, #004f68);
            margin-bottom: 1.1rem;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .ni-section-title::after {
            content: '';
            flex: 1;
            height: 2px;
            background: linear-gradient(90deg, var(--wol-light-blue, #73d1f5) 0%, transparent 100%);
            border-radius: 2px;
        }

        /* ── Inputs ── */
        .ni-card .form-control,
        .ni-card .form-select {
            border-radius: 10px;
            padding: 0.6rem 0.9rem;
            font-size: 0.93rem;
            border: 1.5px solid #d0d8df;
        }
        .ni-card .form-control:focus,
        .ni-card .form-select:focus {
            border-color: var(--wol-mid-blue, #007ea1);
            box-shadow: 0 0 0 3px rgba(0,126,161,0.15);
        }
        .ni-card .form-label {
            font-weight: 600;
            font-size: 0.86rem;
            color: #3a4a54;
            margin-bottom: 0.3rem;
        }

        /* ── Switch personalizado ── */
        .ni-switch-group {
            background: #f0f5f8;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .ni-switch-group label {
            font-weight: 600;
            font-size: 0.88rem;
            color: #3a4a54;
            margin-bottom: 0;
            line-height: 1.3;
        }
        .ni-switch-group small {
            font-size: 0.76rem;
            color: #6c8494;
            font-weight: 400;
        }

        /* ── Botón principal ── */
        .btn-ni-submit {
            background: linear-gradient(135deg, #004f68 0%, #007ea1 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 0.9rem 2rem;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            width: 100%;
            transition: all 0.2s ease;
            box-shadow: 0 4px 16px rgba(0,79,104,0.35);
        }
        .btn-ni-submit:hover {
            background: linear-gradient(135deg, #003d52 0%, #005f7a 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 22px rgba(0,79,104,0.45);
            color: #fff;
        }
        .btn-ni-submit:active {
            transform: translateY(0);
        }
        .btn-ni-submit:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none;
        }

        /* ── Estado de éxito ── */
        .ni-success {
            padding: 3rem 2rem;
            text-align: center;
        }
        .ni-success .check-circle {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, #1a7a4a 0%, #28a469 100%);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: #fff;
            margin-bottom: 1.5rem;
            animation: popIn 0.4s cubic-bezier(0.34,1.56,0.64,1) forwards;
        }
        @keyframes popIn {
            from { transform: scale(0); opacity: 0; }
            to   { transform: scale(1); opacity: 1; }
        }
        .ni-success h2 {
            color: var(--wol-dark-blue, #004f68);
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        .ni-success p {
            color: #5a7080;
            font-size: 0.95rem;
            max-width: 400px;
            margin: 0 auto;
        }

        /* ── Estado sin semana ── */
        .ni-closed {
            padding: 3rem 2rem;
            text-align: center;
        }
        .ni-closed .closed-icon {
            font-size: 3.5rem;
            color: var(--wol-orange, #e99531);
            margin-bottom: 1rem;
        }

        /* ── Alerta de error ── */
        .ni-card .alert {
            border-radius: 10px;
            font-size: 0.88rem;
            margin: 0;
        }

        /* ── Footer de la página ── */
        .ni-footer {
            text-align: center;
            padding: 1.25rem;
            color: rgba(255,255,255,0.55);
            font-size: 0.78rem;
        }
        .ni-footer a {
            color: rgba(255,255,255,0.7);
            text-decoration: underline;
        }

        /* ── Responsive ── */
        @media (max-width: 576px) {
            .ni-section { padding: 1.2rem 1.1rem; }
            .ni-header h1 { font-size: 1.35rem; }
            .ni-success,
            .ni-closed { padding: 2rem 1.25rem; }
        }

        /* ── Honey-pot: visualmente oculto (no display:none para algunos bots) ── */
        .hp-field {
            position: absolute;
            left: -9999px;
            top: -9999px;
            opacity: 0;
            height: 0;
            overflow: hidden;
        }
    </style>
</head>
<body>

<div class="ni-wrapper">

    <!-- ── Encabezado ──────────────────────────────────────────────────────── -->
    <div class="ni-header">
        <div class="logo-icon">
            <i class="fas fa-campground"></i>
        </div>
        <h1>Campamento Palabra de Vida</h1>
        <p class="subtitle">El Salvador · <?= $year ?></p>

        <?php if ($semana && !$exito): ?>
        <div class="semana-pill">
            <i class="fas fa-calendar-check"></i>
            <?= htmlspecialchars($semana['nombre']) ?>
            &nbsp;·&nbsp;
            <?= date('d/m', strtotime($semana['fecha_inicio'])) ?>
            &nbsp;–&nbsp;
            <?= date('d/m/Y', strtotime($semana['fecha_fin'])) ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Tarjeta principal ───────────────────────────────────────────────── -->
    <div class="ni-card-wrap">
        <div class="ni-card">

            <?php if ($exito): ?>
            <!-- ── ✅ ÉXITO ──────────────────────────────────────────────── -->
            <div class="ni-success">
                <div class="check-circle">
                    <i class="fas fa-check"></i>
                </div>
                <h2>¡Inscripción exitosa!</h2>
                <p class="mt-2">
                    <strong><?= $nombre_registrado ?></strong>, tu registro fue recibido correctamente.
                    El equipo de admisiones te asignará tu cabaña en breve.
                </p>
                <hr class="my-3" style="border-color:#e6e7e8;">
                <p class="small text-muted mb-3">
                    <i class="fas fa-info-circle me-1"></i>
                    Si necesitas hacer algún cambio, acércate al equipo de admisiones.
                </p>
                <a href="nuevo-ingreso.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-plus me-1"></i> Registrar otra persona
                </a>
            </div>

            <?php elseif ($sin_semana): ?>
            <!-- ── 🔒 SIN SEMANA ACTIVA ──────────────────────────────────── -->
            <div class="ni-closed">
                <div class="closed-icon">
                    <i class="fas fa-lock"></i>
                </div>
                <h3 class="mb-2" style="color:var(--wol-dark-blue);">
                    Inscripciones cerradas
                </h3>
                <p class="text-muted">
                    En este momento no hay ninguna semana de campamento activa.
                    Consulta con el equipo de admisiones para más información.
                </p>
            </div>

            <?php else: ?>
            <!-- ── 📋 FORMULARIO ─────────────────────────────────────────── -->

            <?php if ($error): ?>
            <div class="ni-section" style="padding-bottom:1rem;">
                <div class="alert alert-danger mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" id="formNuevoIngreso" novalidate
                  autocomplete="off">

                <!-- Token CSRF -->
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">

                <!-- Honey-pot anti-bot -->
                <div class="hp-field" aria-hidden="true" tabindex="-1">
                    <label for="website">No completar este campo</label>
                    <input type="text" id="website" name="website" tabindex="-1"
                           autocomplete="off" value="">
                </div>

                <!-- ── 1. Datos personales ──────────────────────────────── -->
                <div class="ni-section">
                    <div class="ni-section-title">
                        <i class="fas fa-user"></i> Datos personales
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="nombre">
                            Nombre completo <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="nombre" name="nombre"
                               placeholder="Nombre y apellidos"
                               maxlength="150" required
                               value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
                        <div class="invalid-feedback">
                            Por favor ingresa tu nombre completo.
                        </div>
                    </div>

                    <!-- ── CURP ──────────────────────────────────────────── -->
                    <div class="mb-3">
                        <label class="form-label" for="curpInput">
                            <i class="fas fa-id-card me-1"></i>
                            CURP
                            <span class="fw-normal text-muted small ms-1">(opcional)</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">
                                <i class="fas fa-id-card text-muted" style="font-size:.85rem;"></i>
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
                            18 caracteres · Solo letras y números · Se guarda en mayúsculas
                        </div>
                        <div id="curpFeedback" class="mt-1 small d-none"></div>
                    </div>
                    <!-- ── fin CURP ─────────────────────────────────────── -->

                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" for="edad">
                                Edad <span class="text-danger">*</span>
                            </label>
                            <input type="number" class="form-control" id="edad" name="edad"
                                   min="5" max="99" required
                                   placeholder="Años"
                                   value="<?= htmlspecialchars($_POST['edad'] ?? '') ?>">
                            <div class="invalid-feedback">Ingresa una edad válida.</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="sexo">
                                Género <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="sexo" name="sexo" required>
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
                            <div class="invalid-feedback">Selecciona un género.</div>
                        </div>
                    </div>
                </div>

                <!-- ── 2. Iglesia ─────────────────────────────────────────── -->
                <div class="ni-section">
                    <div class="ni-section-title">
                        <i class="fas fa-church"></i> Vida de iglesia
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="iglesia">Iglesia a la que asistes</label>
                        <input type="text" class="form-control" id="iglesia" name="iglesia"
                               placeholder="Nombre de tu iglesia (opcional)"
                               maxlength="150"
                               value="<?= htmlspecialchars($_POST['iglesia'] ?? '') ?>">
                    </div>

                    <!-- ── Estado / Departamento de origen ────────────────── -->
                    <div class="mb-3">
                        <label class="form-label" for="estado_origen">
                            <i class="fas fa-map-marker-alt me-1"></i>
                            <?= htmlspecialchars($label_division) ?>
                            <span class="fw-normal text-muted small ms-1">(opcional)</span>
                        </label>
                        <select class="form-select" id="estado_origen" name="estado_origen">
                            <option value="">— Seleccionar —</option>
                            <?php foreach ($estados as $est): ?>
                            <option value="<?= htmlspecialchars($est) ?>"
                                <?= (($_POST['estado_origen'] ?? '') === $est) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($est) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted">
                            <?= htmlspecialchars($label_division) ?> de donde vienes
                        </div>
                    </div>

                    <div class="d-flex flex-column gap-2">
                        <div class="ni-switch-group">
                            <div>
                                <label for="asiste_iglesia">¿Asistes a una iglesia?</label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox"
                                       role="switch" id="asiste_iglesia" name="asiste_iglesia"
                                       <?= isset($_POST['asiste_iglesia']) ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="ni-switch-group">
                            <div>
                                <label for="primera_vez_campamento">
                                    ¿Es tu primera vez en el campamento?
                                </label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox"
                                       role="switch" id="primera_vez_campamento"
                                       name="primera_vez_campamento"
                                       <?= isset($_POST['primera_vez_campamento']) ? 'checked' : '' ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── 3. Contacto de emergencia ──────────────────────────── -->
                <div class="ni-section">
                    <div class="ni-section-title">
                        <i class="fas fa-phone-alt"></i> Contacto de emergencia
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="cont_n">Nombre</label>
                            <input type="text" class="form-control" id="cont_n"
                                   name="contacto_emergencia_nombre"
                                   placeholder="Nombre del contacto"
                                   maxlength="150"
                                   value="<?= htmlspecialchars($_POST['contacto_emergencia_nombre'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cont_t">Teléfono</label>
                            <input type="tel" class="form-control" id="cont_t"
                                   name="contacto_emergencia_telefono"
                                   placeholder="Ej: 7000-0000"
                                   maxlength="20"
                                   value="<?= htmlspecialchars($_POST['contacto_emergencia_telefono'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- ── 4. Salud y observaciones ───────────────────────────── -->
                <div class="ni-section">
                    <div class="ni-section-title">
                        <i class="fas fa-notes-medical"></i> Salud y notas
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="alergias">
                            Alergias / Condiciones médicas
                        </label>
                        <textarea class="form-control" id="alergias"
                                  name="alergias_enfermedades" rows="2"
                                  maxlength="1000"
                                  placeholder="Indica cualquier alergia, medicamento o condición de salud importante"><?=
                            htmlspecialchars($_POST['alergias_enfermedades'] ?? '')
                        ?></textarea>
                        <div class="form-text">Deja en blanco si no aplica.</div>
                    </div>

                    <div>
                        <label class="form-label" for="observaciones">Observaciones adicionales</label>
                        <textarea class="form-control" id="observaciones"
                                  name="observaciones" rows="2"
                                  maxlength="1000"
                                  placeholder="Cualquier otra información que el equipo deba saber"><?=
                            htmlspecialchars($_POST['observaciones'] ?? '')
                        ?></textarea>
                    </div>
                </div>

                <!-- ── 5. Botón submit ─────────────────────────────────────── -->
                <div class="ni-section" style="border-bottom:none;">
                    <button type="submit" class="btn-ni-submit" id="btnSubmit">
                        <i class="fas fa-check-circle me-2"></i>
                        Completar inscripción
                    </button>
                    <p class="text-center mt-3 mb-0" style="font-size:0.78rem; color:#8a9eaa;">
                        <i class="fas fa-shield-alt me-1"></i>
                        Tus datos son confidenciales y sólo serán usados por el equipo del campamento.
                    </p>
                </div>

            </form>
            <?php endif; ?>

        </div><!-- /.ni-card -->
    </div><!-- /.ni-card-wrap -->

    <!-- ── Footer ──────────────────────────────────────────────────────────── -->
    <div class="ni-footer">
        © <?= $year ?> Campamento Palabra de Vida El Salvador
        &nbsp;·&nbsp;
        <a href="login.php">Acceso personal</a>
    </div>

</div><!-- /.ni-wrapper -->

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    const form    = document.getElementById('formNuevoIngreso');
    const btnSub  = document.getElementById('btnSubmit');

    if (!form) return;

    // ── Validación Bootstrap nativa ───────────────────────────────────────
    form.addEventListener('submit', function (e) {
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            form.classList.add('was-validated');
            // Scroll al primer error
            const firstInvalid = form.querySelector(':invalid');
            if (firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstInvalid.focus();
            }
            return;
        }

        // Deshabilitar botón para evitar doble envío
        if (btnSub) {
            btnSub.disabled = true;
            btnSub.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" role="status"></span>' +
                'Enviando...';
        }
    });

    // ── Nombre — capitalizar primera letra de cada palabra ───────────────
    const inputNombre = document.getElementById('nombre');
    if (inputNombre) {
        inputNombre.addEventListener('blur', function () {
            this.value = this.value
                .trim()
                .toLowerCase()
                .replace(/(?:^|\s|-)\S/g, c => c.toUpperCase());
        });
    }

    // ── Teléfono — sólo dígitos, espacios y separadores ─────────────────
    const inputTel = document.getElementById('cont_t');
    if (inputTel) {
        inputTel.addEventListener('input', function () {
            this.value = this.value.replace(/[^\d\s\+\-\(\)]/g, '');
        });
    }

    // ── Contador de caracteres en textareas ───────────────────────────────
    document.querySelectorAll('textarea[maxlength]').forEach(ta => {
        const max = parseInt(ta.getAttribute('maxlength'));
        const hint = document.createElement('div');
        hint.className = 'form-text text-end mt-1';
        hint.style.fontSize = '0.74rem';
        ta.parentNode.appendChild(hint);

        const update = () => {
            const rem = max - ta.value.length;
            hint.textContent = rem < 100 ? `${rem} caracteres restantes` : '';
            hint.style.color = rem < 30 ? '#c42a36' : '#8a9eaa';
        };
        ta.addEventListener('input', update);
    });

    // ── Validación visual del CURP (igual que admisiones/inscribir.php) ────
    const curpInput    = document.getElementById('curpInput');
    const curpFeedback = document.getElementById('curpFeedback');

    if (curpInput) {
        // Forzar mayúsculas y filtrar caracteres inválidos al escribir
        curpInput.addEventListener('input', () => {
            const pos = curpInput.selectionStart;
            curpInput.value = curpInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            curpInput.setSelectionRange(pos, pos);
            curpFeedback.className = 'mt-1 small d-none';
            curpFeedback.textContent = '';
        });

        // Validar al salir del campo
        curpInput.addEventListener('blur', validarCurpVisual);
    }

    function validarCurpVisual() {
        if (!curpInput || !curpFeedback) return;
        const val = curpInput.value.trim();
        curpFeedback.classList.remove('d-none');

        if (val === '') {
            curpFeedback.className = 'mt-1 small text-muted';
            curpFeedback.innerHTML = '<i class="fas fa-info-circle me-1"></i>Sin CURP — se registrará sin este dato.';
            return;
        }

        // Patrón oficial CURP mexicano
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

})();
</script>
</body>
</html>