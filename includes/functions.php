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

            // Destruir sesión DESPUÉS de preparar el redirect
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
  
// Verificar si es administrador (funciones futuras por definir)
function esAdministrador() {  
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'administrador';  
}

// Verificar si es encargado de consejeros
function esEncargadoConsejeros() {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'encargado_consejeros';
}

// Verificar si tiene acceso al panel de gestión (admin O encargado)
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

// ── Calcular saldo de un acampante ───────────────────────────
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
            SUM(CASE WHEN p.total_pagado >= a.costo_total 
                      AND a.costo_total > 0 THEN 1 ELSE 0 END) AS pagados_completo
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
  
// Verificar si es consejero  
function esConsejero() {  
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'consejero';  
} 

//Verificar si es apoyo de consejeros
function esApoyo() {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'apoyo';
}
  
// Limpiar datos de entrada  
function limpiarDatos($data) {  
    return htmlspecialchars(strip_tags(trim($data)));  
}  
  
// Formatear fecha - versión mejorada  
function formatearFecha($fecha) {  
    if (!$fecha || $fecha == '0000-00-00' || $fecha == '1970-01-01') {  
        return 'No registrada';  
    }  
    return date('d/m/Y', strtotime($fecha));  
}   
  
// Formatear hora  
function formatearHora($hora) {  
    return date('H:i', strtotime($hora));  
}  
  
// Generar hash de password  
function hashPassword($password) {  
    return password_hash($password, PASSWORD_DEFAULT);  
}  
  
// Verificar password  
function verificarPassword($password, $hash) {  
    return password_verify($password, $hash);  
}  
  
// Obtener año actual del campamento  
function obtenerAnioCampamento(): int {
    global $pdo;
    try {
        // Fuente de verdad: tabla campamentos (estado = activo)
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
        // Fallback: configuracion
        $valor = obtenerConfig($pdo, 'anio_activo', date('Y'));
        return (int)$valor;

    } catch (Exception $e) {
        return (int)date('Y');
    }
}
  
// Subir archivo  
function subirArchivo($archivo, $directorio = 'uploads/') {  
    $target_dir = "../assets/" . $directorio;  
    $nombre_archivo = time() . "_" . basename($archivo["name"]);  
    $target_file = $target_dir . $nombre_archivo;  
      
    // Verificar si es un archivo válido  
    $extensiones_permitidas = array("jpg", "jpeg", "png", "gif", "pdf", "doc", "docx", "mp4", "avi");  
    $extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));  
      
    if (in_array($extension, $extensiones_permitidas)) {  
        if (move_uploaded_file($archivo["tmp_name"], $target_file)) {  
            return $directorio . $nombre_archivo;  
        }  
    }  
    return false;  
}  

// Verificar si el usuario está logueado como consejero  
function verificarConsejero() {  
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['cabana_id'])) {  
        header('Location: ../login.php');  
        exit();  
    }  
    return $_SESSION['cabana_id'];  
}  
  
// Debug de sesión (temporal)  
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

    // Fallback siempre disponible
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

        // equipo_1 siempre es 'verde' en cabanas.equipo
        // equipo_2 siempre es 'azul'  en cabanas.equipo
        $mapa = ['equipo_1' => 'verde', 'equipo_2' => 'azul'];

        foreach ($rows as $eq) {
            $clave_bd = $mapa[$eq['clave']] ?? null;
            if ($clave_bd) {
                // Sobreescribir el fallback con los datos reales de BD
                $cache[$clave_bd] = $eq;
            }
        }

    } catch (Exception $e) {
        // Tabla no existe — se usa el fallback definido arriba
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
        // Tomar solo la primera IP si hay varias (proxy)
        $ip = trim(explode(',', $ip)[0]);

        $stmt = $pdo->prepare("INSERT INTO sistema_logs 
            (usuario_id, username, rol, accion, descripcion, ip, modulo, nivel)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $usuario_id, $username, $rol,
            $accion, $descripcion, $ip,
            $modulo, $nivel
        ]);

        // Auto-limpiar logs > 30 días (1 de cada 50 requests para no sobrecargar)
        if (rand(1, 50) === 1) {
            $pdo->exec("DELETE FROM sistema_logs 
                        WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        }

    } catch (Exception $e) {
        // Silencioso — el log no debe romper el flujo principal
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
?>  