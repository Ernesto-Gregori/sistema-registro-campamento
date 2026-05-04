<?php
/* ═══════════════════════════════════════════════════════════════
   API ENDPOINT — Guardar Sesión de Consejería (Offline Sync)
   Recibe JSON desde offline-sync.js y guarda en sesiones_consejeria
═══════════════════════════════════════════════════════════════ */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: same-origin');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit();
}

require_once '../config/database.php';
require_once '../includes/functions.php';

// ── Leer body JSON ──────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido o vacío']);
    exit();
}

// ── Función auxiliar de limpieza ────────────────────────────
function limpiar(?string $val): ?string {
    if ($val === null || $val === '') return null;
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

function limpiarInt($val): ?int {
    $v = filter_var($val, FILTER_VALIDATE_INT);
    return $v !== false ? (int)$v : null;
}

function limpiarBool($val): int {
    return (isset($val) && $val && $val !== '0' && $val !== 'false') ? 1 : 0;
}

try {
    // ── 1. Validar campos obligatorios ──────────────────────
    $acampante_id = limpiarInt($data['acampante_id'] ?? null);
    $fecha_sesion = limpiar($data['fecha_sesion'] ?? null);

    if (!$acampante_id || !$fecha_sesion) {
        http_response_code(422);
        echo json_encode([
            'ok'    => false,
            'error' => 'Faltan campos obligatorios: acampante_id y fecha_sesion'
        ]);
        exit();
    }

    // Validar formato de fecha
    $fechaObj = DateTime::createFromFormat('Y-m-d', $fecha_sesion);
    if (!$fechaObj) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Formato de fecha inválido']);
        exit();
    }

    // ── 2. Extraer campos ───────────────────────────────────
    $hora_sesion         = limpiar($data['hora_sesion']         ?? null);
    $observaciones       = limpiar($data['observaciones']       ?? null);
    $tema_personalizado  = limpiar($data['tema_personalizado']  ?? null);
    $consejero_responsable = limpiar($data['consejero_responsable'] ?? null);

    // Temas seleccionados — puede venir como array o string CSV
    $temas = [];
    if (!empty($data['temas'])) {
        if (is_array($data['temas'])) {
            $temas = array_filter(array_map('intval', $data['temas']));
        } elseif (is_string($data['temas'])) {
            $temas = array_filter(array_map('intval', explode(',', $data['temas'])));
        }
    }

    // Evaluación espiritual
    $asiste_iglesia        = limpiarBool($data['asiste_iglesia']        ?? false);
    $era_creyente_antes    = limpiarBool($data['era_creyente_antes']    ?? false);
    $recibio_cristo_semana = limpiarBool($data['recibio_cristo_semana'] ?? false);
    $consagro_vida_fogata  = limpiarBool($data['consagro_vida_fogata']  ?? false);
    $decision_tomada       = limpiar($data['decision_tomada']           ?? null);

    // ── 3. Verificar que el acampante existe ────────────────
    $stmt = $pdo->prepare("SELECT id, cabana_id FROM acampantes WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$acampante_id]);
    $acampante = $stmt->fetch();

    if (!$acampante) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Acampante no encontrado o inactivo']);
        exit();
    }

    // ── 4. Resolver consejero_id ────────────────────────────
    // El sync viene sin sesión activa — usamos cabana_id del acampante
    $cabana_id = $acampante['cabana_id'];

    $stmt = $pdo->prepare(
        "SELECT id FROM usuarios WHERE cabana_id = ? AND rol = 'consejero' LIMIT 1"
    );
    $stmt->execute([$cabana_id]);
    $consejeroRow = $stmt->fetch();

    if ($consejeroRow) {
        $consejero_id = $consejeroRow['id'];
    } else {
        // Crear usuario temporal de cabaña
        $stmt = $pdo->prepare(
            "INSERT INTO usuarios (username, password, rol, cabana_id)
             VALUES (?, ?, 'consejero', ?)"
        );
        $username_temp = 'consejero_cabana_' . $cabana_id;
        $stmt->execute([$username_temp, password_hash('temp_' . $cabana_id, PASSWORD_DEFAULT), $cabana_id]);
        $consejero_id = (int)$pdo->lastInsertId();
    }

    // ── 5. Obtener número de sesión ─────────────────────────
    $stmt = $pdo->prepare(
        "SELECT COALESCE(MAX(numero_sesion), 0) + 1 AS numero
         FROM sesiones_consejeria
         WHERE acampante_id = ?"
    );
    $stmt->execute([$acampante_id]);
    $numero_sesion = (int)$stmt->fetch()['numero'];

    // ── 6. Iniciar transacción ──────────────────────────────
    $pdo->beginTransaction();

    // ── 7. Actualizar evaluación espiritual del acampante ───
    $stmt = $pdo->prepare(
        "UPDATE acampantes SET
            asiste_iglesia        = ?,
            era_creyente_antes    = ?,
            recibio_cristo_semana = ?,
            consagro_vida_fogata  = ?,
            decision_tomada       = ?,
            consejero_responsable = ?
         WHERE id = ?"
    );
    $stmt->execute([
        $asiste_iglesia,
        $era_creyente_antes,
        $recibio_cristo_semana,
        $consagro_vida_fogata,
        $decision_tomada,
        $consejero_responsable,
        $acampante_id
    ]);

    // ── 8. Insertar sesiones ────────────────────────────────
    $sesiones_creadas = 0;

    // 8a. Temas predefinidos
    if (!empty($temas)) {
        $stmt = $pdo->prepare(
            "INSERT INTO sesiones_consejeria
                (acampante_id, consejero_id, tema_id, observaciones,
                 fecha_sesion, hora_sesion, numero_sesion)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($temas as $tema_id) {
            $stmt->execute([
                $acampante_id,
                $consejero_id,
                $tema_id,
                $observaciones,
                $fecha_sesion,
                $hora_sesion,
                $numero_sesion
            ]);
            $sesiones_creadas++;
        }
    }

    // 8b. Tema personalizado
    if ($tema_personalizado) {
        $stmt = $pdo->prepare(
            "INSERT INTO sesiones_consejeria
                (acampante_id, consejero_id, tema_personalizado, observaciones,
                 fecha_sesion, hora_sesion, numero_sesion)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $acampante_id,
            $consejero_id,
            $tema_personalizado,
            $observaciones,
            $fecha_sesion,
            $hora_sesion,
            $numero_sesion
        ]);
        $sesiones_creadas++;
    }

    // 8c. Solo observaciones
    if ($sesiones_creadas === 0 && $observaciones) {
        $stmt = $pdo->prepare(
            "INSERT INTO sesiones_consejeria
                (acampante_id, consejero_id, observaciones,
                 fecha_sesion, hora_sesion, numero_sesion)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $acampante_id,
            $consejero_id,
            $observaciones,
            $fecha_sesion,
            $hora_sesion,
            $numero_sesion
        ]);
        $sesiones_creadas++;
    }

    // 8d. Solo evaluación espiritual
    if ($sesiones_creadas === 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO sesiones_consejeria
                (acampante_id, consejero_id, fecha_sesion, hora_sesion, numero_sesion)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $acampante_id,
            $consejero_id,
            $fecha_sesion,
            $hora_sesion,
            $numero_sesion
        ]);
        $sesiones_creadas++;
    }

    $pdo->commit();

    // ── 9. Respuesta exitosa ────────────────────────────────
    http_response_code(200);
    echo json_encode([
        'ok'             => true,
        'mensaje'        => "Consejería #$numero_sesion sincronizada correctamente",
        'numero_sesion'  => $numero_sesion,
        'sesiones_creadas' => $sesiones_creadas,
        'acampante_id'   => $acampante_id,
        'sincronizado_en' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Error interno: ' . $e->getMessage()
    ]);
}