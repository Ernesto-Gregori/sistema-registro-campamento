<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador()) {
    header('Location: ../login.php');
    exit();
}

$titulo    = "Importar desde Google Sheets";
$year      = obtenerAnioCampamento();
$error     = '';
$message   = '';
$preview   = [];
$errores_fila = [];

// Semanas disponibles
$semanas = $pdo->prepare("SELECT * FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio");
$semanas->execute([$year]);
$semanas = $semanas->fetchAll();

if ($_POST && isset($_FILES['csv_file'])) {
    try {
        $semana_id = (int)($_POST['semana_id'] ?? 0);
        $modo      = $_POST['modo'] ?? 'nuevo';        // nuevo | actualizar | ambos
        $costo     = (float)($_POST['costo_default'] ?? 0);

        if (!$semana_id) throw new Exception("Selecciona una semana");
        if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Error al subir el archivo CSV");
        }

        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') throw new Exception("El archivo debe ser CSV");

        // Leer CSV
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$handle) throw new Exception("No se pudo leer el archivo");
        
        // Eliminar BOM UTF-8 si existe (Google Sheets lo agrega a veces)
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle); // No era BOM, regresar al inicio
        }

        // Detectar encabezados — saltar hasta encontrar la fila con "Nombre"
        $encabezados = null;
        $fila_header = 0;
        while (($row = fgetcsv($handle, 1000, ',')) !== false) {
            $fila_header++;
            // Buscar la fila que contiene "Nombre" o "nombre"
            foreach ($row as $celda) {
                if (stripos($celda, 'nombre') !== false && stripos($celda, 'apellido') !== false) {
                    $encabezados = array_map('trim', $row);
                    break 2;
                }
            }
            // Máximo 5 filas para encontrar el header
            if ($fila_header > 5) break;
        }

        // Si no detectó automáticamente, asumir primera fila
        if (!$encabezados) {
            rewind($handle);
            $encabezados = array_map('trim', fgetcsv($handle, 1000, ','));
        }

        // Mapeo flexible de columnas
        // Detecta la columna correcta según palabras clave en el encabezado
        function detectarColumna(array $headers, array $keywords): int {
            foreach ($headers as $i => $h) {
                $h_lower = mb_strtolower(trim($h));
                foreach ($keywords as $kw) {
                    if (str_contains($h_lower, mb_strtolower($kw))) return $i;
                }
            }
            return -1;
        }

        $col = [
            'nombre'       => detectarColumna($encabezados, ['nombre', 'apellido']),
            'edad'         => detectarColumna($encabezados, ['edad']),
            'sexo'         => detectarColumna($encabezados, ['sexo', 'género', 'genero', 'opcion', 'opción']),
            'primera'      => detectarColumna($encabezados, ['primera vez', 'primera']),
            'asiste'       => detectarColumna($encabezados, ['asistes', 'asiste', 'iglesia']),
            'nom_igl'      => detectarColumna($encabezados, ['nombre de la iglesia', 'iglesia']),
            'cont_nom'     => detectarColumna($encabezados, ['contacto de emergencia', 'nombre.*contacto', 'contacto']),
            'cont_tel'     => detectarColumna($encabezados, ['número', 'telefono', 'teléfono', 'celular']),
            'suma_pagada'  => detectarColumna($encabezados, ['suma']),           // total acumulado pagado
            'debe_pagar'   => detectarColumna($encabezados, ['debe pagar']),     // costo total del campamento
            'modo'         => detectarColumna($encabezados, ['modo']),
            'llego'        => detectarColumna($encabezados, ['llegaron', 'llegó', 'llego']),
        ];

        // Procesar filas
        $insertados  = 0;
        $actualizados = 0;
        $omitidos    = 0;
        $fila_num    = $fila_header;

        $pdo->beginTransaction();

        while (($row = fgetcsv($handle, 1000, ',')) !== false) {
            $fila_num++;

            // Saltar filas vacías
            $row_limpia = array_filter($row, fn($c) => trim($c) !== '');
            if (empty($row_limpia)) continue;

            // Extraer nombre
            $nombre = $col['nombre'] >= 0 ? trim($row[$col['nombre']] ?? '') : '';
            if (empty($nombre) || strlen($nombre) < 2) {
                $omitidos++;
                continue;
            }

            // Extraer y normalizar campos
            $edad_raw = $col['edad'] >= 0 ? trim($row[$col['edad']] ?? '') : '';
            $edad     = is_numeric($edad_raw) ? (int)$edad_raw : null;

            $sexo_raw = $col['sexo'] >= 0 ? strtolower(trim($row[$col['sexo']] ?? '')) : '';
            $sexo = '';
            if (str_contains($sexo_raw, 'mujer') || str_contains($sexo_raw, 'femenino') || $sexo_raw === 'f') {
                $sexo = 'femenino';
            } elseif (str_contains($sexo_raw, 'hombre') || str_contains($sexo_raw, 'masculino') || $sexo_raw === 'm') {
                $sexo = 'masculino';
            }

            $primera_raw = $col['primera'] >= 0 ? strtolower(trim($row[$col['primera']] ?? '')) : '';
            $primera = in_array($primera_raw, ['si', 'sí', 'yes', '1']) ? 1 : 0;

            $asiste_raw = $col['asiste'] >= 0 ? strtolower(trim($row[$col['asiste']] ?? '')) : '';
            $asiste = in_array($asiste_raw, ['si', 'sí', 'yes', '1']) ? 1 : 0;

            $iglesia   = $col['nom_igl']  >= 0 ? trim($row[$col['nom_igl']]  ?? '') : '';
            $cont_nom  = $col['cont_nom'] >= 0 ? trim($row[$col['cont_nom']] ?? '') : '';
            $cont_tel  = $col['cont_tel'] >= 0 ? trim($row[$col['cont_tel']] ?? '') : '';

            // ── Costo total del campamento (DEBE PAGAR) ──────────────
            // Si está vacío o es 0, usar el costo default de la semana
            $debe_pagar_raw  = $col['debe_pagar'] >= 0 
                ? trim($row[$col['debe_pagar']] ?? '') : '';
            $costo_acampante = (float)preg_replace('/[^0-9.]/', '', $debe_pagar_raw);
            if ($costo_acampante <= 0) $costo_acampante = $costo; // fallback: costo de semana
            
            // ── Total acumulado pagado (SUMA) ─────────────────────────
            // Es el total que ha dado hasta ahora, no el abono parcial
            $suma_raw   = $col['suma_pagada'] >= 0 
                ? trim($row[$col['suma_pagada']] ?? '') : '';
            $monto_pago = (float)preg_replace('/[^0-9.]/', '', $suma_raw);
            
            // ── Modo de pago ──────────────────────────────────────────
            $modo_pago_raw = $col['modo'] >= 0 
                ? strtolower(trim($row[$col['modo']] ?? '')) : '';
            $modo_pago = 'efectivo';
            if (str_contains($modo_pago_raw, 'banco'))    $modo_pago = 'banco';
            if (str_contains($modo_pago_raw, 'transfer')) $modo_pago = 'transferencia';

            $llego_raw = $col['llego'] >= 0 ? strtolower(trim($row[$col['llego']] ?? '')) : '';
            $llego = in_array($llego_raw, ['si', 'sí', 'yes', '1', 'x', '✓']) ? 1 : 0;

            // Buscar si ya existe en esta semana (por nombre exacto)
            $stmt = $pdo->prepare("
                SELECT id FROM acampantes 
                WHERE semana_id = ? AND nombre = ? AND estado = 'activo'
                LIMIT 1
            ");
            $stmt->execute([$semana_id, $nombre]);
            $existente = $stmt->fetchColumn();

            if ($existente && $modo === 'nuevo') {
                // Solo nuevos — saltar existentes
                $omitidos++;
                continue;
            }

            if ($existente && in_array($modo, ['actualizar', 'ambos'])) {
                // Actualizar
                $pdo->prepare("
                    UPDATE acampantes SET
                        edad = COALESCE(NULLIF(?, 0), edad),
                        sexo = CASE WHEN ? != '' THEN ? ELSE sexo END,
                        iglesia = CASE WHEN ? != '' THEN ? ELSE iglesia END,
                        asiste_iglesia = ?,
                        primera_vez_campamento = ?,
                        contacto_emergencia_nombre   = CASE WHEN ? != '' THEN ? ELSE contacto_emergencia_nombre END,
                        contacto_emergencia_telefono = CASE WHEN ? != '' THEN ? ELSE contacto_emergencia_telefono END,
                        costo_total = CASE WHEN ? > 0 THEN ? ELSE costo_total END,
                        llego = CASE WHEN ? = 1 THEN 1 ELSE llego END,
                        fecha_llegada = CASE WHEN ? = 1 AND llego = 0 THEN NOW() ELSE fecha_llegada END
                    WHERE id = ?
                ")->execute([
                    $edad,
                    $sexo, $sexo,
                    $iglesia, $iglesia,
                    $asiste, $primera,
                    $cont_nom, $cont_nom,
                    $cont_tel, $cont_tel,
                    $costo_acampante, $costo_acampante,
                    $llego, $llego,
                    $existente
                ]);
                // Solo registrar pago si hay monto y no supera el costo total
                if ($monto_pago > 0 && $monto_pago <= $costo_acampante) {
                
                    // Verificar si ya existe un pago de este monto exacto para no duplicar
                    $ya_tiene_pago = $pdo->prepare("
                        SELECT COALESCE(SUM(monto), 0) 
                        FROM pagos_acampante 
                        WHERE acampante_id = ? AND es_pago_registro = 1
                    ");
                    $ya_tiene_pago->execute([$existente]);
                    $ya_pagado = (float)$ya_tiene_pago->fetchColumn();
                
                    // Solo insertar si el total ya pagado es diferente al del CSV
                    // (evita duplicar en reimportaciones)
                    if (abs($ya_pagado - $monto_pago) > 0.01) {
                        // Si ya tiene pagos, ajustar para no duplicar
                        $diferencia = $monto_pago - $ya_pagado;
                        if ($diferencia > 0.01) {
                            $pdo->prepare("
                                INSERT INTO pagos_acampante 
                                    (acampante_id, monto, modo_pago, es_pago_registro, notas, registrado_por)
                                VALUES (?, ?, ?, 1, 'Importado desde CSV (ajuste)', ?)
                            ")->execute([$existente, $diferencia, $modo_pago, $_SESSION['user_id']]);
                        }
                    }
                }
                $actualizados++;

            } elseif (!$existente && in_array($modo, ['nuevo', 'ambos'])) {
                // Insertar nuevo
                $pdo->prepare("
                    INSERT INTO acampantes
                        (nombre, edad, sexo, iglesia, asiste_iglesia, primera_vez_campamento,
                         contacto_emergencia_nombre, contacto_emergencia_telefono,
                         semana_id, year_campamento, costo_total, llego,
                         fecha_llegada, estado, registrado_por)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,  'activo', ?)
                ")->execute([
                    $nombre, $edad, $sexo ?: null, $iglesia, $asiste, $primera,
                    $cont_nom, $cont_tel,
                    $semana_id, $year, $costo_acampante,
                    $llego,
                    $llego ? date('Y-m-d H:i:s') : null,
                    $_SESSION['user_id']
                ]);
                $nuevo_id = $pdo->lastInsertId();

                // Pago inicial — solo si hay monto y no supera el costo
                if ($monto_pago > 0 && $monto_pago <= $costo_acampante) {
                    $pdo->prepare("
                        INSERT INTO pagos_acampante 
                            (acampante_id, monto, modo_pago, es_pago_registro, notas, registrado_por)
                        VALUES (?, ?, ?, 1, 'Importado desde CSV', ?)
                    ")->execute([$nuevo_id, $monto_pago, $modo_pago, $_SESSION['user_id']]);
                }
                $insertados++;
            }
        }

        fclose($handle);
        $pdo->commit();

        registrarLog($pdo, 'csv_importado',
            "CSV importado: {$insertados} nuevos, {$actualizados} actualizados, {$omitidos} omitidos",
            'admisiones', 'success');

        $message = "✅ Importación completada — 
                    <strong>{$insertados}</strong> nuevos · 
                    <strong>{$actualizados}</strong> actualizados · 
                    <strong>{$omitidos}</strong> omitidos";
        
        // Agregar detalle si todo fue omitido
        if ($omitidos > 0 && $insertados === 0 && $actualizados === 0) {
            $message .= "<br><small class='text-warning'>
                ⚠️ Todos fueron omitidos. Posibles causas:<br>
                • Modo <strong>Solo nuevos</strong> y todos ya existen<br>
                • Modo <strong>Solo actualizar</strong> y ninguno existe aún<br>
                • La columna <strong>Nombre</strong> no fue detectada correctamente en el CSV<br>
                • Encabezados detectados: <code>" . htmlspecialchars(implode(' | ', array_slice($encabezados, 0, 8))) . "</code>
            </small>";
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
            <h1><i class="fas fa-file-csv"></i> <?php echo $titulo; ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Importar CSV</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
    <a href="lista_acampantes.php" class="btn btn-success btn-sm ms-2">Ver lista</a>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- Formulario importar -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-upload"></i> Subir archivo CSV</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Semana de Campamento *</label>
                        <select class="form-select" name="semana_id" required>
                            <option value="">Seleccionar semana...</option>
                            <?php foreach ($semanas as $s): ?>
                            <option value="<?php echo $s['id']; ?>">
                                <?php echo htmlspecialchars($s['nombre']); ?>
                                — $<?php echo number_format($s['costo_campamento'],0); ?>
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
                                    <small class="text-muted d-block">
                                        Inserta nuevos y actualiza los que ya existen (recomendado)
                                    </small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="modo" value="nuevo" id="modo_nuevo">
                                <label class="form-check-label" for="modo_nuevo">
                                    <strong>Solo nuevos</strong>
                                    <small class="text-muted d-block">
                                        Omite los acampantes que ya están registrados
                                    </small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="modo" value="actualizar" id="modo_act">
                                <label class="form-check-label" for="modo_act">
                                    <strong>Solo actualizar existentes</strong>
                                    <small class="text-muted d-block">
                                        Solo modifica los que ya están, no agrega nuevos
                                    </small>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Costo por defecto</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" name="costo_default"
                                   step="0.01" min="0" value="0"
                                   placeholder="Se usa si el CSV no trae el costo">
                        </div>
                        <small class="text-muted">
                            Si el CSV tiene columna de costo, ese valor tiene prioridad
                        </small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Archivo CSV *</label>
                        <input type="file" class="form-control" name="csv_file"
                               accept=".csv" required>
                        <small class="text-muted">
                            Exporta desde Google Sheets → Archivo → Descargar → CSV
                        </small>
                    </div>

                    <button type="submit" class="btn btn-success w-100 btn-lg">
                        <i class="fas fa-upload"></i> Importar CSV
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Instrucciones -->
    <div class="col-md-5">

        <!-- Cómo exportar -->
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fab fa-google"></i> Cómo exportar de Google Sheets
                </h6>
            </div>
            <div class="card-body">
                <ol class="small mb-0">
                    <li class="mb-2">Abre tu Google Sheets de la semana</li>
                    <li class="mb-2">Click en <strong>Archivo</strong></li>
                    <li class="mb-2">Click en <strong>Descargar</strong></li>
                    <li class="mb-2">Selecciona <strong>Valores separados por comas (.csv)</strong></li>
                    <li>Sube ese archivo aquí</li>
                </ol>
            </div>
        </div>

        <!-- Columnas detectadas automáticamente -->
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fas fa-magic"></i> Columnas detectadas automáticamente
                </h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Columna del Sheets</th>
                            <th>Campo del sistema</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <tr><td>Nombre y Apellidos</td><td>Nombre completo</td></tr>
                        <tr><td>Edad</td><td>Edad</td></tr>
                        <tr><td>Sexo / Opción (Mujer/Hombre)</td><td>Sexo</td></tr>
                        <tr><td>¿Primera vez?</td><td>Primera vez campamento</td></tr>
                        <tr><td>¿Asistes a iglesia?</td><td>Asiste a iglesia</td></tr>
                        <tr><td>Nombre de la iglesia</td><td>Iglesia</td></tr>
                        <tr><td>Nombre contacto emergencia</td><td>Contacto emergencia</td></tr>
                        <tr><td>Número de contacto</td><td>Teléfono emergencia</td></tr>
                        <tr><td>SUMA</td><td>Total acumulado pagado</td></tr>
                        <tr><td>DEBE PAGAR</td><td>Costo total del campamento</td></tr>
                        <tr><td>MODO</td><td>Modo de pago</td></tr>
                        <tr><td>LLEGARON</td><td>Check-in</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Notas -->
        <div class="alert alert-info small">
            <i class="fas fa-info-circle"></i>
            <strong>Nota:</strong> El sistema detecta automáticamente las columnas.
            Los acampantes se identifican por <strong>nombre exacto</strong> dentro de la misma semana.
            Si hay dudas de ortografía, el sistema los creará como nuevos.
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>