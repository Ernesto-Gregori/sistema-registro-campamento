<?php
// admisiones/grupos/nuevo_grupo.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../libs/SimpleXLSX.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador()) {
    header('Location: ../../login.php'); exit();
}

$titulo    = "Nuevo Grupo";
$year      = obtenerAnioCampamento();
$semana_id = $_GET['semana_id'] ?? null;
$error     = '';
$message   = '';

$simplexlsx_ok = class_exists('Shuchkin\\SimpleXLSX');

$stmt = $pdo->prepare("SELECT * FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio");
$stmt->execute([$year]);
$semanas = $stmt->fetchAll();

// ── Helpers de detección de columnas ──────────────────────────────────────
if (!function_exists('detectarColumnaGrupo')) {
    function detectarColumnaGrupo(array $headers, array $keywords): int {
        foreach ($headers as $i => $h) {
            $hl = mb_strtolower(trim((string)$h));
            foreach ($keywords as $kw) {
                if (mb_strpos($hl, mb_strtolower($kw)) !== false) return $i;
            }
        }
        return -1;
    }
}

// Reutilizar obtenerFilasOscuras si existe (del importar.php individual)
if (!function_exists('obtenerFilasOscurasGrupo')) {
    function obtenerFilasOscurasGrupo(\Shuchkin\SimpleXLSX $xlsx): array {
        $oscuras  = [];
        $fills_ok = [];
        if ($xlsx->styles && isset($xlsx->styles->fills->fill)) {
            $fi = 0;
            foreach ($xlsx->styles->fills->fill as $fill) {
                if (isset($fill->patternFill)) {
                    foreach (['fgColor', 'bgColor'] as $tag) {
                        if (isset($fill->patternFill->$tag)) {
                            $attrs = [];
                            foreach ($fill->patternFill->$tag->attributes() as $k => $v) {
                                $attrs[(string)$k] = (string)$v;
                            }
                            if (!empty($attrs['rgb'])) {
                                $hex = substr($attrs['rgb'], -6);
                                if (strlen($hex) === 6) {
                                    $r = hexdec(substr($hex,0,2));
                                    $g = hexdec(substr($hex,2,2));
                                    $b = hexdec(substr($hex,4,2));
                                    if ((0.299*$r + 0.587*$g + 0.114*$b) < 80) {
                                        $fills_ok[$fi] = true;
                                    }
                                }
                                break;
                            }
                        }
                    }
                }
                $fi++;
            }
        }
        if (empty($fills_ok) || !isset($xlsx->styles->cellXfs->xf)) return [];
        $xf_fill = [];
        $xi = 0;
        foreach ($xlsx->styles->cellXfs->xf as $xf) {
            $a = [];
            foreach ($xf->attributes() as $k => $v) $a[(string)$k] = (string)$v;
            $xf_fill[$xi] = isset($a['fillId']) ? (int)$a['fillId'] : 0;
            $xi++;
        }
        if (!isset($xlsx->sheets[0])) return [];
        $ri = 0;
        foreach ($xlsx->sheets[0]->sheetData->row as $row) {
            $dark = false;
            $checked = 0;
            foreach ($row->c as $cell) {
                if ($checked >= 4) break;
                $ca = [];
                foreach ($cell->attributes() as $k => $v) $ca[(string)$k] = (string)$v;
                if (isset($ca['s'])) {
                    $s = (int)$ca['s'];
                    if (isset($xf_fill[$s]) && isset($fills_ok[$xf_fill[$s]])) {
                        $dark = true; break;
                    }
                }
                $checked++;
            }
            if ($dark) {
                $ra = [];
                foreach ($row->attributes() as $k => $v) $ra[(string)$k] = (string)$v;
                $oscuras[isset($ra['r']) ? (int)$ra['r'] - 1 : $ri] = true;
            }
            $ri++;
        }
        return $oscuras;
    }
}

// ── POST: crear grupo + importar XLSX ─────────────────────────────────────
if ($_POST) {
    try {
        $enc_nombre   = trim($_POST['encargado_nombre'] ?? '');
        $enc_tel      = trim($_POST['encargado_telefono'] ?? '');
        $enc_email    = trim($_POST['encargado_email'] ?? '');
        $sid          = (int)($_POST['semana_id'] ?? 0);
        $costo_pp     = (float)($_POST['costo_por_persona'] ?? 0);
        $notas        = trim($_POST['notas'] ?? '');
        
        if (empty($enc_nombre)) throw new Exception("El nombre del encargado es obligatorio");
        if ($sid < 1)           throw new Exception("Selecciona una semana");

        $pdo->beginTransaction();

        // Generar código de acceso único
        $codigo_acceso = generarCodigoAccesoGrupo();
        $intentos = 0;
        while ($intentos < 10) {
            $check = $pdo->prepare("SELECT id FROM grupos_campamento WHERE codigo_acceso = ? LIMIT 1");
            $check->execute([$codigo_acceso]);
            if (!$check->fetch()) break;
            $codigo_acceso = generarCodigoAccesoGrupo();
            $intentos++;
        }

        // Crear grupo
        $pdo->prepare("
            INSERT INTO grupos_campamento
                (encargado_nombre, encargado_telefono, encargado_email,
                 semana_id, year_campamento, costo_por_persona, notas, creado_por,
                 codigo_acceso)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([
            $enc_nombre, $enc_tel, $enc_email,
            $sid, $year, $costo_pp, $notas, $_SESSION['user_id'],
            $codigo_acceso
        ]);
        $grupo_id = $pdo->lastInsertId();

        $insertados = 0;
        $omitidos_color = 0;

        // ── Importar XLSX si se subió ──────────────────────────────────
        if (isset($_FILES['xlsx_file']) && $_FILES['xlsx_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['xlsx_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'csv'])) {
                throw new Exception("El archivo debe ser .xlsx o .csv");
            }

            $filas_datos   = [];
            $filas_oscuras = [];

            if ($ext === 'xlsx') {
                if (!$simplexlsx_ok) throw new Exception("SimpleXLSX no disponible");
                $xlsx = \Shuchkin\SimpleXLSX::parse($_FILES['xlsx_file']['tmp_name']);
                if (!$xlsx) throw new Exception("No se pudo leer el XLSX");
                $filas_oscuras = obtenerFilasOscurasGrupo($xlsx);
                $all_rows = $xlsx->rows(0);
                foreach ($all_rows as $ri => $row) {
                    $filas_datos[] = [
                        'values' => array_map(fn($v) => trim((string)$v), $row),
                        'oscura' => isset($filas_oscuras[$ri]),
                    ];
                }
            } else {
                $handle = fopen($_FILES['xlsx_file']['tmp_name'], 'r');
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") rewind($handle);
                while (($row = fgetcsv($handle, 2000, ',')) !== false) {
                    $filas_datos[] = ['values' => array_map('trim', $row), 'oscura' => false];
                }
                fclose($handle);
            }

            // Detectar encabezados
            $encabezados = null; $header_idx = 0;
            foreach ($filas_datos as $idx => $fila) {
                foreach ($fila['values'] as $celda) {
                    if (mb_stripos($celda, 'nombre') !== false &&
                        mb_stripos($celda, 'apellido') !== false) {
                        $encabezados = $fila['values']; $header_idx = $idx; break 2;
                    }
                }
                if ($idx >= 5) break;
            }
            if (!$encabezados) { $encabezados = $filas_datos[0]['values'] ?? []; $header_idx = 0; }

            // Mapeo columnas — formato GRUPOS tiene NP en col A, luego igual que individual
            $col = [
                'np'       => detectarColumnaGrupo($encabezados, ['np', 'n°', 'número', 'numero', '#']),
                'nombre'   => detectarColumnaGrupo($encabezados, ['nombre y apellido', 'nombre y apellidos', 'nombre', 'apellido']),
                'edad'     => detectarColumnaGrupo($encabezados, ['edad']),
                'curp'     => detectarColumnaGrupo($encabezados, ['curp']),
                'sexo'     => detectarColumnaGrupo($encabezados, ['marca la opci', 'sexo', 'género', 'genero', 'opcion', 'opción']),
                'primera'  => detectarColumnaGrupo($encabezados, ['primera vez']),
                'asiste'   => detectarColumnaGrupo($encabezados, ['asistes a alguna iglesia', '¿asistes']),
                'iglesia'  => detectarColumnaGrupo($encabezados, ['nombre de la iglesia']),
                'cont_nom' => detectarColumnaGrupo($encabezados, ['nombre y apellidos del contacto', 'contacto de emergencia']),
                'cont_tel' => detectarColumnaGrupo($encabezados, ['número de contacto para emergencias', 'contacto para emergencias']),
            ];

            // Obtener costo semana
            $stmt_c = $pdo->prepare("SELECT costo_campamento FROM semanas_campamento WHERE id = ?");
            $stmt_c->execute([$sid]);
            $costo_semana = (float)($stmt_c->fetchColumn() ?? 0);
            $costo_persona_final = $costo_pp > 0 ? $costo_pp : $costo_semana;

            foreach ($filas_datos as $fi => $fila) {
                if ($fi <= $header_idx) continue;
                $row = $fila['values'];
                if (empty(array_filter($row, fn($c) => trim($c) !== ''))) continue;
                if ($fila['oscura']) { $omitidos_color++; continue; }

                $nombre = $col['nombre'] >= 0 ? trim($row[$col['nombre']] ?? '') : '';
                if (empty($nombre) || strlen($nombre) < 2) continue;

                $curp_raw = $col['curp'] >= 0 ? trim($row[$col['curp']] ?? '') : '';
                $curp = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $curp_raw));
                if (strlen($curp) > 18) $curp = substr($curp, 0, 18);
                if (strlen($curp) < 10) $curp = '';

                $edad_raw = $col['edad'] >= 0 ? trim($row[$col['edad']] ?? '') : '';
                $edad = is_numeric($edad_raw) ? (int)$edad_raw : null;

                $sexo_raw = $col['sexo'] >= 0 ? strtolower(trim($row[$col['sexo']] ?? '')) : '';
                $sexo = '';
                if (mb_strpos($sexo_raw, 'mujer') !== false || mb_strpos($sexo_raw, 'femenino') !== false || $sexo_raw === 'f') $sexo = 'femenino';
                elseif (mb_strpos($sexo_raw, 'hombre') !== false || mb_strpos($sexo_raw, 'masculino') !== false || $sexo_raw === 'm') $sexo = 'masculino';

                $primera_raw = $col['primera'] >= 0 ? strtolower(trim($row[$col['primera']] ?? '')) : '';
                $primera = in_array($primera_raw, ['si','sí','yes','1']) ? 1 : 0;

                $asiste_raw = $col['asiste'] >= 0 ? strtolower(trim($row[$col['asiste']] ?? '')) : '';
                $asiste = in_array($asiste_raw, ['si','sí','yes','1']) ? 1 : 0;

                $iglesia = $col['iglesia'] >= 0 ? trim($row[$col['iglesia']] ?? '') : '';
                if (in_array(strtolower($iglesia), ['si','sí','no','yes'])) $iglesia = '';

                $cont_nom = $col['cont_nom'] >= 0 ? trim($row[$col['cont_nom']] ?? '') : '';
                $cont_tel = $col['cont_tel'] >= 0 ? trim($row[$col['cont_tel']] ?? '') : '';

                $pdo->prepare("
                    INSERT INTO acampantes
                        (nombre, curp, edad, sexo, iglesia,
                         asiste_iglesia, primera_vez_campamento,
                         contacto_emergencia_nombre, contacto_emergencia_telefono,
                         semana_id, grupo_id, year_campamento, costo_total,
                         estado, registrado_por)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'activo',?)
                ")->execute([
                    $nombre, $curp ?: null, $edad, $sexo ?: null, $iglesia,
                    $asiste, $primera, $cont_nom, $cont_tel,
                    $sid, $grupo_id, $year, $costo_persona_final,
                    $_SESSION['user_id']
                ]);
                $insertados++;
            }
        }

        $pdo->commit();

        registrarLog($pdo, 'grupo_creado',
            "Grupo del encargado '{$enc_nombre}' creado con {$insertados} acampantes. Código: {$codigo_acceso}",
            'admisiones', 'success');
        
        $msg = "Grupo de '{$enc_nombre}' creado con {$insertados} acampantes";
        if ($omitidos_color > 0) $msg .= " ({$omitidos_color} filas oscuras ignoradas)";
        // Guardar código en sesión flash para mostrarlo en ver_grupo.php
        $_SESSION['codigo_acceso_nuevo'] = $codigo_acceso;
        header("Location: ver_grupo.php?id={$grupo_id}&message=" . urlencode("✅ $msg"));
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$base_path = '../';
include '../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-users-cog"></i> <?= $titulo ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="lista_grupos.php">Grupos</a></li>
                    <li class="breadcrumb-item active">Nuevo Grupo</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<div class="row g-4">

    <!-- Datos del grupo -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="fas fa-users"></i> Datos del Grupo</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Semana *</label>
                    <select class="form-select" name="semana_id" required id="sel_semana"
                            onchange="actualizarCostoPP()">
                        <option value="">Seleccionar semana...</option>
                        <?php foreach ($semanas as $s): ?>
                        <option value="<?= $s['id'] ?>"
                                data-costo="<?= $s['costo_campamento'] ?>"
                                <?= ($semana_id == $s['id'] || ($_POST['semana_id'] ?? '') == $s['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Costo por persona *</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" class="form-control" name="costo_por_persona"
                               id="costo_pp" step="0.01" min="0" required
                               value="<?= $_POST['costo_por_persona'] ?? 0 ?>">
                    </div>
                    <small class="text-muted">Se llena automático con el costo de la semana</small>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Notas</label>
                    <textarea class="form-control" name="notas" rows="2"
                              placeholder="Observaciones del grupo..."><?=
                        htmlspecialchars($_POST['notas'] ?? '')
                    ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Encargado + Archivo -->
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0"><i class="fas fa-user-tie"></i> Encargado del Grupo</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre del encargado *</label>
                    <input type="text" class="form-control" name="encargado_nombre" required
                           value="<?= htmlspecialchars($_POST['encargado_nombre'] ?? '') ?>"
                           placeholder="Nombre completo del líder">
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Teléfono</label>
                        <input type="text" class="form-control" name="encargado_telefono"
                               value="<?= htmlspecialchars($_POST['encargado_telefono'] ?? '') ?>"
                               placeholder="Teléfono de contacto">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Email</label>
                        <input type="email" class="form-control" name="encargado_email"
                               value="<?= htmlspecialchars($_POST['encargado_email'] ?? '') ?>"
                               placeholder="correo@ejemplo.com">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-dark text-white">
                <h6 class="mb-0">
                    <i class="fas fa-file-excel"></i> Importar Lista de Acampantes
                    <span class="badge bg-secondary ms-1">Opcional</span>
                </h6>
            </div>
            <div class="card-body">
                <input type="file" class="form-control mb-2" name="xlsx_file"
                       accept="<?= $simplexlsx_ok ? '.xlsx,.csv' : '.csv' ?>">
                <div class="small text-muted">
                    <?php if ($simplexlsx_ok): ?>
                    <i class="fas fa-info-circle text-info"></i>
                    Sube el <strong>FORMATO GRUPOS</strong> en <code>.xlsx</code> (recomendado)
                    o <code>.csv</code>. Las filas con relleno oscuro serán ignoradas.
                    <?php else: ?>
                    <i class="fas fa-info-circle text-info"></i>
                    Sube el formato en <code>.csv</code>.
                    <?php endif; ?>
                    <br>
                    <span class="text-warning">
                        Puedes importar ahora o agregar acampantes después desde el detalle del grupo.
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-2 mt-3">
    <a href="lista_grupos.php" class="btn btn-secondary">
        <i class="fas fa-times"></i> Cancelar
    </a>
    <button type="submit" class="btn btn-success px-4">
        <i class="fas fa-save"></i> Crear Grupo
    </button>
</div>
</form>

<script>
const costosSemanas = {};
document.querySelectorAll('#sel_semana option[data-costo]').forEach(opt => {
    costosSemanas[opt.value] = parseFloat(opt.dataset.costo) || 0;
});
function actualizarCostoPP() {
    const sid = document.getElementById('sel_semana').value;
    if (costosSemanas[sid]) {
        document.getElementById('costo_pp').value = costosSemanas[sid].toFixed(2);
    }
}
document.addEventListener('DOMContentLoaded', actualizarCostoPP);
</script>

<?php include '../../includes/footer.php'; ?>
