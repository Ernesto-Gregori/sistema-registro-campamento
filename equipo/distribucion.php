<?php
// equipo/distribucion.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
verificarMantenimiento($pdo);

if (!esEquipoOAdmin()) {
    header('Location: ../default.php');
    exit();
}

$year = obtenerAnioCampamento();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mensaje = '';
$error   = '';

// ---------------------------------------------------------------------
// Condicion SQL reutilizable para "quien puede ser distribuido":
//   - tipo_persona = 'equipante' Y estado en (aceptado, consejero)
//   - tipo_persona = 'alumno' SIEMPRE (sin filtrar por estado)
// Se usa como fragmento dentro de WHERE/JOIN en todas las consultas
// de esta pantalla, para que los alumnos pasen directo sin necesitar
// pasar por el flujo de aceptacion de equipantes.
// ---------------------------------------------------------------------
const SQL_ELEGIBLE_DISTRIBUCION = "(e.tipo_persona = 'equipante' AND e.estado IN ('aceptado','consejero')) OR e.tipo_persona = 'alumno'";

// ---------------------------------------------------------------------
// GUARDAR / ACTUALIZAR asignacion
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Token de seguridad invalido.';
    } else {
        $accionPost = $_POST['accion'] ?? '';
        $userId = $_SESSION['user_id'] ?? 0;

        try {
            // --- Asignar equipante a semana + area ---
            if ($accionPost === 'asignar') {
                $eid       = (int)($_POST['equipante_id'] ?? 0);
                $semanaId  = (int)($_POST['semana_id'] ?? 0);
                $areaId    = !empty($_POST['area_id']) ? (int)$_POST['area_id'] : null;
                $observa   = trim($_POST['observacion'] ?? '');

                if ($eid <= 0 || $semanaId <= 0) {
                    throw new Exception('Faltan datos: equipante o semana.');
                }

                // Si el equipante es consejero y no se especifico area,
                // auto-asignar al area "Consejeros"
                if (empty($areaId)) { // Usar empty() en lugar de === null
                    $stmtEstado = $pdo->prepare("SELECT estado FROM equipantes WHERE id = ?");
                    $stmtEstado->execute([$eid]);
                    $estadoEq = $stmtEstado->fetchColumn();

                    if ($estadoEq === 'consejero') {
                        // Buscar el area "Consejeros" (con LIKE para evitar problemas de codificacion)
                        $stmtArea = $pdo->prepare("SELECT id FROM areas_servicio WHERE nombre LIKE '%onsejer%' AND activa = 1 LIMIT 1");
                        $stmtArea->execute();
                        $areaConsejeros = $stmtArea->fetchColumn();
                        if ($areaConsejeros) {
                            $areaId = (int)$areaConsejeros;
                        }
                    }
                }

                $stmt = $pdo->prepare("
                    INSERT INTO distribucion_equipantes (equipante_id, semana_id, area_id, observacion, asignado_por)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        area_id = VALUES(area_id),
                        observacion = VALUES(observacion),
                        asignado_por = VALUES(asignado_por),
                        fecha_asignacion = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$eid, $semanaId, $areaId, $observa, $userId]);
                $mensaje = 'Asignacion guardada correctamente.';
            }

            // --- Cambiar area de un distribuido (inline) ---
            // Soporta dos casos:
            //   a) Ya existe distribucion_id (fila real en distribucion_equipantes):
            //      simplemente se actualiza el area_id.
            //   b) No existe distribucion_id (caso de un ALUMNO que aparece en la
            //      tabla solo porque su semanas_disponibles incluye esta semana,
            //      pero todavia no tiene fila en distribucion_equipantes):
            //      se crea la fila con INSERT ... ON DUPLICATE KEY UPDATE,
            //      usando equipante_id + semana_id que SI llegan siempre desde
            //      el formulario inline de la tabla.
            if ($accionPost === 'cambiar_area') {
                $idDist   = (int)($_POST['distribucion_id'] ?? 0);
                $areaId   = !empty($_POST['area_id']) ? (int)$_POST['area_id'] : null;
                $eidArea  = (int)($_POST['equipante_id'] ?? 0);
                $semArea  = (int)($_POST['semana_id'] ?? 0);

                if ($idDist > 0) {
                    // Caso a: ya existe la fila, solo actualizar area
                    $stmt = $pdo->prepare("UPDATE distribucion_equipantes SET area_id = ?, asignado_por = ? WHERE id = ?");
                    $stmt->execute([$areaId, $userId, $idDist]);
                    $mensaje = 'Area actualizada.';
                } elseif ($eidArea > 0 && $semArea > 0) {
                    // Caso b: alumno sin fila previa, crearla ahora
                    $stmt = $pdo->prepare("
                        INSERT INTO distribucion_equipantes (equipante_id, semana_id, area_id, asignado_por)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE area_id = VALUES(area_id), asignado_por = VALUES(asignado_por)
                    ");
                    $stmt->execute([$eidArea, $semArea, $areaId, $userId]);
                    $mensaje = 'Area asignada.';
                } else {
                    throw new Exception('Faltan datos para cambiar el area.');
                }
            }

            // --- Quitar asignacion ---
            if ($accionPost === 'quitar') {
                $idDist = (int)($_POST['distribucion_id'] ?? 0);
                if ($idDist <= 0) throw new Exception('ID de asignacion invalido.');
                $stmt = $pdo->prepare("DELETE FROM distribucion_equipantes WHERE id = ?");
                $stmt->execute([$idDist]);
                $mensaje = 'Asignacion eliminada.';
            }

            // --- Asignacion masiva ---
            if ($accionPost === 'asignar_todas') {
                $eid    = (int)($_POST['equipante_id'] ?? 0);
                $areaId = !empty($_POST['area_id']) ? (int)$_POST['area_id'] : null;
                if ($eid <= 0) throw new Exception('Falta el equipante.');

                // Si el equipante es consejero y no se especifico area,
                // auto-asignar al area "Consejeros"
                if (empty($areaId)) { // Usar empty() en lugar de === null
                    $stmtEstado = $pdo->prepare("SELECT estado FROM equipantes WHERE id = ?");
                    $stmtEstado->execute([$eid]);
                    $estadoEq = $stmtEstado->fetchColumn();

                    if ($estadoEq === 'consejero') {
                        // Buscar el area "Consejeros" (con LIKE para evitar problemas de codificacion)
                        $stmtArea = $pdo->prepare("SELECT id FROM areas_servicio WHERE nombre LIKE '%onsejer%' AND activa = 1 LIMIT 1");
                        $stmtArea->execute();
                        $areaConsejeros = $stmtArea->fetchColumn();
                        if ($areaConsejeros) {
                            $areaId = (int)$areaConsejeros;
                        }
                    }
                }

                $stmt = $pdo->prepare("SELECT id FROM semanas_campamento WHERE year_campamento = ? AND activa = 1");
                $stmt->execute([$year]);
                $semanasTodas = $stmt->fetchAll();

                $count = 0;
                foreach ($semanasTodas as $sem) {
                    $stmt = $pdo->prepare("
                        INSERT INTO distribucion_equipantes (equipante_id, semana_id, area_id, asignado_por)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE area_id = VALUES(area_id), asignado_por = VALUES(asignado_por)
                    ");
                    $stmt->execute([$eid, $sem['id'], $areaId, $userId]);
                    $count++;
                }
                $mensaje = "Equipante asignado a {$count} semana(s).";
            }

        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }

        if (empty($error)) {
            $url = 'distribucion.php?semana_id=' . ($_POST['semana_id'] ?? '');
            if (!empty($_POST['search']))        $url .= '&search=' . urlencode($_POST['search']);
            if (!empty($_POST['filtro_estado'])) $url .= '&filtro_estado=' . urlencode($_POST['filtro_estado']);
            if (!empty($_POST['filtro_area']))   $url .= '&filtro_area=' . urlencode($_POST['filtro_area']);
            header('Location: ' . $url . '&message=' . urlencode($mensaje));
            exit();
        }
    }
}

// ---------------------------------------------------------------------
// Cargar datos
// ---------------------------------------------------------------------

// Semanas ACTIVAS
$semanas = [];
try {
    $stmt = $pdo->prepare("SELECT id, nombre, tipo_acampante, fecha_inicio, fecha_fin, activa
                           FROM semanas_campamento
                           WHERE year_campamento = ?
                           ORDER BY fecha_inicio ASC");
    $stmt->execute([$year]);
    $semanas = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Error al cargar semanas: ' . $e->getMessage();
}

// Areas
$areas = [];
try {
    $stmt = $pdo->query("SELECT id, nombre FROM areas_servicio WHERE activa = 1 ORDER BY nombre ASC");
    $areas = $stmt->fetchAll();
} catch (Exception $e) {}

// Semana seleccionada
$semanaIdSel = isset($_GET['semana_id']) ? (int)$_GET['semana_id'] : ($semanas[0]['id'] ?? 0);

// Filtros
$search       = trim($_GET['search'] ?? '');
$filtroEstado = $_GET['filtro_estado'] ?? '';
$filtroArea   = $_GET['filtro_area'] ?? '';

// Equipantes elegibles para asignar (para el combo de "Asignar equipante a esta semana")
// Incluye:
//   - tipo_persona = 'equipante' con estado aceptado/consejero (como antes)
//   - tipo_persona = 'alumno' sin importar su estado (pasan directo)
$equipantesAceptados = [];
try {
    $whereA = ["e.year_campamento = ?", "e.activo = 1", "(" . SQL_ELEGIBLE_DISTRIBUCION . ")"];
    $parA   = [$year];
    if ($search !== '') {
        $whereA[] = "e.nombre LIKE ?";
        $parA[] = "%$search%";
    }
    $stmt = $pdo->prepare("SELECT id, nombre, sexo, edad, estado, tipo_persona FROM equipantes e WHERE " . implode(' AND ', $whereA) . " ORDER BY nombre ASC");
    $stmt->execute($parA);
    $equipantesAceptados = $stmt->fetchAll();
} catch (Exception $e) {}

// Contar aceptados, consejeros y alumnos por separado (totales del ano)
$totalAceptadosSolo  = 0;
$totalConsejerosSolo = 0;
$totalAlumnosSolo    = 0;
try {
    // Nota: SQL_ELEGIBLE_DISTRIBUCION usa el alias 'e', pero aqui la tabla
    // no tiene alias. Se reemplaza el alias 'e.' por '' para esta consulta.
    $sqlSinAlias = str_replace('e.', '', SQL_ELEGIBLE_DISTRIBUCION);
    $stmt = $pdo->prepare("
        SELECT
            CASE
                WHEN tipo_persona = 'alumno' THEN 'alumno'
                ELSE estado
            END AS grupo,
            COUNT(*) as t
        FROM equipantes
        WHERE year_campamento = ?
          AND activo = 1
          AND ($sqlSinAlias)
        GROUP BY grupo
    ");
    $stmt->execute([$year]);
    foreach ($stmt->fetchAll() as $r) {
        if ($r['grupo'] === 'aceptado')   $totalAceptadosSolo  = (int)$r['t'];
        if ($r['grupo'] === 'consejero')  $totalConsejerosSolo = (int)$r['t'];
        if ($r['grupo'] === 'alumno')     $totalAlumnosSolo    = (int)$r['t'];
    }
} catch (Exception $e) {}

// Distribucion actual de la semana (con filtros)
//
// Esta consulta combina DOS fuentes con UNION:
//
//   A) Asignaciones reales: cualquier persona (equipante o alumno) que YA
//      tiene una fila en distribucion_equipantes para esta semana (porque
//      se le asigno manualmente con el boton "Asignar", o porque ya se le
//      cambio de area desde esta misma tabla).
//
//   B) Alumnos "implicitos": tipo_persona = 'alumno' que AUN NO tienen fila
//      en distribucion_equipantes para esta semana, pero que marcaron esta
//      semana en su campo semanas_disponibles (el listado de IDs separados
//      por coma que genero equipo/sincronizar.php). Estos aparecen en la
//      tabla con area_id = NULL y distribucion_id = 0, y se les puede
//      asignar area directamente desde la fila (ver accion 'cambiar_area').
//
// La fuente B EXCLUYE a quien ya aparece en la fuente A para no duplicar
// filas cuando un alumno ya fue asignado manualmente.
$distribucion = [];

// --- Filtros comunes a ambas partes del UNION ---
$condSearch = '';
$parSearch  = [];
if ($search !== '') {
    $condSearch = " AND (e.nombre LIKE ? OR e.iglesia LIKE ?)";
    $parSearch  = ["%$search%", "%$search%"];
}

$condArea = '';
$parArea  = [];
if ((int)$filtroArea > 0) {
    $condArea = " AND area_id_calc = ?";
    $parArea  = [(int)$filtroArea];
} elseif ($filtroArea === 'sin_area') {
    $condArea = " AND area_id_calc IS NULL";
}

$condEstado = '';
$parEstadoA = []; // para la parte A (tiene alias de_ / e_ propios via columnas ya seleccionadas)
if (in_array($filtroEstado, ['aceptado','consejero','alumno'], true)) {
    if ($filtroEstado === 'alumno') {
        $condEstado = " AND tipo_persona_calc = 'alumno'";
    } else {
        $condEstado = " AND tipo_persona_calc = 'equipante' AND estado_calc = ?";
        $parEstadoA = [$filtroEstado];
    }
}

try {
    $sqlUnion = "
        SELECT * FROM (
            -- PARTE A: asignaciones reales (ya tienen fila en distribucion_equipantes)
            SELECT
                de.id AS distribucion_id,
                de.equipante_id AS equipante_id,
                de.area_id AS area_id_calc,
                de.observacion, de.fecha_asignacion,
                e.nombre, e.sexo, e.edad, e.iglesia, e.tipo_persona AS tipo_persona_calc, e.estado AS estado_calc,
                e.devocional_usado, e.pastor_autoriza, e.pastor_telefono,
                e.telefono_whatsapp, e.ministerio_iglesia, e.testimonio_salvacion,
                e.motivo_servir, e.practica_deporte, e.deporte_especifica,
                e.toca_instrumento, e.instrumento_especifica, e.estudios,
                e.habilidades_oficios, e.cualidades, e.fue_campero, e.temporadas_campero,
                e.semanas_disponibles,
                a.nombre AS area_nombre
            FROM distribucion_equipantes de
            INNER JOIN equipantes e ON de.equipante_id = e.id
            LEFT JOIN areas_servicio a ON de.area_id = a.id
            WHERE de.semana_id = ?
              AND e.activo = 1
              AND (" . SQL_ELEGIBLE_DISTRIBUCION . ")

            UNION ALL

            -- PARTE B: alumnos sin fila previa, detectados por semanas_disponibles
            SELECT
                0 AS distribucion_id,
                e.id AS equipante_id,
                NULL AS area_id_calc,
                NULL AS observacion, NULL AS fecha_asignacion,
                e.nombre, e.sexo, e.edad, e.iglesia, e.tipo_persona AS tipo_persona_calc, e.estado AS estado_calc,
                e.devocional_usado, e.pastor_autoriza, e.pastor_telefono,
                e.telefono_whatsapp, e.ministerio_iglesia, e.testimonio_salvacion,
                e.motivo_servir, e.practica_deporte, e.deporte_especifica,
                e.toca_instrumento, e.instrumento_especifica, e.estudios,
                e.habilidades_oficios, e.cualidades, e.fue_campero, e.temporadas_campero,
                e.semanas_disponibles,
                NULL AS area_nombre
            FROM equipantes e
            WHERE e.activo = 1
              AND e.tipo_persona = 'alumno'
              AND e.year_campamento = ?
              AND (
                    e.semanas_disponibles = ?
                 OR e.semanas_disponibles LIKE ?
                 OR e.semanas_disponibles LIKE ?
                 OR e.semanas_disponibles LIKE ?
              )
              AND e.id NOT IN (
                    SELECT equipante_id FROM distribucion_equipantes WHERE semana_id = ?
              )
        ) AS combinado
        WHERE 1=1 $condSearch $condArea $condEstado
        ORDER BY area_nombre ASC, nombre ASC
    ";

    // IDs comodin para detectar el ID de semana dentro de la lista CSV
    // guardada en semanas_disponibles (ej: "3,4" o "12,3,7").
    $semanaExacta   = (string)$semanaIdSel;            // "3"
    $semanaInicio   = $semanaIdSel . ',%';             // "3,%"
    $semanaMedio    = '%,' . $semanaIdSel . ',%';      // "%,3,%"
    $semanaFinal    = '%,' . $semanaIdSel;             // "%,3"

    // El filtro de busqueda (search) se aplica DESPUES del UNION, sobre la
    // tabla combinada, asi que el parametro solo se necesita una vez.
    $params = array_merge(
        [$semanaIdSel],                                   // parte A: de.semana_id
        [$year, $semanaExacta, $semanaInicio, $semanaMedio, $semanaFinal, $semanaIdSel], // parte B
        $parSearch,
        $parArea,
        $parEstadoA
    );

    $stmt = $pdo->prepare($sqlUnion);
    $stmt->execute($params);
    $distribucion = $stmt->fetchAll();

    // Renombrar columnas calculadas a los nombres que usa el resto de la
    // pagina (area_id, tipo_persona, estado, id) para no tener que tocar
    // el HTML de las tablas mas abajo.
    foreach ($distribucion as &$d) {
        $d['id']           = $d['distribucion_id'];
        $d['area_id']      = $d['area_id_calc'];
        $d['tipo_persona'] = $d['tipo_persona_calc'];
        $d['estado']       = $d['estado_calc'];
    }
    unset($d);
} catch (Exception $e) {
    $error = 'Error al cargar distribucion: ' . $e->getMessage();
}

// Contar distribuidos en esta semana (incluye alumnos implicitos por
// semanas_disponibles). Se calcula directamente sobre $distribucion para
// no duplicar la logica de matching de semanas_disponibles en SQL aparte.
$totalDistribuidosSemana = count($distribucion);
$distribuidosSinArea = 0;
foreach ($distribucion as $d) {
    if (empty($d['area_id'])) $distribuidosSinArea++;
}

// Equipantes/alumnos NO distribuidos en esta semana:
//   - equipantes (aceptado/consejero) sin fila en distribucion_equipantes
//   - alumnos que NO marcaron esta semana en semanas_disponibles y tampoco
//     tienen fila manual en distribucion_equipantes
$totalSinAsignarSemana = 0;
try {
    $semanaExacta2 = (string)$semanaIdSel;
    $semanaInicio2 = $semanaIdSel . ',%';
    $semanaMedio2  = '%,' . $semanaIdSel . ',%';
    $semanaFinal2  = '%,' . $semanaIdSel;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM equipantes e
        WHERE e.year_campamento = ? AND e.activo = 1 AND (" . SQL_ELEGIBLE_DISTRIBUCION . ")
          AND e.id NOT IN (SELECT equipante_id FROM distribucion_equipantes WHERE semana_id = ?)
          AND NOT (
                e.tipo_persona = 'alumno'
                AND (
                      e.semanas_disponibles = ?
                   OR e.semanas_disponibles LIKE ?
                   OR e.semanas_disponibles LIKE ?
                   OR e.semanas_disponibles LIKE ?
                )
          )
    ");
    $stmt->execute([$year, $semanaIdSel, $semanaExacta2, $semanaInicio2, $semanaMedio2, $semanaFinal2]);
    $totalSinAsignarSemana = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Resumen por area (clickeable). Se calcula sobre $distribucion para
// que incluya tanto asignaciones reales como alumnos implicitos.
$porArea = [];
try {
    $stmtAreas = $pdo->query("SELECT id, nombre FROM areas_servicio WHERE activa = 1 ORDER BY nombre ASC");
    $areasTodas = $stmtAreas->fetchAll();
    $conteoPorArea = [];
    foreach ($distribucion as $d) {
        if (!empty($d['area_id'])) {
            $aid = (int)$d['area_id'];
            $conteoPorArea[$aid] = ($conteoPorArea[$aid] ?? 0) + 1;
        }
    }
    foreach ($areasTodas as $a) {
        $total = $conteoPorArea[(int)$a['id']] ?? 0;
        if ($total > 0) {
            $porArea[] = ['id' => $a['id'], 'nombre' => $a['nombre'], 'total' => $total];
        }
    }
    usort($porArea, fn($x, $y) => $y['total'] <=> $x['total']);
} catch (Exception $e) {}

include '../includes/header.php';
?>

<div class="container-fluid py-3">

<?php if (!empty($_GET['message'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

    <!-- Cabecera -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0"><i class="fas fa-clipboard-list text-primary"></i> Distribucion de Equipantes</h1>
            <small class="text-muted">Asigna los equipantes aceptados y alumnos a semanas y areas de servicio.</small>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Dashboard
        </a>
    </div>

    <!-- Selector de semana -->
    <div class="card mb-3 shadow-sm">
        <div class="card-body py-2">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="fw-bold text-muted small"><i class="fas fa-calendar-week"></i> Semana:</span>
                <?php if (empty($semanas)): ?>
                    <span class="text-danger small">No hay semanas activas</span>
                <?php else: foreach ($semanas as $s): ?>
                    <a href="?semana_id=<?php echo $s['id']; ?>"
                       class="btn btn-sm <?php echo $semanaIdSel == $s['id'] ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                        <?php echo htmlspecialchars($s['nombre']); ?>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- Mini stats -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-success"><?php echo $totalAceptadosSolo; ?></div>
                    <small class="text-muted">Aceptados</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-info"><?php echo $totalConsejerosSolo; ?></div>
                    <small class="text-muted">Consejeros</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-secondary"><?php echo $totalAlumnosSolo; ?></div>
                    <small class="text-muted">Alumnos</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-primary"><?php echo $totalDistribuidosSemana; ?></div>
                    <small class="text-muted">Distribuidos esta semana</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-warning"><?php echo $totalSinAsignarSemana; ?></div>
                    <small class="text-muted">Sin asignar esta semana</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 <?php echo $distribuidosSinArea > 0 ? 'text-danger' : 'text-muted'; ?>"><?php echo $distribuidosSinArea; ?></div>
                    <small class="text-muted">Sin area asignada</small>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Columna izquierda: resumen por area clickeable -->
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-chart-bar"></i> Resumen por area</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($porArea)): ?>
                        <p class="text-muted small text-center py-3 mb-0">Sin areas con personas asignadas</p>
                    <?php else: ?>
                    <table class="table table-sm mb-0">
                        <tbody>
                            <?php foreach ($porArea as $pa): ?>
                            <tr>
                                <td>
                                    <a href="?semana_id=<?php echo $semanaIdSel; ?>&filtro_area=<?php echo $pa['id']; ?>" class="text-decoration-none">
                                        <?php echo htmlspecialchars($pa['nombre']); ?>
                                    </a>
                                </td>
                                <td class="text-end">
                                    <span class="badge bg-primary"><?php echo (int)$pa['total']; ?></span>
                                </td>
                                <td class="text-end" style="width:40px">
                                    <a href="area_detalle.php?semana_id=<?php echo $semanaIdSel; ?>&area_id=<?php echo $pa['id']; ?>"
                                       class="btn btn-outline-info btn-sm py-0 px-1" title="Ver e imprimir" target="_blank">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
                <?php if ($totalDistribuidosSemana > 0): ?>
                <div class="card-footer bg-light p-2">
                    <a href="?semana_id=<?php echo $semanaIdSel; ?>" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="fas fa-list"></i> Ver todos
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Columna derecha: asignar + tabla -->
        <div class="col-12 col-lg-8">
            <!-- Asignar equipante (movido aqui) -->
            <?php if (!empty($equipantesAceptados) && $semanaIdSel): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-light py-2">
                    <h6 class="mb-0 small"><i class="fas fa-plus-circle"></i> Asignar equipante a esta semana</h6>
                </div>
                <div class="card-body py-2">
                    <form method="POST" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="accion" value="asignar">
                        <input type="hidden" name="semana_id" value="<?php echo $semanaIdSel; ?>">
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                        <input type="hidden" name="filtro_estado" value="<?php echo htmlspecialchars($filtroEstado); ?>">
                        <input type="hidden" name="filtro_area" value="<?php echo htmlspecialchars($filtroArea); ?>">
                        <div class="col-md-5">
                            <select name="equipante_id" class="form-select form-select-sm" required>
                                <option value="">-- Equipante --</option>
                                <?php foreach ($equipantesAceptados as $eq): ?>
                                <option value="<?php echo $eq['id']; ?>">
                                    <?php echo htmlspecialchars($eq['nombre']); ?>
                                    (<?php echo $eq['sexo']==='masculino'?'M':'F'; ?>, <?php echo (int)$eq['edad']; ?>)
                                    <?php echo $eq['tipo_persona'] === 'alumno' ? ' - Alumno' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="area_id" class="form-select form-select-sm">
                                <option value="">-- Sin area --</option>
                                <?php foreach ($areas as $a): ?>
                                <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="fas fa-plus"></i> Asignar
                            </button>
                        </div>
                    </form>
                    <details class="mt-2">
                        <summary class="small text-muted"><i class="fas fa-layer-group"></i> Asignar a todas las semanas</summary>
                        <form method="POST" class="row g-2 mt-1">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="accion" value="asignar_todas">
                            <div class="col-md-5">
                                <select name="equipante_id" class="form-select form-select-sm" required>
                                    <option value="">-- Equipante --</option>
                                    <?php foreach ($equipantesAceptados as $eq): ?>
                                    <option value="<?php echo $eq['id']; ?>"><?php echo htmlspecialchars($eq['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select name="area_id" class="form-select form-select-sm">
                                    <option value="">-- Sin area --</option>
                                    <?php foreach ($areas as $a): ?>
                                    <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                                    <i class="fas fa-calendar-check"></i> Todas
                                </button>
                            </div>
                        </form>
                    </details>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filtros de busqueda -->
            <div class="card shadow-sm mb-3">
                <div class="card-body py-2">
                    <form method="GET" class="row g-2 align-items-end">
                        <input type="hidden" name="semana_id" value="<?php echo $semanaIdSel; ?>">
                        <div class="col-md-5">
                            <input type="text" name="search" class="form-control form-control-sm" placeholder="Buscar por nombre o iglesia..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="filtro_estado" class="form-select form-select-sm">
                                <option value="">Todos los estados</option>
                                <option value="aceptado" <?php echo $filtroEstado==='aceptado'?'selected':''; ?>>Aceptados</option>
                                <option value="consejero" <?php echo $filtroEstado==='consejero'?'selected':''; ?>>Consejeros</option>
                                <option value="alumno" <?php echo $filtroEstado==='alumno'?'selected':''; ?>>Alumnos</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="filtro_area" class="form-select form-select-sm">
                                <option value="">Todas las areas</option>
                                <option value="sin_area" <?php echo $filtroArea==='sin_area'?'selected':''; ?>>Sin area</option>
                                <?php foreach ($areas as $a): ?>
                                <option value="<?php echo $a['id']; ?>" <?php echo (string)$filtroArea===(string)$a['id']?'selected':''; ?>><?php echo htmlspecialchars($a['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search"></i></button>
                        </div>
                        <div class="col-md-1">
                            <a href="?semana_id=<?php echo $semanaIdSel; ?>" class="btn btn-outline-secondary btn-sm w-100"><i class="fas fa-times"></i></a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Separar por genero: lado a lado -->
            <?php
            $distribMasc = [];
            $distribFem  = [];
            foreach ($distribucion as $d) {
                if ($d['sexo'] === 'masculino') $distribMasc[] = $d;
                elseif ($d['sexo'] === 'femenino') $distribFem[] = $d;
            }
            ?>

            <?php if (empty($distribucion)): ?>

        <!-- Tablas por genero a ANCHO COMPLETO -->
    <div class="card shadow-sm mt-3">
        <div class="card-body text-center text-muted py-4">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
            Nadie asignado con estos filtros.
        </div>
    </div>
    <?php else: ?>

        </div><!-- fin col-lg-8 -->
    </div>

    <div class="row g-3 mt-1">
        <!-- ====== MASCULINOS ====== -->
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-primary">
                <div class="card-header bg-primary text-white py-2">
                    <h6 class="mb-0">
                        <i class="fas fa-mars"></i> Masculinos
                        <span class="badge bg-light text-primary ms-1"><?php echo count($distribMasc); ?></span>
                    </h6>
                </div>
                <div class="card-body p-0" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th>Nombre</th>
                                <th>Estado</th>
                                <th style="min-width:160px">Area</th>
                                <th class="text-end" style="width:80px">Acc.</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($distribMasc)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Sin masculinos</td></tr>
                        <?php else: foreach ($distribMasc as $d): ?>
                            <tr>
                                <td>
                                    <strong class="small"><?php echo htmlspecialchars($d['nombre']); ?></strong>
                                    <?php if ($d['edad']): ?>
                                        <small class="text-muted">(<?php echo (int)$d['edad']; ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($d['tipo_persona'] === 'alumno'): ?>
                                        <span class="badge bg-secondary">Alumno</span>
                                    <?php else: ?>
                                        <span class="badge <?php echo $d['estado']==='consejero'?'bg-info':'bg-success'; ?>"><?php echo htmlspecialchars($d['estado']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="form-area-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="accion" value="cambiar_area">
                                        <input type="hidden" name="distribucion_id" value="<?php echo $d['id']; ?>">
                                        <input type="hidden" name="equipante_id" value="<?php echo $d['equipante_id']; ?>">
                                        <input type="hidden" name="semana_id" value="<?php echo $semanaIdSel; ?>">
                                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                                        <input type="hidden" name="filtro_estado" value="<?php echo htmlspecialchars($filtroEstado); ?>">
                                        <input type="hidden" name="filtro_area" value="<?php echo htmlspecialchars($filtroArea); ?>">
                                        <select name="area_id" class="form-select form-select-sm cambio-area">
                                            <option value="">--</option>
                                            <?php foreach ($areas as $a): ?>
                                            <option value="<?php echo $a['id']; ?>" <?php echo (int)$d['area_id']===(int)$a['id']?'selected':''; ?>><?php echo htmlspecialchars($a['nombre']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-outline-info btn-sm py-0 px-1"
                                            data-bs-toggle="modal" data-bs-target="#modalDetalle"
                                            data-equipante-id="<?php echo $d['equipante_id']; ?>"
                                            data-nombre="<?php echo htmlspecialchars($d['nombre']); ?>"
                                            data-sexo="<?php echo htmlspecialchars($d['sexo'] ?? ''); ?>"
                                            data-edad="<?php echo (int)$d['edad']; ?>"
                                            data-iglesia="<?php echo htmlspecialchars($d['iglesia'] ?? ''); ?>"
                                            data-area="<?php echo htmlspecialchars($d['area_nombre'] ?? ''); ?>"
                                            data-estado="<?php echo htmlspecialchars($d['tipo_persona'] === 'alumno' ? 'alumno' : ($d['estado'] ?? '')); ?>"
                                            data-tipo="<?php echo htmlspecialchars($d['tipo_persona'] ?? ''); ?>"
                                            data-devocional="<?php echo htmlspecialchars($d['devocional_usado'] ?? ''); ?>"
                                            data-pastor="<?php echo htmlspecialchars($d['pastor_autoriza'] ?? ''); ?>"
                                            data-pastor-tel="<?php echo htmlspecialchars($d['pastor_telefono'] ?? ''); ?>"
                                            data-whatsapp="<?php echo htmlspecialchars($d['telefono_whatsapp'] ?? ''); ?>"
                                            data-ministerio="<?php echo htmlspecialchars($d['ministerio_iglesia'] ?? ''); ?>"
                                            data-estudios="<?php echo htmlspecialchars($d['estudios'] ?? ''); ?>"
                                            data-habilidades="<?php echo htmlspecialchars($d['habilidades_oficios'] ?? ''); ?>"
                                            data-deporte="<?php echo htmlspecialchars($d['deporte_especifica'] ?? ''); ?>"
                                            data-instrumento="<?php echo htmlspecialchars($d['instrumento_especifica'] ?? ''); ?>"
                                            data-campero="<?php echo htmlspecialchars($d['temporadas_campero'] ?? ''); ?>"
                                            data-semanas="<?php echo htmlspecialchars($d['semanas_disponibles'] ?? ''); ?>"
                                            data-testimonio="<?php echo htmlspecialchars($d['testimonio_salvacion'] ?? ''); ?>"
                                            data-motivo="<?php echo htmlspecialchars($d['motivo_servir'] ?? ''); ?>"
                                            title="Ver detalle">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ((int)$d['id'] > 0): ?>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Quitar a <?php echo htmlspecialchars($d['nombre']); ?>?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="accion" value="quitar">
                                        <input type="hidden" name="distribucion_id" value="<?php echo $d['id']; ?>">
                                        <input type="hidden" name="semana_id" value="<?php echo $semanaIdSel; ?>">
                                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                                        <input type="hidden" name="filtro_estado" value="<?php echo htmlspecialchars($filtroEstado); ?>">
                                        <input type="hidden" name="filtro_area" value="<?php echo htmlspecialchars($filtroArea); ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Quitar"><i class="fas fa-times"></i></button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-muted" title="Alumno detectado por sus semanas disponibles; aun sin asignacion formal" style="font-size:0.75rem;">
                                        <i class="fas fa-info-circle"></i>
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ====== FEMENINOS ====== -->
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-danger">
                <div class="card-header bg-danger text-white py-2">
                    <h6 class="mb-0">
                        <i class="fas fa-venus"></i> Femeninos
                        <span class="badge bg-light text-danger ms-1"><?php echo count($distribFem); ?></span>
                    </h6>
                </div>
                <div class="card-body p-0" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th>Nombre</th>
                                <th>Estado</th>
                                <th style="min-width:160px">Area</th>
                                <th class="text-end" style="width:80px">Acc.</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($distribFem)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Sin femeninos</td></tr>
                        <?php else: foreach ($distribFem as $d): ?>
                            <tr>
                                <td>
                                    <strong class="small"><?php echo htmlspecialchars($d['nombre']); ?></strong>
                                    <?php if ($d['edad']): ?>
                                        <small class="text-muted">(<?php echo (int)$d['edad']; ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($d['tipo_persona'] === 'alumno'): ?>
                                        <span class="badge bg-secondary">Alumno</span>
                                    <?php else: ?>
                                        <span class="badge <?php echo $d['estado']==='consejero'?'bg-info':'bg-success'; ?>"><?php echo htmlspecialchars($d['estado']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="form-area-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="accion" value="cambiar_area">
                                        <input type="hidden" name="distribucion_id" value="<?php echo $d['id']; ?>">
                                        <input type="hidden" name="equipante_id" value="<?php echo $d['equipante_id']; ?>">
                                        <input type="hidden" name="semana_id" value="<?php echo $semanaIdSel; ?>">
                                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                                        <input type="hidden" name="filtro_estado" value="<?php echo htmlspecialchars($filtroEstado); ?>">
                                        <input type="hidden" name="filtro_area" value="<?php echo htmlspecialchars($filtroArea); ?>">
                                        <select name="area_id" class="form-select form-select-sm cambio-area">
                                            <option value="">--</option>
                                            <?php foreach ($areas as $a): ?>
                                            <option value="<?php echo $a['id']; ?>" <?php echo (int)$d['area_id']===(int)$a['id']?'selected':''; ?>><?php echo htmlspecialchars($a['nombre']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-outline-info btn-sm py-0 px-1"
                                            data-bs-toggle="modal" data-bs-target="#modalDetalle"
                                            data-equipante-id="<?php echo $d['equipante_id']; ?>"
                                            data-nombre="<?php echo htmlspecialchars($d['nombre']); ?>"
                                            data-sexo="<?php echo htmlspecialchars($d['sexo'] ?? ''); ?>"
                                            data-edad="<?php echo (int)$d['edad']; ?>"
                                            data-iglesia="<?php echo htmlspecialchars($d['iglesia'] ?? ''); ?>"
                                            data-area="<?php echo htmlspecialchars($d['area_nombre'] ?? ''); ?>"
                                            data-estado="<?php echo htmlspecialchars($d['tipo_persona'] === 'alumno' ? 'alumno' : ($d['estado'] ?? '')); ?>"
                                            data-tipo="<?php echo htmlspecialchars($d['tipo_persona'] ?? ''); ?>"
                                            data-devocional="<?php echo htmlspecialchars($d['devocional_usado'] ?? ''); ?>"
                                            data-pastor="<?php echo htmlspecialchars($d['pastor_autoriza'] ?? ''); ?>"
                                            data-pastor-tel="<?php echo htmlspecialchars($d['pastor_telefono'] ?? ''); ?>"
                                            data-whatsapp="<?php echo htmlspecialchars($d['telefono_whatsapp'] ?? ''); ?>"
                                            data-ministerio="<?php echo htmlspecialchars($d['ministerio_iglesia'] ?? ''); ?>"
                                            data-estudios="<?php echo htmlspecialchars($d['estudios'] ?? ''); ?>"
                                            data-habilidades="<?php echo htmlspecialchars($d['habilidades_oficios'] ?? ''); ?>"
                                            data-deporte="<?php echo htmlspecialchars($d['deporte_especifica'] ?? ''); ?>"
                                            data-instrumento="<?php echo htmlspecialchars($d['instrumento_especifica'] ?? ''); ?>"
                                            data-campero="<?php echo htmlspecialchars($d['temporadas_campero'] ?? ''); ?>"
                                            data-semanas="<?php echo htmlspecialchars($d['semanas_disponibles'] ?? ''); ?>"
                                            data-testimonio="<?php echo htmlspecialchars($d['testimonio_salvacion'] ?? ''); ?>"
                                            data-motivo="<?php echo htmlspecialchars($d['motivo_servir'] ?? ''); ?>"
                                            title="Ver detalle">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ((int)$d['id'] > 0): ?>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Quitar a <?php echo htmlspecialchars($d['nombre']); ?>?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="accion" value="quitar">
                                        <input type="hidden" name="distribucion_id" value="<?php echo $d['id']; ?>">
                                        <input type="hidden" name="semana_id" value="<?php echo $semanaIdSel; ?>">
                                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                                        <input type="hidden" name="filtro_estado" value="<?php echo htmlspecialchars($filtroEstado); ?>">
                                        <input type="hidden" name="filtro_area" value="<?php echo htmlspecialchars($filtroArea); ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Quitar"><i class="fas fa-times"></i></button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-muted" title="Alumno detectado por sus semanas disponibles; aun sin asignacion formal" style="font-size:0.75rem;">
                                        <i class="fas fa-info-circle"></i>
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>

</div>

<!-- Modal de detalle del equipante -->
<div class="modal fade" id="modalDetalle" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user"></i> <span id="md_nombre">Detalle</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="fas fa-id-card"></i> Datos personales</h6>
                        <table class="table table-sm">
                            <tr><th>Sexo:</th><td id="md_sexo"></td></tr>
                            <tr><th>Edad:</th><td id="md_edad"></td></tr>
                            <tr><th>Iglesia:</th><td id="md_iglesia"></td></tr>
                            <tr><th>WhatsApp:</th><td id="md_whatsapp"></td></tr>
                            <tr><th>Estado:</th><td id="md_estado"></td></tr>
                            <tr><th>Tipo:</th><td id="md_tipo"></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="fas fa-church"></i> Vida espiritual</h6>
                        <table class="table table-sm">
                            <tr><th>Devocional:</th><td id="md_devocional"></td></tr>
                            <tr><th>Pastor:</th><td id="md_pastor"></td></tr>
                            <tr><th>Tel pastor:</th><td id="md_pastor_tel"></td></tr>
                            <tr><th>Ministerio:</th><td id="md_ministerio"></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="fas fa-tools"></i> Habilidades</h6>
                        <table class="table table-sm">
                            <tr><th>Estudios:</th><td id="md_estudios"></td></tr>
                            <tr><th>Habilidades:</th><td id="md_habilidades"></td></tr>
                            <tr><th>Deporte:</th><td id="md_deporte"></td></tr>
                            <tr><th>Instrumento:</th><td id="md_instrumento"></td></tr>
                            <tr><th>Fue campero:</th><td id="md_campero"></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="fas fa-calendar"></i> Distribucion</h6>
                        <table class="table table-sm">
                            <tr><th>Area actual:</th><td id="md_area"></td></tr>
                            <tr><th>Semanas disp.:</th><td id="md_semanas"></td></tr>
                        </table>
                    </div>
                    <div class="col-12">
                        <h6 class="text-primary"><i class="fas fa-heart"></i> Testimonio y motivo</h6>
                        <p class="small bg-light p-2 rounded"><strong>Testimonio:</strong> <span id="md_testimonio"></span></p>
                        <p class="small bg-light p-2 rounded"><strong>Motivo:</strong> <span id="md_motivo"></span></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                <a href="#" class="btn btn-primary" id="md_editar"><i class="fas fa-edit"></i> Editar ficha</a>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-enviar al cambiar area
    document.querySelectorAll('.cambio-area').forEach(function(sel) {
        sel.addEventListener('change', function() {
            this.closest('form').submit();
        });
    });

    // Modal de detalle
    var modal = document.getElementById('modalDetalle');
    modal.addEventListener('show.bs.modal', function(event) {
        var btn = event.relatedTarget;
        var campos = ['nombre','sexo','edad','iglesia','area','estado','tipo','devocional',
                      'pastor','pastor_tel','whatsapp','ministerio','estudios','habilidades',
                      'deporte','instrumento','campero','semanas','testimonio','motivo'];
        campos.forEach(function(c) {
            var el = document.getElementById('md_' + c);
            if (el) el.textContent = btn.dataset[c] || '-';
        });
        // Link de editar
        var editar = document.getElementById('md_editar');
        editar.href = 'reclutamiento.php?action=edit&id=' + btn.dataset.equipanteId;
    });
});
</script>

<?php include '../includes/footer.php'; ?>