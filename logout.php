<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// ── LOG: logout ──
if (isset($_SESSION['user_id'])) {
    registrarLog($pdo, 'logout',
        "Sesión cerrada — usuario: " . ($_SESSION['username'] ?? 'desconocido'),
        'auth', 'info');
}

// Destruir sesión correctamente
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

header('Location: login.php');
exit();
?>