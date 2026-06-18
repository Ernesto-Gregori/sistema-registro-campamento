<?php
// admisiones/marcar_docs.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$id        = (int)($_GET['id'] ?? 0);
$semana_id = (int)($_GET['semana_id'] ?? 0);
$grupo_id  = (int)($_GET['grupo_id']  ?? 0);

// Si viene de un grupo, redirigir de vuelta al grupo
if ($grupo_id > 0) {
    $redirect = "../admisiones/grupos/ver_grupo.php?id={$grupo_id}";
} else {
    $redirect = "lista_acampantes.php" . ($semana_id ? "?semana_id=$semana_id" : "");
}

if (!$id) {
    header("Location: $redirect");
    exit();
}

try {
    // Verificar que el acampante existe y no está ya revisado
    $stmt = $pdo->prepare("SELECT id, nombre, documentos_revisados FROM acampantes WHERE id = ?");
    $stmt->execute([$id]);
    $acampante = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$acampante) throw new Exception("Acampante no encontrado");

    if ($acampante['documentos_revisados']) {
        // Ya revisado — solo redirige con aviso
        header("Location: $redirect&message=" . urlencode("ℹ️ Documentos ya estaban verificados"));
        exit();
    }

    // Marcar documentos como revisados
    $stmt = $pdo->prepare("
        UPDATE acampantes 
        SET documentos_revisados      = 1,
            documentos_revisados_por  = ?,
            documentos_revisados_at   = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $id]);

    registrarLog($pdo,
        'documentos_revisados',
        "Documentos de '{$acampante['nombre']}' (ID:{$id}) verificados por inscripción",
        'admisiones', 'success'
    );

    header("Location: $redirect&message=" . urlencode("✅ Documentos de '{$acampante['nombre']}' marcados como verificados"));
    exit();

} catch (Exception $e) {
    header("Location: $redirect&error=" . urlencode($e->getMessage()));
    exit();
}