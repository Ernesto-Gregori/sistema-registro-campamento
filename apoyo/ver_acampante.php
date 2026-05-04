<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esApoyo()) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$titulo = "Detalle del Acampante";
$id = $_GET['id'] ?? null;

if (!$id) {
    header('Location: lista_acampantes.php');
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT a.*, c.nombre_cabana, c.genero as genero_cabana,
                           c.equipo as cabana_equipo, c.consejero_principal,
                           s.nombre as semana_nombre, s.activa as semana_activa
                           FROM acampantes a
                           LEFT JOIN cabanas c ON a.cabana_id = c.id
                           LEFT JOIN semanas_campamento s ON a.semana_id = s.id
                           WHERE a.id = ? AND a.estado = 'activo'");
    $stmt->execute([$id]);
    $acampante = $stmt->fetch();

    if (!$acampante) {
        header('Location: lista_acampantes.php');
        exit();
    }

    // Historial de consejerías
    $stmt = $pdo->prepare("SELECT sc.*, tc.categoria, tc.tema as tema_predefinido
                           FROM sesiones_consejeria sc
                           LEFT JOIN temas_consejeria tc ON sc.tema_id = tc.id
                           WHERE sc.acampante_id = ?
                           ORDER BY sc.numero_sesion DESC, sc.fecha_sesion DESC, sc.created_at DESC");
    $stmt->execute([$id]);
    $consejerias = $stmt->fetchAll();

} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
    $consejerias = [];
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-user"></i> <?php echo $titulo; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="lista_acampantes.php">Lista de Acampantes</a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($acampante['nombre']); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<div class="row">
    <!-- Foto -->
    <div class="col-md-3 mb-4">
        <div class="card">
            <?php if (!empty($acampante['foto']) && file_exists('../' . $acampante['foto'])): ?>
                <img src="<?php echo htmlspecialchars('../' . $acampante['foto']); ?>"
                     alt="<?php echo htmlspecialchars($acampante['nombre']); ?>"
                     class="card-img-top" style="height:300px; object-fit:cover;">
            <?php else: ?>
                <div class="card-img-top bg-light text-center d-flex align-items-center justify-content-center"
                     style="height:300px;">
                    <i class="fas fa-user-circle" style="font-size:80px; color:#ccc;"></i>
                </div>
            <?php endif; ?>
            <div class="card-body text-center">
                <h6><?php echo htmlspecialchars($acampante['nombre']); ?></h6>
                <span class="badge bg-<?php echo $acampante['sexo'] === 'masculino' ? 'primary' : 'danger'; ?>">
                    <i class="fas fa-<?php echo $acampante['sexo'] === 'masculino' ? 'mars' : 'venus'; ?>"></i>
                    <?php echo ucfirst($acampante['sexo'] ?? 'N/A'); ?>
                </span>
            </div>
        </div>

        <!-- Acciones -->
        <div class="card mt-3">
            <div class="card-header">
                <h6><i class="fas fa-tools"></i> Acciones</h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="editar_acampante.php?id=<?php echo $acampante['id']; ?>"
                       class="btn btn-primary">
                        <i class="fas fa-edit"></i> Editar Acampante
                    </a>
                    <a href="lista_acampantes.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Volver a la Lista
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Información -->
    <div class="col-md-9">
        <div class="row">
            <!-- Datos personales -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="fas fa-user"></i> Datos Personales</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <td class="fw-bold text-muted" style="width:40%">Nombre:</td>
                                <td><?php echo htmlspecialchars($acampante['nombre']); ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Edad:</td>
                                <td><?php echo $acampante['edad'] ?? '<em class="text-muted">No registrada</em>'; ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Sexo:</td>
                                <td>
                                    <span class="badge bg-<?php echo $acampante['sexo'] === 'masculino' ? 'primary' : 'danger'; ?>">
                                        <?php echo ucfirst($acampante['sexo'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Iglesia:</td>
                                <td><?php echo htmlspecialchars($acampante['iglesia'] ?? 'No registrada'); ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Estado/Dpto.:</td>
                                <td><?php echo htmlspecialchars($acampante['estado_origen'] ?? 'No registrado'); ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Fecha registro:</td>
                                <td>
                                    <?php echo !empty($acampante['fecha_registro'])
                                        ? date('d/m/Y H:i', strtotime($acampante['fecha_registro']))
                                        : '<em class="text-muted">No registrada</em>'; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Asignación -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="fas fa-home"></i> Asignación</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <td class="fw-bold text-muted" style="width:40%">Semana:</td>
                                <td>
                                    <?php if ($acampante['semana_nombre']): ?>
                                        <span class="badge bg-<?php echo $acampante['semana_activa'] ? 'success' : 'secondary'; ?>">
                                            <i class="fas fa-calendar-week"></i>
                                            <?php echo htmlspecialchars($acampante['semana_nombre']); ?>
                                        </span>
                                    <?php else: ?>
                                        <em class="text-muted">Sin semana</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Cabaña:</td>
                                <td>
                                    <span class="badge bg-primary">
                                        <?php echo htmlspecialchars($acampante['nombre_cabana'] ?? 'Sin asignar'); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">
                                    <i class="fas fa-user-shield text-warning"></i>
                                    Responsable:
                                </td>
                                <td>
                                    <?php if (!empty($acampante['consejero_responsable'])): ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="fas fa-user-shield"></i>
                                            <?php echo htmlspecialchars($acampante['consejero_responsable']); ?>
                                        </span>
                                    <?php else: ?>
                                        <em class="text-muted small">Sin asignar</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Año:</td>
                                <td><?php echo $acampante['year_campamento']; ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Contacto de emergencia -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-warning text-dark">
                        <h6 class="mb-0"><i class="fas fa-phone-alt"></i> Contacto de Emergencia</h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($acampante['contacto_emergencia_nombre']) || !empty($acampante['contacto_emergencia_telefono'])): ?>
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <td class="fw-bold text-muted" style="width:40%">Persona:</td>
                                <td><?php echo htmlspecialchars($acampante['contacto_emergencia_nombre'] ?? 'No registrado'); ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-muted">Teléfono:</td>
                                <td>
                                    <?php if (!empty($acampante['contacto_emergencia_telefono'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($acampante['contacto_emergencia_telefono']); ?>">
                                            <i class="fas fa-phone"></i>
                                            <?php echo htmlspecialchars($acampante['contacto_emergencia_telefono']); ?>
                                        </a>
                                    <?php else: ?>
                                        <em class="text-muted">No registrado</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <?php else: ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-phone-slash fa-2x mb-2"></i>
                            <p class="mb-0">Sin contacto de emergencia registrado</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Salud -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0"><i class="fas fa-notes-medical"></i> Salud</h6>
                    </div>
                    <div class="card-body">
                        <p class="fw-bold text-muted small mb-1">Alergias / Enfermedades:</p>
                        <?php if (!empty($acampante['alergias_enfermedades'])): ?>
                            <div class="alert alert-danger py-2 mb-3">
                                <i class="fas fa-exclamation-triangle"></i>
                                <?php echo nl2br(htmlspecialchars($acampante['alergias_enfermedades'])); ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted small mb-3"><em>Ninguna registrada</em></p>
                        <?php endif; ?>

                        <p class="fw-bold text-muted small mb-1">Observaciones:</p>
                        <?php if (!empty($acampante['observaciones'])): ?>
                            <p class="small"><?php echo nl2br(htmlspecialchars($acampante['observaciones'])); ?></p>
                        <?php else: ?>
                            <p class="text-muted small"><em>Sin observaciones</em></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ ESTADO ESPIRITUAL + HISTORIAL ══ -->
<div class="row mt-2">

    <!-- Estado Espiritual -->
    <div class="col-md-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-secondary text-white">
                <h6 class="mb-0">
                    <i class="fas fa-cross"></i> Estado Espiritual
                </h6>
            </div>
            <div class="card-body">

                <!-- Badges de estado -->
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php if ($acampante['recibio_cristo_semana']): ?>
                    <span class="badge bg-success px-3 py-2">
                        ✝️ Recibió a Cristo esta semana
                    </span>
                    <?php endif; ?>
                    <?php if ($acampante['consagro_vida_fogata']): ?>
                    <span class="badge bg-warning text-dark px-3 py-2">
                        🙏 Consagró su vida en la fogata
                    </span>
                    <?php endif; ?>
                    <?php if ($acampante['era_creyente_antes']): ?>
                    <span class="badge bg-primary px-3 py-2">
                        📖 Era creyente antes del campamento
                    </span>
                    <?php endif; ?>
                    <?php if ($acampante['asiste_iglesia']): ?>
                    <span class="badge bg-info px-3 py-2">
                        ⛪ Asiste a una iglesia
                    </span>
                    <?php endif; ?>
                    <?php if (!$acampante['recibio_cristo_semana'] && !$acampante['consagro_vida_fogata']
                           && !$acampante['era_creyente_antes']    && !$acampante['asiste_iglesia']): ?>
                    <em class="text-muted small">Sin información espiritual registrada</em>
                    <?php endif; ?>
                </div>

                <!-- Decisión tomada -->
                <?php if (!empty($acampante['decision_tomada'])): ?>
                <hr class="my-2">
                <p class="small fw-bold text-muted mb-1">
                    <i class="fas fa-hand-holding-heart"></i> Decisión tomada:
                </p>
                <div class="bg-light rounded p-2 small">
                    <?php echo nl2br(htmlspecialchars($acampante['decision_tomada'])); ?>
                </div>
                <?php endif; ?>

                <!-- Resumen visual -->
                <?php
                $total_flags = (int)$acampante['recibio_cristo_semana']
                             + (int)$acampante['consagro_vida_fogata']
                             + (int)$acampante['era_creyente_antes']
                             + (int)$acampante['asiste_iglesia'];
                if ($total_flags > 0):
                ?>
                <hr class="my-2">
                <div class="progress mt-1" style="height:10px; border-radius:8px;"
                     title="<?php echo $total_flags; ?>/4 indicadores espirituales">
                    <div class="progress-bar bg-success"
                         style="width:<?php echo ($total_flags / 4) * 100; ?>%;">
                    </div>
                </div>
                <small class="text-muted">
                    <?php echo $total_flags; ?>/4 indicadores espirituales registrados
                </small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Historial de Consejerías -->
    <div class="col-md-8 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="fas fa-history"></i> Historial de Consejerías
                    <?php
                    $num_sesiones = !empty($consejerias)
                        ? count(array_unique(array_column($consejerias, 'numero_sesion')))
                        : 0;
                    ?>
                    <span class="badge bg-primary ms-1"><?php echo $num_sesiones; ?></span>
                </h6>
                <small class="text-muted">
                    <?php echo count($consejerias); ?> tema(s) en total
                </small>
            </div>
            <div class="card-body">
                <?php if (empty($consejerias)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-comments fa-3x mb-2 opacity-25 d-block"></i>
                    No hay consejerías registradas aún
                </div>
                <?php else:
                    // Agrupar por número de sesión
                    $sesionesAgrupadas = [];
                    foreach ($consejerias as $cons) {
                        $sesionesAgrupadas[$cons['numero_sesion']][] = $cons;
                    }
                ?>
                <div class="row g-3">
                    <?php foreach ($sesionesAgrupadas as $numSesion => $sesion_items): ?>
                    <div class="col-md-6">
                        <div class="card border-primary h-100">
                            <!-- Header sesión -->
                            <div class="card-header bg-primary bg-opacity-10 py-2
                                        d-flex justify-content-between align-items-center">
                                <span class="fw-bold text-primary small">
                                    <i class="fas fa-comments"></i> Sesión #<?php echo $numSesion; ?>
                                </span>
                                <small class="text-muted">
                                    <?php echo date('d/m/Y', strtotime($sesion_items[0]['fecha_sesion'])); ?>
                                    <?php if (!empty($sesion_items[0]['hora_sesion'])): ?>
                                    — <?php echo substr($sesion_items[0]['hora_sesion'], 0, 5); ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="card-body py-2 px-3">

                                <!-- Consejero responsable -->
                                <?php if (!empty($acampante['consejero_responsable'])): ?>
                                <div class="mb-2">
                                    <span class="badge bg-warning text-dark" style="font-size:10px;">
                                        <i class="fas fa-user-shield"></i>
                                        <?php echo htmlspecialchars($acampante['consejero_responsable']); ?>
                                    </span>
                                </div>
                                <?php endif; ?>

                                <!-- Temas -->
                                <?php
                                $tiene_temas = false;
                                foreach ($sesion_items as $item) {
                                    if (!empty($item['tema_predefinido']) || !empty($item['tema_personalizado'])) {
                                        $tiene_temas = true;
                                        break;
                                    }
                                }
                                ?>
                                <?php if ($tiene_temas): ?>
                                <ul class="small mb-2 ps-3">
                                    <?php foreach ($sesion_items as $item): ?>
                                    <?php if (!empty($item['tema_predefinido']) || !empty($item['tema_personalizado'])): ?>
                                    <li>
                                        <?php if (!empty($item['tema_predefinido'])): ?>
                                        <span class="text-primary">
                                            <?php echo htmlspecialchars($item['tema_predefinido']); ?>
                                        </span>
                                        <span class="text-muted small">
                                            (<?php echo htmlspecialchars($item['categoria'] ?? ''); ?>)
                                        </span>
                                        <?php else: ?>
                                        <span class="text-success">
                                            <?php echo htmlspecialchars($item['tema_personalizado']); ?>
                                        </span>
                                        <span class="text-muted small">(Personalizado)</span>
                                        <?php endif; ?>
                                    </li>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </ul>
                                <?php else: ?>
                                <p class="small text-muted mb-2">
                                    <em>Solo evaluación espiritual</em>
                                </p>
                                <?php endif; ?>

                                <!-- Observaciones -->
                                <?php if (!empty($sesion_items[0]['observaciones'])): ?>
                                <div class="bg-light rounded p-2 small">
                                    <i class="fas fa-sticky-note text-muted"></i>
                                    <?php echo nl2br(htmlspecialchars($sesion_items[0]['observaciones'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php include '../includes/footer.php'; ?>