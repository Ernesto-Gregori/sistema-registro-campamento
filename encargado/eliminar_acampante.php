<?php
// encargado/eliminar_acampante.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarAccesoEncargado();

$grupo_id = obtenerGrupoEncargado();
$acampante_id = (int)($_GET['acampante_id'] ?? 0);

if (!$acampante_id) {
    header('Location: panel.php');
    exit();
}

try {
    // Verificar que el acampante pertenezca al grupo del encargado
    $stmt = $pdo->prepare("SELECT id, nombre FROM acampantes WHERE id = ? AND grupo_id = ? AND estado = 'activo'");
    $stmt->execute([$acampante_id, $grupo_id]);
    $ac = $stmt->fetch();

    if (!$ac) {
        $_SESSION['mensaje_error'] = "Acampante no encontrado en tu grupo.";
        header('Location: panel.php');
        exit();
    }

    // Eliminación lógica
    $pdo->prepare("
        UPDATE acampantes SET
            estado = 'eliminado',
            grupo_id = NULL,
            fecha_eliminacion = NOW(),
            eliminado_por = NULL
        WHERE id = ? AND grupo_id = ?
    ")->execute([$acampante_id, $grupo_id]);

    registrarLog($pdo, 'encargado_acampante_eliminado',
        "Acampante '{$ac['nombre']}' (ID {$acampante_id}) eliminado del grupo {$grupo_id} por el encargado",
        'encargado', 'warning');

    $_SESSION['mensaje_exito'] = "✅ Acampante '{$ac['nombre']}' eliminado correctamente.";
} catch (Exception $e) {
    $_SESSION['mensaje_error'] = "Error al eliminar: " . $e->getMessage();
}

header('Location: panel.php');
exit();
