<?php
// admisiones/importar.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// ── Cargar SimpleXLSX (solo el archivo base, SIN SimpleXLSXEx) ───────────
$simplexlsx_disponible = false;
$simplexlsx_path = __DIR__ . '/../libs/SimpleXLSX.php';
if (file_exists($simplexlsx_path)) {
    require_once $simplexlsx_path;
    $simplexlsx_disponible = class_exists('Shuchkin\SimpleXLSX');
}

verificarLogin();
if (!esAdmisiones() && !esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$titulo  = "Importar desde Google Sheets";
$year    = obtenerAnioCampamento();
$error   = '';
$message = '';

$stmt_sem = $pdo->prepare("SELECT * FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio");
$stmt_sem->execute([$year]);
$semanas = $stmt_sem->fetchAll();

// ── Helpers ────────────────────────────────────────────────────────────────

if (!function_exists('detectarColumna')) {
    function detectarColumna(array $headers, array $keywords): int {
        foreach ($headers as $i => $h) {
            $h_lower = mb_strtolower(trim((string)$h));
            foreach ($keywords as $kw) {
                if (mb_strpos($h_lower, mb_strtolower($kw)) !== false) return $i;
            }
        }
        return -1;
    }
}

/**
 * Extrae un mapa [fila_0based => true] de filas con relleno oscuro
 * leyendo directamente el XML interno del XLSX.
 *
 * Estrategia:
 *  1. Parsear xl/styles.xml → obtener fills (colores de fondo)
 *  2. Parsear xl/worksheets/sheet1.xml → para cada <row> ver el atributo
 *     s (style index) de la primera celda y resolverlo contra los fills.
 *
 * No usa rowsEx() ni SimpleXLSXEx.php.
 *
 * @param  \Shuchkin\SimpleXLSX $xlsx
 * @return array  [fila_0based => true]  filas que deben omitirse
 */
function obtenerFilasOscuras(\Shuchkin\SimpleXLSX $xlsx): array
{
    $oscuras = [];

    // ── 1. Leer fills desde styles ─────────────────────────────────────
    // $xlsx->styles ya es un SimpleXMLElement parseado
    $fills_oscuros = []; // [fillId => true]

    if ($xlsx->styles && isset($xlsx->styles->fills->fill)) {
        $fill_idx = 0;
        foreach ($xlsx->styles->fills->fill as $fill) {
            $color_hex = null;

            // patternFill puede tener fgColor o bgColor
            if (isset($fill->patternFill)) {
                $pf = $fill->patternFill;

                // fgColor tiene prioridad (es el color de relleno sólido)
                foreach (['fgColor', 'bgColor'] as $tag) {
                    if (isset($pf->$tag)) {
                        $c = $pf->$tag;
                        $attrs = [];
                        foreach ($c->attributes() as $k => $v) {
                            $attrs[(string)$k] = (string)$v;
                        }
                        // rgb="FF000000" → los últimos 6 son RRGGBB
                        if (!empty($attrs['rgb'])) {
                            $hex = substr($attrs['rgb'], -6); // quitar canal alpha
                            $color_hex = $hex;
                            break;
                        }
                        // theme color: no podemos resolverlo fácilmente, ignorar
                    }
                }
            }

            if ($color_hex && strlen($color_hex) === 6) {
                $r = hexdec(substr($color_hex, 0, 2));
                $g = hexdec(substr($color_hex, 2, 2));
                $b = hexdec(substr($color_hex, 4, 2));
                $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                if ($lum < 80) {
                    $fills_oscuros[$fill_idx] = true;
                }
            }
            $fill_idx++;
        }
    }

    if (empty($fills_oscuros)) return []; // no hay fills oscuros en el archivo

    // ── 2. Mapear cellXfs → fillId ────────────────────────────────────
    // cellXfs: cada xf tiene fillId → [xf_index => fillId]
    $xf_fill = []; // [xf_index => fillId]
    if ($xlsx->styles && isset($xlsx->styles->cellXfs->xf)) {
        $xf_idx = 0;
        foreach ($xlsx->styles->cellXfs->xf as $xf) {
            $attrs = [];
            foreach ($xf->attributes() as $k => $v) {
                $attrs[(string)$k] = (string)$v;
            }
            $xf_fill[$xf_idx] = isset($attrs['fillId']) ? (int)$attrs['fillId'] : 0;
            $xf_idx++;
        }
    }

    // ── 3. Leer hoja y detectar filas oscuras ─────────────────────────
    // Usamos el XML de la hoja directamente: $xlsx->sheets[0]
    if (!isset($xlsx->sheets[0])) return [];

    $ws = $xlsx->sheets[0];
    $row_idx = 0;

    foreach ($ws->sheetData->row as $row) {
        $es_oscura = false;

        // Revisar las primeras 4 celdas de la fila
        $checked = 0;
        foreach ($row->c as $cell) {
            if ($checked >= 4) break;

            $cell_attrs = [];
            foreach ($cell->attributes() as $k => $v) {
                $cell_attrs[(string)$k] = (string)$v;
            }

            // s = style index (xf index)
            if (isset($cell_attrs['s'])) {
                $s = (int)$cell_attrs['s'];
                if (isset($xf_fill[$s])) {
                    $fill_id = $xf_fill[$s];
                    if (isset($fills_oscuros[$fill_id])) {
                        $es_oscura = true;
                        break;
                    }
                }
            }
            $checked++;
        }

        if ($es_oscura) {
            // Obtener número de fila real del XML (atributo r="N")
            $row_attrs = [];
            foreach ($row->attributes() as $k => $v) {
                $row_attrs[(string)$k] = (string)$v;
            }
            // r es 1-based en XLSX, convertir a 0-based
            $r = isset($row_attrs['r']) ? (int)$row_attrs['r'] - 1 : $row_idx;
            $oscuras[$r] = true;
        }
        $row_idx++;
    }

    return $oscuras;
}

// ── Procesar upload ────────────────────────────────────────────────────────
if ($_POST && isset($_FILES['xlsx_file'])) {
    try {
        $semana_id = (int)($_POST['semana_id'] ?? 0);
        $modo      = $_POST['modo'] ?? 'ambos';
        $costo     = (float)($_POST['costo_default'] ?? 0);

        if (!$semana_id) throw new Exception("Selecciona una semana");

        if ($_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE   => 'El archivo supera upload_max_filesize en php.ini',
                UPLOAD_ERR_FORM_SIZE  => 'El archivo supera MAX_FILE_SIZE del formulario',
                UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente',
                UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo',
                UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal del servidor',
                UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en disco',
            ];
            $code = $_FILES['xlsx_file']['error'];
            throw new Exception($upload_errors[$code] ?? "Error al subir el archivo (código {$code})");
        }

        $ext = strtolower(pathinfo($_FILES['xlsx_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'])) {
            throw new Exception("El archivo debe ser .xlsx o .csv (recibido: .{$ext})");
        }

        // ── Leer filas ─────────────────────────────────────────────────
        $filas_datos   = []; // [ ['values'=>[...], 'oscura'=>bool] ]
        $filas_oscuras = []; // mapa de índice → true (solo para xlsx)

        if ($ext === 'xlsx') {
            if (!$simplexlsx_disponible) {
                throw new Exception("SimpleXLSX no disponible en libs/SimpleXLSX.php. Usa CSV.");
            }

            $xlsx = \Shuchkin\SimpleXLSX::parse($_FILES['xlsx_file']['tmp_name']);
            if (!$xlsx) {
                throw new Exception("No se pudo leer el XLSX: " . \Shuchkin\SimpleXLSX::parseError());
            }

            // Detectar filas oscuras ANTES de leer rows()
            // (rows() consume el XML internamente, hacerlo después puede fallar)
            $filas_oscuras = obtenerFilasOscuras($xlsx);

            // Leer valores con rows() (no necesita SimpleXLSXEx)
            $all_rows = $xlsx->rows(0);

            foreach ($all_rows as $row_0based => $row) {
                $values = array_map(fn($v) => trim((string)$v), $row);
                $filas_datos[] = [
                    'values' => $values,
                    'oscura' => isset($filas_oscuras[$row_0based]),
                ];
            }

        } else {
            // ── CSV fallback ───────────────────────────────────────────
            $handle = fopen($_FILES['xlsx_file']['tmp_name'], 'r');
            if (!$handle) throw new Exception("No se pudo leer el CSV");

            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($handle);

            while (($row = fgetcsv($handle, 2000, ',')) !== false) {
                $filas_datos[] = [
                    'values' => array_map('trim', $row),
                    'oscura' => false,
                ];
            }
            fclose($handle);
        }

        if (empty($filas_datos)) throw new Exception("El archivo está vacío o no se pudo leer");

        // ── Detectar fila de encabezados ───────────────────────────────
        $encabezados = null;
        $header_idx  = 0;

        foreach ($filas_datos as $idx => $fila) {
            foreach ($fila['values'] as $celda) {
                if (stripos($celda, 'nombre') !== false &&
                    stripos($celda, 'apellido') !== false) {
                    $encabezados = $fila['values'];
                    $header_idx  = $idx;
                    break 2;
                }
            }
            if ($idx >= 5) break;
        }

        if (!$encabezados) {
            $encabezados = $filas_datos[0]['values'];
            $header_idx  = 0;
        }

        // ── Mapeo de columnas ──────────────────────────────────────────
        $col = [
            'nombre'      => detectarColumna($encabezados, ['nombre y apellido', 'nombre y apellidos', 'nombre', 'apellido']),
            'edad'        => detectarColumna($encabezados, ['edad']),
            'curp'        => detectarColumna($encabezados, ['curp']),
            'sexo'        => detectarColumna($encabezados, ['marca la opci', 'sexo', 'género', 'genero', 'opcion', 'opción']),
            'primera'     => detectarColumna($encabezados, ['primera vez']),
            'asiste'      => detectarColumna($encabezados, ['asistes a alguna iglesia', '¿asistes']),
            'nom_igl'     => detectarColumna($encabezados, ['nombre de la iglesia']),
            'cont_nom'    => detectarColumna($encabezados, ['nombre y apellidos del contacto', 'contacto de emergencia']),
            'cont_tel'    => detectarColumna($encabezados, ['número de contacto para emergencias', 'contacto para emergencias']),
            'suma_pagada' => detectarColumna($encabezados, ['suma']),
            'debe_pagar'  => detectarColumna($encabezados, ['debe pagar']),
            'modo'        => detectarColumna($encabezados, ['modo']),
            'llego'       => detectarColumna($encabezados, ['llegaron', 'llegó', 'llego']),
        ];

        if ($col['nombre'] < 0) {
            throw new Exception(
                "No se detectó columna 'Nombre'. Encabezados: " .
                htmlspecialchars(implode(' | ', array_slice($encabezados, 0, 10)))
            );
        }

        // ── Contadores ─────────────────────────────────────────────────
        $insertados     = 0;
        $actualizados   = 0;
        $omitidos       = 0;
        $omitidos_color = 0;
        $checkins_auto  = 0;

        $stmt_costo = $pdo->prepare("SELECT costo_campamento FROM semanas_campamento WHERE id = ?");
        $stmt_costo->execute([$semana_id]);
        $costo_semana = (float)($stmt_costo->fetchColumn() ?? 0);

        $pdo->beginTransaction();

        foreach ($filas_datos as $fila_idx => $fila) {
            if ($fila_idx <= $header_idx) continue;

            $row    = $fila['values'];
            $oscura = $fila['oscura'];

            // Saltar filas vacías
            if (empty(array_filter($row, fn($c) => trim($c) !== ''))) continue;

            // ── Omitir filas con relleno oscuro ────────────────────────
            if ($oscura) {
                $omitidos_color++;
                continue;
            }

            // ── Nombre ────────────────────────────────────────────────
            $nombre = $col['nombre'] >= 0 ? trim($row[$col['nombre']] ?? '') : '';
            if (empty($nombre) || strlen($nombre) < 2) { $omitidos++; continue; }

            // ── CURP ──────────────────────────────────────────────────
            $curp_raw = $col['curp'] >= 0 ? trim($row[$col['curp']] ?? '') : '';
            $curp     = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $curp_raw));
            if (strlen($curp) > 18) $curp = substr($curp, 0, 18);
            if (strlen($curp) < 10) $curp = '';

            // ── Edad ──────────────────────────────────────────────────
            $edad_raw = $col['edad'] >= 0 ? trim($row[$col['edad']] ?? '') : '';
            $edad     = is_numeric($edad_raw) ? (int)$edad_raw : null;

            // ── Sexo ──────────────────────────────────────────────────
            $sexo_raw = $col['sexo'] >= 0 ? strtolower(trim($row[$col['sexo']] ?? '')) : '';
            $sexo = '';
            if (mb_strpos($sexo_raw, 'mujer') !== false ||
                mb_strpos($sexo_raw, 'femenino') !== false || $sexo_raw === 'f') {
                $sexo = 'femenino';
            } elseif (mb_strpos($sexo_raw, 'hombre') !== false ||
                      mb_strpos($sexo_raw, 'masculino') !== false || $sexo_raw === 'm') {
                $sexo = 'masculino';
            }

            // ── Primera vez / Asiste iglesia ──────────────────────────
            $primera_raw = $col['primera'] >= 0 ? strtolower(trim($row[$col['primera']] ?? '')) : '';
            $primera     = in_array($primera_raw, ['si', 'sí', 'yes', '1']) ? 1 : 0;

            $asiste_raw = $col['asiste'] >= 0 ? strtolower(trim($row[$col['asiste']] ?? '')) : '';
            $asiste     = in_array($asiste_raw, ['si', 'sí', 'yes', '1']) ? 1 : 0;

            // ── Iglesia ───────────────────────────────────────────────
            $iglesia = $col['nom_igl'] >= 0 ? trim($row[$col['nom_igl']] ?? '') : '';
            if (in_array(strtolower($iglesia), ['si', 'sí', 'no', 'yes'])) $iglesia = '';

            // ── Contacto emergencia ───────────────────────────────────
            $cont_nom = $col['cont_nom'] >= 0 ? trim($row[$col['cont_nom']] ?? '') : '';
            $cont_tel = $col['cont_tel'] >= 0 ? trim($row[$col['cont_tel']] ?? '') : '';

            // ── Costo ─────────────────────────────────────────────────
            $debe_raw        = $col['debe_pagar'] >= 0 ? trim($row[$col['debe_pagar']] ?? '') : '';
            $costo_acampante = (float)preg_replace('/[^0-9.]/', '', $debe_raw);
            if ($costo_acampante <= 0) {
                $costo_acampante = $costo_semana > 0 ? $costo_semana : $costo;
            }

            // ── Suma pagada ───────────────────────────────────────────
            $suma_raw   = $col['suma_pagada'] >= 0 ? trim($row[$col['suma_pagada']] ?? '') : '';
            $monto_pago = (float)preg_replace('/[^0-9.]/', '', $suma_raw);

            // ── Modo pago ─────────────────────────────────────────────
            $modo_raw  = $col['modo'] >= 0 ? strtolower(trim($row[$col['modo']] ?? '')) : '';
            $modo_pago = 'efectivo';
            if (mb_strpos($modo_raw, 'banco') !== false)    $modo_pago = 'banco';
            if (mb_strpos($modo_raw, 'transfer') !== false) $modo_pago = 'transferencia';

            // ── Llegó ─────────────────────────────────────────────────
            $llego_raw = $col['llego'] >= 0 ? strtolower(trim($row[$col['llego']] ?? '')) : '';
            $llego     = in_array($llego_raw, ['si', 'sí', 'yes', '1', 'x', '✓']) ? 1 : 0;

            // ── ¿Existe ya? ───────────────────────────────────────────
            $stmt_exist = $pdo->prepare("
                SELECT id FROM acampantes
                WHERE semana_id = ? AND nombre = ? AND estado = 'activo'
                LIMIT 1
            ");
            $stmt_exist->execute([$semana_id, $nombre]);
            $existente = $stmt_exist->fetchColumn();

            if ($existente && $modo === 'nuevo') { $omitidos++; continue; }

            if ($existente && in_array($modo, ['actualizar', 'ambos'])) {
                // ── UPDATE ────────────────────────────────────────────
                $pdo->prepare("
                    UPDATE acampantes SET
                        curp       = CASE WHEN ? != '' THEN ? ELSE curp END,
                        edad       = COALESCE(NULLIF(?, 0), edad),
                        sexo       = CASE WHEN ? != '' THEN ? ELSE sexo END,
                        iglesia    = CASE WHEN ? != '' THEN ? ELSE iglesia END,
                        asiste_iglesia         = ?,
                        primera_vez_campamento = ?,
                        contacto_emergencia_nombre   = CASE WHEN ? != '' THEN ? ELSE contacto_emergencia_nombre END,
                        contacto_emergencia_telefono = CASE WHEN ? != '' THEN ? ELSE contacto_emergencia_telefono END,
                        costo_total   = CASE WHEN ? > 0 THEN ? ELSE costo_total END,
                        llego         = CASE WHEN ? = 1 THEN 1 ELSE llego END,
                        fecha_llegada = CASE WHEN ? = 1 AND llego = 0 THEN NOW() ELSE fecha_llegada END
                    WHERE id = ?
                ")->execute([
                    $curp, $curp, $edad,
                    $sexo, $sexo, $iglesia, $iglesia,
                    $asiste, $primera,
                    $cont_nom, $cont_nom, $cont_tel, $cont_tel,
                    $costo_acampante, $costo_acampante,
                    $llego, $llego, $existente
                ]);

                if ($monto_pago > 0 && $monto_pago <= $costo_acampante) {
                    $stmt_ya = $pdo->prepare("
                        SELECT COALESCE(SUM(monto), 0) FROM pagos_acampante
                        WHERE acampante_id = ? AND es_pago_registro = 1
                    ");
                    $stmt_ya->execute([$existente]);
                    $diferencia = $monto_pago - (float)$stmt_ya->fetchColumn();
                    if ($diferencia > 0.01) {
                        $pdo->prepare("
                            INSERT INTO pagos_acampante
                                (acampante_id, monto, modo_pago, es_pago_registro, notas, registrado_por)
                            VALUES (?, ?, ?, 1, 'Importado (ajuste)', ?)
                        ")->execute([$existente, $diferencia, $modo_pago, $_SESSION['user_id']]);
                    }
                }
                $actualizados++;
                if (function_exists('verificarYActivarCheckin')) {
                    if (verificarYActivarCheckin($pdo, $existente)) $checkins_auto++;
                }

            } elseif (!$existente && in_array($modo, ['nuevo', 'ambos'])) {
                // ── INSERT ────────────────────────────────────────────
                $pdo->prepare("
                    INSERT INTO acampantes
                        (nombre, curp, edad, sexo, iglesia,
                         asiste_iglesia, primera_vez_campamento,
                         contacto_emergencia_nombre, contacto_emergencia_telefono,
                         semana_id, year_campamento, costo_total,
                         llego, fecha_llegada, estado, registrado_por)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'activo',?)
                ")->execute([
                    $nombre, $curp ?: null, $edad, $sexo ?: null, $iglesia,
                    $asiste, $primera, $cont_nom, $cont_tel,
                    $semana_id, $year, $costo_acampante,
                    $llego, $llego ? date('Y-m-d H:i:s') : null,
                    $_SESSION['user_id']
                ]);
                $nuevo_id = $pdo->lastInsertId();

                if ($monto_pago > 0 && $monto_pago <= $costo_acampante) {
                    $pdo->prepare("
                        INSERT INTO pagos_acampante
                            (acampante_id, monto, modo_pago, es_pago_registro, notas, registrado_por)
                        VALUES (?, ?, ?, 1, 'Importado', ?)
                    ")->execute([$nuevo_id, $monto_pago, $modo_pago, $_SESSION['user_id']]);
                }
                $insertados++;
                if (function_exists('verificarYActivarCheckin')) {
                    if (verificarYActivarCheckin($pdo, $nuevo_id)) $checkins_auto++;
                }
            }
        } // fin foreach

        $pdo->commit();

        registrarLog($pdo, 'importacion',
            "Importado: {$insertados} nuevos, {$actualizados} actualizados, " .
            "{$omitidos} omitidos, {$omitidos_color} ignorados por color",
            'admisiones', 'success');

        $message = "&#10003; Importación completada — " .
            "<strong>{$insertados}</strong> nuevos &middot; " .
            "<strong>{$actualizados}</strong> actualizados &middot; " .
            "<strong>{$omitidos}</strong> omitidos";

        if ($omitidos_color > 0) {
            $message .= " &middot; <strong class='text-warning'>" .
                "<i class='fas fa-fill-drip'></i> " .
                "{$omitidos_color} ignorado" . ($omitidos_color > 1 ? 's' : '') .
                " por relleno oscuro</strong>";
        }
        if ($checkins_auto > 0) {
            $message .= " &middot; <strong class='text-success'>" .
                "<i class='fas fa-magic'></i> " .
                "{$checkins_auto} check-in automático" .
                ($checkins_auto > 1 ? 's' : '') . "</strong>";
        }
        if ($insertados === 0 && $actualizados === 0 && $omitidos > 0) {
            $message .= "<br><small class='text-warning'>&#9888; Todos omitidos. " .
                "Encabezados detectados: <code>" .
                htmlspecialchars(implode(' | ', array_slice($encabezados ?? [], 0, 8))) .
                "</code></small>";
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-file-excel"></i> <?= $titulo ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Importar</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<?php if (!$simplexlsx_disponible): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>SimpleXLSX no encontrado</strong> en <code>libs/SimpleXLSX.php</code>.
    Solo podrás importar <strong>.csv</strong> (sin filtro de color de fila).
</div>
<?php endif; ?>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> <?= $message ?>
    <a href="lista_acampantes.php" class="btn btn-success btn-sm ms-2">Ver lista</a>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?= $error ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">

    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-upload"></i> Subir archivo</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Semana de Campamento *</label>
                        <select class="form-select" name="semana_id" required>
                            <option value="">Seleccionar semana...</option>
                            <?php foreach ($semanas as $s): ?>
                            <option value="<?= $s['id'] ?>">
                                <?= htmlspecialchars($s['nombre']) ?>
                                — $<?= number_format($s['costo_campamento'], 0) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Modo de importación *</label>
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                    name="modo" value="ambos" id="modo_ambos" checked>
                                <label class="form-check-label" for="modo_ambos">
                                    <strong>Nuevos + Actualizar existentes</strong>
                                    <small class="text-muted d-block">Recomendado</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                    name="modo" value="nuevo" id="modo_nuevo">
                                <label class="form-check-label" for="modo_nuevo">
                                    <strong>Solo nuevos</strong>
                                    <small class="text-muted d-block">Omite los que ya existen</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                    name="modo" value="actualizar" id="modo_act">
                                <label class="form-check-label" for="modo_act">
                                    <strong>Solo actualizar existentes</strong>
                                    <small class="text-muted d-block">No agrega nuevos</small>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Costo por defecto</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" name="costo_default"
                                step="0.01" min="0" value="0">
                        </div>
                        <small class="text-muted">Se usa si el archivo no trae la columna DEBE PAGAR</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Archivo *</label>
                        <input type="file" class="form-control" name="xlsx_file"
                               accept="<?= $simplexlsx_disponible ? '.xlsx,.csv' : '.csv' ?>"
                               required>
                        <?php if ($simplexlsx_disponible): ?>
                        <div class="mt-2 p-2 rounded border border-success bg-light small">
                            <i class="fas fa-star text-success me-1"></i>
                            <strong>Recomendado: <code>.xlsx</code></strong> —
                            detecta y omite automáticamente las filas con
                            <span class="badge" style="background:#222;color:#fff;">relleno oscuro</span>.
                            <br>
                            <span class="text-muted">
                                El <code>.csv</code> también funciona pero sin filtro de color.
                            </span>
                        </div>
                        <?php else: ?>
                        <small class="text-muted">Solo CSV disponible</small>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-success w-100 btn-lg">
                        <i class="fas fa-upload"></i> Importar
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-5">

        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0"><i class="fab fa-google"></i> Cómo exportar de Google Sheets</h6>
            </div>
            <div class="card-body small">
                <?php if ($simplexlsx_disponible): ?>
                <p class="fw-bold text-success mb-2">
                    <i class="fas fa-check-circle"></i> Opción recomendada (conserva colores):
                </p>
                <ol class="mb-2">
                    <li class="mb-1">Abre el Google Sheets de la semana</li>
                    <li class="mb-1">Click en <strong>Archivo → Descargar</strong></li>
                    <li class="mb-1">Selecciona <strong>Microsoft Excel (.xlsx)</strong></li>
                    <li>Sube el archivo aquí ✅</li>
                </ol>
                <hr class="my-2">
                <p class="text-muted mb-0">
                    <i class="fas fa-exclamation-triangle text-warning"></i>
                    Si descargas como <strong>CSV</strong>, las filas con relleno negro
                    <strong>no serán filtradas</strong>.
                </p>
                <?php else: ?>
                <ol class="mb-0">
                    <li class="mb-1">Abre el Google Sheets de la semana</li>
                    <li class="mb-1"><strong>Archivo → Descargar → CSV</strong></li>
                    <li>Sube el archivo aquí</li>
                </ol>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($simplexlsx_disponible): ?>
        <div class="card mb-3 border-warning">
            <div class="card-header bg-warning text-dark">
                <h6 class="mb-0">
                    <i class="fas fa-fill-drip"></i> Cómo marcar filas para ignorar
                </h6>
            </div>
            <div class="card-body small">
                <ol class="mb-2">
                    <li class="mb-1">Selecciona la fila completa en Google Sheets</li>
                    <li class="mb-1">Aplica relleno <strong>negro o muy oscuro</strong></li>
                    <li class="mb-1">Descarga como <strong>.xlsx</strong></li>
                    <li>El sistema las omitirá automáticamente 🎉</li>
                </ol>
                <div class="p-2 rounded text-center text-white small fw-bold"
                     style="background:#111; letter-spacing:1px;">
                    Esta fila será ignorada
                </div>
                <div class="mt-1 text-muted" style="font-size:0.75rem;">
                    Umbral: luminosidad &lt; 80/255
                    (negro, azul marino, verde muy oscuro, etc.)
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fas fa-magic"></i> Columnas detectadas automáticamente
                </h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Columna del Sheets</th><th>Campo del sistema</th></tr>
                    </thead>
                    <tbody class="small">
                        <tr><td>Nombre y Apellidos</td><td>Nombre completo</td></tr>
                        <tr><td>Edad</td><td>Edad</td></tr>
                        <tr class="table-info">
                            <td><i class="fas fa-id-card fa-xs me-1"></i><strong>CURP</strong></td>
                            <td><strong>CURP</strong></td>
                        </tr>
                        <tr><td>Marca la opción (Mujer/Hombre)</td><td>Sexo</td></tr>
                        <tr><td>¿Primera vez en campamento?</td><td>Primera vez</td></tr>
                        <tr><td>¿Asistes a alguna iglesia?</td><td>Asiste a iglesia</td></tr>
                        <tr><td>Nombre de la iglesia</td><td>Iglesia</td></tr>
                        <tr><td>Nombre y Apellidos del contacto</td><td>Contacto emergencia</td></tr>
                        <tr><td>Número de contacto para emergencias</td><td>Teléfono emergencia</td></tr>
                        <tr><td>SUMA</td><td>Total acumulado pagado</td></tr>
                        <tr><td>DEBE PAGAR</td><td>Costo total</td></tr>
                        <tr><td>MODO</td><td>Modo de pago</td></tr>
                        <tr><td>LLEGARON</td><td>Check-in</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="alert alert-info small">
            <i class="fas fa-info-circle"></i>
            Los acampantes se identifican por <strong>nombre exacto</strong>
            dentro de la misma semana.
            El CURP se guarda en mayúsculas (mínimo 10 caracteres válidos).
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>