<?php  
session_start();  
  
// Si ya está logueado, redirigir al dashboard correspondiente  
if (isset($_SESSION['user_id'])) {  
    if ($_SESSION['rol'] === 'administrador') {  
        header('Location: admin/dashboard.php');  
    } else {  
        header('Location: consejero/dashboard.php');  
    }  
    exit();  
}  
  
// Si no está logueado, redirigir al login  
header('Location: login.php');  
exit();  
?>  