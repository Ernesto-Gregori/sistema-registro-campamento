<?php
// equipo/reclutamiento.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
verificarMantenimiento($pdo);

if (!esEquipoOAdmin()) {
    header('Location: ../default.php');
    exit();
}

$year   = obtenerAnioCampamento();
$action = $_GET['action'] ?? 'list';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mensaje = '';
$error   = '';

// ---------------------------------------------------------------------
// GUARDAR (alta o edición)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'], true)) {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Token de seguridad inválido. Intenta de nuevo.';
    } else {
        try {
            $nombre           = trim($_POST['nombre'] ?? '');
            $edad             = !empty($_POST['edad']) ? (int)$_POST['edad'] : null;
            $sexo             = in_array($_POST['sexo'] ?? '', ['masculino','femenino'], true) ? $_POST['sexo'] : null;
            $direccion        = trim($_POST['direccion'] ?? '');
            $correo           = trim($_POST['correo'] ?? '');
            $telefono         = trim($_POST['telefono_whatsapp'] ?? '');
            $talla            = trim($_POST['talla'] ?? '');
            $semanas_disp     = is_array($_POST['semanas_disponibles'] ?? null)
                                ? implode(',', array_map('intval', $_POST['semanas_disponibles']))
                                : trim($_POST['semanas_disponibles'] ?? '');
            $devocional       = trim($_POST['devocional_usado'] ?? '');
            $iglesia          = trim($_POST['iglesia'] ?? '');
            $pastor_nombre    = trim($_POST['pastor_autoriza'] ?? '');
            $pastor_tel       = trim($_POST['pastor_telefono'] ?? '');
            $pastor_correo    = trim($_POST['pastor_correo'] ?? '');
            $ministerio       = trim($_POST['ministerio_iglesia'] ?? '');
            $testimonio       = trim($_POST['testimonio_salvacion'] ?? '');
            $motivo           = trim($_POST['motivo_servir'] ?? '');
            $practica_deporte = isset($_POST['practica_deporte']) ? 1 : 0;
            $deporte_esp      = trim($_POST['deporte_especifica'] ?? '');
            $tocca_instr      = isset($_POST['toca_instrumento']) ? 1 : 0;
            $instrumento_esp  = trim($_POST['instrumento_especifica'] ?? '');
            $estudios         = trim($_POST['estudios'] ?? '');
            $habilidades      = trim($_POST['habilidades_oficios'] ?? '');
            $cualidades       = is_array($_POST['cualidades'] ?? null) ? implode(', ', $_POST['cualidades']) : trim($_POST['cualidades'] ?? '');
            $fue_campero      = isset($_POST['fue_campero']) ? 1 : 0;
            $temporadas       = trim($_POST['temporadas_campero'] ?? '');
            $tipo_persona     = in_array($_POST['tipo_persona'] ?? 'equipante', ['equipante','alumno','misionero','invitado','cocina'], true) ? $_POST['tipo_persona'] : 'equipante';
            $estado           = in_array($_POST['estado'] ?? 'en espera', ['en espera','aceptado','rechazado','consejero'], true) ? $_POST['estado'] : 'en espera';
            $observaciones    = trim($_POST['observaciones'] ?? '');
            $llamada_realizada = isset($_POST['llamada_realizada']) ? 1 : 0;

            // Validación mínima
            if ($nombre === '') {
                throw new Exception('El nombre es obligatorio.');
            }

            $userId = $_SESSION['user_id'] ?? 0;

            if ($action === 'add') {
                $sql = "INSERT INTO equipantes (
                            nombre, edad, sexo, direccion, correo, telefono_whatsapp, talla,
                            semanas_disponibles, devocional_usado, iglesia, pastor_autoriza,
                            pastor_telefono, pastor_correo, ministerio_iglesia,
                            testimonio_salvacion, motivo_servir, practica_deporte, deporte_especifica,
                            toca_instrumento, instrumento_especifica, estudios, habilidades_oficios,
                            cualidades, fue_campero, temporadas_campero, tipo_persona, estado,
                            observaciones, llamada_realizada, year_campamento, registrado_por
                        ) VALUES (
                            :nombre, :edad, :sexo, :direccion, :correo, :telefono_whatsapp, :talla,
                            :semanas_disponibles, :devocional_usado, :iglesia, :pastor_autoriza,
                            :pastor_telefono, :pastor_correo, :ministerio_iglesia,
                            :testimonio_salvacion, :motivo_servir, :practica_deporte, :deporte_especifica,
                            :toca_instrumento, :instrumento_especifica, :estudios, :habilidades_oficios,
                            :cualidades, :fue_campero, :temporadas_campero, :tipo_persona, :estado,
                            :observaciones, :llamada_realizada, :year, :registrado_por
                        )";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':nombre' => $nombre, ':edad' => $edad, ':sexo' => $sexo,
                    ':direccion' => $direccion, ':correo' => $correo,
                    ':telefono_whatsapp' => $telefono, ':talla' => $talla,
                    ':semanas_disponibles' => $semanas_disp, ':devocional_usado' => $devocional,
                    ':iglesia' => $iglesia, ':pastor_autoriza' => $pastor_nombre,
                    ':pastor_telefono' => $pastor_tel, ':pastor_correo' => $pastor_correo,
                    ':ministerio_iglesia' => $ministerio, ':testimonio_salvacion' => $testimonio,
                    ':motivo_servir' => $motivo, ':practica_deporte' => $practica_deporte,
                    ':deporte_especifica' => $deporte_esp, ':toca_instrumento' => $tocca_instr,
                    ':instrumento_especifica' => $instrumento_esp, ':estudios' => $estudios,
                    ':habilidades_oficios' => $habilidades, ':cualidades' => $cualidades,
                    ':fue_campero' => $fue_campero, ':temporadas_campero' => $temporadas,
                    ':tipo_persona' => $tipo_persona, ':estado' => $estado,
                    ':observaciones' => $observaciones, ':llamada_realizada' => $llamada_realizada,
                    ':year' => $year, ':registrado_por' => $userId,
                ]);
                $mensaje = 'Equipante registrado correctamente.';
            } else {
                // Editar
                $sql = "UPDATE equipantes SET
                            nombre=:nombre, edad=:edad, sexo=:sexo, direccion=:direccion,
                            correo=:correo, telefono_whatsapp=:telefono_whatsapp, talla=:talla,
                            semanas_disponibles=:semanas_disponibles, devocional_usado=:devocional_usado,
                            iglesia=:iglesia, pastor_autoriza=:pastor_autoriza,
                            pastor_telefono=:pastor_telefono, pastor_correo=:pastor_correo,
                            ministerio_iglesia=:ministerio_iglesia, testimonio_salvacion=:testimonio_salvacion,
                            motivo_servir=:motivo_servir, practica_deporte=:practica_deporte,
                            deporte_especifica=:deporte_especifica, toca_instrumento=:toca_instrumento,
                            instrumento_especifica=:instrumento_especifica, estudios=:estudios,
                            habilidades_oficios=:habilidades_oficios, cualidades=:cualidades,
                            fue_campero=:fue_campero, temporadas_campero=:temporadas_campero,
                            tipo_persona=:tipo_persona, estado=:estado,
                            observaciones=:observaciones, llamada_realizada=:llamada_realizada
                        WHERE id=:id";
                $stmt = $pdo->prepare($sql);
                                $stmt->execute([
                    ':nombre' => $nombre, ':edad' => $edad, ':sexo' => $sexo,
                    ':direccion' => $direccion, ':correo' => $correo,
                    ':telefono_whatsapp' => $telefono, ':talla' => $talla,
                    ':semanas_disponibles' => $semanas_disp, ':devocional_usado' => $devocional,
                    ':iglesia' => $iglesia, ':pastor_autoriza' => $pastor_nombre,
                    ':pastor_telefono' => $pastor_tel, ':pastor_correo' => $pastor_correo,
                    ':ministerio_iglesia' => $ministerio, ':testimonio_salvacion' => $testimonio,
                    ':motivo_servir' => $motivo, ':practica_deporte' => $practica_deporte,
                    ':deporte_especifica' => $deporte_esp, ':toca_instrumento' => $tocca_instr,
                    ':instrumento_especifica' => $instrumento_esp, ':estudios' => $estudios,
                    ':habilidades_oficios' => $habilidades, ':cualidades' => $cualidades,
                    ':fue_campero' => $fue_campero, ':temporadas_campero' => $temporadas,
                    ':tipo_persona' => $tipo_persona, ':estado' => $estado,
                    ':observaciones' => $observaciones, ':llamada_realizada' => $llamada_realizada,
                    ':id' => $id,
                ]);
                $mensaje = 'Equipante actualizado correctamente.';
                
                // Si el estado es consejero, auto-asignar al área "Consejeros" en todas las semanas
                if ($estado === 'consejero') {
                    $stmtArea = $pdo->prepare("SELECT id FROM areas_servicio WHERE nombre LIKE '%onsejer%' AND activa = 1 LIMIT 1");
                    $stmtArea->execute();
                    $areaConsejerosId = $stmtArea->fetchColumn();

                    if ($areaConsejerosId) {
                        $stmtSem = $pdo->prepare("SELECT id FROM semanas_campamento WHERE year_campamento = ?");
                        $stmtSem->execute([$year]);
                        $semanas = $stmtSem->fetchAll();

                        $count = 0;
                        foreach ($semanas as $sem) {
                            $stmtDist = $pdo->prepare("
                                INSERT INTO distribucion_equipantes (equipante_id, semana_id, area_id, asignado_por)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE area_id = VALUES(area_id), asignado_por = VALUES(asignado_por)
                            ");
                            $stmtDist->execute([$id, $sem['id'], $areaConsejerosId, $userId]);
                            $count++;
                        }
                        $mensaje .= " Asignado a {$count} semana(s) en area de Consejeros.";
                    }
                }
            }

            header('Location: reclutamiento.php?message=' . urlencode($mensaje));
            exit();

        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------------------
// CAMBIO RÁPIDO DE ESTADO (vía GET)
// ---------------------------------------------------------------------
if ($action === 'cambiar_estado' && $id > 0) {
    $nuevoEstado = $_GET['estado'] ?? '';
    if (in_array($nuevoEstado, ['en espera','aceptado','rechazado','consejero'], true)) {
        try {
            $userId = $_SESSION['user_id'] ?? 0;
            
            // Actualizar el estado del equipante
            $stmt = $pdo->prepare("UPDATE equipantes SET estado = ? WHERE id = ?");
            $stmt->execute([$nuevoEstado, $id]);
            
            $mensajeExtra = '';
            
            // Si el nuevo estado es consejero, auto-asignar al área "Consejeros" en todas las semanas
            if ($nuevoEstado === 'consejero') {
                // Buscar el área "Consejeros"
                $stmtArea = $pdo->prepare("SELECT id FROM areas_servicio WHERE nombre LIKE '%onsejer%' AND activa = 1 LIMIT 1");
                $stmtArea->execute();
                $areaConsejerosId = $stmtArea->fetchColumn();

                if ($areaConsejerosId) {
                    // Buscar todas las semanas activas del año
                    $stmtSem = $pdo->prepare("SELECT id FROM semanas_campamento WHERE year_campamento = ?");
                    $stmtSem->execute([$year]);
                    $semanas = $stmtSem->fetchAll();

                    $count = 0;
                    foreach ($semanas as $sem) {
                        // Asignar (o actualizar si ya estaba distribuido) al área de Consejeros
                        $stmtDist = $pdo->prepare("
                            INSERT INTO distribucion_equipantes (equipante_id, semana_id, area_id, asignado_por)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE area_id = VALUES(area_id), asignado_por = VALUES(asignado_por)
                        ");
                        $stmtDist->execute([$id, $sem['id'], $areaConsejerosId, $userId]);
                        $count++;
                    }
                    $mensajeExtra = " Asignado a {$count} semana(s) en area de Consejeros.";
                }
            }
            
            // Si el estado era consejero y se cambia a otro, opcionalmente podríamos quitarlo del área
            // pero por simplicidad lo dejamos asignado (el encargado puede quitarlo manualmente si quiere)
            
            header('Location: reclutamiento.php?message=' . urlencode('Estado actualizado.' . $mensajeExtra));
            exit();
        } catch (Exception $e) {
            $error = 'Error al cambiar estado: ' . $e->getMessage();
        }
    }
}

// Marcar llamada realizada
if ($action === 'marcar_llamada' && $id > 0) {
    try {
        $stmt = $pdo->prepare("UPDATE equipantes SET llamada_realizada = 1, fecha_llamada = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        header('Location: reclutamiento.php?message=' . urlencode('Llamada marcada como realizada.'));
        exit();
    } catch (Exception $e) {
        $error = 'Error al marcar llamada: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------
// Cargar datos para edición
// ---------------------------------------------------------------------
$equipante = null;
if ($action === 'edit' && $id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM equipantes WHERE id = ?");
        $stmt->execute([$id]);
        $equipante = $stmt->fetch();
        if (!$equipante) {
            $error = 'Equipante no encontrado.';
            $action = 'list';
        }
    } catch (Exception $e) {
        $error = 'Error al cargar equipante: ' . $e->getMessage();
        $action = 'list';
    }
}

// ---------------------------------------------------------------------
// LISTAR con filtros
// ---------------------------------------------------------------------
$filtroEstado   = $_GET['estado_filtro'] ?? '';
$filtroTipo     = $_GET['tipo_filtro'] ?? '';
$filtroLlamada  = $_GET['llamada_filtro'] ?? '';
$search         = trim($_GET['search'] ?? '');

$where  = ["e.year_campamento = ?", "e.activo = 1"];
$params = [$year];

if ($search !== '') {
    $where[] = "(e.nombre LIKE ? OR e.iglesia LIKE ? OR e.correo LIKE ?)";
    $term = "%$search%";
    $params[] = $term; $params[] = $term; $params[] = $term;
}
if (in_array($filtroEstado, ['en espera','aceptado','rechazado','consejero'], true)) {
    $where[] = "e.estado = ?";
    $params[] = $filtroEstado;
}
if (in_array($filtroTipo, ['equipante','alumno','misionero','invitado','cocina'], true)) {
    $where[] = "e.tipo_persona = ?";
    $params[] = $filtroTipo;
}
if ($filtroLlamada === 'pendientes') {
    $where[] = "e.llamada_realizada = 0 AND e.estado = 'en espera'";
}
if ($filtroLlamada === 'realizadas') {
    $where[] = "e.llamada_realizada = 1";
}

$equipantes = [];
try {
    $sql = "SELECT e.*, u.username AS registrado_por_nombre
            FROM equipantes e
            LEFT JOIN usuarios u ON e.registrado_por = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $equipantes = $stmt->fetchAll();
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

<?php if (in_array($action, ['add', 'edit'], true)): ?>
    <!-- ============================ FORMULARIO ============================ -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">
            <i class="fas fa-user-plus text-primary"></i>
            <?php echo $action === 'add' ? 'Nuevo Equipante' : 'Editar Equipante'; ?>
        </h1>
        <a href="reclutamiento.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>

    <form method="POST" action="?action=<?php echo $action; ?><?php echo $id ? '&id=' . $id : ''; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

        <div class="row g-3">
            <!-- Datos personales -->
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-id-card"></i> Datos personales</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nombre y apellidos <span class="text-danger">*</span></label>
                                <input type="text" name="nombre" class="form-control" required
                                       value="<?php echo htmlspecialchars($equipante['nombre'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Edad</label>
                                <input type="number" name="edad" class="form-control" min="1" max="99"
                                       value="<?php echo htmlspecialchars($equipante['edad'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Sexo</label>
                                <select name="sexo" class="form-select">
                                    <option value="">--</option>
                                    <option value="femenino"  <?php echo ($equipante['sexo'] ?? '')==='femenino'?'selected':''; ?>>Femenino</option>
                                    <option value="masculino" <?php echo ($equipante['sexo'] ?? '')==='masculino'?'selected':''; ?>>Masculino</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Talla camiseta</label>
                                <select name="talla" class="form-select">
                                    <?php foreach (['XS','S','M','G','XL','XXL'] as $t): ?>
                                        <option value="<?php echo $t; ?>" <?php echo ($equipante['talla'] ?? '')===$t?'selected':''; ?>><?php echo $t; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Dirección</label>
                                <input type="text" name="direccion" class="form-control"
                                       value="<?php echo htmlspecialchars($equipante['direccion'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Correo electrónico</label>
                                <input type="email" name="correo" class="form-control"
                                       value="<?php echo htmlspecialchars($equipante['correo'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">WhatsApp / Teléfono</label>
                                <input type="text" name="telefono_whatsapp" class="form-control"
                                       value="<?php echo htmlspecialchars($equipante['telefono_whatsapp'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Disponibilidad -->
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-calendar-alt"></i> Semanas que considera asistir</h6>
                    </div>
                    <div class="card-body">
                        <?php
                        // Cargar semanas del año
                        $semanasForm = [];
                        try {
                            $stmtSem = $pdo->prepare("SELECT id, nombre, fecha_inicio, fecha_fin FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio ASC");
                            $stmtSem->execute([$year]);
                            $semanasForm = $stmtSem->fetchAll();
                        } catch (Exception $e) {}

                        // IDs de semanas ya seleccionadas (guardados como "1,3,5")
                        $semanasSel = [];
                        if (!empty($equipante['semanas_disponibles'])) {
                            $semanasSel = array_map('intval', array_filter(explode(',', $equipante['semanas_disponibles'])));
                        }
                        ?>
                        <?php if (empty($semanasForm)): ?>
                            <p class="text-muted small mb-0">No hay semanas registradas para <?php echo $year; ?>.</p>
                        <?php else: ?>
                            <div class="row g-2">
                                <?php foreach ($semanasForm as $sf): ?>
                                <div class="col-12 col-md-6">
                                    <div class="form-check">
                                        <input type="checkbox" name="semanas_disponibles[]" value="<?php echo $sf['id']; ?>"
                                               class="form-check-input" id="sem_<?php echo $sf['id']; ?>"
                                               <?php echo in_array((int)$sf['id'], $semanasSel, true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="sem_<?php echo $sf['id']; ?>">
                                            <?php echo htmlspecialchars($sf['nombre']); ?>
                                            <small class="text-muted d-block">
                                                <?php echo date('d/m/Y', strtotime($sf['fecha_inicio'])); ?> -
                                                <?php echo date('d/m/Y', strtotime($sf['fecha_fin'])); ?>
                                            </small>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Vida espiritual -->
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-church"></i> Vida espiritual</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Devocional que usa</label>
                                <input type="text" name="devocional_usado" class="form-control"
                                       value="<?php echo htmlspecialchars($equipante['devocional_usado'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Iglesia</label>
                                <input type="text" name="iglesia" class="form-control"
                                       value="<?php echo htmlspecialchars($equipante['iglesia'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Pastor que autoriza</label>
                                <input type="text" name="pastor_autoriza" class="form-control"
                                       value="<?php echo htmlspecialchars($equipante['pastor_autoriza'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Teléfono del pastor</label>
                                <input type="text" name="pastor_telefono" class="form-control"
                                       value="<?php echo htmlspecialchars($equipante['pastor_telefono'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Correo del pastor</label>
                                <input type="email" name="pastor_correo" class="form-control"
                                       value="<?php echo htmlspecialchars($equipante['pastor_correo'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Ministerio en su iglesia</label>
                                <input type="text" name="ministerio_iglesia" class="form-control"
                                       value="<?php echo htmlspecialchars($equipante['ministerio_iglesia'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Testimonio de salvación</label>
                                <textarea name="testimonio_salvacion" class="form-control" rows="2"><?php echo htmlspecialchars($equipante['testimonio_salvacion'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">¿Por qué desea ser parte del equipo?</label>
                                <textarea name="motivo_servir" class="form-control" rows="2"><?php echo htmlspecialchars($equipante['motivo_servir'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Habilidades -->
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-tools"></i> Habilidades y gustos</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input type="checkbox" name="practica_deporte" value="1" class="form-check-input" id="dep" <?php echo !empty($equipante['practica_deporte'])?'checked':''; ?>>
                                    <label class="form-check-label" for="dep">¿Practica deporte?</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="deporte_especifica" class="form-control form-control-sm" placeholder="¿Cuál deporte?" value="<?php echo htmlspecialchars($equipante['deporte_especifica'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input type="checkbox" name="toca_instrumento" value="1" class="form-check-input" id="inst" <?php echo !empty($equipante['toca_instrumento'])?'checked':''; ?>>
                                    <label class="form-check-label" for="inst">¿Toca instrumento?</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="instrumento_especifica" class="form-control form-control-sm" placeholder="¿Cuál instrumento?" value="<?php echo htmlspecialchars($equipante['instrumento_especifica'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Estudios</label>
                                <input type="text" name="estudios" class="form-control" value="<?php echo htmlspecialchars($equipante['estudios'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Habilidades / oficios / profesión</label>
                                <input type="text" name="habilidades_oficios" class="form-control" value="<?php echo htmlspecialchars($equipante['habilidades_oficios'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">¿Fue campero en PV?</label>
                                <div class="form-check">
                                    <input type="checkbox" name="fue_campero" value="1" class="form-check-input" id="camp" <?php echo !empty($equipante['fue_campero'])?'checked':''; ?>>
                                    <label class="form-check-label" for="camp">Sí fue campero</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">¿En qué temporada(s)?</label>
                                <input type="text" name="temporadas_campero" class="form-control" value="<?php echo htmlspecialchars($equipante['temporadas_campero'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estado y seguimiento -->
            <div class="col-12">
                <div class="card shadow-sm border-primary">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-clipboard-check"></i> Estado y seguimiento</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Tipo de persona</label>
                                <select name="tipo_persona" class="form-select">
                                    <?php
                                    $tipos = ['equipante'=>'Equipante','alumno'=>'Alumno','misionero'=>'Misionero','invitado'=>'Invitado','cocina'=>'Cocina'];
                                    foreach ($tipos as $k => $v):
                                    ?>
                                        <option value="<?php echo $k; ?>" <?php echo ($equipante['tipo_persona'] ?? 'equipante')===$k?'selected':''; ?>><?php echo $v; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Estado</label>
                                <select name="estado" class="form-select">
                                    <?php
                                    $estados = ['en espera'=>'En espera','aceptado'=>'Aceptado','rechazado'=>'Rechazado','consejero'=>'Consejero'];
                                    foreach ($estados as $k => $v):
                                    ?>
                                        <option value="<?php echo $k; ?>" <?php echo ($equipante['estado'] ?? 'en espera')===$k?'selected':''; ?>><?php echo $v; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" name="llamada_realizada" value="1" class="form-check-input" id="llam" <?php echo !empty($equipante['llamada_realizada'])?'checked':''; ?>>
                                    <label class="form-check-label" for="llam">Llamada realizada</label>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Observaciones (seguimiento de llamadas)</label>
                                <textarea name="observaciones" class="form-control" rows="3"><?php echo htmlspecialchars($equipante['observaciones'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Botones -->
            <div class="col-12">
                <div class="d-flex justify-content-end gap-2">
                    <a href="reclutamiento.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i>
                        <?php echo $action === 'add' ? 'Registrar' : 'Guardar cambios'; ?>
                    </button>
                </div>
            </div>
        </div>
    </form>

<?php else: ?>
    <!-- ============================ LISTADO ============================ -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h1 class="h3 mb-0"><i class="fas fa-users text-primary"></i> Reclutamiento</h1>
        <div class="d-flex gap-2 flex-wrap">
            <a href="importar.php" class="btn btn-primary btn-sm">
                <i class="fas fa-file-import"></i> Importar Excel
            </a>
            <a href="hoja_trabajo.php" class="btn btn-outline-info btn-sm">
                <i class="fas fa-table"></i> Hoja de Trabajo
            </a>
            <a href="?action=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus"></i> Nuevo Equipante
            </a>
            <a href="nuevo_alumno.php?tipo=alumno" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-user-graduate"></i> Alumno
            </a>
            <a href="nuevo_alumno.php?tipo=misionero" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-pray"></i> Misionero
            </a>
            <a href="nuevo_alumno.php?tipo=invitado" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-user-tag"></i> Invitado
            </a>
            <a href="nuevo_alumno.php?tipo=cocina" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-utensils"></i> Cocina
            </a>
        </div>
    </div>

    <!-- Pestañas por tipo -->
    <?php
    $tipos = ['equipante'=>'Equipantes','alumno'=>'Alumnos','misionero'=>'Misioneros','invitado'=>'Invitados','cocina'=>'Cocina'];
    $tiposIcono = ['equipante'=>'fa-users','alumno'=>'fa-user-graduate','misionero'=>'fa-pray','invitado'=>'fa-user-tag','cocina'=>'fa-utensils'];
    
    // Si hay filtro de tipo, usarlo como activo; si no, 'equipante'
    $tabActiva = in_array($filtroTipo, ['equipante','alumno','misionero','invitado','cocina'], true) ? $filtroTipo : 'equipante';
    ?>
    <ul class="nav nav-tabs mb-3" id="tabsTipo" role="tablist">
        <?php foreach ($tipos as $k => $v): ?>
        <li class="nav-item" role="presentation">
            <a href="?tipo_filtro=<?php echo $k; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $filtroEstado ? '&estado_filtro=' . urlencode($filtroEstado) : ''; ?><?php echo $filtroLlamada ? '&llamada_filtro=' . urlencode($filtroLlamada) : ''; ?>"
               class="nav-link <?php echo $tabActiva === $k ? 'active' : ''; ?>">
                <i class="fas <?php echo $tiposIcono[$k]; ?>"></i> <?php echo $v; ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- Filtros (sin tipo_filtro) -->
    <div class="card mb-3 shadow-sm">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="tipo_filtro" value="<?php echo $tabActiva; ?>">
                <div class="col-12 col-md-4">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="🔍 Nombre, iglesia o correo..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-6 col-md-3">
                    <select name="estado_filtro" class="form-select form-select-sm">
                        <option value="">Todos los estados</option>
                        <?php foreach (['en espera'=>'En espera','aceptado'=>'Aceptado','rechazado'=>'Rechazado','consejero'=>'Consejero'] as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo $filtroEstado===$k?'selected':''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <select name="llamada_filtro" class="form-select form-select-sm">
                        <option value="">Todas las llamadas</option>
                        <option value="pendientes" <?php echo $filtroLlamada==='pendientes'?'selected':''; ?>>⚠️ Pendientes</option>
                        <option value="realizadas" <?php echo $filtroLlamada==='realizadas'?'selected':''; ?>>✅ Realizadas</option>
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search"></i></button>
                </div>
                <div class="col-6 col-md-1">
                    <a href="?tipo_filtro=<?php echo $tabActiva; ?>" class="btn btn-outline-secondary btn-sm w-100"><i class="fas fa-times"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla dinámica según pestaña -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Nombre</th>
                            <?php if ($tabActiva === 'equipante'): ?>
                                <th>Edad</th>
                                <th>Sexo</th>
                                <th>Iglesia</th>
                                <th>Estado</th>
                                <th>Llamada</th>
                            <?php else: ?>
                                <th class="text-center">Personas</th>
                                <th class="text-center">Comedor</th>
                                <th>Iglesia</th>
                            <?php endif; ?>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($equipantes)): ?>
                        <tr>
                            <td colspan="<?php echo $tabActiva === 'equipante' ? 7 : 5; ?>" class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                No hay <?php echo strtolower($tipos[$tabActiva]); ?> con estos filtros.
                            </td>
                        </tr>
                    <?php else: foreach ($equipantes as $e):
                        $badgeEstado = [
                            'en espera'  => 'bg-warning text-dark',
                            'aceptado'   => 'bg-success',
                            'rechazado'  => 'bg-danger',
                            'consejero'  => 'bg-info',
                        ][$e['estado']] ?? 'bg-secondary';

                        // Calcular total de personas si es familia
                        $totalPersonas = 1;
                        if (!empty($e['es_familia'])) {
                            $totalPersonas = (int)($e['familiares_menores_12'] ?? 0) + 
                                             (int)($e['familiares_mayores_12'] ?? 0) + 
                                             (int)($e['familiares_mayores_18'] ?? 0);
                            if ($totalPersonas === 0) $totalPersonas = 1;
                        }
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($e['nombre']); ?></strong>
                                <?php if ($e['telefono_whatsapp']): ?>
                                    <br><small class="text-muted"><i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($e['telefono_whatsapp']); ?></small>
                                <?php endif; ?>
                                <?php
                                // Mostrar semanas seleccionadas
                                if (!empty($e['semanas_disponibles'])) {
                                    $idsSem = array_filter(explode(',', $e['semanas_disponibles']));
                                    if (!empty($idsSem)) {
                                        $placeholders = implode(',', array_fill(0, count($idsSem), '?'));
                                        $stmtSemNom = $pdo->prepare("SELECT nombre FROM semanas_campamento WHERE id IN ($placeholders)");
                                        $stmtSemNom->execute(array_map('intval', $idsSem));
                                        $nombresSem = $stmtSemNom->fetchAll(PDO::FETCH_COLUMN);
                                        if (!empty($nombresSem)) {
                                            echo '<div class="mt-1">';
                                            foreach ($nombresSem as $ns) {
                                                echo '<span class="badge bg-light text-dark me-1 mb-1 small">' . htmlspecialchars($ns) . '</span>';
                                            }
                                            echo '</div>';
                                        }
                                    }
                                }
                                ?>
                            </td>
                            
                            <?php if ($tabActiva === 'equipante'): ?>
                                <!-- Columnas solo para equipantes -->
                                <td><?php echo (int)$e['edad'] ?: '-'; ?></td>
                                <td><?php echo $e['sexo'] === 'masculino' ? '♂' : ($e['sexo'] === 'femenino' ? '♀' : '-'); ?></td>
                                <td><?php echo htmlspecialchars($e['iglesia'] ?: '-'); ?></td>
                                <td>
                                    <span class="badge <?php echo $badgeEstado; ?>"><?php echo htmlspecialchars($e['estado']); ?></span>
                                </td>
                                <td>
                                    <?php if ($e['llamada_realizada']): ?>
                                        <span class="text-success"><i class="fas fa-phone-volume"></i> Sí</span>
                                    <?php else: ?>
                                        <a href="?action=marcar_llamada&id=<?php echo $e['id']; ?>&tipo_filtro=<?php echo $tabActiva; ?>" class="text-warning" title="Marcar llamada realizada">
                                            <i class="fas fa-phone"></i> Pendiente
                                        </a>
                                    <?php endif; ?>
                                </td>
                            <?php else: ?>
                                <!-- Columnas para alumnos/misioneros/invitados/cocina -->
                                <td class="text-center">
                                    <?php if (!empty($e['es_familia'])): ?>
                                        <span class="badge bg-info text-dark"><?php echo $totalPersonas; ?> personas</span>
                                        <small class="text-muted d-block">Familia</small>
                                    <?php else: ?>
                                        <span class="text-muted">1</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (isset($e['come_comedor']) && $e['come_comedor']): ?>
                                        <i class="fas fa-utensils text-success" title="Sí come en comedor"></i>
                                    <?php else: ?>
                                        <i class="fas fa-times text-muted" title="No come en comedor"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($e['iglesia'] ?: '-'); ?></td>
                            <?php endif; ?>
                            
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <?php if ($e['tipo_persona'] === 'equipante'): ?>
                                    <a href="?action=edit&id=<?php echo $e['id']; ?>" class="btn btn-outline-primary" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php else: ?>
                                    <a href="nuevo_alumno.php?action=edit&id=<?php echo $e['id']; ?>&tipo=<?php echo $e['tipo_persona']; ?>" class="btn btn-outline-primary" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($tabActiva === 'equipante'): ?>
                                    <!-- Cambio rápido de estado (solo para equipantes) -->
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                            <i class="fas fa-flag"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="?action=cambiar_estado&id=<?php echo $e['id']; ?>&estado=aceptado&tipo_filtro=<?php echo $tabActiva; ?>"><i class="fas fa-check text-success"></i> Aceptar</a></li>
                                            <li><a class="dropdown-item" href="?action=cambiar_estado&id=<?php echo $e['id']; ?>&estado=en espera&tipo_filtro=<?php echo $tabActiva; ?>"><i class="fas fa-clock text-warning"></i> En espera</a></li>
                                            <li><a class="dropdown-item" href="?action=cambiar_estado&id=<?php echo $e['id']; ?>&estado=rechazado&tipo_filtro=<?php echo $tabActiva; ?>"><i class="fas fa-times text-danger"></i> Rechazar</a></li>
                                            <li><a class="dropdown-item" href="?action=cambiar_estado&id=<?php echo $e['id']; ?>&estado=consejero&tipo_filtro=<?php echo $tabActiva; ?>"><i class="fas fa-praying-hands text-info"></i> Consejero</a></li>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>