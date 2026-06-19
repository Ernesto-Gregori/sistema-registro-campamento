<?php  
 
// Verificar si la sesión ya está iniciada antes de llamar session_start()  
if (session_status() === PHP_SESSION_NONE) {  
    session_start();  
}   
  
// Verificar si el usuario está logueado  
function verificarLogin() {  
    if (!isset($_SESSION['user_id'])) {  
        header('Location: ../login.php');  
        exit();  
    }  
}  

// ── Verificar modo mantenimiento ────────────────────────────
function verificarMantenimiento(PDO $pdo): void {
    if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'administrador') return;

    try {
        $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'mantenimiento_modo'");
        $stmt->execute();
        $modo = $stmt->fetchColumn();

        if ($modo === '1') {
            $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host      = $_SERVER['HTTP_HOST'];
            $url       = "{$protocolo}://{$host}/mantenimiento.html";

            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'], $params['domain'],
                    $params['secure'], $params['httponly']
                );
            }
            session_destroy();

            header("Location: {$url}");
            exit();
        }
    } catch (Exception $e) {
        // No bloquear si falla
    }
}
  
function esAdministrador() {  
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'administrador';  
}

function esEncargadoConsejeros() {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'encargado_consejeros';
}

function esGestor() {
    return isset($_SESSION['rol']) && in_array($_SESSION['rol'], [
        'administrador',
        'encargado_consejeros'
    ]);
} 

// ── Admisiones ──────────────────────────────────────────────
function esAdmisiones(): bool {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'admisiones';
}

function esAdmisionesOAdmin(): bool {
    return esAdmisiones() || esAdministrador();
}

// Verificar si es administración (caja/pagos)
function esAdministracion() {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'administracion';
}

function esDireccion() {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'direccion_campamento';
}

// ── Verificador genérico de rol (para roles sin función propia) ──
function esRol(string $rol): bool {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === $rol;
}

// ── Calcular saldo de un acampante ───────────────────────────
// ✅ pagado_100 ya funciona con costo=0 ($saldo <= 0 → 0 <= 0 → true)
function calcularSaldoAcampante(PDO $pdo, int $acampante_id): array {
    $stmt = $pdo->prepare("
        SELECT a.costo_total,
               COALESCE(SUM(p.monto), 0) AS total_pagado
        FROM acampantes a
        LEFT JOIN pagos_acampante p ON p.acampante_id = a.id
        WHERE a.id = ?
        GROUP BY a.id
    ");
    $stmt->execute([$acampante_id]);
    $row = $stmt->fetch();

    $costo_total  = (float)($row['costo_total']  ?? 0);
    $total_pagado = (float)($row['total_pagado'] ?? 0);
    $saldo        = $costo_total - $total_pagado;

    return [
        'costo_total'  => $costo_total,
        'total_pagado' => $total_pagado,
        'saldo'        => max(0, $saldo),
        // ✅ costo=0 → saldo=0 → 0 <= 0 → true (beca cuenta como pagado)
        'pagado_100'   => $saldo <= 0,
    ];
}

// ── Resumen de pagos por semana ──────────────────────────────
function resumenPagosSemana(PDO $pdo, int $semana_id): array {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(a.id)                          AS total_inscritos,
            SUM(a.llego)                         AS total_llegaron,
            SUM(a.costo_total)                   AS recaudacion_esperada,
            COALESCE(SUM(p.total_pagado), 0)     AS recaudacion_real,
            -- ✅ FIX BECA: costo=0 también cuenta como 'pagado completo'
            SUM(
                CASE
                    WHEN a.costo_total = 0                       THEN 1
                    WHEN p.total_pagado >= a.costo_total
                         AND a.costo_total > 0                   THEN 1
                    ELSE 0
                END
            ) AS pagados_completo
        FROM acampantes a
        LEFT JOIN (
            SELECT acampante_id, SUM(monto) AS total_pagado
            FROM pagos_acampante
            GROUP BY acampante_id
        ) p ON p.acampante_id = a.id
        WHERE a.semana_id = ? AND a.estado = 'activo'
    ");
    $stmt->execute([$semana_id]);
    return $stmt->fetch() ?: [];
}
  
function esConsejero() {  
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'consejero';  
} 

function esApoyo() {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'apoyo';
}
  
function limpiarDatos($data) {  
    return htmlspecialchars(strip_tags(trim($data)));  
}  
  
function formatearFecha($fecha) {  
    if (!$fecha || $fecha == '0000-00-00' || $fecha == '1970-01-01') {  
        return 'No registrada';  
    }  
    return date('d/m/Y', strtotime($fecha));  
}   
  
function formatearHora($hora) {  
    return date('H:i', strtotime($hora));  
}  
  
function hashPassword($password) {  
    return password_hash($password, PASSWORD_DEFAULT);  
}  
  
function verificarPassword($password, $hash) {  
    return password_verify($password, $hash);  
}  
  
function obtenerAnioCampamento(): int {
    global $pdo;
    try {
        if (isset($pdo)) {
            $stmt = $pdo->query("
                SELECT year FROM campamentos 
                WHERE estado = 'activo' 
                ORDER BY year DESC 
                LIMIT 1
            ");
            $year = $stmt->fetchColumn();
            if ($year) return (int)$year;
        }
        $valor = obtenerConfig($pdo, 'anio_activo', date('Y'));
        return (int)$valor;

    } catch (Exception $e) {
        return (int)date('Y');
    }
}
  
function subirArchivo($archivo, $directorio = 'uploads/') {  
    $target_dir = "../assets/" . $directorio;  
    $nombre_archivo = time() . "_" . basename($archivo["name"]);  
    $target_file = $target_dir . $nombre_archivo;  
      
    $extensiones_permitidas = array("jpg", "jpeg", "png", "gif", "pdf", "doc", "docx", "mp4", "avi");  
    $extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));  
      
    if (in_array($extension, $extensiones_permitidas)) {  
        if (move_uploaded_file($archivo["tmp_name"], $target_file)) {  
            return $directorio . $nombre_archivo;  
        }  
    }  
    return false;  
}  

function verificarConsejero() {  
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['cabana_id'])) {  
        header('Location: ../login.php');  
        exit();  
    }  
    return $_SESSION['cabana_id'];  
}  
  
function debugSesion() {  
    if (isset($_GET['debug'])) {  
        echo "<div class='alert alert-info'>";  
        echo "<strong>DEBUG:</strong> ";  
        echo "User ID: " . ($_SESSION['user_id'] ?? 'NULL') . " | ";  
        echo "Cabaña ID: " . ($_SESSION['cabana_id'] ?? 'NULL') . " | ";  
        echo "Rol: " . ($_SESSION['rol'] ?? 'NULL');  
        echo "</div>";  
    }  
}  

function obtenerEquipos(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [
        'verde' => [
            'clave'     => 'equipo_1',
            'nombre'    => 'Verde',
            'color'     => 'success',
            'color_hex' => '#198754',
            'emoji'     => '🟢'
        ],
        'azul'  => [
            'clave'     => 'equipo_2',
            'nombre'    => 'Azul',
            'color'     => 'primary',
            'color_hex' => '#0d6efd',
            'emoji'     => '🔵'
        ],
    ];

    try {
        $stmt = $pdo->query("SELECT * FROM equipos ORDER BY id");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mapa = ['equipo_1' => 'verde', 'equipo_2' => 'azul'];

        foreach ($rows as $eq) {
            $clave_bd = $mapa[$eq['clave']] ?? null;
            if ($clave_bd) {
                $cache[$clave_bd] = $eq;
            }
        }

    } catch (Exception $e) {
        // Tabla no existe — se usa el fallback
    }

    return $cache;
}

// ── Registrar log del sistema ────────────────────────────────
function registrarLog(
    PDO    $pdo,
    string $accion,
    string $descripcion = '',
    string $modulo      = 'general',
    string $nivel       = 'info'
): void {
    try {
        $usuario_id = $_SESSION['user_id'] ?? null;
        $username   = $_SESSION['username'] ?? 'sistema';
        $rol        = $_SESSION['rol']      ?? null;
        $ip         = $_SERVER['HTTP_X_FORWARDED_FOR']
                      ?? $_SERVER['REMOTE_ADDR']
                      ?? '0.0.0.0';
        $ip = trim(explode(',', $ip)[0]);

        $stmt = $pdo->prepare("INSERT INTO sistema_logs 
            (usuario_id, username, rol, accion, descripcion, ip, modulo, nivel)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $usuario_id, $username, $rol,
            $accion, $descripcion, $ip,
            $modulo, $nivel
        ]);

        if (rand(1, 50) === 1) {
            $pdo->exec("DELETE FROM sistema_logs 
                        WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        }

    } catch (Exception $e) {
        error_log('[LOG_ERROR] ' . $e->getMessage());
    }
}

// ── Obtener configuración del sistema ───────────────────────
function obtenerConfig(PDO $pdo, string $clave, string $default = ''): string {
    static $cache = [];

    if (isset($cache[$clave])) return $cache[$clave];

    try {
        $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = ?");
        $stmt->execute([$clave]);
        $valor = $stmt->fetchColumn();
        $cache[$clave] = ($valor !== false) ? $valor : $default;
    } catch (Exception $e) {
        $cache[$clave] = $default;
    }

    return $cache[$clave];
}

// ── Obtener todas las configuraciones como array ─────────────
function obtenerTodasConfig(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT clave, valor FROM configuracion");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows ?: [];
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Verifica si el acampante completó el pago y activa el check-in
 * automáticamente si aún no había llegado.
 *
 * ✅ FIX BECA: costo_total = 0 → beca completa → check-in inmediato
 *
 * Reglas:
 *  - Solo actúa si llego = 0
 *  - costo_total = 0  → beca/gratuito → check-in directo ✅
 *  - costo_total > 0  → requiere SUM(pagos) >= costo_total
 *
 * @param  PDO $pdo
 * @param  int $acampante_id
 * @return bool  true = check-in activado ahora | false = no aplica
 */
function verificarYActivarCheckin(PDO $pdo, int $acampante_id): bool
{
    $stmt = $pdo->prepare("
        SELECT a.llego,
               a.costo_total,
               COALESCE(SUM(p.monto), 0) AS total_pagado
        FROM   acampantes a
        LEFT   JOIN pagos_acampante p ON p.acampante_id = a.id
        WHERE  a.id = ?
        GROUP  BY a.id
    ");
    $stmt->execute([$acampante_id]);
    $d = $stmt->fetch();

    if (!$d)         return false; // no existe
    if ($d['llego']) return false; // ya tiene check-in

    $costo  = (float)$d['costo_total'];
    $pagado = (float)$d['total_pagado'];

    // ✅ FIX: beca (costo=0) → check-in inmediato sin requerir pago
    // Antes: if ($costo <= 0) return false; ← BLOQUEABA a los becados
    $debe_checkin = ($costo == 0) || ($pagado >= $costo);

    if (!$debe_checkin) return false;

    // ✅ Activar check-in
    $pdo->prepare("
        UPDATE acampantes
        SET    llego         = 1,
               fecha_llegada = NOW()
        WHERE  id    = ?
          AND  llego = 0
    ")->execute([$acampante_id]);

    return true;
}

/**
 * Valida si una edad cumple el rango de una semana y cabaña.
 * La config de cabaña sobreescribe la de semana cuando existe.
 * Retorna ['valido' => bool, 'mensaje' => string]
 */
function validarEdadAcampante(
    PDO  $pdo,
    int  $edad,
    int  $semana_id,
    ?int $cabana_id = null
): array {
    // Límites de la semana
    $stmt = $pdo->prepare(
        "SELECT edad_min, edad_max FROM semanas_campamento WHERE id = ?"
    );
    $stmt->execute([$semana_id]);
    $sem = $stmt->fetch();

    $emin = $sem['edad_min'];
    $emax = $sem['edad_max'];

    // Sobreescribir con config de cabaña si existe
    if ($cabana_id) {
        $stmt2 = $pdo->prepare("
            SELECT edad_min, edad_max FROM cabana_semana_config
            WHERE cabana_id = ? AND semana_id = ?
        ");
        $stmt2->execute([$cabana_id, $semana_id]);
        $cfg = $stmt2->fetch();
        if ($cfg) {
            if ($cfg['edad_min'] !== null) $emin = $cfg['edad_min'];
            if ($cfg['edad_max'] !== null) $emax = $cfg['edad_max'];
        }
    }

    if ($emin !== null && $edad < (int)$emin)
        return [
            'valido'  => false,
            'mensaje' => "El acampante tiene {$edad} años. El mínimo para esta semana es {$emin}."
        ];

    if ($emax !== null && $edad > (int)$emax)
        return [
            'valido'  => false,
            'mensaje' => "El acampante tiene {$edad} años. El máximo para esta semana es {$emax}."
        ];

    return ['valido' => true, 'mensaje' => ''];
}
?>



