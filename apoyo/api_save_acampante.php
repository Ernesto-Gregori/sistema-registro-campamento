<?php
/* ═══════════════════════════════════════════════════════════════
   API ENDPOINT — Guardar Acampante (Offline Sync)
   Recibe JSON desde offline-sync.js y guarda en acampantes
═══════════════════════════════════════════════════════════════ */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: same-origin');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

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

// ── Funciones auxiliares ────────────────────────────────────
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
    $nombre    = limpiar($data['nombre']    ?? null);
    $cabana_id = limpiarInt($data['cabana_id'] ?? null);
    $semana_id = limpiarInt($data['semana_id'] ?? null);

    if (!$nombre || !$cabana_id) {
        http_response_code(422);
        echo json_encode([
            'ok'    => false,
            'error' => 'Faltan campos obligatorios: nombre y cabana_id'
        ]);
        exit();
    }

    // ── 2. Obtener semana activa si no viene en los datos ───
    if (!$semana_id) {
        $stmt      = $pdo->query("SELECT id FROM semanas_campamento WHERE activa = 1 LIMIT 1");
        $semanaRow = $stmt->fetch();
        $semana_id = $semanaRow ? (int)$semanaRow['id'] : null;
    }

    // ── 3. Obtener año del campamento ───────────────────────
    $year_campamento = limpiarInt($data['year_campamento'] ?? null)
                    ?? (int)date('Y');

    // ── 4. Extraer y limpiar todos los campos ───────────────
    $edad      = limpiarInt($data['edad'] ?? null);
    $sexo      = in_array($data['sexo'] ?? '', ['masculino', 'femenino'])
                    ? $data['sexo']
                    : null;
    $iglesia              = limpiar($data['iglesia']              ?? null);
    $estado_origen        = limpiar($data['estado_origen']        ?? null);
    $contacto             = limpiar($data['contacto']             ?? null);
    $contacto_emergencia_nombre   = limpiar($data['contacto_emergencia_nombre']   ?? null);
    $contacto_emergencia_telefono = limpiar($data['contacto_emergencia_telefono'] ?? null);
    $alergias_enfermedades = limpiar($data['alergias_enfermedades'] ?? null);
    $observaciones         = limpiar($data['observaciones']         ?? null);
    $consejero_responsable = limpiar($data['consejero_responsable'] ?? null);

    // Campos booleanos
    $edad_autorizada       = limpiarBool($data['edad_autorizada']       ?? false);
    $asiste_iglesia        = limpiarBool($data['asiste_iglesia']        ?? false);
    $era_creyente_antes    = limpiarBool($data['era_creyente_antes']    ?? false);
    $recibio_cristo_semana = limpiarBool($data['recibio_cristo_semana'] ?? false);
    $consagro_vida_fogata  = limpiarBool($data['consagro_vida_fogata']  ?? false);
    $decision_tomada       = limpiar($data['decision_tomada']           ?? null);

    // ── 5. Verificar duplicado (mismo nombre + cabaña + semana) ──
    $stmt = $pdo->prepare(
        "SELECT id FROM acampantes
         WHERE nombre = ? AND cabana_id = ? AND semana_id = ?
         AND year_campamento = ? AND estado = 'activo'
         LIMIT 1"
    );
    $stmt->execute([$nombre, $cabana_id, $semana_id, $year_campamento]);
    $duplicado = $stmt->fetch();

    if ($duplicado) {
        // Retornar éxito con aviso — no duplicar
        http_response_code(200);
        echo json_encode([
            'ok'          => true,
            'duplicado'   => true,
            'mensaje'     => "Acampante '$nombre' ya existe en esta cabaña/semana",
            'acampante_id'=> $duplicado['id']
        ]);
        exit();
    }

    // ── 6. Insertar acampante ───────────────────────────────
    $stmt = $pdo->prepare(
        "INSERT INTO acampantes (
            nombre, edad, edad_autorizada,
            sexo, iglesia, estado_origen,
            contacto,
            contacto_emergencia_nombre, contacto_emergencia_telefono,
            alergias_enfermedades, observaciones,
            cabana_id, semana_id, year_campamento, estado,
            asiste_iglesia, era_creyente_antes,
            recibio_cristo_semana, consagro_vida_fogata,
            decision_tomada, consejero_responsable,
            fecha_registro
         ) VALUES (
            ?, ?, ?,
            ?, ?, ?,
            ?,
            ?, ?,
            ?, ?,
            ?, ?, ?, 'activo',
            ?, ?,
            ?, ?,
            ?, ?,
            NOW()
         )"
    );

    $stmt->execute([
        $nombre, $edad, $edad_autorizada,
        $sexo, $iglesia, $estado_origen,
        $contacto,
        $contacto_emergencia_nombre, $contacto_emergencia_telefono,
        $alergias_enfermedades, $observaciones,
        $cabana_id, $semana_id, $year_campamento,
        $asiste_iglesia, $era_creyente_antes,
        $recibio_cristo_semana, $consagro_vida_fogata,
        $decision_tomada, $consejero_responsable
    ]);

    $nuevo_id = (int)$pdo->lastInsertId();

    // ── 7. Respuesta exitosa ────────────────────────────────
    http_response_code(201);
    echo json_encode([
        'ok'             => true,
        'mensaje'        => "Acampante '$nombre' registrado correctamente",
        'acampante_id'   => $nuevo_id,
        'cabana_id'      => $cabana_id,
        'semana_id'      => $semana_id,
        'sincronizado_en'=> date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Error interno: ' . $e->getMessage()
    ]);
}