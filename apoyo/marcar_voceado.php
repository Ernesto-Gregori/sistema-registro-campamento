<?php
// apoyo/marcar_voceado.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esApoyo()) {
    header('Location: ../login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: sala_espera.php');
    exit();
}

$acampante_id = (int)($_POST['acampante_id'] ?? 0);
$semana_id    = (int)($_POST['semana_id']    ?? 0);

if (!$acampante_id) {
    header('Location: sala_espera.php?message=' . urlencode('Error: acampante no válido.'));
    exit();
}

// Verificar que el acampante existe, tiene llego=1 y aún no fue enviado
$stmt = $pdo->prepare("
    SELECT a.id, a.nombre, c.nombre_cabana
    FROM acampantes a
    JOIN cabanas c ON a.cabana_id = c.id
    WHERE a.id = ?
      AND a.llego = 1
      AND a.enviado_cabana = 0
      AND a.estado = 'activo'
    LIMIT 1
");
$stmt->execute([$acampante_id]);
$acampante = $stmt->fetch();

if (!$acampante) {
    header('Location: sala_espera.php?message=' . urlencode('Este acampante ya fue enviado o no está disponible.'));
    exit();
}

// Marcar como enviado a cabaña
$stmt = $pdo->prepare("
    UPDATE acampantes
    SET enviado_cabana = 1
    WHERE id = ?
");
$stmt->execute([$acampante_id]);

// Registrar en logs
registrarLog(
    $pdo,
    'voceado_cabana',
    "Acampante '{$acampante['nombre']}' enviado a cabaña '{$acampante['nombre_cabana']}'",
    'apoyo',
    'success'
);

$msg = "✅ {$acampante['nombre']} fue enviado a {$acampante['nombre_cabana']}";
header('Location: sala_espera.php?message=' . urlencode($msg));
exit();