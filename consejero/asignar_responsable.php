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

$acampante_id         = (int)($_POST['acampante_id'] ?? 0);
$consejero_responsable = trim($_POST['consejero_responsable'] ?? '');

if ($acampante_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID inválido']);
    exit();
}

$cabana_id = $_SESSION['cabana_id'] ?? null;
if (!$cabana_id) {
    echo json_encode(['ok' => false, 'error' => 'Sin cabaña asignada']);
    exit();
}

// Verificar que el acampante pertenezca a la cabaña del consejero
$stmt = $pdo->prepare("
    SELECT id, nombre, consejero_responsable
    FROM acampantes
    WHERE id = ? AND cabana_id = ? AND estado = 'activo'
");
$stmt->execute([$acampante_id, $cabana_id]);
$acampante = $stmt->fetch();

if (!$acampante) {
    echo json_encode(['ok' => false, 'error' => 'Acampante no encontrado en tu cabaña']);
    exit();
}

// Guardar (vacío = quitar responsable)
$responsable_final = $consejero_responsable !== '' ? $consejero_responsable : null;

$stmt = $pdo->prepare("
    UPDATE acampantes
    SET consejero_responsable = ?
    WHERE id = ?
");
$stmt->execute([$responsable_final, $acampante_id]);

registrarLog($pdo, 'responsable_asignado',
    "Consejero '{$_SESSION['username']}' asignó a '" . ($responsable_final ?? 'nadie') .
    "' como responsable de '{$acampante['nombre']}'",
    'consejeria', 'info');

echo json_encode([
    'ok'         => true,
    'responsable' => $responsable_final,
    'acampante'  => $acampante['nombre'],
]);