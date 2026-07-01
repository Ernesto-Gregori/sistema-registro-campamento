<?php
/**
 * acceso-encargado.php
 * Página pública de acceso para encargados de grupo.
 * Ruta: /acceso-encargado.php (raíz del proyecto)
 *
 * Seguridad:
 *  - No requiere login tradicional.
 *  - El encargado ingresa su nombre (como aparece en el grupo) y el código de acceso.
 *  - Se crea una sesión temporal con acceso sólo a su grupo.
 *  - Rate-limiting por IP (máx 10 intentos / 15 min).
 *  - Honey-pot anti-bot oculto.
 *  - POST/Redirect/GET para evitar reenvío al recargar.
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

// ── Configuración ───────────────────────────────────────────────────
define('MAX_INTENTOS', 10);
define('VENTANA_MIN',  15);   // minutos

// ── Helpers de seguridad ──────────────────────────────────────────
function obtenerIpCliente(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
    return substr(trim(explode(',', $ip)[0]), 0, 45);
}

function checkRateLimitAcceso(PDO $pdo, string $ip): bool {
    $ventana = date('Y-m-d H:i:s', strtotime('-' . VENTANA_MIN . ' minutes'));
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM sistema_logs
            WHERE ip = ? AND modulo = 'encargado_acceso'
              AND created_at >= ?
        ");
        $stmt->execute([$ip, $ventana]);
        return (int)$stmt->fetchColumn() < MAX_INTENTOS;
    } catch (\Exception $e) {
        return true;
    }
}

// ── Estado del formulario ────────────────────────────────────────────
define('APP_SECRET', defined('APP_SECRET') ? APP_SECRET : 'wol_camp_2025_secret_key');

function generarCsrfToken(): string {
    $secret = APP_SECRET;
    $ts     = time();
    $rand   = bin2hex(random_bytes(16));
    $sig    = hash_hmac('sha256', "$ts|$rand", $secret);
    return base64_encode("$ts|$rand|$sig");
}

function validarCsrfToken(string $token): bool {
    $secret  = APP_SECRET;
    $decoded = base64_decode($token, true);
    if (!$decoded) return false;
    $parts = explode('|', $decoded);
    if (count($parts) !== 3) return false;
    [$ts, $rand, $sig] = $parts;
    if ((time() - (int)$ts) > 3600) return false;
    $expected = hash_hmac('sha256', "$ts|$rand", $secret);
    return hash_equals($expected, $sig);
}

$csrf_token = generarCsrfToken();
$error = '';
$exito = false;

// ── Venimos de un redirect post-éxito ──────────────────────────────
if (isset($_GET['ok']) && $_GET['ok'] === '1') {
    $exito = true;
}

// ── Procesar POST ───────────────────────────────────────────────────
define('YEAR', obtenerAnioCampamento());

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$exito) {
    do {
        $ip_cliente = obtenerIpCliente();

        // 1. CSRF
        $token_post = trim($_POST['_csrf'] ?? '');
        if (!validarCsrfToken($token_post)) {
            $error = 'Sesión expirada. Recarga la página e inténtalo de nuevo.';
            break;
        }

        // 2. Honey-pot
        if (!empty($_POST['website'])) {
            header('Location: acceso-encargado.php?ok=1');
            exit;
        }

        // 3. Rate limit
        if (!checkRateLimitAcceso($pdo, $ip_cliente)) {
            $error = 'Demasiados intentos desde esta dirección. Espera unos minutos.';
            break;
        }

        // 4. Sanitización
        $nombre_encargado = trim($_POST['encargado_nombre'] ?? '');
        $codigo_acceso    = strtoupper(trim($_POST['codigo_acceso'] ?? ''));

        if (empty($nombre_encargado) || empty($codigo_acceso)) {
            $error = 'Ingresa tu nombre y el código de acceso.';
            break;
        }

        // 5. Buscar grupo
        try {
            $stmt = $pdo->prepare("
                SELECT g.*, s.nombre AS semana_nombre
                FROM grupos_campamento g
                LEFT JOIN semanas_campamento s ON s.id = g.semana_id
                WHERE g.codigo_acceso = ?
                  AND g.estado = 'activo'
                LIMIT 1
            ");
            $stmt->execute([$codigo_acceso]);
            $grupo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$grupo) {
                $error = 'Código de acceso no válido.';
                registrarLog($pdo, 'encargado_acceso_fallido',
                    "Código inválido: {$codigo_acceso} | IP: {$ip_cliente}",
                    'encargado_acceso', 'warning');
                break;
            }

            // Comparación de nombre: insensible a mayúsculas, acentos y espacios extra
            $nombre_bd  = mb_strtolower(preg_replace('/\s+/', ' ', trim($grupo['encargado_nombre'])));
            $nombre_in  = mb_strtolower(preg_replace('/\s+/', ' ', $nombre_encargado));

            if ($nombre_bd !== $nombre_in) {
                $error = 'El nombre no coincide con el registrado para este grupo.';
                registrarLog($pdo, 'encargado_acceso_fallido',
                    "Nombre no coincide. Código: {$codigo_acceso} | IP: {$ip_cliente}",
                    'encargado_acceso', 'warning');
                break;
            }

            // Éxito: crear sesión de encargado
            iniciarSesionEncargado($grupo);
            registrarLog($pdo, 'encargado_acceso_ok',
                "Acceso encargado: {$grupo['encargado_nombre']} (grupo ID {$grupo['id']}) | IP: {$ip_cliente}",
                'encargado_acceso', 'success');

            header('Location: encargado/panel.php');
            exit;

        } catch (\Exception $e) {
            error_log('[Acceso encargado] ' . $e->getMessage());
            $error = 'Ocurrió un error. Inténtalo de nuevo.';
        }

    } while (false);
}

// Si ya hay sesión válida, redirigir al panel
if (esSesionEncargadoGrupo()) {
    header('Location: encargado/panel.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Acceso Encargado — Campamento Palabra de Vida</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700;900&display=swap" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">

    <style>
        .ae-wrapper {
            min-height: 100vh;
            background: linear-gradient(160deg, #004f68 0%, #007ea1 60%, #73d1f5 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .ae-card {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            width: 100%;
            max-width: 460px;
            overflow: hidden;
        }
        .ae-header {
            padding: 2rem 1.5rem 1.5rem;
            text-align: center;
        }
        .ae-header .logo-icon {
            width: 72px; height: 72px;
            background: rgba(0,79,104,0.1);
            border: 2px solid rgba(0,79,104,0.2);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #004f68;
            margin-bottom: 1rem;
        }
        .ae-header h1 {
            color: #004f68;
            font-size: 1.5rem;
            font-weight: 900;
            margin-bottom: 0.25rem;
        }
        .ae-header .subtitle {
            color: #6c8494;
            font-size: 0.9rem;
        }
        .ae-body {
            padding: 0 1.75rem 1.75rem;
        }
        .ae-body .form-control {
            border-radius: 10px;
            padding: 0.7rem 1rem;
            border: 1.5px solid #d0d8df;
        }
        .ae-body .form-control:focus {
            border-color: #007ea1;
            box-shadow: 0 0 0 3px rgba(0,126,161,0.15);
        }
        .ae-body .form-label {
            font-weight: 600;
            font-size: 0.86rem;
            color: #3a4a54;
        }
        .btn-ae-submit {
            background: linear-gradient(135deg, #004f68 0%, #007ea1 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 0.85rem;
            font-weight: 700;
            width: 100%;
            transition: all 0.2s ease;
        }
        .btn-ae-submit:hover {
            background: linear-gradient(135deg, #003d52 0%, #005f7a 100%);
            color: #fff;
        }
        .ae-footer {
            text-align: center;
            padding: 1rem 1.5rem;
            background: #f8f9fa;
            font-size: 0.78rem;
            color: #6c8494;
        }
        .ae-footer a {
            color: #007ea1;
            text-decoration: none;
        }
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

<div class="ae-wrapper">
    <div class="ae-card">
        <div class="ae-header">
            <div class="logo-icon">
                <i class="fas fa-user-tie"></i>
            </div>
            <h1>Acceso para Encargados</h1>
            <p class="subtitle">Campamento Palabra de Vida · El Salvador</p>
        </div>

        <div class="ae-body">
            <?php if ($exito): ?>
            <div class="alert alert-success text-center">
                <i class="fas fa-check-circle me-2"></i>
                Acceso concedido. Redirigiendo...
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off" novalidate>
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">

                <div class="hp-field" aria-hidden="true" tabindex="-1">
                    <label for="website">No completar</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="encargado_nombre">
                        Nombre del encargado <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control" id="encargado_nombre"
                           name="encargado_nombre" required autofocus
                           placeholder="Tal como aparece registrado"
                           value="<?= htmlspecialchars($_POST['encargado_nombre'] ?? '') ?>">
                    <div class="form-text">
                        Usa el nombre con el que te registraron en admisiones.
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label" for="codigo_acceso">
                        Código de acceso <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control font-monospace text-uppercase"
                           id="codigo_acceso" name="codigo_acceso" required
                           placeholder="Ej: GRP-XXXXXX" maxlength="20"
                           value="<?= htmlspecialchars($_POST['codigo_acceso'] ?? '') ?>">
                    <div class="form-text">
                        Te lo proporcionó el equipo de admisiones.
                    </div>
                </div>

                <button type="submit" class="btn-ae-submit">
                    <i class="fas fa-sign-in-alt me-2"></i> Entrar a mi grupo
                </button>
            </form>
        </div>

        <div class="ae-footer">
            <a href="login.php"><i class="fas fa-lock me-1"></i> Acceso personal del equipo</a>
            &nbsp;·&nbsp;
            <a href="nuevo-ingreso.php"><i class="fas fa-user-plus me-1"></i> Inscribir acampante</a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
