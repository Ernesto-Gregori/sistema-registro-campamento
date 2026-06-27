<?php
// equipo/hoja_trabajo.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
verificarMantenimiento($pdo);

if (!esEquipoOAdmin()) {
    header('Location: ../default.php');
    exit();
}

$year = obtenerAnioCampamento();

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mensaje = '';
$error   = '';

// ---------------------------------------------------------------------
// GUARDAR edición rápida (vía AJAX o POST normal)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Token de seguridad inválido.';
    } else {
        $accionPost = $_POST['accion'] ?? '';

        try {
            // --- Guardar observaciones inline ---
            if ($accionPost === 'guardar_observacion' && !empty($_POST['equipante_id'])) {
                $eid = (int)$_POST['equipante_id'];
                $obs = trim($_POST['observaciones'] ?? '');
                $stmt = $pdo->prepare("UPDATE equipantes SET observaciones = ? WHERE id = ?");
                $stmt->execute([$obs, $eid]);
                $mensaje = 'Observaciones guardadas.';
            }

            // --- Cambiar estado ---
            if ($accionPost === 'cambiar_estado' && !empty($_POST['equipante_id'])) {
                $eid = (int)$_POST['equipante_id'];
                $nuevo = $_POST['estado'] ?? '';
                if (in_array($nuevo, ['en espera','aceptado','rechazado','consejero'], true)) {
                    $stmt = $pdo->prepare("UPDATE equipantes SET estado = ? WHERE id = ?");
                    $stmt->execute([$nuevo, $eid]);
                    $mensaje = 'Estado actualizado a: ' . $nuevo;
                }
            }

            // --- Marcar / desmarcar llamada ---
            if ($accionPost === 'toggle_llamada' && !empty($_POST['equipante_id'])) {
                $eid = (int)$_POST['equipante_id'];
                $stmt = $pdo->prepare("SELECT llamada_realizada FROM equipantes WHERE id = ?");
                $stmt->execute([$eid]);
                $actual = (int)$stmt->fetchColumn();

                if ($actual === 1) {
                    $stmt = $pdo->prepare("UPDATE equipantes SET llamada_realizada = 0, fecha_llamada = NULL WHERE id = ?");
                    $stmt->execute([$eid]);
                    $mensaje = 'Llamada marcada como pendiente.';
                } else {
                    $stmt = $pdo->prepare("UPDATE equipantes SET llamada_realizada = 1, fecha_llamada = NOW() WHERE id = ?");
                    $stmt->execute([$eid]);
                    $mensaje = 'Llamada marcada como realizada.';
                }
            }

        } catch (Exception $e) {
            $error = 'Error al guardar: ' . $e->getMessage();
        }

        // Si es petición AJAX, devolver JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => empty($error), 'mensaje' => $mensaje ?: $error]);
            exit();
        }

        if (empty($error)) {
            header('Location: hoja_trabajo.php?message=' . urlencode($mensaje));
            exit();
        }
    }
}

// ---------------------------------------------------------------------
// Filtros y listado
// ---------------------------------------------------------------------
$filtroEstado = $_GET['estado_filtro'] ?? '';
$search       = trim($_GET['search'] ?? '');

$where  = ["year_campamento = ?", "activo = 1", "tipo_persona = 'equipante'"];
$params = [$year];

if ($search !== '') {
    $where[] = "(nombre LIKE ? OR iglesia LIKE ? OR pastor_autoriza LIKE ?)";
    $term = "%$search%";
    array_push($params, $term, $term, $term);
}
if (in_array($filtroEstado, ['en espera','aceptado','rechazado','consejero'], true)) {
    $where[] = "estado = ?";
    $params[] = $filtroEstado;
}

$equipantes = [];
$contadores = ['en espera' => 0, 'aceptado' => 0, 'rechazado' => 0, 'consejero' => 0, 'llamadas_pend' => 0];

try {
    $stmt = $pdo->prepare("SELECT * FROM equipantes WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(estado,'en espera','aceptado','consejero','rechazado'), created_at DESC");
    $stmt->execute($params);
    $equipantes = $stmt->fetchAll();

    foreach ($equipantes as $e) {
        $contadores[$e['estado']] = ($contadores[$e['estado']] ?? 0) + 1;
        if ($e['llamada_realizada'] == 0 && $e['estado'] === 'en espera') {
            $contadores['llamadas_pend']++;
        }
    }
} catch (Exception $e) {
    $error = 'Error al cargar la lista: ' . $e->getMessage();
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
            <h1 class="h3 mb-0"><i class="fas fa-table text-primary"></i> Hoja de Trabajo</h1>
            <small class="text-muted">Edita estado, observaciones y llamadas sin abrir la ficha completa.</small>
        </div>
        <a href="reclutamiento.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Reclutamiento
        </a>
    </div>

    <!-- Mini stats -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-warning"><?php echo $contadores['en espera']; ?></div>
                    <small class="text-muted">En espera</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-success"><?php echo $contadores['aceptado']; ?></div>
                    <small class="text-muted">Aceptados</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-info"><?php echo $contadores['consejero']; ?></div>
                    <small class="text-muted">Consejeros</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-danger"><?php echo $contadores['rechazado']; ?></div>
                    <small class="text-muted">Rechazados</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <div class="fw-bold fs-5 text-dark"><?php echo $contadores['llamadas_pend']; ?></div>
                    <small class="text-muted">Llamadas pend.</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-3 shadow-sm">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="🔍 Nombre, iglesia o pastor..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-6 col-md-3">
                    <select name="estado_filtro" class="form-select form-select-sm">
                        <option value="">Todos los estados</option>
                        <?php foreach (['en espera'=>'En espera','aceptado'=>'Aceptado','rechazado'=>'Rechazado','consejero'=>'Consejero'] as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo $filtroEstado===$k?'selected':''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search"></i> Filtrar</button>
                </div>
                <div class="col-6 col-md-2">
                    <a href="hoja_trabajo.php" class="btn btn-outline-secondary btn-sm w-100"><i class="fas fa-times"></i> Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla editable -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tablaHojaTrabajo">
                    <thead class="table-dark">
                        <tr>
                            <th style="min-width:180px">Nombre / Contacto</th>
                            <th>Iglesia / Pastor</th>
                            <th style="min-width:140px">Estado</th>
                            <th>Llamada</th>
                            <th style="min-width:280px">Observaciones</th>
                            <th class="text-end">Ficha</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($equipantes)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                No hay equipantes con estos filtros.
                            </td>
                        </tr>
                    <?php else: foreach ($equipantes as $e):
                        $badgeEstado = [
                            'en espera'  => 'bg-warning text-dark',
                            'aceptado'   => 'bg-success',
                            'rechazado'  => 'bg-danger',
                            'consejero'  => 'bg-info',
                        ][$e['estado']] ?? 'bg-secondary';
                    ?>
                        <tr id="fila-<?php echo $e['id']; ?>">
                            <!-- Nombre y contacto -->
                            <td>
                                <strong><?php echo htmlspecialchars($e['nombre']); ?></strong>
                                <?php if ($e['edad']): ?>
                                    <span class="badge bg-light text-dark ms-1"><?php echo (int)$e['edad']; ?> años</span>
                                <?php endif; ?>
                                <?php if ($e['sexo']): ?>
                                    <span class="text-muted"><?php echo $e['sexo']==='masculino'?'♂':'♀'; ?></span>
                                <?php endif; ?>
                                <?php if ($e['telefono_whatsapp']): ?>
                                    <br><small class="text-muted"><i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($e['telefono_whatsapp']); ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- Iglesia / Pastor -->
                            <td>
                                <small><?php echo htmlspecialchars($e['iglesia'] ?: '-'); ?></small>
                                <?php if ($e['pastor_autoriza']): ?>
                                    <br><small class="text-muted"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($e['pastor_autoriza']); ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- Estado (select inline) -->
                            <td>
                                <form method="POST" class="d-inline-block form-estado" data-fila="<?php echo $e['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="accion" value="cambiar_estado">
                                    <input type="hidden" name="equipante_id" value="<?php echo $e['id']; ?>">
                                    <select name="estado" class="form-select form-select-sm cambio-estado" data-estado-anterior="<?php echo htmlspecialchars($e['estado']); ?>">
                                        <?php foreach (['en espera'=>'En espera','aceptado'=>'Aceptado','rechazado'=>'Rechazado','consejero'=>'Consejero'] as $k => $v): ?>
                                            <option value="<?php echo $k; ?>" <?php echo $e['estado']===$k?'selected':''; ?>><?php echo $v; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>

                            <!-- Llamada (toggle) -->
                            <td class="text-center">
                                <form method="POST" class="form-llamada" data-fila="<?php echo $e['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="accion" value="toggle_llamada">
                                    <input type="hidden" name="equipante_id" value="<?php echo $e['id']; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $e['llamada_realizada']?'btn-success':'btn-outline-warning'; ?>" title="<?php echo $e['llamada_realizada']?'Realizada '.($e['fecha_llamada']??''):'Pendiente'; ?>">
                                        <i class="fas <?php echo $e['llamada_realizada']?'fa-phone-slash':'fa-phone'; ?>"></i>
                                    </button>
                                </form>
                                <?php if ($e['fecha_llamada']): ?>
                                    <div class="small text-muted"><?php echo date('d/m H:i', strtotime($e['fecha_llamada'])); ?></div>
                                <?php endif; ?>
                            </td>

                            <!-- Observaciones (textarea inline + botón guardar) -->
                            <td>
                                <form method="POST" class="form-observacion" data-fila="<?php echo $e['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="accion" value="guardar_observacion">
                                    <input type="hidden" name="equipante_id" value="<?php echo $e['id']; ?>">
                                    <div class="input-group input-group-sm">
                                        <textarea name="observaciones" class="form-control form-control-sm" rows="1" placeholder="Notas de la llamada..."><?php echo htmlspecialchars($e['observaciones'] ?? ''); ?></textarea>
                                        <button type="submit" class="btn btn-outline-primary" title="Guardar">
                                            <i class="fas fa-save"></i>
                                        </button>
                                    </div>
                                </form>
                            </td>

                            <!-- Ficha completa -->
                            <td class="text-end">
                                <a href="reclutamiento.php?action=edit&id=<?php echo $e['id']; ?>" class="btn btn-outline-primary btn-sm" title="Ver ficha completa">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-2 mb-0">
        <i class="fas fa-info-circle"></i>
        Cambia el estado con el selector, marca la llamada con el botón del teléfono y guarda observaciones con el icono del diskette. Todo se guarda al instante.
    </p>
</div>

<!-- JS: auto-submit al cambiar estado (con confirmación si pasa a rechazado) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-enviar al cambiar el select de estado
    document.querySelectorAll('.cambio-estado').forEach(function(sel) {
        sel.addEventListener('change', function() {
            if (this.value === 'rechazado' && this.dataset.estadoAnterior !== 'rechazado') {
                if (!confirm('¿Seguro que deseas RECHAZAR a este equipante?')) {
                    this.value = this.dataset.estadoAnterior;
                    return;
                }
            }
            this.closest('form').submit();
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>