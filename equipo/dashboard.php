<?php
// equipo/dashboard.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
verificarMantenimiento($pdo);

if (!esEquipoOAdmin()) {
    header('Location: ../default.php');
    exit();
}

$yearActual = obtenerAnioCampamento();

// Inicializar variables
$totales        = ['en espera' => 0, 'aceptado' => 0, 'rechazado' => 0, 'consejero' => 0];
$totalEquipantes = 0;
$llamadasPendientes = 0;
$pagosPendientes    = 0;
$semanas            = [];
$tablaPersonas      = [];
$error              = '';

// Semana seleccionada (0 = General)
$semanaSeleccionada = (int)($_GET['semana_id'] ?? 0);

try {
    // ── Semanas del año para el selector ─────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT id, nombre
        FROM semanas_campamento
        WHERE year_campamento = ?
        ORDER BY fecha_inicio
    ");
    $stmt->execute([$yearActual]);
    $semanas = $stmt->fetchAll();

    // Validar que la semana seleccionada pertenece al año actual
    $idsSemanasValidas = array_column($semanas, 'id');
    if ($semanaSeleccionada !== 0 && !in_array($semanaSeleccionada, $idsSemanasValidas)) {
        $semanaSeleccionada = 0;
    }

    // ── Totales de reclutamiento (tarjetas superiores, siempre General) ──────
    $stmt = $pdo->prepare("
        SELECT estado, COUNT(*) as total
        FROM equipantes
        WHERE year_campamento = ? AND activo = 1 AND tipo_persona = 'equipante'
        GROUP BY estado
    ");
    $stmt->execute([$yearActual]);
    foreach ($stmt->fetchAll() as $row) {
        $totales[$row['estado']] = (int)$row['total'];
    }
    $totalEquipantes = array_sum($totales);

    // ── Llamadas pendientes ───────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM equipantes
        WHERE year_campamento = ? AND activo = 1 AND tipo_persona = 'equipante'
          AND estado = 'en espera' AND llamada_realizada = 0
    ");
    $stmt->execute([$yearActual]);
    $llamadasPendientes = (int)$stmt->fetchColumn();

    // ── Pagos pendientes ──────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM pagos_equipante pe
        INNER JOIN equipantes e ON pe.equipante_id = e.id
        WHERE e.year_campamento = ? AND pe.estado_pago != 'pagado'
    ");
    $stmt->execute([$yearActual]);
    $pagosPendientes = (int)$stmt->fetchColumn();

    // ── Tabla de personas por tipo ────────────────────────────────────────────
    // Los consejeros (estado = 'consejero') se separan de su tipo_persona.
    // Tipos con desglose M/F: equipante, alumno, consejero
    // Tipos con solo total:   misionero, invitado, cocina

    if ($semanaSeleccionada === 0) {
        // GENERAL: todos los registros del año
        $stmt = $pdo->prepare("
            SELECT
                tipo_persona,
                estado,
                sexo,
                COUNT(*) AS total
            FROM equipantes
            WHERE year_campamento = ? AND activo = 1
            GROUP BY tipo_persona, estado, sexo
        ");
        $stmt->execute([$yearActual]);
    } else {
        // POR SEMANA: filtrar por distribucion_equipantes
        $stmt = $pdo->prepare("
            SELECT
                e.tipo_persona,
                e.estado,
                e.sexo,
                COUNT(*) AS total
            FROM equipantes e
            INNER JOIN distribucion_equipantes de ON de.equipante_id = e.id
            WHERE e.year_campamento = ? AND e.activo = 1
              AND de.semana_id = ?
            GROUP BY e.tipo_persona, e.estado, e.sexo
        ");
        $stmt->execute([$yearActual, $semanaSeleccionada]);
    }

    // Procesar resultados en una estructura manejable
    // $raw[tipo_persona][estado][sexo] = total
    $raw = [];
    foreach ($stmt->fetchAll() as $row) {
        $tipo   = $row['tipo_persona'];
        $estado = $row['estado'];
        $sexo   = $row['sexo'] ?? 'sin_dato';
        $total  = (int)$row['total'];
        $raw[$tipo][$estado][$sexo] = ($raw[$tipo][$estado][$sexo] ?? 0) + $total;
    }

    // Helper: suma por tipo+estado+sexo
    $suma = function(string $tipo, ?string $estado, ?string $sexo) use ($raw): int {
        if (!isset($raw[$tipo])) return 0;
        $estados = ($estado !== null) ? [$estado] : array_keys($raw[$tipo]);
        $total = 0;
        foreach ($estados as $e) {
            if (!isset($raw[$tipo][$e])) continue;
            if ($sexo !== null) {
                $total += $raw[$tipo][$e][$sexo] ?? 0;
            } else {
                $total += array_sum($raw[$tipo][$e]);
            }
        }
        return $total;
    };

    // Construir tabla:
    // Consejeros = estado='consejero' de cualquier tipo_persona
    $consejeroM = 0;
    $consejeroF = 0;
    foreach (['equipante', 'alumno', 'misionero', 'invitado', 'cocina'] as $t) {
        $consejeroM += $raw[$t]['consejero']['masculino'] ?? 0;
        $consejeroF += $raw[$t]['consejero']['femenino']  ?? 0;
    }
    $consejeroTotal = $consejeroM + $consejeroF;

    // Equipantes activos = tipo_persona=equipante, estado != consejero
    $estadosActivos = ['en espera', 'aceptado', 'rechazado'];
    $equipM = 0; $equipF = 0;
    foreach ($estadosActivos as $e) {
        $equipM += $raw['equipante'][$e]['masculino'] ?? 0;
        $equipF += $raw['equipante'][$e]['femenino']  ?? 0;
    }
    $equipTotal = $equipM + $equipF;

    // Alumnos activos = tipo_persona=alumno, estado != consejero
    $alumM = 0; $alumF = 0;
    foreach ($estadosActivos as $e) {
        $alumM += $raw['alumno'][$e]['masculino'] ?? 0;
        $alumF += $raw['alumno'][$e]['femenino']  ?? 0;
    }
    $alumTotal = $alumM + $alumF;

    // Tipos sin desglose
    $misioneroTotal = 0;
    $invitadoTotal  = 0;
    $cocinaTotal    = 0;
    foreach (['en espera', 'aceptado', 'rechazado', 'consejero'] as $e) {
        $misioneroTotal += array_sum($raw['misionero'][$e] ?? []);
        $invitadoTotal  += array_sum($raw['invitado'][$e]  ?? []);
        $cocinaTotal    += array_sum($raw['cocina'][$e]    ?? []);
    }

    $granTotal = $equipTotal + $alumTotal + $misioneroTotal + $invitadoTotal + $cocinaTotal + $consejeroTotal;

    $tablaPersonas = [
        'equipante'  => ['m' => $equipM,     'f' => $equipF,     'total' => $equipTotal],
        'alumno'     => ['m' => $alumM,       'f' => $alumF,      'total' => $alumTotal],
        'consejero'  => ['m' => $consejeroM,  'f' => $consejeroF, 'total' => $consejeroTotal],
        'misionero'  => ['total' => $misioneroTotal],
        'invitado'   => ['total' => $invitadoTotal],
        'cocina'     => ['total' => $cocinaTotal],
        'gran_total' => $granTotal,
    ];

} catch (Exception $e) {
    $error = "Error al cargar estadísticas: " . $e->getMessage();
}

include '../includes/header.php';
?>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0"><i class="fas fa-users-cog text-primary"></i> Panel de Equipo</h1>
            <p class="text-muted mb-0">Campamento <?php echo $yearActual; ?></p>
        </div>
        <a href="reclutamiento.php?tipo_filtro=equipante" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> Nuevo Equipante
        </a>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- Tarjetas de estado de reclutamiento -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-start border-warning border-4 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">En espera</div>
                            <div class="fs-3 fw-bold text-warning"><?php echo $totales['en espera']; ?></div>
                        </div>
                        <i class="fas fa-clock fa-2x text-warning opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-start border-success border-4 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Aceptados</div>
                            <div class="fs-3 fw-bold text-success"><?php echo $totales['aceptado']; ?></div>
                        </div>
                        <i class="fas fa-check-circle fa-2x text-success opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-start border-danger border-4 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Rechazados</div>
                            <div class="fs-3 fw-bold text-danger"><?php echo $totales['rechazado']; ?></div>
                        </div>
                        <i class="fas fa-times-circle fa-2x text-danger opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-start border-info border-4 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Consejeros</div>
                            <div class="fs-3 fw-bold text-info"><?php echo $totales['consejero']; ?></div>
                        </div>
                        <i class="fas fa-praying-hands fa-2x text-info opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Acciones pendientes -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <a href="reclutamiento.php?llamada_filtro=pendientes" class="text-decoration-none">
                <div class="card shadow-sm h-100">
                    <div class="card-body text-center py-3">
                        <i class="fas fa-phone-alt fa-2x text-warning mb-2"></i>
                        <div class="fs-4 fw-bold"><?php echo $llamadasPendientes; ?></div>
                        <small class="text-muted">Llamadas pendientes</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4">
            <a href="distribucion.php" class="text-decoration-none">
                <div class="card shadow-sm h-100">
                    <div class="card-body text-center py-3">
                        <i class="fas fa-clipboard-list fa-2x text-primary mb-2"></i>
                        <div class="fs-4 fw-bold"><?php echo $totales['aceptado']; ?></div>
                        <small class="text-muted">Por distribuir</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4">
            <a href="pagos.php" class="text-decoration-none">
                <div class="card shadow-sm h-100">
                    <div class="card-body text-center py-3">
                        <i class="fas fa-hand-holding-usd fa-2x text-danger mb-2"></i>
                        <div class="fs-4 fw-bold"><?php echo $pagosPendientes; ?></div>
                        <small class="text-muted">Pagos pendientes</small>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Tabla de personas + Módulos -->
    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0"><i class="fas fa-chart-pie"></i> Personas registradas por tipo</h6>
                    <!-- Selector de semana -->
                    <form method="GET" class="d-flex align-items-center gap-2 mb-0" id="formSemana">
                        <select name="semana_id" class="form-select form-select-sm"
                                style="min-width:160px; max-width:220px;"
                                onchange="this.form.submit()">
                            <option value="0" <?php echo $semanaSeleccionada === 0 ? 'selected' : ''; ?>>
                                General (todas)
                            </option>
                            <?php foreach ($semanas as $s): ?>
                            <option value="<?php echo $s['id']; ?>"
                                <?php echo $semanaSeleccionada === (int)$s['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['nombre']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <div class="card-body p-0">
                    <?php if (!empty($error)): ?>
                        <p class="text-muted small p-3 mb-0">Sin datos disponibles.</p>
                    <?php else: ?>
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Tipo</th>
                                <th class="text-center" style="width:60px;">
                                    <i class="fas fa-mars text-primary" title="Masculino"></i>
                                </th>
                                <th class="text-center" style="width:60px;">
                                    <i class="fas fa-venus text-danger" title="Femenino"></i>
                                </th>
                                <th class="text-end" style="width:60px;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Equipantes (sin consejeros) -->
                            <tr>
                                <td>
                                    <i class="fas fa-user-tie text-primary fa-fw"></i>
                                    Equipantes
                                </td>
                                <td class="text-center"><?php echo $tablaPersonas['equipante']['m']; ?></td>
                                <td class="text-center"><?php echo $tablaPersonas['equipante']['f']; ?></td>
                                <td class="text-end fw-bold"><?php echo $tablaPersonas['equipante']['total']; ?></td>
                            </tr>
                            <!-- Alumnos (sin consejeros) -->
                            <tr>
                                <td>
                                    <i class="fas fa-graduation-cap text-success fa-fw"></i>
                                    Alumnos
                                </td>
                                <td class="text-center"><?php echo $tablaPersonas['alumno']['m']; ?></td>
                                <td class="text-center"><?php echo $tablaPersonas['alumno']['f']; ?></td>
                                <td class="text-end fw-bold"><?php echo $tablaPersonas['alumno']['total']; ?></td>
                            </tr>
                            <!-- Consejeros (extraídos de equipante/alumno con estado=consejero) -->
                            <tr class="table-warning">
                                <td>
                                    <i class="fas fa-praying-hands text-warning fa-fw"></i>
                                    Consejeros
                                </td>
                                <td class="text-center"><?php echo $tablaPersonas['consejero']['m']; ?></td>
                                <td class="text-center"><?php echo $tablaPersonas['consejero']['f']; ?></td>
                                <td class="text-end fw-bold"><?php echo $tablaPersonas['consejero']['total']; ?></td>
                            </tr>
                            <!-- Misioneros -->
                            <tr>
                                <td>
                                    <i class="fas fa-globe text-info fa-fw"></i>
                                    Misioneros
                                </td>
                                <td class="text-center text-muted">—</td>
                                <td class="text-center text-muted">—</td>
                                <td class="text-end fw-bold"><?php echo $tablaPersonas['misionero']['total']; ?></td>
                            </tr>
                            <!-- Invitados -->
                            <tr>
                                <td>
                                    <i class="fas fa-star text-secondary fa-fw"></i>
                                    Invitados
                                </td>
                                <td class="text-center text-muted">—</td>
                                <td class="text-center text-muted">—</td>
                                <td class="text-end fw-bold"><?php echo $tablaPersonas['invitado']['total']; ?></td>
                            </tr>
                            <!-- Cocina -->
                            <tr>
                                <td>
                                    <i class="fas fa-utensils text-danger fa-fw"></i>
                                    Cocina
                                </td>
                                <td class="text-center text-muted">—</td>
                                <td class="text-center text-muted">—</td>
                                <td class="text-end fw-bold"><?php echo $tablaPersonas['cocina']['total']; ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="table-dark">
                                <td><strong>Total general</strong></td>
                                <td colspan="2"></td>
                                <td class="text-end"><strong><?php echo $tablaPersonas['gran_total']; ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                    <?php endif; ?>
                </div>

                <?php if ($semanaSeleccionada !== 0): ?>
                <div class="card-footer bg-light small text-muted">
                    <i class="fas fa-info-circle"></i>
                    Mostrando personas asignadas a esta semana en distribución.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Accesos rápidos -->
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-th-large"></i> Módulos</h6>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-6">
                            <a href="reclutamiento.php?tipo_filtro=equipante" class="btn btn-outline-primary w-100 text-start">
                                <i class="fas fa-user-plus"></i> Reclutamiento
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="hoja_trabajo.php" class="btn btn-outline-primary w-100 text-start">
                                <i class="fas fa-table"></i> Hoja de trabajo
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="distribucion.php" class="btn btn-outline-primary w-100 text-start">
                                <i class="fas fa-clipboard-list"></i> Distribución
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="pagos.php" class="btn btn-outline-primary w-100 text-start">
                                <i class="fas fa-hand-holding-usd"></i> Pagos
                            </a>
                        </div>
                        <div class="col-12">
                            <a href="totales_semanales.php" class="btn btn-outline-primary w-100 text-start">
                                <i class="fas fa-calculator"></i> Totales semanales
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>