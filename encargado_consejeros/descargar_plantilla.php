<?php  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
if (!esEncargadoConsejeros()) { 
    header('Location: ../consejero/dashboard.php');  
    exit();  
}  
  
$tipo = $_GET['tipo'] ?? 'excel';  
  
if ($tipo === 'csv') {  
    // Generar CSV  
    header('Content-Type: text/csv; charset=utf-8');  
    header('Content-Disposition: attachment; filename="plantilla_acampantes.csv"');  
      
    $output = fopen('php://output', 'w');  
      
    // BOM para UTF-8 en Excel  
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));  
      
    // Encabezados  
    fputcsv($output, ['Nombre', 'Iglesia', 'Contacto', 'Cabaña']);  
      
    // Filas de ejemplo  
    fputcsv($output, ['Juan Pérez', 'Iglesia Central', '7777-7777', 'Cabaña 1']);  
    fputcsv($output, ['María González', 'Iglesia del Este', '7888-8888', 'Cabaña 2']);  
    fputcsv($output, ['Carlos Rodríguez', 'Iglesia del Norte', '7999-9999', '']);  
      
    fclose($output);  
    exit();  
      
} else {  
    // Generar Excel básico (formato HTML que Excel puede abrir)  
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');  
    header('Content-Disposition: attachment; filename="plantilla_acampantes.xlsx"');  
      
    echo "\xEF\xBB\xBF"; // BOM para UTF-8  
    ?>  
    <html xmlns:o="urn:schemas-microsoft-com:office:office"  
          xmlns:x="urn:schemas-microsoft-com:office:excel"  
          xmlns="http://www.w3.org/TR/REC-html40">  
    <head>  
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">  
        <style>  
            .header { font-weight: bold; background-color: #4472C4; color: white; }  
            .example { background-color: #E7E6E6; }  
        </style>  
    </head>  
    <body>  
        <table border="1">  
            <tr class="header">  
                <td>Nombre</td>  
                <td>Iglesia</td>  
                <td>Contacto</td>  
                <td>Cabaña</td>  
            </tr>  
            <tr class="example">  
                <td>Juan Pérez</td>  
                <td>Iglesia Central</td>  
                <td>7777-7777</td>  
                <td>Cabaña 1</td>  
            </tr>  
            <tr class="example">  
                <td>María González</td>  
                <td>Iglesia del Este</td>  
                <td>7888-8888</td>  
                <td>Cabaña 2</td>  
            </tr>  
            <tr class="example">  
                <td>Carlos Rodríguez</td>  
                <td>Iglesia del Norte</td>  
                <td>7999-9999</td>  
                <td></td>  
            </tr>  
        </table>  
    </body>  
    </html>  
    <?php  
    exit();  
}  
?>  