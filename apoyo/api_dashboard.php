<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Obtener timestamp del último cambio en acampantes
$stmt_ts = $pdo->query("SELECT MAX(fecha_registro) as ultimo_cambio FROM acampantes WHERE estado = 'activo'");
$ultimo_cambio = $stmt_ts->fetch()['ultimo_cambio'] ?? '';
$hash_estado = md5($ultimo_cambio . $totalAcampantes);

// Si el cliente ya tiene este hash, no enviar datos pesados
$hash_cliente = $_GET['hash'] ?? '';
if ($hash_cliente === $hash_estado) {
    echo json_encode([
        'ok'        => true,
        'sin_cambio' => true,
        'hash'      => $hash_estado,
        'timestamp' => date('H:i:s'),
    ]);
    exit();
}

verificarLogin();
if (!esApoyo()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin acceso']);
    exit();
}

header('Content-Type: application/json');

try {
    // Semana activa
    $stmt = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
    $semana_activa = $stmt->fetch();
    $semana_id_activa = $semana_activa['id'] ?? null;

    // Género del usuario
    $stmt = $pdo->prepare("SELECT genero_acceso FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $usuario = $stmt->fetch();
    $genero_acceso = $usuario['genero_acceso'] ?? 'ambos';

    $filtro_genero_sql = $genero_acceso !== 'ambos'
        ? "AND c.genero = " . $pdo->quote($genero_acceso)
        : "";

    // ── Cabañas ───────────────────────────────────────────────────
    if ($semana_id_activa) {
        $sql_cab = "SELECT c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                    MAX(CASE WHEN cs.rol = 'principal'  THEN cs.nombre_consejero END) AS consejero_principal,
                    MAX(CASE WHEN cs.rol = 'asistente'  THEN cs.nombre_consejero END) AS consejero_asistente,
                    COALESCE(csc.edad_min, s.edad_min) AS edad_min_efectiva,
                    COALESCE(csc.edad_max, s.edad_max) AS edad_max_efectiva,
                    COUNT(DISTINCT a.id) AS total_acampantes
                    FROM cabanas c
                    LEFT JOIN acampantes a ON c.id = a.cabana_id
                        AND a.semana_id = ? AND a.estado = 'activo'
                    LEFT JOIN consejeros_semana cs
                        ON cs.cabana_id = c.id AND cs.semana_id = ?
                    LEFT JOIN cabana_semana_config csc
                        ON csc.cabana_id = c.id AND csc.semana_id = ?
                    LEFT JOIN semanas_campamento s ON s.id = ?
                    WHERE c.activa = 1 $filtro_genero_sql
                    GROUP BY c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                             edad_min_efectiva, edad_max_efectiva
                    ORDER BY c.equipo ASC, c.nombre_cabana ASC";
        $stmt_cab = $pdo->prepare($sql_cab);
        $stmt_cab->execute([
            $semana_id_activa,
            $semana_id_activa,
            $semana_id_activa,
            $semana_id_activa
        ]);;
    } else {
        $sql_cab = "SELECT c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                    c.consejero_principal,
                    c.consejero_asistente,
                    NULL AS edad_min_efectiva,
                    NULL AS edad_max_efectiva,
                    COUNT(DISTINCT a.id) AS total_acampantes
                    FROM cabanas c
                    LEFT JOIN acampantes a ON c.id = a.cabana_id
                        AND a.year_campamento = ? AND a.estado = 'activo'
                    WHERE c.activa = 1 $filtro_genero_sql
                    GROUP BY c.id, c.nombre_cabana, c.capacidad_maxima, c.genero, c.equipo,
                             c.consejero_principal, c.consejero_asistente
                    ORDER BY c.equipo ASC, c.nombre_cabana ASC";
        $stmt_cab = $pdo->prepare($sql_cab);
        $stmt_cab->execute([obtenerAnioCampamento()]);
    }
    $cabanas = $stmt_cab->fetchAll(PDO::FETCH_ASSOC);

    // ── Stats por equipo (subconsulta para evitar duplicados) ──
    $filtro_genero_cab = $genero_acceso !== 'ambos'
        ? "AND genero = " . $pdo->quote($genero_acceso)
        : "";
    $filtro_genero_join = $genero_acceso !== 'ambos'
        ? "AND c2.genero = " . $pdo->quote($genero_acceso)
        : "";

    if ($semana_id_activa) {
        $sql_eq = "SELECT eq.equipo, eq.total_cabanas, eq.capacidad_total,
                   COALESCE(ac.total_acampantes, 0) as total_acampantes
                   FROM (
                       SELECT equipo, COUNT(id) as total_cabanas, SUM(capacidad_maxima) as capacidad_total
                       FROM cabanas
                       WHERE activa = 1 AND equipo IS NOT NULL $filtro_genero_cab
                       GROUP BY equipo
                   ) eq
                   LEFT JOIN (
                       SELECT c2.equipo, COUNT(a.id) as total_acampantes
                       FROM acampantes a
                       JOIN cabanas c2 ON a.cabana_id = c2.id
                       WHERE a.semana_id = ? AND a.estado = 'activo' $filtro_genero_join
                       GROUP BY c2.equipo
                   ) ac ON eq.equipo = ac.equipo
                   ORDER BY eq.equipo";
        $stmt = $pdo->prepare($sql_eq);
        $stmt->execute([$semana_id_activa]);
    } else {
        $sql_eq = "SELECT eq.equipo, eq.total_cabanas, eq.capacidad_total,
                   COALESCE(ac.total_acampantes, 0) as total_acampantes
                   FROM (
                       SELECT equipo, COUNT(id) as total_cabanas, SUM(capacidad_maxima) as capacidad_total
                       FROM cabanas
                       WHERE activa = 1 AND equipo IS NOT NULL $filtro_genero_cab
                       GROUP BY equipo
                   ) eq
                   LEFT JOIN (
                       SELECT c2.equipo, COUNT(a.id) as total_acampantes
                       FROM acampantes a
                       JOIN cabanas c2 ON a.cabana_id = c2.id
                       WHERE a.year_campamento = ? AND a.estado = 'activo' $filtro_genero_join
                       GROUP BY c2.equipo
                   ) ac ON eq.equipo = ac.equipo
                   ORDER BY eq.equipo";
        $stmt = $pdo->prepare($sql_eq);
        $stmt->execute([obtenerAnioCampamento()]);
    }
    $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Total acampantes ───────────────────────────────
    if ($semana_id_activa) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM acampantes a
                               JOIN cabanas c ON a.cabana_id = c.id
                               WHERE a.semana_id = ? AND a.estado = 'activo' $filtro_genero_sql");
        $stmt->execute([$semana_id_activa]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM acampantes a
                               JOIN cabanas c ON a.cabana_id = c.id
                               WHERE a.year_campamento = ? AND a.estado = 'activo' $filtro_genero_sql");
        $stmt->execute([obtenerAnioCampamento()]);
    }
    $totalAcampantes = $stmt->fetch()['total'];

    // ── Últimos 5 registros ────────────────────────────
    if ($semana_id_activa) {
        $stmt = $pdo->prepare("SELECT a.nombre, a.sexo, a.iglesia, a.fecha_registro, c.nombre_cabana
                               FROM acampantes a
                               LEFT JOIN cabanas c ON a.cabana_id = c.id
                               WHERE a.semana_id = ? AND a.estado = 'activo' $filtro_genero_sql
                               ORDER BY a.fecha_registro DESC LIMIT 5");
        $stmt->execute([$semana_id_activa]);
    } else {
        $stmt = $pdo->prepare("SELECT a.nombre, a.sexo, a.iglesia, a.fecha_registro, c.nombre_cabana
                               FROM acampantes a
                               LEFT JOIN cabanas c ON a.cabana_id = c.id
                               WHERE a.year_campamento = ? AND a.estado = 'activo' $filtro_genero_sql
                               ORDER BY a.fecha_registro DESC LIMIT 5");
        $stmt->execute([obtenerAnioCampamento()]);
    }
    $ultimos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'              => true,
        'sin_cambio'      => false,
        'hash'            => $hash_estado,      // ← AGREGAR
        'totalAcampantes' => (int)$totalAcampantes,
        'cabanas'         => $cabanas,
        'equipos'         => $equipos,
        'ultimos'         => $ultimos,
        'timestamp'       => date('H:i:s'),
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}