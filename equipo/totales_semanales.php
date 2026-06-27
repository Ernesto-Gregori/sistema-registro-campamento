<?php
// equipo/totales_semanales.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
verificarMantenimiento($pdo);

if (!esEquipoOAdmin()) {
    header('Location: ../default.php');
    exit();
}

$year = obtenerAnioCampamento();
$error = '';

// ---------------------------------------------------------------------
// Cargar TODAS las semanas del año
// ---------------------------------------------------------------------
$semanas = [];
try {
    $stmt = $pdo->prepare("SELECT id, nombre, descripcion, fecha_inicio, fecha_fin,
                                  tipo_acampante, activa
                           FROM semanas_campamento
                           WHERE year_campamento = ?
                           ORDER BY fecha_inicio ASC");
    $stmt->execute([$year]);
    $semanas = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Error al cargar semanas: ' . $e->getMessage();
}

// Semana seleccionada (todas por defecto)
$semanaIdSel = isset($_GET['semana_id']) ? (int)$_GET['semana_id'] : 0;
$verTodas = ($semanaIdSel === 0);

// ---------------------------------------------------------------------
// Función: obtener totales de personas por semana
// ---------------------------------------------------------------------
function obtenerTotalesSemana($pdo, $semanaId, $year) {
    $totales = [
        'equipantes'    => 0,
        'equipantes_m'  => 0,
        'equipantes_f'  => 0,
        'alumnos'       => 0,
        'alumnos_m'     => 0,
        'alumnos_f'     => 0,
        'consejeros'    => 0,
        'consejeros_m'  => 0,
        'consejeros_f'  => 0,
        'misioneros'    => 0,
        'invitados'     => 0,
        'cocina'        => 0,
        'acampantes'    => 0,
        'total_equipo'  => 0,
        'total_general' => 0,
        'hombres'       => 0,
        'mujeres'       => 0,
        'detalle_areas' => [],
        'desglose'      => [
            'alumnos'    => ['menores_12' => 0, 'mayores_12' => 0, 'mayores_18' => 0],
            'misioneros' => ['menores_12' => 0, 'mayores_12' => 0, 'mayores_18' => 0],
            'invitados'  => ['menores_12' => 0, 'mayores_12' => 0, 'mayores_18' => 0],
            'cocina'     => ['menores_12' => 0, 'mayores_12' => 0, 'mayores_18' => 0],
        ],
        'familias_total' => ['menores_12' => 0, 'mayores_12' => 0, 'mayores_18' => 0],
    ];

    try {
        // ── Equipantes y Alumnos distribuidos en esta semana ───────────────
        $stmt = $pdo->prepare("
            SELECT e.tipo_persona, e.estado, e.sexo, e.come_comedor,
                   e.es_familia, e.familiares_menores_12, e.familiares_mayores_12, e.familiares_mayores_18,
                   COUNT(*) AS total,
                   a.nombre AS area_nombre
            FROM distribucion_equipantes de
            INNER JOIN equipantes e ON de.equipante_id = e.id
            LEFT JOIN areas_servicio a ON de.area_id = a.id
            WHERE de.semana_id = ?
              AND e.activo = 1
              AND e.year_campamento = ?
              AND e.tipo_persona IN ('equipante', 'alumno')
              AND e.estado IN ('aceptado', 'consejero')
            GROUP BY e.tipo_persona, e.estado, e.sexo, e.come_comedor,
                     e.es_familia, e.familiares_menores_12, e.familiares_mayores_12, e.familiares_mayores_18,
                     a.nombre
        ");
        $stmt->execute([$semanaId, $year]);

        foreach ($stmt->fetchAll() as $row) {
            $esConsejero = ($row['estado'] === 'consejero');
            $tipo        = $row['tipo_persona'];
            $sexo        = $row['sexo'] ?? '';
            $comeComedor = (int)$row['come_comedor'];
            $esFamilia   = (int)$row['es_familia'];

            $cant = 1;

            if ($esFamilia === 1) {
                $menores   = (int)$row['familiares_menores_12'];
                $mayores12 = (int)$row['familiares_mayores_12'];
                $mayores18 = (int)$row['familiares_mayores_18'];
                $familiares = $menores + $mayores12 + $mayores18;

                if ($comeComedor === 0) {
                    $cant = 0;
                    $menores = $mayores12 = $mayores18 = 0;
                } else {
                    $cant = max(1, $familiares);
                }

                $keyDesglose = $tipo === 'alumno' ? 'alumnos' : null;
                if ($keyDesglose && isset($totales['desglose'][$keyDesglose])) {
                    $totales['desglose'][$keyDesglose]['menores_12'] += $menores;
                    $totales['desglose'][$keyDesglose]['mayores_12'] += $mayores12;
                    $totales['desglose'][$keyDesglose]['mayores_18'] += $mayores18;
                }
                $totales['familias_total']['menores_12'] += $menores;
                $totales['familias_total']['mayores_12'] += $mayores12;
                $totales['familias_total']['mayores_18'] += $mayores18;
            } else {
                if ($comeComedor === 0) {
                    $cant = 0;
                }
            }

            if ($esConsejero) {
                $totales['consejeros'] += $cant;
                if ($sexo === 'masculino') $totales['consejeros_m'] += $cant;
                if ($sexo === 'femenino')  $totales['consejeros_f'] += $cant;
            } else {
                if ($tipo === 'equipante') {
                    $totales['equipantes'] += $cant;
                    if ($sexo === 'masculino') $totales['equipantes_m'] += $cant;
                    if ($sexo === 'femenino')  $totales['equipantes_f'] += $cant;
                } else {
                    $totales['alumnos'] += $cant;
                    if ($sexo === 'masculino') $totales['alumnos_m'] += $cant;
                    if ($sexo === 'femenino')  $totales['alumnos_f'] += $cant;
                }
            }

            if ($sexo === 'masculino') $totales['hombres'] += $cant;
            if ($sexo === 'femenino')  $totales['mujeres'] += $cant;

            if (!$esConsejero && $row['area_nombre'] && $cant > 0) {
                $area = $row['area_nombre'];
                $totales['detalle_areas'][$area] = ($totales['detalle_areas'][$area] ?? 0) + $cant;
            }
        }

        // ── Misioneros, invitados y cocina distribuidos en esta semana ────
        $stmtOtros = $pdo->prepare("
            SELECT e.tipo_persona, e.sexo, e.come_comedor, e.es_familia,
                   e.familiares_menores_12, e.familiares_mayores_12, e.familiares_mayores_18
            FROM distribucion_equipantes de
            INNER JOIN equipantes e ON de.equipante_id = e.id
            WHERE de.semana_id = ?
              AND e.activo = 1
              AND e.year_campamento = ?
              AND e.tipo_persona IN ('misionero', 'invitado', 'cocina')
        ");
        $stmtOtros->execute([$semanaId, $year]);

        $mapaTipo = [
            'misionero' => 'misioneros',
            'invitado'  => 'invitados',
            'cocina'    => 'cocina',
        ];

        foreach ($stmtOtros->fetchAll() as $row) {
            $tipo       = $row['tipo_persona'];
            $keyTotales = $mapaTipo[$tipo] ?? null;
            $comeComedor = (int)$row['come_comedor'];
            $esFamilia   = (int)$row['es_familia'];

            $personas = 1;

            if ($esFamilia === 1) {
                $menores   = (int)$row['familiares_menores_12'];
                $mayores12 = (int)$row['familiares_mayores_12'];
                $mayores18 = (int)$row['familiares_mayores_18'];
                $personas  = $menores + $mayores12 + $mayores18;
                if ($personas === 0) $personas = 1;

                if ($comeComedor === 0) {
                    $personas = 0;
                    $menores = $mayores12 = $mayores18 = 0;
                }

                if ($keyTotales && isset($totales['desglose'][$keyTotales])) {
                    $totales['desglose'][$keyTotales]['menores_12'] += $menores;
                    $totales['desglose'][$keyTotales]['mayores_12'] += $mayores12;
                    $totales['desglose'][$keyTotales]['mayores_18'] += $mayores18;
                }
                $totales['familias_total']['menores_12'] += $menores;
                $totales['familias_total']['mayores_12'] += $mayores12;
                $totales['familias_total']['mayores_18'] += $mayores18;
            } else {
                if ($comeComedor === 0) {
                    $personas = 0;
                }
            }

            if ($keyTotales !== null) {
                $totales[$keyTotales] += $personas;
            }

            if ($esFamilia !== 1) {
                if ($row['sexo'] === 'masculino') $totales['hombres'] += $personas;
                if ($row['sexo'] === 'femenino')  $totales['mujeres'] += $personas;
            }
        }

        // ── Acampantes de esta semana ─────────────────────────────────────
        $stmtAcmp = $pdo->prepare("
            SELECT COUNT(*) AS total, sexo
            FROM acampantes
            WHERE semana_id = ? AND estado = 'activo'
            GROUP BY sexo
        ");
        $stmtAcmp->execute([$semanaId]);
        foreach ($stmtAcmp->fetchAll() as $row) {
            $totales['acampantes'] += (int)$row['total'];
            if ($row['sexo'] === 'masculino') $totales['hombres'] += (int)$row['total'];
            if ($row['sexo'] === 'femenino')  $totales['mujeres'] += (int)$row['total'];
        }

        // ── Totales finales ───────────────────────────────────────────────
        $totales['total_equipo'] = $totales['equipantes'] + $totales['alumnos']
                                 + $totales['consejeros']  + $totales['misioneros']
                                 + $totales['invitados']   + $totales['cocina'];
        $totales['total_general'] = $totales['total_equipo'] + $totales['acampantes'];

    } catch (Exception $e) {
        // silencioso
    }

    return $totales;
}

// ---------------------------------------------------------------------
// Calcular totales
// ---------------------------------------------------------------------
$totalesPorSemana = [];

if ($verTodas) {
    foreach ($semanas as $s) {
        $totalesPorSemana[$s['id']] = obtenerTotalesSemana($pdo, $s['id'], $year);
        $totalesPorSemana[$s['id']]['nombre']      = $s['nombre'];
        $totalesPorSemana[$s['id']]['fecha_inicio'] = $s['fecha_inicio'];
        $totalesPorSemana[$s['id']]['fecha_fin']    = $s['fecha_fin'];
        $totalesPorSemana[$s['id']]['activa']       = $s['activa'];
    }
} else {
    $totalesDetalle = obtenerTotalesSemana($pdo, $semanaIdSel, $year);

    // Distribución detallada por persona
    $distribucionDetallada = [];
    try {
        $stmt = $pdo->prepare("
            SELECT e.nombre, e.sexo, e.edad, e.tipo_persona, e.estado, e.iglesia,
                   a.nombre AS area_nombre
            FROM distribucion_equipantes de
            INNER JOIN equipantes e ON de.equipante_id = e.id
            LEFT JOIN areas_servicio a ON de.area_id = a.id
            WHERE de.semana_id = ?
              AND e.activo = 1
              AND e.estado IN ('aceptado', 'consejero')
              AND e.tipo_persona IN ('equipante', 'alumno', 'misionero', 'invitado', 'cocina')
            ORDER BY a.nombre ASC, e.nombre ASC
        ");
        $stmt->execute([$semanaIdSel]);
        $distribucionDetallada = $stmt->fetchAll();
    } catch (Exception $e) {}
}

include '../includes/header.php';
?>

<div class="container-fluid py-3">

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

    <!-- Cabecera -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0"><i class="fas fa-calculator text-primary"></i> Totales Semanales</h1>
            <small class="text-muted">Resumen de personas presentes cada semana (para cocina y planificación).</small>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Dashboard
        </a>
    </div>

    <!-- Selector de semana -->
    <div class="card mb-3 shadow-sm">
        <div class="card-body py-2">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="fw-bold text-muted small"><i class="fas fa-calendar-week"></i> Vista:</span>
                <a href="?semana_id=0"
                   class="btn btn-sm <?php echo $verTodas ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                    <i class="fas fa-table"></i> Resumen general
                </a>
                <?php if (empty($semanas)): ?>
                    <span class="text-danger small">No hay semanas registradas para <?php echo $year; ?></span>
                <?php else: foreach ($semanas as $s): ?>
                    <a href="?semana_id=<?php echo $s['id']; ?>"
                       class="btn btn-sm <?php echo $semanaIdSel == $s['id'] ? 'btn-dark' : 'btn-outline-secondary'; ?>"
                       <?php echo !$s['activa'] ? 'style="opacity:0.6"' : ''; ?>>
                        <?php echo htmlspecialchars($s['nombre']); ?>
                        <?php if (!$s['activa']): ?><i class="fas fa-pause fa-xs text-muted"></i><?php endif; ?>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

<?php if ($verTodas): ?>
<!-- ================================================================
     RESUMEN GENERAL — todas las semanas
     ================================================================ -->

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="fas fa-table"></i> Resumen por semana — Campamento <?php echo $year; ?></h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th rowspan="2" class="align-middle">Semana</th>
                            <th rowspan="2" class="align-middle">Fechas</th>
                            <th colspan="3" class="text-center border-start">Equipantes</th>
                            <th colspan="3" class="text-center border-start">Alumnos</th>
                            <th colspan="3" class="text-center border-start">Consejeros</th>
                            <th class="text-center border-start">Mis.</th>
                            <th class="text-center">Inv.</th>
                            <th class="text-center">Coc.</th>
                            <th class="text-center border-start">Acamp.</th>
                            <th class="text-center border-start text-warning fw-bold">TOTAL</th>
                        </tr>
                        <tr>
                            <th class="text-center border-start small text-info">♂</th>
                            <th class="text-center small text-danger">♀</th>
                            <th class="text-center small">∑</th>
                            <th class="text-center border-start small text-info">♂</th>
                            <th class="text-center small text-danger">♀</th>
                            <th class="text-center small">∑</th>
                            <th class="text-center border-start small text-info">♂</th>
                            <th class="text-center small text-danger">♀</th>
                            <th class="text-center small">∑</th>
                            <th class="text-center border-start small">∑</th>
                            <th class="text-center small">∑</th>
                            <th class="text-center small">∑</th>
                            <th class="text-center border-start small">∑</th>
                            <th class="text-center border-start small">∑</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($totalesPorSemana)): ?>
                        <tr><td colspan="16" class="text-center text-muted py-4">Sin datos</td></tr>
                    <?php else:
                        $gt = [
                            'equipantes_m' => 0, 'equipantes_f' => 0, 'equipantes' => 0,
                            'alumnos_m'    => 0, 'alumnos_f'    => 0, 'alumnos'    => 0,
                            'consejeros_m' => 0, 'consejeros_f' => 0, 'consejeros' => 0,
                            'misioneros'   => 0, 'invitados'    => 0, 'cocina'     => 0,
                            'acampantes'   => 0, 'total_general'=> 0,
                        ];
                        foreach ($totalesPorSemana as $id => $t):
                            foreach (array_keys($gt) as $k) {
                                $gt[$k] += ($t[$k] ?? 0);
                            }
                    ?>
                        <tr>
                            <td>
                                <a href="?semana_id=<?php echo $id; ?>" class="text-decoration-none fw-bold">
                                    <?php echo htmlspecialchars($t['nombre']); ?>
                                </a>
                                <?php if (!$t['activa']): ?><i class="fas fa-pause fa-xs text-muted"></i><?php endif; ?>
                            </td>
                            <td><small class="text-muted">
                                <?php echo date('d/m', strtotime($t['fecha_inicio'])); ?> –
                                <?php echo date('d/m/Y', strtotime($t['fecha_fin'])); ?>
                            </small></td>
                            <td class="text-center border-start text-primary"><?php echo $t['equipantes_m']; ?></td>
                            <td class="text-center text-danger"><?php echo $t['equipantes_f']; ?></td>
                            <td class="text-center fw-bold"><?php echo $t['equipantes']; ?></td>
                            <td class="text-center border-start text-primary"><?php echo $t['alumnos_m']; ?></td>
                            <td class="text-center text-danger"><?php echo $t['alumnos_f']; ?></td>
                            <td class="text-center fw-bold"><?php echo $t['alumnos']; ?></td>
                            <td class="text-center border-start text-primary"><?php echo $t['consejeros_m']; ?></td>
                            <td class="text-center text-danger"><?php echo $t['consejeros_f']; ?></td>
                            <td class="text-center fw-bold"><?php echo $t['consejeros']; ?></td>
                            <td class="text-center border-start"><?php echo $t['misioneros']; ?></td>
                            <td class="text-center"><?php echo $t['invitados']; ?></td>
                            <td class="text-center"><?php echo $t['cocina']; ?></td>
                            <td class="text-center border-start"><?php echo $t['acampantes']; ?></td>
                            <td class="text-center border-start">
                                <span class="badge bg-primary"><?php echo $t['total_general']; ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                        <tr class="table-secondary fw-bold">
                            <td colspan="2">TOTAL TEMPORADA</td>
                            <td class="text-center border-start text-primary"><?php echo $gt['equipantes_m']; ?></td>
                            <td class="text-center text-danger"><?php echo $gt['equipantes_f']; ?></td>
                            <td class="text-center"><?php echo $gt['equipantes']; ?></td>
                            <td class="text-center border-start text-primary"><?php echo $gt['alumnos_m']; ?></td>
                            <td class="text-center text-danger"><?php echo $gt['alumnos_f']; ?></td>
                            <td class="text-center"><?php echo $gt['alumnos']; ?></td>
                            <td class="text-center border-start text-primary"><?php echo $gt['consejeros_m']; ?></td>
                            <td class="text-center text-danger"><?php echo $gt['consejeros_f']; ?></td>
                            <td class="text-center"><?php echo $gt['consejeros']; ?></td>
                            <td class="text-center border-start"><?php echo $gt['misioneros']; ?></td>
                            <td class="text-center"><?php echo $gt['invitados']; ?></td>
                            <td class="text-center"><?php echo $gt['cocina']; ?></td>
                            <td class="text-center border-start"><?php echo $gt['acampantes']; ?></td>
                            <td class="text-center border-start">
                                <span class="badge bg-dark"><?php echo $gt['total_general']; ?></span>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Género por semana + Info cocina -->
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-venus-mars"></i> Distribución por género (por semana)</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Semana</th>
                                <th class="text-center text-primary">♂</th>
                                <th class="text-center text-danger">♀</th>
                                <th class="text-center">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($totalesPorSemana as $id => $t): ?>
                            <tr>
                                <td><a href="?semana_id=<?php echo $id; ?>" class="text-decoration-none"><?php echo htmlspecialchars($t['nombre']); ?></a></td>
                                <td class="text-center text-primary fw-bold"><?php echo $t['hombres']; ?></td>
                                <td class="text-center text-danger fw-bold"><?php echo $t['mujeres']; ?></td>
                                <td class="text-center fw-bold"><?php echo $t['hombres'] + $t['mujeres']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card border-success shadow-sm">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="fas fa-utensils"></i> Información para cocina (por semana)</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Semana</th>
                                <th class="text-center">Equipo</th>
                                <th class="text-center">Acampantes</th>
                                <th class="text-center text-success fw-bold">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($totalesPorSemana as $id => $t): ?>
                            <tr>
                                <td><a href="?semana_id=<?php echo $id; ?>" class="text-decoration-none"><?php echo htmlspecialchars($t['nombre']); ?></a></td>
                                <td class="text-center"><?php echo $t['total_equipo']; ?></td>
                                <td class="text-center"><?php echo $t['acampantes']; ?></td>
                                <td class="text-center"><span class="badge bg-success"><?php echo $t['total_general']; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="card-footer bg-light small text-muted">
                        <i class="fas fa-info-circle"></i> Total de personas presentes por semana.
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
<!-- ================================================================
     DETALLE DE UNA SEMANA
     ================================================================ -->

    <?php
    $nombreSemana = '';
    foreach ($semanas as $s) {
        if ((int)$s['id'] === $semanaIdSel) { $nombreSemana = $s['nombre']; break; }
    }
    $t = $totalesDetalle;
    ?>

    <!-- Tarjetas de totales por tipo -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-start border-primary border-4 shadow-sm h-100">
                <div class="card-body py-2 text-center">
                    <div class="fs-4 fw-bold text-primary"><?php echo $t['equipantes']; ?></div>
                    <small class="text-muted d-block">Equipantes</small>
                    <small class="text-primary">♂ <?php echo $t['equipantes_m']; ?></small>
                    <small class="text-danger ms-1">♀ <?php echo $t['equipantes_f']; ?></small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-start border-info border-4 shadow-sm h-100">
                <div class="card-body py-2 text-center">
                    <div class="fs-4 fw-bold text-info"><?php echo $t['alumnos']; ?></div>
                    <small class="text-muted d-block">Alumnos</small>
                    <small class="text-primary">♂ <?php echo $t['alumnos_m']; ?></small>
                    <small class="text-danger ms-1">♀ <?php echo $t['alumnos_f']; ?></small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-start border-warning border-4 shadow-sm h-100">
                <div class="card-body py-2 text-center">
                    <div class="fs-4 fw-bold text-warning"><?php echo $t['consejeros']; ?></div>
                    <small class="text-muted d-block">Consejeros</small>
                    <small class="text-primary">♂ <?php echo $t['consejeros_m']; ?></small>
                    <small class="text-danger ms-1">♀ <?php echo $t['consejeros_f']; ?></small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-start border-secondary border-4 shadow-sm h-100">
                <div class="card-body py-2 text-center">
                    <div class="fs-4 fw-bold text-secondary"><?php echo $t['misioneros']; ?></div>
                    <small class="text-muted d-block">Misioneros</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-start border-secondary border-4 shadow-sm h-100">
                <div class="card-body py-2 text-center">
                    <div class="fs-4 fw-bold text-secondary"><?php echo $t['invitados']; ?></div>
                    <small class="text-muted d-block">Invitados</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-start border-danger border-4 shadow-sm h-100">
                <div class="card-body py-2 text-center">
                    <div class="fs-4 fw-bold text-danger"><?php echo $t['cocina']; ?></div>
                    <small class="text-muted d-block">Cocina</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Segunda fila: acampantes + totales -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-start border-success border-4 shadow-sm h-100">
                <div class="card-body py-2 text-center">
                    <div class="fs-4 fw-bold text-success"><?php echo $t['acampantes']; ?></div>
                    <small class="text-muted">Acampantes</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-start border-dark border-4 shadow-sm h-100">
                <div class="card-body py-2 text-center">
                    <div class="fs-4 fw-bold"><?php echo $t['total_equipo']; ?></div>
                    <small class="text-muted">Total equipo</small>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card bg-dark text-white shadow-sm h-100">
                <div class="card-body py-2 text-center">
                    <div class="fs-2 fw-bold"><?php echo $t['total_general']; ?> <i class="fas fa-users"></i></div>
                    <small class="opacity-75">TOTAL GENERAL — <?php echo htmlspecialchars($nombreSemana); ?></small>
                </div>
            </div>
        </div>
    </div>

    <?php
    $famTotal = $t['familias_total'] ?? ['menores_12' => 0, 'mayores_12' => 0, 'mayores_18' => 0];
    $hayFamilias = ($famTotal['menores_12'] + $famTotal['mayores_12'] + $famTotal['mayores_18']) > 0;
    if ($hayFamilias):
    ?>
    <div class="card border-warning shadow-sm mb-3">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="fas fa-home text-warning"></i> Desglose de familias (para cocina)</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tipo</th>
                        <th class="text-center">&lt; 12 años</th>
                        <th class="text-center">12–18 años</th>
                        <th class="text-center">&gt; 18 años</th>
                        <th class="text-center fw-bold">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $tiposDesglose = ['alumnos' => 'Alumnos', 'misioneros' => 'Misioneros', 'invitados' => 'Invitados', 'cocina' => 'Cocina'];
                    foreach ($tiposDesglose as $key => $label):
                        $d = $t['desglose'][$key] ?? ['menores_12' => 0, 'mayores_12' => 0, 'mayores_18' => 0];
                        $totalFam = $d['menores_12'] + $d['mayores_12'] + $d['mayores_18'];
                        if ($totalFam > 0):
                    ?>
                    <tr>
                        <td><?php echo $label; ?></td>
                        <td class="text-center"><?php echo $d['menores_12']; ?></td>
                        <td class="text-center"><?php echo $d['mayores_12']; ?></td>
                        <td class="text-center"><?php echo $d['mayores_18']; ?></td>
                        <td class="text-center fw-bold"><?php echo $totalFam; ?></td>
                    </tr>
                    <?php endif; endforeach; ?>
                    <tr class="table-light fw-bold">
                        <td>TOTAL FAMILIAS</td>
                        <td class="text-center"><?php echo $famTotal['menores_12']; ?></td>
                        <td class="text-center"><?php echo $famTotal['mayores_12']; ?></td>
                        <td class="text-center"><?php echo $famTotal['mayores_18']; ?></td>
                        <td class="text-center"><?php echo array_sum($famTotal); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Género + Áreas -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-venus-mars"></i> Distribución por género</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="fs-2 text-primary">♂</div>
                            <div class="fs-4 fw-bold text-primary"><?php echo $t['hombres']; ?></div>
                            <small>Hombres</small>
                        </div>
                        <div class="col-4">
                            <div class="fs-2 text-danger">♀</div>
                            <div class="fs-4 fw-bold text-danger"><?php echo $t['mujeres']; ?></div>
                            <small>Mujeres</small>
                        </div>
                        <div class="col-4">
                            <div class="fs-2 text-secondary">∑</div>
                            <div class="fs-4 fw-bold"><?php echo $t['hombres'] + $t['mujeres']; ?></div>
                            <small>Total</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-clipboard-list"></i> Equipantes y Alumnos por área</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Área</th><th class="text-end">Personas</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($t['detalle_areas'])): ?>
                            <tr><td colspan="2" class="text-muted text-center py-2">Sin áreas asignadas</td></tr>
                        <?php else: foreach ($t['detalle_areas'] as $area => $cant): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($area); ?></td>
                                <td class="text-end fw-bold"><?php echo $cant; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla detallada de personas -->
    <div class="card shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0">
                <i class="fas fa-users"></i> Personas en <?php echo htmlspecialchars($nombreSemana); ?>
                <span class="badge bg-primary ms-1"><?php echo count($distribucionDetallada) + $t['acampantes']; ?></span>
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Nombre</th>
                            <th>Sexo</th>
                            <th>Edad</th>
                            <th>Tipo</th>
                            <th>Estado</th>
                            <th>Área</th>
                            <th>Iglesia</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($distribucionDetallada) && $t['acampantes'] == 0): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                No hay personas registradas en esta semana.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($distribucionDetallada as $d): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($d['nombre']); ?></strong></td>
                            <td>
                                <?php if ($d['sexo']): ?>
                                <span class="badge bg-<?php echo $d['sexo']==='masculino'?'primary':'danger'; ?>">
                                    <?php echo $d['sexo']==='masculino'?'♂':'♀'; ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)$d['edad'] ?: '—'; ?></td>
                            <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($d['tipo_persona']); ?></span></td>
                            <td>
                                <?php
                                $badgeEstado = ['aceptado' => 'success', 'consejero' => 'warning', 'en espera' => 'secondary', 'rechazado' => 'danger'];
                                $colorEstado = $badgeEstado[$d['estado']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?php echo $colorEstado; ?>"><?php echo htmlspecialchars($d['estado']); ?></span>
                            </td>
                            <td>
                                <?php if ($d['area_nombre']): ?>
                                    <span class="badge bg-info text-dark"><?php echo htmlspecialchars($d['area_nombre']); ?></span>
                                <?php else: ?>
                                    <small class="text-muted">Sin área</small>
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo htmlspecialchars($d['iglesia'] ?: '—'); ?></small></td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if ($t['acampantes'] > 0): ?>
                        <tr class="table-light">
                            <td colspan="7" class="text-center">
                                <i class="fas fa-users"></i>
                                <strong><?php echo $t['acampantes']; ?> acampantes</strong> adicionales en esta semana
                                <a href="../encargado_consejeros/acampantes.php?semana_id=<?php echo $semanaIdSel; ?>"
                                   class="btn btn-outline-primary btn-sm ms-2">
                                    <i class="fas fa-eye"></i> Ver acampantes
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3 d-flex gap-2">
        <a href="?semana_id=0" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-table"></i> Ver resumen general
        </a>
        <a href="distribucion.php?semana_id=<?php echo $semanaIdSel; ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-clipboard-list"></i> Ir a distribución
        </a>
    </div>

<?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>