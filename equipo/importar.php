<?php
// equipo/importar.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
verificarMantenimiento($pdo);

if (!esEquipoOAdmin()) {
    header('Location: ../default.php');
    exit();
}

// ── Cargar SimpleXLSX ────────────────────────────────────────────────────
$simplexlsx_disponible = false;
$simplexlsx_path = __DIR__ . '/../libs/SimpleXLSX.php';
if (file_exists($simplexlsx_path)) {
    require_once $simplexlsx_path;
    $simplexlsx_disponible = class_exists('Shuchkin\SimpleXLSX');
}

$year   = obtenerAnioCampamento();
$userId = $_SESSION['user_id'] ?? 0;

$mensaje = '';
$error   = '';
$resultados = [];

// ---------------------------------------------------------------------
// Mapeo de columnas de la hoja "HOJA DE TRABAJO" (1-indexed)
// Estructura confirmada del Excel real
// ---------------------------------------------------------------------
$mapaColumnas = [
    1  => 'id_excel',
    2  => 'nombre',
    3  => 'edad',
    4  => 'sexo',
    5  => 'direccion',
    6  => 'correo',
    7  => 'telefono_whatsapp',
    8  => 'semanas_disponibles',
    9  => 'devocional_usado',
    10 => 'iglesia',
    11 => 'pastor_autoriza',
    12 => 'pastor_telefono',
    13 => 'pastor_correo',
    14 => 'ministerio_iglesia',
    15 => 'testimonio_salvacion',
    16 => 'motivo_servir',
    17 => 'practica_deporte',
    18 => 'deporte_especifica',
    19 => 'toca_instrumento',
    20 => 'instrumento_especifica',
    21 => 'estudios',
    22 => 'habilidades_oficios',
    23 => 'cualidades',
    24 => 'fue_campero',
    26 => 'estado_excel',          // ACEPTADO-EN ESPERA-RECHAZADO
    36 => 'observaciones_excel',   // OBSERVACIONES
];

/**
 * Normaliza el estado del Excel al enum de la BD
 * Valores del Excel: ACEPTADA, ACEPTADO, RECHAZADO, CONSEJERA, CONSEJERO, EN ESPERA, NINGUNO
 * Enum BD: en espera, aceptado, rechazado, consejero
 */
function normalizarEstadoExcel($valor) {
    if ($valor === null || trim((string)$valor) === '') return 'en espera';
    $v = mb_strtoupper(trim((string)$valor), 'UTF-8');
    if (in_array($v, ['ACEPTADA','ACEPTADO'], true)) return 'aceptado';
    if (in_array($v, ['RECHAZADO','RECHAZADA'], true)) return 'rechazado';
    if (in_array($v, ['CONSEJERA','CONSEJERO'], true)) return 'consejero';
    if ($v === 'EN ESPERA') return 'en espera';
    return 'en espera'; // NINGUNO o vacio
}

function siNoABool($valor) {
    if ($valor === null || $valor === '') return 0;
    $v = mb_strtolower(trim((string)$valor), 'UTF-8');
    if (in_array($v, ['si','sí','sip','s','yes','1','true'], true)) return 1;
    return 0;
}

function generoDeTexto($valor) {
    if ($valor === null || $valor === '') return null;
    $v = mb_strtolower(trim((string)$valor), 'UTF-8');
    if (in_array($v, ['mujer','femenino','f','fem'], true)) return 'femenino';
    if (in_array($v, ['hombre','masculino','m','masc'], true)) return 'masculino';
    return null;
}

function extraerTemporadasCampero($valor, &$fueCampero) {
    if ($valor === null || trim((string)$valor) === '') {
        $fueCampero = 0;
        return null;
    }
    $v = trim((string)$valor);
    $vLower = mb_strtolower($v, 'UTF-8');
    if (strpos($vLower, 'no') === 0) {
        $fueCampero = 0;
        return null;
    }
    $fueCampero = 1;
    if (preg_match('/\(([^)]+)\)/', $v, $m)) {
        return trim($m[1]);
    }
    return $v;
}

/**
 * Matching contra TODAS las semanas del año (no solo activas).
 */
function matchearSemanas($pdo, $textoSemanas, $year) {
    if (empty($textoSemanas)) return [];

    // ⭐ TODAS las semanas del año, sin filtrar por activa
    $stmt = $pdo->prepare("SELECT id, nombre, fecha_inicio, fecha_fin, tipo_acampante FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio");
    $stmt->execute([$year]);
    $semanas = $stmt->fetchAll();

    if (empty($semanas)) return [];

    $textoLower = mb_strtolower(trim((string)$textoSemanas), 'UTF-8');
    $idsMatch = [];

    // Caso especial: "toda la temporada"
    if (strpos($textoLower, 'toda la temporada') !== false) {
        foreach ($semanas as $s) {
            $idsMatch[] = $s['id'];
        }
        return array_unique($idsMatch);
    }

    foreach ($semanas as $s) {
        $nombreLower = mb_strtolower($s['nombre'], 'UTF-8');
        $match = false;

        // 1. Match directo por nombre de la semana
        if (strpos($textoLower, $nombreLower) !== false) {
            $match = true;
        }

        // 2. Match por palabras clave
        if (!$match) {
            $palabrasClave = [
                'entrenamiento' => ['entrenamiento'],
                'mayores'       => ['jóvenes + 18', 'jovenes + 18', 'comunidad jóvenes', 'comunidad jovenes', '+ 18', '+18', 'joven'],
                'ninos'         => ['niños', 'niñas', 'ninos', 'ninas', 'niños y niñas'],
                'sem1'          => ['sem1', 'sem 1', 'sem1/'],
                'sem2'          => ['sem2', 'sem 2', 'sem2/'],
                'sem3'          => ['sem3', 'sem 3', 'sem3/'],
                'adolescentes'  => ['adolescentes', '13', '17 años', '17 a'],
            ];
            foreach ($palabrasClave as $tipo => $claves) {
                foreach ($claves as $clave) {
                    $claveLower = mb_strtolower($clave, 'UTF-8');
                    if (strpos($textoLower, $claveLower) !== false) {
                        // Coincidencia por tipo_acampante de la semana
                        $tipoSem = $s['tipo_acampante'] ?? '';
                        if ($tipo === 'entrenamiento' && strpos($nombreLower, 'entrenamiento') !== false) $match = true;
                        if ($tipo === 'mayores' && $tipoSem === 'mayores') $match = true;
                        if ($tipo === 'ninos' && $tipoSem === 'ninos') $match = true;
                        if (($tipo === 'adolescentes' || strpos($tipo, 'sem') === 0) && $tipoSem === 'adolescentes') {
                            // Distinguir SEM1/SEM2/SEM3 si el nombre de la semana lo indica
                            if (strpos($tipo, 'sem') === 0) {
                                $numSem = substr($tipo, 3);
                                if (strpos($nombreLower, 'sem'.$numSem) !== false ||
                                    strpos($nombreLower, 'sem '.$numSem) !== false ||
                                    preg_match('/(\d+)/', $nombreLower, $m) && $m[1] === $numSem) {
                                    $match = true;
                                }
                            } else {
                                $match = true;
                            }
                        }
                        if ($match) break 2;
                    }
                }
            }
        }

        // 3. Match por rango de fechas
        if (!$match && !empty($s['fecha_inicio'])) {
            $semInicio = (int)date('d', strtotime($s['fecha_inicio']));
            $semFin = (int)date('d', strtotime($s['fecha_fin']));
            if (preg_match_all('/(\d{1,2})\s*[-–]\s*(\d{1,2})/', $textoLower, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $txtInicio = (int)$m[1];
                    $txtFin = (int)$m[2];
                    if ($txtInicio === $semInicio || $txtFin === $semFin ||
                        ($txtInicio <= $semFin && $txtFin >= $semInicio)) {
                        $match = true;
                        break;
                    }
                }
            }
        }

        if ($match) {
            $idsMatch[] = $s['id'];
        }
    }

    return array_unique($idsMatch);
}

// ---------------------------------------------------------------------
// PROCESAR UPLOAD
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_excel'])) {

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Token de seguridad inválido.';
    } elseif (!$simplexlsx_disponible) {
        $error = 'La librería SimpleXLSX no está disponible en libs/SimpleXLSX.php';
    } else {
        $archivo = $_FILES['archivo_excel'];

        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            $error = 'Error al subir el archivo (código ' . $archivo['error'] . ').';
        } elseif ($archivo['size'] > 10 * 1024 * 1024) {
            $error = 'El archivo es muy grande (máx 10MB).';
        } else {
            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, ['xlsx','xls'], true)) {
                $error = 'Solo se permiten archivos .xlsx o .xls';
            } else {
                $rutaTemp = '../assets/uploads/temp_import_' . time() . '.' . $extension;
                if (!is_dir('../assets/uploads/')) {
                    mkdir('../assets/uploads/', 0755, true);
                }

                if (!move_uploaded_file($archivo['tmp_name'], $rutaTemp)) {
                    $error = 'No se pudo guardar el archivo temporal.';
                } else {
                    try {
                        $xlsx = \Shuchkin\SimpleXLSX::parse($rutaTemp);
                        if (!$xlsx) {
                            throw new Exception('No se pudo leer el Excel: ' . \Shuchkin\SimpleXLSX::parseError());
                        }

                        // Buscar la hoja "HOJA DE TRABAJO", si no existe usar la primera
                        $nombresHojas = $xlsx->sheetNames();
                        $hojaIndex = 0;
                        foreach ($nombresHojas as $i => $nombre) {
                            if (mb_strtoupper(trim($nombre), 'UTF-8') === 'HOJA DE TRABAJO') {
                                $hojaIndex = $i;
                                break;
                            }
                        }

                        $filas = $xlsx->rows($hojaIndex);

                        if (empty($filas)) {
                            throw new Exception('La hoja no tiene datos.');
                        }

                        // Encontrar fila de headers
                        $filaHeaders = null;
                        foreach ($filas as $i => $fila) {
                            $col1 = mb_strtolower(trim((string)($fila[1] ?? '')), 'UTF-8');
                            $col25 = mb_strtolower(trim((string)($fila[25] ?? '')), 'UTF-8');
                            if (strpos($col1, 'nombre') !== false || strpos($col25, 'aceptado') !== false) {
                                $filaHeaders = $i;
                                break;
                            }
                        }
                        if ($filaHeaders === null) $filaHeaders = 0;

                        $importados = 0;
                        $omitidos = 0;
                        $semanasAsignadas = 0;

                        for ($i = $filaHeaders + 1; $i < count($filas); $i++) {
                            $fila = $filas[$i];
                            $nombre = trim((string)($fila[1] ?? '')); // Col 2 = Nombre

                            if ($nombre === '') continue;

                            // Verificar duplicado
                            $stmtCheck = $pdo->prepare("SELECT id FROM equipantes WHERE nombre = ? AND year_campamento = ? LIMIT 1");
                            $stmtCheck->execute([$nombre, $year]);
                            if ($stmtCheck->fetch()) {
                                $omitidos++;
                                $resultados[] = ['nombre' => $nombre, 'estado_imp' => 'omitido', 'razon' => 'Ya existe', 'semanas' => [], 'estado_eq' => ''];
                                continue;
                            }

                            // Mapear valores
                            $vals = [];
                            foreach ($mapaColumnas as $col => $campo) {
                                $vals[$campo] = $fila[$col - 1] ?? null;
                            }

                            $edad = !empty($vals['edad']) ? (int)$vals['edad'] : null;
                            $sexo = generoDeTexto($vals['sexo']);
                            $practicaDep = siNoABool($vals['practica_deporte']);
                            $tocaInst = siNoABool($vals['toca_instrumento']);
                            $fueCamp = 0;
                            $temporadasCamp = extraerTemporadasCampero($vals['fue_campero'], $fueCamp);
                            $estadoEq = normalizarEstadoExcel($vals['estado_excel']);
                            $obsExcel = trim((string)($vals['observaciones_excel'] ?? ''));

                            // ⭐ Matching de semanas ANTES del INSERT (para guardar los IDs)
                            $semanasMatch = matchearSemanas($pdo, $vals['semanas_disponibles'] ?? '', $year);
                            $semanasIdsStr = implode(',', $semanasMatch);

                            // Insertar
                            $stmt = $pdo->prepare("
                                INSERT INTO equipantes (
                                    nombre, edad, sexo, direccion, correo, telefono_whatsapp,
                                    semanas_disponibles,
                                    devocional_usado, iglesia, pastor_autoriza, pastor_telefono,
                                    pastor_correo, ministerio_iglesia, testimonio_salvacion, motivo_servir,
                                    practica_deporte, deporte_especifica, toca_instrumento, instrumento_especifica,
                                    estudios, habilidades_oficios, cualidades, fue_campero, temporadas_campero,
                                    estado, observaciones, tipo_persona, year_campamento, registrado_por
                                ) VALUES (
                                    ?, ?, ?, ?, ?, ?,
                                    ?,
                                    ?, ?, ?, ?,
                                    ?, ?, ?, ?,
                                    ?, ?, ?, ?,
                                    ?, ?, ?, ?, ?,
                                    ?, ?, 'equipante', ?, ?
                                )
                            ");
                            $stmt->execute([
                                $nombre, $edad, $sexo,
                                trim((string)($vals['direccion'] ?? '')),
                                trim((string)($vals['correo'] ?? '')),
                                trim((string)($vals['telefono_whatsapp'] ?? '')),
                                $semanasIdsStr,
                                trim((string)($vals['devocional_usado'] ?? '')),
                                trim((string)($vals['iglesia'] ?? '')),
                                trim((string)($vals['pastor_autoriza'] ?? '')),
                                trim((string)($vals['pastor_telefono'] ?? '')),
                                trim((string)($vals['pastor_correo'] ?? '')),
                                trim((string)($vals['ministerio_iglesia'] ?? '')),
                                trim((string)($vals['testimonio_salvacion'] ?? '')),
                                trim((string)($vals['motivo_servir'] ?? '')),
                                $practicaDep,
                                trim((string)($vals['deporte_especifica'] ?? '')),
                                $tocaInst,
                                trim((string)($vals['instrumento_especifica'] ?? '')),
                                trim((string)($vals['estudios'] ?? '')),
                                trim((string)($vals['habilidades_oficios'] ?? '')),
                                trim((string)($vals['cualidades'] ?? '')),
                                $fueCamp, $temporadasCamp,
                                $estadoEq, $obsExcel,
                                $year, $userId
                            ]);

                            $nuevoId = (int)$pdo->lastInsertId();
                            $importados++;

                            // Las semanas ya se matchearon antes del INSERT
                            $nombresSemanas = [];

                            foreach ($semanasMatch as $semId) {
                                $stmtDist = $pdo->prepare("
                                    INSERT IGNORE INTO distribucion_equipantes (equipante_id, semana_id, area_id, asignado_por)
                                    VALUES (?, ?, NULL, ?)
                                ");
                                $stmtDist->execute([$nuevoId, $semId, $userId]);
                                $semanasAsignadas++;

                                $stmtNom = $pdo->prepare("SELECT nombre FROM semanas_campamento WHERE id = ?");
                                $stmtNom->execute([$semId]);
                                $nombresSemanas[] = $stmtNom->fetchColumn();
                            }

                            $resultados[] = [
                                'nombre'     => $nombre,
                                'estado_imp' => 'importado',
                                'razon'      => '',
                                'semanas'    => $nombresSemanas,
                                'estado_eq'  => $estadoEq,
                            ];
                        }

                        if (file_exists($rutaTemp)) unlink($rutaTemp);
                        $mensaje = "Importación completa: {$importados} importados, {$omitidos} omitidos, {$semanasAsignadas} asignaciones de semana.";

                    } catch (Exception $e) {
                        $error = 'Error al procesar: ' . $e->getMessage();
                        if (file_exists($rutaTemp)) unlink($rutaTemp);
                    }
                }
            }
        }
    }
}

// ⭐ Cargar TODAS las semanas del año (no solo activas)
$semanas = [];
try {
    $stmt = $pdo->prepare("SELECT id, nombre, fecha_inicio, fecha_fin, tipo_acampante, activa FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio");
    $stmt->execute([$year]);
    $semanas = $stmt->fetchAll();
} catch (Exception $e) {}

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

<?php if (!$simplexlsx_disponible): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Librería no encontrada:</strong> Falta <code>libs/SimpleXLSX.php</code>.
    </div>
<?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0"><i class="fas fa-file-import text-primary"></i> Importar Equipantes</h1>
            <small class="text-muted">Sube el Excel de RECLUTAMIENTO (hoja "HOJA DE TRABAJO") con estado y observaciones incluidos.</small>
        </div>
        <a href="reclutamiento.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>

    <div class="row g-3">
        <!-- Formulario -->
        <div class="col-12 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-upload"></i> Subir archivo Excel</h6>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="mb-3">
                            <label class="form-label">Archivo de reclutamiento (.xlsx)</label>
                            <input type="file" name="archivo_excel" class="form-control" accept=".xlsx,.xls" required>
                            <small class="text-muted">Lee la hoja "HOJA DE TRABAJO" con estado y observaciones.</small>
                        </div>
                        <div class="alert alert-info py-2 small">
                            <i class="fas fa-info-circle"></i>
                            <strong>¿Qué importa?</strong>
                            <ul class="mb-0 ps-3">
                                <li>Datos personales, espirituales y habilidades del equipante</li>
                                <li><strong>Estado</strong> (ACEPTADO/EN ESPERA/RECHAZADO/CONSEJERO)</li>
                                <li><strong>Observaciones</strong> de la hoja de trabajo</li>
                                <li>Matching automático de semanas contra <strong>todas las semanas del año</strong></li>
                                <li>Salta equipantes que ya existan</li>
                            </ul>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" <?php echo !$simplexlsx_disponible ? 'disabled' : ''; ?>>
                            <i class="fas fa-file-import"></i> Importar Excel
                        </button>
                    </form>
                </div>
            </div>

            <!-- TODAS las semanas del año -->
            <div class="card shadow-sm mt-3">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-calendar-week"></i> Semanas del año <?php echo $year; ?> (para matching)</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Nombre</th><th>Fechas</th><th>Tipo</th><th>Activa</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($semanas)): ?>
                            <tr><td colspan="4" class="text-muted text-center">No hay semanas registradas</td></tr>
                            <?php else: foreach ($semanas as $s):
                                $tipos = ['mayores'=>'Mayores','ninos'=>'Niños','adolescentes'=>'Adolescentes'];
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s['nombre']); ?></td>
                                <td><small class="text-muted"><?php echo date('d/m/Y', strtotime($s['fecha_inicio'])); ?> - <?php echo date('d/m/Y', strtotime($s['fecha_fin'])); ?></small></td>
                                <td><small><?php echo $tipos[$s['tipo_acampante']] ?? $s['tipo_acampante']; ?></small></td>
                                <td><?php echo $s['activa'] ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Resultados -->
        <div class="col-12 col-lg-7">
            <?php if (!empty($resultados)): ?>
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-list-check"></i> Resultado (<?php echo count($resultados); ?> filas)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-dark sticky-top">
                                <tr>
                                    <th>Nombre</th>
                                    <th>Estado import</th>
                                    <th>Estado equipante</th>
                                    <th>Semanas asignadas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultados as $r):
                                    $badgeEstadoEq = [
                                        'en espera'  => 'bg-warning text-dark',
                                        'aceptado'   => 'bg-success',
                                        'rechazado'  => 'bg-danger',
                                        'consejero'  => 'bg-info',
                                    ][$r['estado_eq']] ?? 'bg-secondary';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($r['nombre']); ?></td>
                                    <td>
                                        <?php if ($r['estado_imp'] === 'importado'): ?>
                                            <span class="badge bg-success">Importado</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Omitido</span>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($r['razon'] ?? ''); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['estado_eq']): ?>
                                            <span class="badge <?php echo $badgeEstadoEq; ?>"><?php echo htmlspecialchars($r['estado_eq']); ?></span>
                                        <?php else: ?>
                                            <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($r['semanas'])): ?>
                                            <?php foreach ($r['semanas'] as $sn): ?>
                                                <span class="badge bg-info text-dark me-1 mb-1"><?php echo htmlspecialchars($sn); ?></span>
                                            <?php endforeach; ?>
                                        <?php elseif ($r['estado_imp'] === 'importado'): ?>
                                            <small class="text-warning">Sin matching</small>
                                        <?php else: ?>
                                            <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card shadow-sm">
                <div class="card-body text-center text-muted py-5">
                    <i class="fas fa-file-excel fa-3x mb-3 d-block"></i>
                    <p class="mb-0">Sube un archivo Excel para ver los resultados aquí.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>