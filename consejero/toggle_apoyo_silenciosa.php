<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esConsejero()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit();
}

header('Content-Type: application/json');

$acampante_id = (int)($_POST['acampante_id'] ?? 0);
if ($acampante_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID inválido']);
    exit();
}

// Verificar que el acampante pertenezca a una cabaña del consejero
$cabana_id = $_SESSION['cabana_id'] ?? null;
if (!$cabana_id) {
    echo json_encode(['ok' => false, 'error' => 'Sin cabaña asignada']);
    exit();
}

$stmt = $pdo->prepare("
    SELECT id, nombre, necesita_apoyo_silenciosa
    FROM acampantes
    WHERE id = ? AND cabana_id = ? AND estado = 'activo'
");
$stmt->execute([$acampante_id, $cabana_id]);
$acampante = $stmt->fetch();

if (!$acampante) {
    echo json_encode(['ok' => false, 'error' => 'Acampante no encontrado en tu cabaña']);
    exit();
}

// Toggle: si está en 0 pasa a 1 (con timestamp), si está en 1 pasa a 0
$nuevo_valor = $acampante['necesita_apoyo_silenciosa'] ? 0 : 1;

if ($nuevo_valor) {
    // Marcar como necesita apoyo + registrar fecha
    $stmt = $pdo->prepare("
        UPDATE acampantes
        SET necesita_apoyo_silenciosa = 1,
            apoyo_silenciosa_solicitado_at = NOW()
        WHERE id = ?
    ");
    registrarLog($pdo, 'apoyo_silenciosa_solicitado',
        "Consejero '{$_SESSION['username']}' solicitó apoyo en hora silenciosa para '{$acampante['nombre']}'",
        'consejeria', 'warning');
} else {
    // Desmarcar
    $stmt = $pdo->prepare("
        UPDATE acampantes
        SET necesita_apoyo_silenciosa = 0,
            apoyo_silenciosa_solicitado_at = NULL
        WHERE id = ?
    ");
    registrarLog($pdo, 'apoyo_silenciosa_cancelado',
        "Consejero '{$_SESSION['username']}' canceló la solicitud de apoyo para '{$acampante['nombre']}'",
        'consejeria', 'info');
}

$stmt->execute([$acampante_id]);

echo json_encode([
    'ok'        => true,
    'estado'    => $nuevo_valor,
    'acampante' => $acampante['nombre'],
]);