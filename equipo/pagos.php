<?php
// equipo/pagos.php
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
// PROCESAR PAGO (registrar o actualizar)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Token de seguridad inválido.';
    } else {
        $accion = $_POST['accion'] ?? '';
        $userId = $_SESSION['user_id'] ?? 0;

        try {
            // --- Guardar/actualizar registro de pago ---
            if ($accion === 'guardar') {
                $equipanteId     = (int)($_POST['equipante_id'] ?? 0);
                $semanasInscritas = (int)($_POST['semanas_inscritas'] ?? 1);
                $montoTotal      = (float)($_POST['monto_total'] ?? 0);
                $montoPagado     = (float)($_POST['monto_pagado'] ?? 0);
                $estadoPago      = $_POST['estado_pago'] ?? 'pendiente';
                $modoPago        = !empty($_POST['modo_pago']) ? $_POST['modo_pago'] : null;
                $fechaPago       = !empty($_POST['fecha_pago']) ? $_POST['fecha_pago'] : null;
                $notas           = trim($_POST['notas'] ?? '');

                if (!in_array($estadoPago, ['pendiente','pagado','pago_parcial'], true)) {
                    $estadoPago = 'pendiente';
                }

                if ($equipanteId <= 0) {
                    throw new Exception('Falta el equipante.');
                }

                // Verificar si ya existe un registro de pago para este equipante
                $stmtCheck = $pdo->prepare("SELECT id FROM pagos_equipante WHERE equipante_id = ? LIMIT 1");
                $stmtCheck->execute([$equipanteId]);
                $pagoExistente = $stmtCheck->fetchColumn();

                if ($pagoExistente) {
                    // Actualizar
                    $stmt = $pdo->prepare("
                        UPDATE pagos_equipante SET
                            semanas_inscritas = ?, monto_total = ?, monto_pagado = ?,
                            estado_pago = ?, modo_pago = ?, fecha_pago = ?, notas = ?,
                            registrado_por = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $semanasInscritas, $montoTotal, $montoPagado,
                        $estadoPago, $modoPago, $fechaPago, $notas,
                        $userId, $pagoExistente
                    ]);
                    $mensaje = 'Pago actualizado correctamente.';
                } else {
                    // Insertar nuevo
                    $stmt = $pdo->prepare("
                        INSERT INTO pagos_equipante (
                            equipante_id, semanas_inscritas, monto_total, monto_pagado,
                            estado_pago, modo_pago, fecha_pago, notas, registrado_por
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $equipanteId, $semanasInscritas, $montoTotal, $montoPagado,
                        $estadoPago, $modoPago, $fechaPago, $notas, $userId
                    ]);
                    $mensaje = 'Pago registrado correctamente.';
                }
            }

            // --- Eliminar registro de pago ---
            if ($accion === 'eliminar') {
                $pagoId = (int)($_POST['pago_id'] ?? 0);
                if ($pagoId <= 0) throw new Exception('ID de pago inválido.');
                $stmt = $pdo->prepare("DELETE FROM pagos_equipante WHERE id = ?");
                $stmt->execute([$pagoId]);
                $mensaje = 'Registro de pago eliminado.';
            }

        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }

        if (empty($error)) {
            $params = [];
            if (!empty($_POST['filtro_estado'])) $params['estado_filtro'] = $_POST['filtro_estado'];
            if (!empty($_POST['search']))       $params['search'] = $_POST['search'];
            $params['message'] = $mensaje;
            $redirect = 'pagos.php?' . http_build_query($params);
            header('Location: ' . $redirect);
            exit();
        }
    }
}

// ---------------------------------------------------------------------
// Cargar datos
// ---------------------------------------------------------------------

// Costos escalonados desde configuracion
$costos = ['1' => 0, '2' => 0, '3' => 0, '99' => 0];
try {
    $claves = ['equipo_costo_1_semana','equipo_costo_2_semanas','equipo_costo_3_semanas','equipo_costo_temporada'];
    $placeholders = implode(',', array_fill(0, count($claves), '?'));
    $stmt = $pdo->prepare("SELECT clave, valor FROM configuracion WHERE clave IN ($placeholders)");
    $stmt->execute($claves);
    foreach ($stmt->fetchAll() as $row) {
        if ($row['clave'] === 'equipo_costo_1_semana')   $costos['1'] = (float)$row['valor'];
        if ($row['clave'] === 'equipo_costo_2_semanas')  $costos['2'] = (float)$row['valor'];
        if ($row['clave'] === 'equipo_costo_3_semanas')  $costos['3'] = (float)$row['valor'];
        if ($row['clave'] === 'equipo_costo_temporada')  $costos['99'] = (float)$row['valor'];
    }
} catch (Exception $e) {}

// Equipantes aceptados/consejero con su info de pago
$filtroEstado = $_GET['estado_filtro'] ?? '';
$search       = trim($_GET['search'] ?? '');

$where  = ["e.year_campamento = ?", "e.activo = 1", "e.estado IN ('aceptado','consejero')", "e.tipo_persona = 'equipante'"];
$params = [$year];

if ($search !== '') {
    $where[] = "(e.nombre LIKE ? OR e.iglesia LIKE ?)";
    $term = "%$search%";
    $params[] = $term; $params[] = $term;
}

$equipantesPagos = [];
try {
    $sql = "SELECT e.id, e.nombre, e.sexo, e.iglesia, e.semanas_disponibles,
                   p.id AS pago_id, p.semanas_inscritas, p.monto_total,
                   p.monto_pagado, p.estado_pago, p.modo_pago, p.fecha_pago, p.notas
            FROM equipantes e
            LEFT JOIN pagos_equipante p ON p.equipante_id = e.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.nombre ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $equipantesPagos = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Error al cargar: ' . $e->getMessage();
}

// Calcular totales generales
$totalesPagos = ['pagados' => 0, 'parciales' => 0, 'pendientes' => 0, 'sin_registro' => 0, 'recaudado' => 0, 'esperado' => 0];
foreach ($equipantesPagos as $ep) {
    if (empty($ep['pago_id'])) {
        $totalesPagos['sin_registro']++;
    } elseif ($ep['estado_pago'] === 'pagado') {
        $totalesPagos['pagados']++;
        $totalesPagos['recaudado'] += (float)$ep['monto_pagado'];
    } elseif ($ep['estado_pago'] === 'pago_parcial') {
        $totalesPagos['parciales']++;
        $totalesPagos['recaudado'] += (float)$ep['monto_pagado'];
    } else {
        $totalesPagos['pendientes']++;
    }
    $totalesPagos['esperado'] += (float)($ep['monto_total'] ?? 0);
}

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
            <h1 class="h3 mb-0"><i class="fas fa-hand-holding-usd text-primary"></i> Pagos de Equipantes</h1>
            <small class="text-muted">Control de pagos por las semanas que sirve cada equipante.</small>
        </div>
        <a href="configurar_costos.php" class="btn btn-warning btn-sm">
            <i class="fas fa-dollar-sign"></i> Configurar Costos
        </a>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Dashboard
        </a>
    </div>

    <!-- Mini stats -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-success"><?php echo $totalesPagos['pagados']; ?></div>
                    <small class="text-muted">Pagados</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-warning"><?php echo $totalesPagos['parciales']; ?></div>
                    <small class="text-muted">Pago parcial</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-danger"><?php echo $totalesPagos['pendientes'] + $totalesPagos['sin_registro']; ?></div>
                    <small class="text-muted">Pendientes / sin registro</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-primary">$<?php echo number_format($totalesPagos['recaudado'], 2); ?></div>
                    <small class="text-muted">Recaudado</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-3 shadow-sm">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-md-6">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="🔍 Nombre o iglesia..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-6 col-md-3">
                    <select name="estado_filtro" class="form-select form-select-sm">
                        <option value="">Todos los pagos</option>
                        <option value="pagado" <?php echo $filtroEstado==='pagado'?'selected':''; ?>>✅ Pagados</option>
                        <option value="pago_parcial" <?php echo $filtroEstado==='pago_parcial'?'selected':''; ?>>⚠️ Pago parcial</option>
                        <option value="pendiente" <?php echo $filtroEstado==='pendiente'?'selected':''; ?>>❌ Pendientes</option>
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search"></i></button>
                </div>
                <div class="col-6 col-md-2">
                    <a href="pagos.php" class="btn btn-outline-secondary btn-sm w-100"><i class="fas fa-times"></i> Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla de pagos -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Nombre</th>
                            <th>Semanas</th>
                            <th>Monto total</th>
                            <th>Pagado</th>
                            <th>Saldo</th>
                            <th>Estado</th>
                            <th>Modo pago</th>
                            <th>Fecha</th>
                            <th class="text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($equipantesPagos)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                No hay equipantes aceptados para registrar pagos.
                            </td>
                        </tr>
                    <?php else: foreach ($equipantesPagos as $ep):
                        // Filtrar por estado de pago si hay filtro
                        if ($filtroEstado !== '') {
                            if ($filtroEstado === 'pendiente' && !empty($ep['pago_id']) && $ep['estado_pago'] !== 'pendiente') continue;
                            if ($filtroEstado !== 'pendiente' && $ep['estado_pago'] !== $filtroEstado) continue;
                        }

                        $badgeEstado = [
                            'pagado'       => 'bg-success',
                            'pago_parcial' => 'bg-warning text-dark',
                            'pendiente'    => 'bg-danger',
                        ][$ep['estado_pago'] ?? ''] ?? 'bg-secondary';

                        $saldo = (float)($ep['monto_total'] ?? 0) - (float)($ep['monto_pagado'] ?? 0);

                        // Contar semanas seleccionadas
                        $numSemanas = 0;
                        if (!empty($ep['semanas_disponibles'])) {
                            $numSemanas = count(array_filter(explode(',', $ep['semanas_disponibles'])));
                        }
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($ep['nombre']); ?></strong>
                                <?php if ($ep['sexo']): ?>
                                    <span class="text-muted ms-1"><?php echo $ep['sexo']==='masculino'?'♂':'♀'; ?></span>
                                <?php endif; ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($ep['iglesia'] ?: '-'); ?></small>
                            </td>
                            <td class="text-center">
                                <?php
                                $labelsSem = [1 => '1 sem', 2 => '2 sem', 3 => '3 sem', 99 => 'Temporada'];
                                if (!empty($ep['pago_id'])):
                                    $sem = (int)$ep['semanas_inscritas'];
                                    if ($sem > 3) $sem = 99;
                                ?>
                                    <span class="badge bg-light text-dark"><?php echo $labelsSem[$sem] ?? $sem.' sem'; ?></span>
                                <?php else: ?>
                                    <small class="text-muted"><?php echo $numSemanas ?: '?'; ?> sem</small>
                                <?php endif; ?>
                            </td>
                            <td>$<?php echo number_format((float)($ep['monto_total'] ?? 0), 2); ?></td>
                            <td>$<?php echo number_format((float)($ep['monto_pagado'] ?? 0), 2); ?></td>
                            <td>
                                <?php if ($saldo > 0): ?>
                                    <span class="text-danger fw-bold">$<?php echo number_format($saldo, 2); ?></span>
                                <?php else: ?>
                                    <span class="text-success">$0.00</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($ep['pago_id'])): ?>
                                    <span class="badge bg-secondary">Sin registro</span>
                                <?php else: ?>
                                    <span class="badge <?php echo $badgeEstado; ?>"><?php echo htmlspecialchars($ep['estado_pago']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo htmlspecialchars($ep['modo_pago'] ?: '-'); ?></small></td>
                            <td><small class="text-muted"><?php echo $ep['fecha_pago'] ? date('d/m/Y', strtotime($ep['fecha_pago'])) : '-'; ?></small></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-primary btn-sm"
                                        data-bs-toggle="modal" data-bs-target="#modalPago"
                                        data-equipante-id="<?php echo $ep['id']; ?>"
                                        data-equipante-nombre="<?php echo htmlspecialchars($ep['nombre']); ?>"
                                        data-pago-id="<?php echo $ep['pago_id'] ?? ''; ?>"
                                        data-semanas="<?php echo $ep['semanas_inscritas'] ?? $numSemanas; ?>"
                                        data-monto-total="<?php echo $ep['monto_total'] ?? ''; ?>"
                                        data-monto-pagado="<?php echo $ep['monto_pagado'] ?? ''; ?>"
                                        data-estado="<?php echo $ep['estado_pago'] ?? 'pendiente'; ?>"
                                        data-modo="<?php echo $ep['modo_pago'] ?? ''; ?>"
                                        data-fecha="<?php echo $ep['fecha_pago'] ? date('Y-m-d', strtotime($ep['fecha_pago'])) : ''; ?>"
                                        data-notas="<?php echo htmlspecialchars($ep['notas'] ?? ''); ?>"
                                        data-costo-semana="<?php echo $costoSemana; ?>"
                                        data-num-semanas="<?php echo $numSemanas; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if (!empty($ep['pago_id'])): ?>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('¿Eliminar el registro de pago de <?php echo htmlspecialchars($ep['nombre']); ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="pago_id" value="<?php echo $ep['pago_id']; ?>">
                                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                                    <input type="hidden" name="filtro_estado" value="<?php echo htmlspecialchars($filtroEstado); ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
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

<!-- ===================== MODAL DE PAGO ===================== -->
<div class="modal fade" id="modalPago" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="equipante_id" id="mp_equipante_id">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                <input type="hidden" name="filtro_estado" value="<?php echo htmlspecialchars($filtroEstado); ?>">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-hand-holding-usd"></i> <span id="mp_titulo">Pago</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Tipo de inscripción</label>
                            <select name="semanas_inscritas" id="mp_semanas" class="form-select">
                                <option value="1">1 semana ($<?php echo number_format($costos['1'], 2); ?>)</option>
                                <option value="2">2 semanas ($<?php echo number_format($costos['2'], 2); ?>)</option>
                                <option value="3">3 semanas ($<?php echo number_format($costos['3'], 2); ?>)</option>
                                <option value="99">Temporada completa ($<?php echo number_format($costos['99'], 2); ?>)</option>
                            </select>
                            <small class="text-muted" id="mp_semanas_suger">Semanas del equipante: -</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Monto total <span class="text-muted">(editable)</span></label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" name="monto_total" id="mp_monto_total" class="form-control" step="0.01" min="0" value="0">
                            </div>
                            <small class="text-muted">Modifica si hay descuento individual</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Monto pagado</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" name="monto_pagado" id="mp_monto_pagado" class="form-control" step="0.01" min="0" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Estado del pago</label>
                            <select name="estado_pago" id="mp_estado" class="form-select">
                                <option value="pendiente">Pendiente</option>
                                <option value="pago_parcial">Pago parcial</option>
                                <option value="pagado">Pagado</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Modo de pago</label>
                            <select name="modo_pago" id="mp_modo" class="form-select">
                                <option value="">--</option>
                                <option value="efectivo">Efectivo</option>
                                <option value="banco">Banco</option>
                                <option value="transferencia">Transferencia</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Fecha de pago</label>
                            <input type="date" name="fecha_pago" id="mp_fecha" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Notas / descuentos</label>
                            <textarea name="notas" id="mp_notas" class="form-control" rows="2" placeholder="Ej: Descuento del 50% por..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Guardar pago</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var costosData = <?php echo json_encode($costos); ?>;

    var modal = document.getElementById('modalPago');
    modal.addEventListener('show.bs.modal', function(event) {
        var btn = event.relatedTarget;
        document.getElementById('mp_titulo').textContent = 'Pago de ' + btn.dataset.equipanteNombre;
        document.getElementById('mp_equipante_id').value = btn.dataset.equipanteId;

        var numSemanasEquip = parseInt(btn.dataset.numSemanas) || 0;
        document.getElementById('mp_semanas_suger').textContent = 'Semanas del equipante: ' + numSemanasEquip;

        var semanasGuardadas = btn.dataset.semanas;
        var selectSem = document.getElementById('mp_semanas');

        if (semanasGuardadas && semanasGuardadas !== '') {
            var val = String(semanasGuardadas);
            if (val === '99' || val === 'temporada') {
                selectSem.value = '99';
            } else if (val === '1' || val === '2' || val === '3') {
                selectSem.value = val;
            } else {
                // Si era un ID de semana viejo, sugerir por cantidad
                if (numSemanasEquip >= 4) selectSem.value = '99';
                else if (numSemanasEquip === 3) selectSem.value = '3';
                else if (numSemanasEquip === 2) selectSem.value = '2';
                else selectSem.value = '1';
            }
        } else {
            if (numSemanasEquip >= 4) selectSem.value = '99';
            else if (numSemanasEquip === 3) selectSem.value = '3';
            else if (numSemanasEquip === 2) selectSem.value = '2';
            else selectSem.value = '1';
        }

        var montoExistente = btn.dataset.montoTotal;
        if (montoExistente && parseFloat(montoExistente) > 0) {
            document.getElementById('mp_monto_total').value = montoExistente;
        } else {
            autollenarMonto();
        }

        document.getElementById('mp_monto_pagado').value = btn.dataset.montoPagado || '';
        document.getElementById('mp_estado').value = btn.dataset.estado || 'pendiente';
        document.getElementById('mp_modo').value = btn.dataset.modo || '';
        document.getElementById('mp_fecha').value = btn.dataset.fecha || '';
        document.getElementById('mp_notas').value = btn.dataset.notas || '';
    });

    function autollenarMonto() {
        var val = document.getElementById('mp_semanas').value;
        var costo = costosData[val] || 0;
        document.getElementById('mp_monto_total').value = costo.toFixed(2);
    }

    document.getElementById('mp_semanas').addEventListener('change', autollenarMonto);
});
</script>

<?php include '../includes/footer.php'; ?>