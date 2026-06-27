<?php  
// Configuración de la base de datos  
// Cambiar estos valores por los de tu hosting  
define('DB_HOST', 'localhost');  
define('DB_NAME', 'xxxxxxxxxxxxx'); // Cambiar por el nombre real  
define('DB_USER', 'xxxxxxxxxxxxxxx');    // Cambiar por tu usuario  
define('DB_PASS', 'xxxxxxxxxxxxxxx');   // Cambiar por tu contraseña  
  
try {  
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);  
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);  
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);  
} catch(PDOException $e) {  
    die("Error de conexión: " . $e->getMessage());  
}  

require_once __DIR__ . '/pais.php';
?>  