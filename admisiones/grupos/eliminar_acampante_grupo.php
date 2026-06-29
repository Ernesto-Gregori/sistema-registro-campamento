<?php
// admisiones/grupos/eliminar_acampante_grupo.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// ── Verificar sesión ───────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$rol_sesion = $_SESSION['rol'] ?? '';

if (!in_array($rol_sesion, ['administrador', 'admisiones'])) {
    $_SESSION['mensaje_error'] = "No tienes permiso para eliminar acampantes.";
    header('Location: ' . (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'lista_grupos.php'));
    exit();
}

$acampante_id = (int)($_GET['acampante_id'] ?? 0);
$grupo_id     = (int)($_GET['grupo_id'] ?? 0);

if ($acampante_id <= 0 || $grupo_id <= 0) {
    $_SESSION['mensaje_error'] = "Parámetros no válidos para eliminar acampante.";
    header('Location: lista_grupos.php');
    exit();
}

// ── Función auxiliar: eliminar de tabla si existe ────────────────────────
function eliminarSiExiste(PDO $pdo, string $tabla, string $campo_id, int $id): void {
    try {
        $pdo->prepare("DELETE FROM {$tabla} WHERE {$campo_id} = ?")->execute([$id]);
    } catch (PDOException $e) {
        error_log("eliminarSiExiste({$tabla}): " . $e->getMessage());
    }
}

// ── PASO 1: Obtener datos del acampante y verificar que pertenezca al grupo ─
try {
    $stmt = $pdo->prepare("
        SELECT id, nombre, foto, grupo_id
        FROM acampantes
        WHERE id = ? AND estado = 'activo'
        LIMIT 1
    ");
    $stmt->execute([$acampante_id]);
    $acampante = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error buscar acampante ID $acampante_id: " . $e->getMessage());
    $_SESSION['mensaje_error'] = "Error de base de datos al buscar el acampante.";
    header("Location: ver_grupo.php?id={$grupo_id}");
    exit();
}

if (!$acampante) {
    $_SESSION['mensaje_error'] = "Acampante no encontrado (ID: {$acampante_id}).";
    header("Location: ver_grupo.php?id={$grupo_id}");
    exit();
}

if ((int)$acampante['grupo_id'] !== $grupo_id) {
    $_SESSION['mensaje_error'] = "El acampante no pertenece a este grupo.";
    header("Location: ver_grupo.php?id={$grupo_id}");
    exit();
}

$nombre_acampante = $acampante['nombre'];
$foto             = $acampante['foto'] ?? '';

// ── PASO 2: Eliminación en transacción ───────────────────────────────────
try {
    $pdo->beginTransaction();

    // 2a. Eliminar registros dependientes del acampante
    eliminarSiExiste($pdo, 'sesiones_consejeria',  'acampante_id', $acampante_id);
    eliminarSiExiste($pdo, 'evaluacion_espiritual', 'acampante_id', $acampante_id);
    eliminarSiExiste($pdo, 'pagos_acampante',      'acampante_id', $acampante_id);

    // 2b. Eliminar acampante (soft delete opcional: cambiar estado a inactivo)
    // Se usa DELETE físico para mantener consistencia con eliminar_grupo.php
    $stmt_del = $pdo->prepare("DELETE FROM acampantes WHERE id = ?");
    $stmt_del->execute([$acampante_id]);

    if ($stmt_del->rowCount() === 0) {
        $pdo->rollBack();
        $_SESSION['mensaje_error'] = "No se pudo eliminar el acampante. Posiblemente ya fue eliminado.";
        header("Location: ver_grupo.php?id={$grupo_id}");
        exit();
    }

    // 2c. Log
    registrarLog(
        $pdo,
        'ELIMINAR_ACAMPANTE_GRUPO',
        "Acampante eliminado del grupo: ID {$acampante_id} — {$nombre_acampante} (grupo ID {$grupo_id})",
        'admisiones',
        'warning'
    );

    $pdo->commit();

    // 2d. Eliminar foto del servidor (fuera de la transacción)
    if (!empty($foto)) {
        $ruta = '../../' . ltrim($foto, '/');
        if (file_exists($ruta)) {
            @unlink($ruta);
        }
    }

    $_SESSION['mensaje_exito'] = "Acampante <strong>" . htmlspecialchars($nombre_acampante) . "</strong> eliminado permanentemente del grupo.";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error CRÍTICO eliminar acampante ID $acampante_id: " . $e->getMessage());
    $_SESSION['mensaje_error'] = "Error al eliminar el acampante. Contacta al administrador.";
}

header("Location: ver_grupo.php?id={$grupo_id}");
exit();
