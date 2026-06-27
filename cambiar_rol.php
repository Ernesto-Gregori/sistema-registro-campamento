<?php

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

verificarLogin();

if (!isset($_GET['rol'])) {
    header('Location: default.php');
    exit();
}

$rol_nuevo = $_GET['rol'];
$user_id   = (int)$_SESSION['user_id'];

// Verificar que el rol solicitado esté entre los roles del usuario
$roles_permitidos = $_SESSION['roles'] ?? [];
if (!in_array($rol_nuevo, $roles_permitidos)) {
    registrarLog($pdo, 'cambio_rol_denegado',
        "Intento de cambiar a rol '{$rol_nuevo}' sin permiso",
        'auth', 'warning');
    header('Location: default.php');
    exit();
}

// Actualizar el rol activo en la sesión
$_SESSION['rol'] = $rol_nuevo;

registrarLog($pdo, 'cambio_rol',
    "Cambió rol activo a '{$rol_nuevo}'",
    'auth', 'info');

// Redirigir al dashboard del nuevo rol
$redirects = [
    'administrador'         => '/admin/dashboard.php',
    'encargado_consejeros'  => '/encargado_consejeros/dashboard.php',
    'administracion'        => '/administracion/dashboard.php',
    'consejero'             => '/consejero/dashboard.php',
    'apoyo'                 => '/apoyo/dashboard.php',
    'admisiones'            => '/admisiones/dashboard.php',
    'equipo'                => '/equipo/dashboard.php',
    'direccion_campamento'  => '/direccion/dashboard.php',
];

$destino = $redirects[$rol_nuevo] ?? '/default.php';
header("Location: {$destino}");
exit();