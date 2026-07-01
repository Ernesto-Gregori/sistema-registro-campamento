<?php
// encargado/logout.php
require_once '../config/database.php';
require_once '../includes/functions.php';

cerrarSesionEncargado();

$_SESSION['mensaje_exito'] = "Has salido del panel de encargado.";
header('Location: ../acceso-encargado.php');
exit();
