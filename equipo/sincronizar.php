<?php
// equipo/sincronizar.php
// Sincroniza los equipantes desde un Google Sheet publicado como CSV
// Version ajustada:
//   1) Mapeo de "Tipo persona" con coincidencia parcial
//   2) Matching flexible de semanas (SEM1/SEM2, parentesis, fechas)
//   3) Limpieza de registros basura

@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', 120);
@ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once '../config/database.php';
require_once '../includes/functions.php';

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<div style="color:red;font-family:sans-serif;padding:20px;">';
        echo '<h3>Error Fatal durante la sincronizacion</h3>';
        echo '<p><strong>Archivo:</strong> ' . htmlspecialchars($error['file']) . ' (linea ' . $error['line'] . ')</p>';
        echo '<p><strong>Mensaje:</strong> ' . htmlspecialchars($error['message']) . '</p>';
        echo '</div>';
    }
});

verificarLogin();
verificarMantenimiento($pdo);

if (!esEquipoOAdmin()) {
    header('Location: ../default.php');
    exit();
}

$year   = obtenerAnioCampamento();
$userId = $_SESSION['user_id'] ?? 0;

$mensaje = '';
$error   = '';

$urlSync = '';
try {
    $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'equipo_google_sheet_url'");
    $stmt->execute();
    $urlSync = $stmt->fetchColumn() ?: '';
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url_sync'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Token de seguridad invalido.';
    } else {
        $nuevaUrl = trim($_POST['url_sync'] ?? '');
        $stmt = $pdo->prepare("
            INSERT INTO configuracion (clave, valor, descripcion, tipo)
            VALUES ('equipo_google_sheet_url', ?, 'URL del Google Sheet publicado como CSV', 'texto')
            ON DUPLICATE KEY UPDATE valor = VALUES(valor)
        ");
        $stmt->execute([$nuevaUrl]);
        $urlSync = $nuevaUrl;
        $mensaje = 'URL guardada. Ahora puedes sincronizar.';
    }
}

// ==================================================================
// 1) MAPEO DE "TIPO PERSONA"
// ==================================================================
function normalizarTipoPersona(?string $valor): string
{
    $tipos = ['alumno', 'misionero', 'invitado', 'cocina', 'equipante'];

    if ($valor === null || trim($valor) === '') {
        return 'equipante';
    }

    $limpio = mb_strtolower(trim($valor), 'UTF-8');
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $limpio);
    $limpio = $translit !== false ? $translit : $limpio;
    $limpio = preg_replace('/[^a-z0-9 _-]/', '', $limpio);

    if ($limpio === '') {
        return 'equipante';
    }

    foreach ($tipos as $tipo) {
        if ($limpio === $tipo) {
            return $tipo;
        }
    }

    foreach ($tipos as $tipo) {
        if (preg_match('/\b' . preg_quote($tipo, '/') . '\b/', $limpio)) {
            return $tipo;
        }
    }

    $sinonimos = [
        'cocina'    => ['chef', 'cocinero', 'voluntario de cocina', 'comedor'],
        'misionero' => ['mision', 'misionera', 'misioneras'],
        'invitado'  => ['invitada', 'invitadas', 'invitados',
                        'doctor', 'conferencista', 'orador'],
        'alumno'    => ['alumna', 'alumnas', 'alumnos',
                        'ibpv', 'instituto', 'seminario'],
    ];
    foreach ($sinonimos as $tipo => $claves) {
        foreach ($claves as $clave) {
            if (strpos($limpio, $clave) !== false) {
                return $tipo;
            }
        }
    }

    return 'equipante';
}

// ==================================================================
// 2) MATCHING FLEXIBLE DE SEMANAS
// ==================================================================

function normalizarTextoSemanaSync(string $texto): string
{
    $t = mb_strtolower(trim($texto), 'UTF-8');

    // Normalizar acentos
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    $t = $translit !== false ? $translit : $t;

    // Eliminar solo fechas entre paréntesis, no el contenido textual
    $t = preg_replace('/\(\s*\d{1,2}\s*[-–]\s*\d{1,2}\s*[a-z]*\s*\)/i', ' ', $t);
    $t = preg_replace('/\b\d{1,2}\s*[-–]\s*\d{1,2}\b/', ' ', $t);
    $t = preg_replace('/\b\d{1,2}\s+de\s+[a-z]+\b/', ' ', $t);

    // Reemplazar guiones medios/bajos por espacios
    $t = str_replace(['-', '_'], ' ', $t);

    // Quitar caracteres raros, mantener letras y números
    $t = preg_replace('/[^a-z0-9 ]/', ' ', $t);
    $t = preg_replace('/\s+/', ' ', $t);

    return trim($t);
}

function palabrasClaveSemanaSync(string $textoYaNormalizado): string
{
    $stopwords = [
        'semana', 'de', 'la', 'el', 'los', 'las', 'y',
        'comunidad', 'campamento', 'palabra', 'vida',
        'anos', 'ano', 'julio', 'agosto', 'junio', 'septiembre',
        'abril', 'mayo', 'del', 'al', 'dias', 'dia'
    ];

    $palabras = explode(' ', $textoYaNormalizado);
    $utiles = [];
    foreach ($palabras as $p) {
        if ($p !== '' && !in_array($p, $stopwords, true)) {
            $utiles[] = $p;
        }
    }
    return implode(' ', $utiles);
}

function extraerNumeroSemanaBD(string $norm): ?string
{
    if (strpos($norm, 'jovenes') === false && strpos($norm, 'joven') === false) {
        return null;
    }
    if (preg_match('/(\d+)\s*$/', trim($norm), $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Detecta si el texto corresponde a la semana de entrenamiento
 * y devuelve su ID si existe en el catálogo.
 */
function detectarSemanaEntrenamiento(array $catalogo, string $textoNormalizado): ?int
{
    $sinonimos = [
        'entrenamiento', 'capacitacion', 'capacitación', 'pre campamento',
        'precampamento', 'pre-campamento', 'entrena', 'capacita'
    ];

    foreach ($sinonimos as $sin) {
        if (strpos($textoNormalizado, $sin) !== false) {
            foreach ($catalogo as $cat) {
                if (strpos($cat['norm'], $sin) !== false) {
                    return $cat['id'];
                }
            }
        }
    }

    return null;
}

function matchearSemanasSync($pdo, $textoSemanas, $year)
{
    if (empty($textoSemanas)) return [];

    $stmt = $pdo->prepare(
        "SELECT id, nombre, fecha_inicio, fecha_fin, tipo_acampante
         FROM semanas_campamento
         WHERE year_campamento = ?
         ORDER BY fecha_inicio"
    );
    $stmt->execute([$year]);
    $semanas = $stmt->fetchAll();

    if (empty($semanas)) return [];

    $textoLower = mb_strtolower(trim((string)$textoSemanas), 'UTF-8');

    // Si dice explícitamente "toda la temporada" y no hay otra semana específica
    if (strpos($textoLower, 'toda la temporada') !== false) {
        if (!preg_match('/\b(entrenamiento|sem\s*\d+|jovenes|comunidad|capacitacion|pre[- ]?campamento|ninos|ninas|mayores|adolescentes)\b/', $textoLower)) {
            return array_column($semanas, 'id');
        }
    }

    $catalogo = [];
    foreach ($semanas as $s) {
        $norm  = normalizarTextoSemanaSync($s['nombre']);
        $clave = palabrasClaveSemanaSync($norm);
        $catalogo[] = [
            'id'     => (int)$s['id'],
            'nombre' => $s['nombre'],
            'norm'   => $norm,
            'clave'  => $clave,
            'tokens' => $clave !== '' ? explode(' ', $clave) : [],
            'num'    => extraerNumeroSemanaBD($norm),
            'tipo'   => $s['tipo_acampante'],
        ];
    }

    $textoNorm = str_replace(["\r\n", "\n", "\r"], ',', (string)$textoSemanas);
    $textoNorm = str_replace(';', ',', $textoNorm);
    $selecciones = preg_split('/,\s*/', $textoNorm);

    $idsMatch = [];

    foreach ($selecciones as $selRaw) {
        $selNorm  = normalizarTextoSemanaSync($selRaw);
        $selClave = palabrasClaveSemanaSync($selNorm);

        if ($selNorm === '') {
            continue;
        }

        if (strpos($selNorm, 'toda la temporada') !== false) {
            continue;
        }

        $selTokens = $selClave !== '' ? explode(' ', $selClave) : [];

        $idMatch = null;

        // --- PRIORIDAD 1: Semana de entrenamiento ---
        if ($idMatch === null) {
            $idMatch = detectarSemanaEntrenamiento($catalogo, $selNorm);
        }

        // --- PRIORIDAD 2: Alias SEM1, SEM2, etc. ---
        if ($idMatch === null && preg_match('/\bsem\s*([0-9]+)\b/', $selNorm, $mAlias)) {
            $numAlias = $mAlias[1];
            foreach ($catalogo as $cat) {
                if ($cat['num'] === $numAlias) {
                    $idMatch = $cat['id'];
                    break;
                }
            }
            if ($idMatch === null) {
                $selTokens = ['jovenes', $numAlias];
            }
        }

        // --- PRIORIDAD 3: Coincidencia exacta normalizada ---
        if ($idMatch === null) {
            foreach ($catalogo as $cat) {
                if ($cat['norm'] === $selNorm) {
                    $idMatch = $cat['id'];
                    break;
                }
            }
        }

        // --- PRIORIDAD 4: Coincidencia de palabras clave exacta ---
        if ($idMatch === null) {
            foreach ($catalogo as $cat) {
                if ($cat['clave'] !== '' && $cat['clave'] === $selClave) {
                    $idMatch = $cat['id'];
                    break;
                }
            }
        }

        // --- PRIORIDAD 5: Tokens completos ---
        if ($idMatch === null && !empty($selTokens)) {
            $bestId = null;
            $bestScore = 0;
            foreach ($catalogo as $cat) {
                if (empty($cat['tokens'])) continue;
                $inter = array_intersect($selTokens, $cat['tokens']);
                $score = count($inter);
                if ($score === count($selTokens) && $score > $bestScore) {
                    $bestScore = $score;
                    $bestId = $cat['id'];
                }
            }
            if ($bestId !== null) {
                $idMatch = $bestId;
            }
        }

        // --- PRIORIDAD 6: Umbral parcial ---
        if ($idMatch === null && !empty($selTokens)) {
            $umbral = max(1, (int)ceil(count($selTokens) / 2));
            $bestId = null;
            $bestScore = 0;
            foreach ($catalogo as $cat) {
                if (empty($cat['tokens'])) continue;
                $inter = array_intersect($selTokens, $cat['tokens']);
                $score = count($inter);
                if ($score >= $umbral && $score > $bestScore) {
                    $bestScore = $score;
                    $bestId = $cat['id'];
                }
            }
            if ($bestId !== null) {
                $idMatch = $bestId;
            }
        }

        // --- PRIORIDAD 7: Matching por tipo de acampante ---
        // Detecta palabras como "niños", "mayores", "adolescentes", "jovenes"
        if ($idMatch === null) {
            $tipoDetectado = null;
            if (preg_match('/\b(ninos|ninas|niños|niñas|peques|chicos)\b/', $selNorm)) {
                $tipoDetectado = 'ninos';
            } elseif (preg_match('/\b(mayores|adultos|jovenes \\+ 18|jovenes mas 18|comunidad joven)\b/', $selNorm)) {
                $tipoDetectado = 'mayores';
            } elseif (preg_match('/\b(adolescentes|jovenes)\b/', $selNorm)) {
                $tipoDetectado = 'adolescentes';
            }

            if ($tipoDetectado !== null) {
                foreach ($catalogo as $cat) {
                    if ($cat['tipo'] === $tipoDetectado) {
                        $idMatch = $cat['id'];
                        break;
                    }
                }
            }
        }

        // --- PRIORIDAD 8: Similaridad textual ---
        if ($idMatch === null && $selClave !== '') {
            foreach ($catalogo as $cat) {
                if ($cat['clave'] === '') continue;
                similar_text($cat['clave'], $selClave, $pct);
                if ($pct >= 85) {
                    $idMatch = $cat['id'];
                    break;
                }
            }
        }

        // --- PRIORIDAD 9: Matching por fechas ---
        if ($idMatch === null && !empty($selRaw)) {
            $fechasTexto = [];
            if (preg_match_all('/(\d{1,2})\s*[-–]\s*(\d{1,2})\s*[a-z]*/i', $selRaw, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $fechasTexto[] = [(int)$m[1], (int)$m[2]];
                }
            }

            foreach ($catalogo as $cat) {
                if (empty($cat['fecha_inicio']) || empty($cat['fecha_fin'])) continue;
                $ini = (int)date('d', strtotime($cat['fecha_inicio']));
                $fin = (int)date('d', strtotime($cat['fecha_fin']));

                foreach ($fechasTexto as $rango) {
                    if (($rango[0] >= $ini && $rango[0] <= $fin) ||
                        ($rango[1] >= $ini && $rango[1] <= $fin) ||
                        ($rango[0] <= $ini && $rango[1] >= $fin)) {
                        $idMatch = $cat['id'];
                        break 2;
                    }
                }
            }
        }

        if ($idMatch !== null && !in_array($idMatch, $idsMatch, true)) {
            $idsMatch[] = $idMatch;
        }
    }

    return array_unique($idsMatch);
}

// ==================================================================
// 3) LIMPIEZA DE REGISTROS BASURA
// ==================================================================
function limpiarEquipantesBasura(PDO $pdo, int $year = 0): int
{
    $condiciones = [
        "nombre NOT REGEXP '[a-zA-ZáéíóúÁÉÍÓÚñÑ]'",
        "nombre LIKE CONCAT('%', CHAR(10), '%')",
        "nombre LIKE CONCAT('%', CHAR(13), '%')",
        "CHAR_LENGTH(nombre) > 150",
        "TRIM(nombre) IN ('#N/A', '#REF!', '#VALUE!', 'NULL', 'null', '')",
    ];

    $where = '(' . implode(' OR ', $condiciones) . ')';
    $params = [];

    if ($year > 0) {
        $where .= " AND (year_campamento = ? OR year_campamento IS NULL)";
        $params[] = $year;
    }

    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM equipantes WHERE $where");
    $stmtC->execute($params);
    $basura = (int)$stmtC->fetchColumn();

    if ($basura === 0) {
        return 0;
    }

    $sqlDist = "DELETE FROM distribucion_equipantes
                 WHERE equipante_id IN (
                     SELECT id FROM equipantes WHERE $where
                 )";
    $pdo->prepare($sqlDist)->execute($params);

    $stmtDel = $pdo->prepare("DELETE FROM equipantes WHERE $where");
    $stmtDel->execute($params);

    return $stmtDel->rowCount() ?: $basura;
}

function parsearCSV(string $csvData): array {
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csvData);
    rewind($handle);

    $filas = [];
    while (($fila = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $contenido = array_filter($fila, fn($v) => trim((string)$v) !== '');
        if (empty($contenido)) continue;
        $filas[] = $fila;
    }

    fclose($handle);
    return $filas;
}

if (isset($_GET['sync']) && $urlSync) {
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlSync);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
        $csvData   = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($csvData === false || $httpCode !== 200 || empty($csvData)) {
            $error = 'No se pudo descargar el CSV. Codigo HTTP: ' . $httpCode . ' Error: ' . $curlError;
        } else {
            if (!mb_check_encoding($csvData, 'UTF-8')) {
                $csvData = mb_convert_encoding($csvData, 'UTF-8', 'auto');
            }

            $lineas = parsearCSV($csvData);

            if (empty($lineas)) {
                $error = 'El CSV esta vacio o no se pudo leer.';
            } else {

                $filaHeaders = null;
                foreach ($lineas as $i => $fila) {
                    $filaLower = array_map(fn($v) => mb_strtolower(trim((string)$v), 'UTF-8'), $fila);
                    foreach ($filaLower as $v) {
                        if (in_array($v, ['nombre', 'nombre y apellidos', 'nombre completo'], true)) {
                            $filaHeaders = $i;
                            break 2;
                        }
                    }
                }

                if ($filaHeaders === null) {
                    $error = 'No se encontro la fila de encabezados en el CSV. Verifica que exista una columna "Nombre y Apellidos".';
                } else {

                    $headersReales = $lineas[$filaHeaders];
                    $mapa          = [];
                    $especificaIdx = 0;

                    foreach ($headersReales as $colIdx => $h) {
                        $hL = mb_strtolower(trim((string)$h), 'UTF-8');

                        if (in_array($hL, ['nombre', 'nombre y apellidos', 'nombre completo'], true))
                            $mapa['nombre'] = $colIdx;

                        elseif (str_contains($hL, 'edad'))
                            $mapa['edad'] = $colIdx;

                        elseif ($hL === 'sexo' || str_contains($hL, 'genero') || str_contains($hL, 'género')
                             || str_contains($hL, 'marca la opción') || str_contains($hL, 'marca la opcion'))
                            $mapa['sexo'] = $colIdx;

                        elseif (str_contains($hL, 'direcci') || str_contains($hL, 'de qué estado')
                             || str_contains($hL, 'de que estado'))
                            $mapa['direccion'] = $colIdx;

                        elseif (str_contains($hL, 'correo') && !str_contains($hL, 'pastor'))
                            $mapa['correo'] = $colIdx;

                        elseif ((str_contains($hL, 'whatsapp') || str_contains($hL, 'teléfono') || str_contains($hL, 'telefono') || str_contains($hL, 'celular'))
                             && !str_contains($hL, 'pastor'))
                            $mapa['telefono_whatsapp'] = $colIdx;

                        elseif (str_contains($hL, 'fecha') || str_contains($hL, 'asistir')
                             || str_contains($hL, 'considera') || str_contains($hL, 'semanas'))
                            $mapa['semanas_disponibles'] = $colIdx;

                        elseif (str_contains($hL, 'devocional'))
                            $mapa['devocional_usado'] = $colIdx;

                        elseif (str_contains($hL, 'iglesia') && !str_contains($hL, 'ministerio'))
                            $mapa['iglesia'] = $colIdx;

                        elseif (str_contains($hL, 'pastor')
                             && (str_contains($hL, 'autoriz') || str_contains($hL, 'nombre del pastor')
                              || str_contains($hL, 'escribe el nombre del pastor')))
                            $mapa['pastor_autoriza'] = $colIdx;

                        elseif (str_contains($hL, 'pastor')
                             && (str_contains($hL, 'celular') || str_contains($hL, 'teléfono') || str_contains($hL, 'telefono')
                              || str_contains($hL, 'número') || str_contains($hL, 'numero')))
                            $mapa['pastor_telefono'] = $colIdx;

                        elseif (str_contains($hL, 'pastor') && str_contains($hL, 'correo'))
                            $mapa['pastor_correo'] = $colIdx;

                        elseif (str_contains($hL, 'ministerio'))
                            $mapa['ministerio_iglesia'] = $colIdx;

                        elseif (str_contains($hL, 'testimonio') || str_contains($hL, 'salvaci'))
                            $mapa['testimonio_salvacion'] = $colIdx;

                        elseif (str_contains($hL, 'por qu') || str_contains($hL, 'deseas ser parte')
                             || str_contains($hL, 'equipo de verano'))
                            $mapa['motivo_servir'] = $colIdx;

                        elseif (str_contains($hL, 'practicas un deporte') || str_contains($hL, 'practica un deporte'))
                            $mapa['practica_deporte'] = $colIdx;

                        elseif (str_contains($hL, 'tocas algún instrumento') || str_contains($hL, 'tocas algun instrumento'))
                            $mapa['toca_instrumento'] = $colIdx;

                        elseif ($hL === 'especifica' || $hL === 'especifíca') {
                            $especificaIdx++;
                            if ($especificaIdx === 1) $mapa['deporte_especifica']     = $colIdx;
                            if ($especificaIdx === 2) $mapa['instrumento_especifica'] = $colIdx;
                        }

                        elseif (str_contains($hL, 'estudias') || str_contains($hL, 'qué estudias') || str_contains($hL, 'que estudias'))
                            $mapa['estudios'] = $colIdx;

                        elseif (str_contains($hL, 'habilidades') || str_contains($hL, 'oficios') || str_contains($hL, 'profesi'))
                            $mapa['habilidades_oficios'] = $colIdx;

                        elseif (str_contains($hL, 'cualidades') || str_contains($hL, 'te identificas'))
                            $mapa['cualidades'] = $colIdx;

                        elseif (str_contains($hL, 'campero') || str_contains($hL, 'campamentos de pv'))
                            $mapa['fue_campero'] = $colIdx;

                        elseif ($hL === 'aceptado' || $hL === 'estado'
                             || str_contains($hL, 'aceptado-en espera') || str_contains($hL, 'aceptado - en espera'))
                            $mapa['estado_excel'] = $colIdx;

                        elseif (str_contains($hL, 'observaciones'))
                            $mapa['observaciones_excel'] = $colIdx;

                        elseif (str_contains($hL, 'tipo persona') || $hL === 'tipo')
                            $mapa['tipo_persona'] = $colIdx;
                    }

                    if (!isset($mapa['nombre'])) {
                        $error = 'No se encontro la columna "Nombre" en el CSV.';
                    } else {

                        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

                        $importados       = 0;
                        $actualizados     = 0;
                        $semanasAsignadas = 0;
                        $vaciasSeguidas   = 0;

                        $basuraEliminada = limpiarEquipantesBasura($pdo, $year);

                        $limites = [
                            'nombre'                => 150,
                            'direccion'             => 255,
                            'correo'                => 150,
                            'telefono_whatsapp'     => 50,
                            'semanas_disponibles'   => 500,
                            'devocional_usado'      => 150,
                            'iglesia'               => 150,
                            'pastor_autoriza'       => 150,
                            'pastor_telefono'       => 50,
                            'pastor_correo'         => 150,
                            'ministerio_iglesia'    => 150,
                            'deporte_especifica'    => 150,
                            'instrumento_especifica'=> 150,
                            'estudios'              => 255,
                            'temporadas_campero'    => 255,
                            'observaciones_excel'   => 255,
                            'testimonio_salvacion'  => 5000,
                            'motivo_servir'         => 5000,
                            'habilidades_oficios'   => 5000,
                            'cualidades'            => 5000,
                            'tipo_persona'          => 20,
                        ];

                        $getVal = function(array $fila, string $campo) use ($mapa): string {
                            if (!isset($mapa[$campo])) return '';
                            return trim($fila[$mapa[$campo]] ?? '');
                        };

                        for ($i = $filaHeaders + 1; $i < count($lineas); $i++) {
                            $fila = $lineas[$i];

                            $nombre = $getVal($fila, 'nombre');
                            if (mb_strlen($nombre, 'UTF-8') > 150) {
                                $nombre = mb_substr($nombre, 0, 150, 'UTF-8');
                            }

                            if ($nombre === '') {
                                $vaciasSeguidas++;
                                if ($vaciasSeguidas > 20) break;
                                continue;
                            }
                            $vaciasSeguidas = 0;

                            $vals = [];
                            $camposTexto = [
                                'edad', 'sexo', 'direccion', 'correo', 'telefono_whatsapp',
                                'semanas_disponibles', 'devocional_usado', 'iglesia',
                                'pastor_autoriza', 'pastor_telefono', 'pastor_correo',
                                'ministerio_iglesia', 'testimonio_salvacion', 'motivo_servir',
                                'practica_deporte', 'deporte_especifica', 'toca_instrumento',
                                'instrumento_especifica', 'estudios', 'habilidades_oficios',
                                'cualidades', 'fue_campero', 'estado_excel', 'observaciones_excel', 'tipo_persona',
                            ];
                            foreach ($camposTexto as $campo) {
                                $valor = $getVal($fila, $campo);
                                $valor = mb_convert_encoding($valor, 'UTF-8', 'UTF-8');
                                $valor = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $valor);
                                $limite = $limites[$campo] ?? null;
                                if ($limite !== null && mb_strlen($valor, 'UTF-8') > $limite) {
                                    $valor = mb_substr($valor, 0, $limite, 'UTF-8');
                                }
                                $vals[$campo] = $valor;
                            }

                            $tipoPersona = normalizarTipoPersona($vals['tipo_persona'] ?? '');

                            $edad = ($vals['edad'] !== '') ? (int)$vals['edad'] : null;

                            $sexoVal = mb_strtolower($vals['sexo'], 'UTF-8');
                            $sexo = null;
                            if (in_array($sexoVal, ['mujer', 'femenino', 'f', 'fem'], true))    $sexo = 'femenino';
                            if (in_array($sexoVal, ['hombre', 'masculino', 'm', 'masc'], true)) $sexo = 'masculino';

                            $depVal      = mb_strtolower($vals['practica_deporte'], 'UTF-8');
                            $practicaDep = in_array($depVal, ['si', 'sí', 'sip', 's', 'yes', '1', 'true'], true) ? 1 : 0;

                            $instVal  = mb_strtolower($vals['toca_instrumento'], 'UTF-8');
                            $tocaInst = in_array($instVal, ['si', 'sí', 'sip', 's', 'yes', '1', 'true'], true) ? 1 : 0;

                            $estadoExcel = mb_strtoupper(trim($vals['estado_excel']), 'UTF-8');
                            $estadoExcelValido = false;
                            $estadoEq = 'en espera';
                            if (in_array($estadoExcel, ['ACEPTADA', 'ACEPTADO'], true)) {
                                $estadoEq = 'aceptado';
                                $estadoExcelValido = true;
                            }
                            if (in_array($estadoExcel, ['RECHAZADO', 'RECHAZADA'], true)) {
                                $estadoEq = 'rechazado';
                                $estadoExcelValido = true;
                            }
                            if (in_array($estadoExcel, ['CONSEJERA', 'CONSEJERO'], true)) {
                                $estadoEq = 'consejero';
                                $estadoExcelValido = true;
                            }

                            // Alumno, misionero, invitado y cocina siempre quedan aceptados
                            if (in_array($tipoPersona, ['alumno', 'misionero', 'invitado', 'cocina'], true)) {
                                $estadoEq = 'aceptado';
                                $estadoExcelValido = true;
                            }

                            $fueCamp        = 0;
                            $temporadasCamp = null;
                            $campVal        = trim($vals['fue_campero']);
                            if ($campVal !== '' && stripos($campVal, 'no') !== 0) {
                                $fueCamp = 1;
                                if (preg_match('/\(([^)]+)\)/', $campVal, $m)) {
                                    $temporadasCamp = trim($m[1]);
                                } else {
                                    $temporadasCamp = $campVal;
                                }
                            }

                            $semanasMatch  = matchearSemanasSync($pdo, $vals['semanas_disponibles'], $year);
                            $semanasIdsStr = implode(',', $semanasMatch);

                            $stmtCheck = $pdo->prepare("SELECT id, estado FROM equipantes WHERE nombre = ? AND year_campamento = ? LIMIT 1");
                            $stmtCheck->execute([$nombre, $year]);
                            $existenteRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                            $existente = $existenteRow['id'] ?? null;
                            $estadoActual = $existenteRow['estado'] ?? 'en espera';

                            if ($existente) {
                                // REGLA PRIORITARIA: Si ya está rechazado en la BD, NUNCA cambiarlo
                                if ($estadoActual === 'rechazado' || $estadoActual === 'rechazada') {
                                    $estadoEq = 'rechazado';
                                }
                                // Si es equipante y el Excel no trae estado válido, mantener estado actual
                                elseif ($tipoPersona === 'equipante' && !$estadoExcelValido) {
                                    $estadoEq = $estadoActual;
                                }

                                // Equipantes: no actualizamos estado (salvo que Excel diga algo, pero ya lo manejamos arriba)
                                if ($tipoPersona === 'equipante') {
                                    $stmt = $pdo->prepare("
                                        UPDATE equipantes SET
                                            edad=?, sexo=?, direccion=?, correo=?, telefono_whatsapp=?,
                                            semanas_disponibles=?, devocional_usado=?, iglesia=?, pastor_autoriza=?,
                                            pastor_telefono=?, pastor_correo=?, ministerio_iglesia=?,
                                            testimonio_salvacion=?, motivo_servir=?, practica_deporte=?, deporte_especifica=?,
                                            toca_instrumento=?, instrumento_especifica=?, estudios=?, habilidades_oficios=?,
                                            cualidades=?, fue_campero=?, temporadas_campero=?,
                                            tipo_persona=?
                                        WHERE id=?
                                    ");
                                    $stmt->execute([
                                        $edad, $sexo, $vals['direccion'], $vals['correo'],
                                        $vals['telefono_whatsapp'], $semanasIdsStr,
                                        $vals['devocional_usado'], $vals['iglesia'],
                                        $vals['pastor_autoriza'], $vals['pastor_telefono'],
                                        $vals['pastor_correo'], $vals['ministerio_iglesia'],
                                        $vals['testimonio_salvacion'], $vals['motivo_servir'],
                                        $practicaDep, $vals['deporte_especifica'], $tocaInst,
                                        $vals['instrumento_especifica'], $vals['estudios'],
                                        $vals['habilidades_oficios'], $vals['cualidades'],
                                        $fueCamp, $temporadasCamp,
                                        $tipoPersona,
                                        $existente,
                                    ]);
                                } else {
                                    // Alumnos, misioneros, invitados, cocina: respetar rechazado, de lo contrario aceptado
                                    $stmt = $pdo->prepare("
                                        UPDATE equipantes SET
                                            edad=?, sexo=?, direccion=?, correo=?, telefono_whatsapp=?,
                                            semanas_disponibles=?, devocional_usado=?, iglesia=?, pastor_autoriza=?,
                                            pastor_telefono=?, pastor_correo=?, ministerio_iglesia=?,
                                            testimonio_salvacion=?, motivo_servir=?, practica_deporte=?, deporte_especifica=?,
                                            toca_instrumento=?, instrumento_especifica=?, estudios=?, habilidades_oficios=?,
                                            cualidades=?, fue_campero=?, temporadas_campero=?,
                                            tipo_persona=?, estado=?
                                        WHERE id=?
                                    ");
                                    $stmt->execute([
                                        $edad, $sexo, $vals['direccion'], $vals['correo'],
                                        $vals['telefono_whatsapp'], $semanasIdsStr,
                                        $vals['devocional_usado'], $vals['iglesia'],
                                        $vals['pastor_autoriza'], $vals['pastor_telefono'],
                                        $vals['pastor_correo'], $vals['ministerio_iglesia'],
                                        $vals['testimonio_salvacion'], $vals['motivo_servir'],
                                        $practicaDep, $vals['deporte_especifica'], $tocaInst,
                                        $vals['instrumento_especifica'], $vals['estudios'],
                                        $vals['habilidades_oficios'], $vals['cualidades'],
                                        $fueCamp, $temporadasCamp,
                                        $tipoPersona, $estadoEq,
                                        $existente,
                                    ]);
                                }
                                $actualizados++;
                                $equipanteId = (int)$existente;
                            } else {
                                $stmt = $pdo->prepare("
                                    INSERT INTO equipantes (
                                        nombre, edad, sexo, direccion, correo, telefono_whatsapp,
                                        semanas_disponibles, devocional_usado, iglesia, pastor_autoriza,
                                        pastor_telefono, pastor_correo, ministerio_iglesia,
                                        testimonio_salvacion, motivo_servir, practica_deporte, deporte_especifica,
                                        toca_instrumento, instrumento_especifica, estudios, habilidades_oficios,
                                        cualidades, fue_campero, temporadas_campero, estado, observaciones,
                                        tipo_persona, year_campamento, registrado_por
                                    ) VALUES (
                                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                                        ?, ?, ?, ?, ?, ?, ?, ?, ?
                                    )
                                ");
                                $stmt->execute([
                                    $nombre, $edad, $sexo, $vals['direccion'], $vals['correo'],
                                    $vals['telefono_whatsapp'], $semanasIdsStr,
                                    $vals['devocional_usado'], $vals['iglesia'],
                                    $vals['pastor_autoriza'], $vals['pastor_telefono'],
                                    $vals['pastor_correo'], $vals['ministerio_iglesia'],
                                    $vals['testimonio_salvacion'], $vals['motivo_servir'],
                                    $practicaDep, $vals['deporte_especifica'], $tocaInst,
                                    $vals['instrumento_especifica'], $vals['estudios'],
                                    $vals['habilidades_oficios'], $vals['cualidades'],
                                    $fueCamp, $temporadasCamp, $estadoEq, $vals['observaciones_excel'],
                                    $tipoPersona, $year, $userId,
                                ]);
                                $importados++;
                                $equipanteId = (int)$pdo->lastInsertId();
                            }

                            foreach ($semanasMatch as $semId) {
                                $stmtDist = $pdo->prepare("
                                    INSERT IGNORE INTO distribucion_equipantes (equipante_id, semana_id, area_id, asignado_por)
                                    VALUES (?, ?, NULL, ?)
                                ");
                                $stmtDist->execute([$equipanteId, $semId, $userId]);
                                $semanasAsignadas++;
                            }
                        }

                        $mensaje = "Sincronizacion completa: {$importados} nuevos, {$actualizados} actualizados, {$semanasAsignadas} semanas asignadas";
                        if ($basuraEliminada > 0) {
                            $mensaje .= ", {$basuraEliminada} registros basura eliminados";
                        }
                        $mensaje .= ".";

                    }
                }
            }
        }

    } catch (Exception $e) {
        $error = 'Error durante la sincronizacion: ' . $e->getMessage();
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

include '../includes/header.php';
?>

<div class="container-fluid py-3">

<?php if ($mensaje): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($mensaje); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0"><i class="fas fa-sync-alt text-primary"></i> Sincronizar Google Sheets</h1>
            <small class="text-muted">Conecta tu Google Sheet para mantener los equipantes actualizados automaticamente.</small>
        </div>
        <a href="reclutamiento.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-link"></i> Configuracion</h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="mb-3">
                            <label class="form-label">URL del Google Sheet (CSV publicado)</label>
                            <input type="text" name="url_sync" class="form-control"
                                   placeholder="https://docs.google.com/spreadsheets/d/e/XXXXX/pub?output=csv"
                                   value="<?php echo htmlspecialchars($urlSync); ?>">
                            <small class="text-muted">
                                Para obtener la URL: Archivo > Publicar en la web > Formato CSV > Publicar
                            </small>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-save"></i> Guardar URL
                        </button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm mt-3">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-info-circle"></i> Como configurar</h6>
                </div>
                <div class="card-body">
                    <ol class="small mb-0">
                        <li>Abre tu Google Sheet</li>
                        <li>Ve a <strong>Archivo > Compartir > Publicar en la web</strong></li>
                        <li>Selecciona la hoja <strong>"HOJA DE TRABAJO"</strong></li>
                        <li>Elige formato <strong>CSV</strong></li>
                        <li>Click en <strong>Publicar</strong></li>
                        <li>Copia el enlace y pegalo arriba</li>
                        <li>Guarda y luego click en "Sincronizar ahora"</li>
                    </ol>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-cloud-download-alt"></i> Sincronizar</h6>
                </div>
                <div class="card-body text-center">
                    <?php if ($urlSync): ?>
                    <p class="text-muted">La URL esta configurada. Click para descargar y actualizar los datos.</p>
                    <a href="?sync=1" class="btn btn-success btn-lg"
                       onclick="return confirm('Se descargaran los datos del Google Sheet y se actualizaran los equipantes. Continuar?')">
                        <i class="fas fa-sync-alt"></i> Sincronizar ahora
                    </a>
                    <?php else: ?>
                    <p class="text-muted">Primero configura la URL del Google Sheet.</p>
                    <button class="btn btn-secondary btn-lg" disabled>
                        <i class="fas fa-sync-alt"></i> Sincronizar ahora
                    </button>
                    <?php endif; ?>
                </div>
                <?php if ($urlSync): ?>
                <div class="card-footer bg-light small text-muted">
                    <i class="fas fa-info-circle"></i>
                    La sincronizacion creara nuevos equipantes o actualizara los existentes (por nombre).
                    Tambien limpiara registros basura de sincronizaciones anteriores.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>