<?php  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
if (!esConsejero()) {  
    header('Location: ../admin/dashboard.php');  
    exit();  
}  
  
$titulo   = "Mis Acampantes";  
$cabana_id = $_SESSION['cabana_id'] ?? null;  

// Obtener semana activa
$stmt_sem = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
$semana_activa    = $stmt_sem->fetch();
$semana_id_activa = $semana_activa['id'] ?? null;

// Obtener acampantes filtrados por semana activa
$misAcampantes = [];  
if ($cabana_id) {
    if ($semana_id_activa) {
        $stmt = $pdo->prepare("SELECT * FROM acampantes   
                               WHERE cabana_id = ? AND semana_id = ? AND estado = 'activo'  
                               ORDER BY nombre");  
        $stmt->execute([$cabana_id, $semana_id_activa]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM acampantes   
                               WHERE cabana_id = ? AND year_campamento = ? AND estado = 'activo'  
                               ORDER BY nombre");  
        $stmt->execute([$cabana_id, obtenerAnioCampamento()]);
    }
    $misAcampantes = $stmt->fetchAll();  
}

// Precargar conteo de consejerías para todos los acampantes
$consejeriasPorAcampante = [];
if (!empty($misAcampantes)) {
    $ids = implode(',', array_column($misAcampantes, 'id'));
    $stmt = $pdo->query("SELECT acampante_id, COUNT(DISTINCT numero_sesion) as total
                         FROM sesiones_consejeria
                         WHERE acampante_id IN ($ids)
                         GROUP BY acampante_id");
    foreach ($stmt->fetchAll() as $row) {
        $consejeriasPorAcampante[$row['acampante_id']] = $row['total'];
    }
}
  
include '../includes/header.php';  
?>  
  
<div class="row mb-4">  
    <div class="col-12">  
        <h1><i class="fas fa-users"></i> <?php echo $titulo; ?></h1>  
        <nav aria-label="breadcrumb">  
            <ol class="breadcrumb">  
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>  
                <li class="breadcrumb-item active">Mis Acampantes</li>  
            </ol>  
        </nav>  
    </div>  
</div>  
  
<!-- Banner semana activa -->
<?php if ($semana_activa): ?>
<div class="alert alert-success border-0 mb-4">
    <div class="d-flex align-items-center gap-3">
        <i class="fas fa-broadcast-tower fa-2x text-success"></i>
        <div>
            <h5 class="mb-0">
                <span class="badge bg-success me-2">ACTIVA</span>
                <?php echo htmlspecialchars($semana_activa['nombre']); ?>
            </h5>
            <small class="text-muted">
                <?php
                $tipos = ['mayores' => '👔 Mayores', 'ninos' => '🧒 Niños', 'adolescentes' => '🎓 Adolescentes'];
                echo $tipos[$semana_activa['tipo_acampante']] ?? $semana_activa['tipo_acampante'];
                ?> |
                <?php echo date('d/m/Y', strtotime($semana_activa['fecha_inicio'])); ?> -
                <?php echo date('d/m/Y', strtotime($semana_activa['fecha_fin'])); ?>
            </small>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning border-0 mb-4">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Sin semana activa.</strong> El administrador debe activar una semana.
</div>
<?php endif; ?>

<div class="card">  
    <div class="card-header d-flex justify-content-between align-items-center">  
        <h5 class="mb-0">
            <i class="fas fa-list"></i>
            Lista Completa (<?php echo count($misAcampantes); ?>)
            <?php if ($semana_activa): ?>
                <small class="text-muted fs-6"> — <?php echo htmlspecialchars($semana_activa['nombre']); ?></small>
            <?php endif; ?>
        </h5>
        <?php if (!empty($misAcampantes)): ?>
        <!-- Búsqueda rápida -->
        <input type="text" id="buscarAcampante" class="form-control form-control-sm w-auto"
               placeholder="🔍 Buscar por nombre..." style="min-width:200px;">
        <?php endif; ?>
    </div> 
    <div class="card-body">  
        <?php if (empty($misAcampantes)): ?>  
            <div class="text-center text-muted py-5">  
                <i class="fas fa-users fa-3x mb-3 opacity-50"></i>  
                <p>No tienes acampantes asignados aún</p>  
            </div>  
        <?php else: ?>  
        <div class="row" id="listaAcampantes">  
            <?php foreach ($misAcampantes as $acampante):
                $consejerias = $consejeriasPorAcampante[$acampante['id']] ?? 0;
                $tieneAlergia = !empty($acampante['alergias_enfermedades']);
                $colorBadge   = $consejerias >= 3 ? 'success' : ($consejerias >= 1 ? 'warning' : 'danger');
            ?>  
            <div class="col-md-6 col-lg-4 mb-4 tarjeta-acampante"
                 data-nombre="<?php echo strtolower(htmlspecialchars($acampante['nombre'])); ?>">  
                <div class="card h-100 <?php echo $tieneAlergia ? 'border-danger' : ''; ?>">

                    <!-- Foto -->
                    <?php if (!empty($acampante['foto']) && file_exists('../' . $acampante['foto'])): ?>  
                        <img src="<?php echo htmlspecialchars('../' . $acampante['foto']); ?>"   
                             alt="<?php echo htmlspecialchars($acampante['nombre']); ?>"   
                             class="card-img-top" style="height:200px; object-fit:cover;">  
                    <?php else: ?>  
                        <div class="card-img-top bg-light text-center d-flex align-items-center
                                    justify-content-center" style="height:200px;">  
                            <i class="fas fa-user-circle fa-5x text-secondary opacity-50"></i>  
                        </div>  
                    <?php endif; ?>

                    <!-- Alerta alergia visible en tarjeta -->
                    <?php if ($tieneAlergia): ?>
                    <div class="bg-danger text-white px-3 py-1 small">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>ALERGIA:</strong>
                        <?php echo htmlspecialchars(
                            strlen($acampante['alergias_enfermedades']) > 40
                                ? substr($acampante['alergias_enfermedades'], 0, 40) . '...'
                                : $acampante['alergias_enfermedades']
                        ); ?>
                    </div>
                    <?php endif; ?>

                    <div class="card-body pb-2">

                        <!-- Nombre y sexo -->
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="card-title mb-0">
                                <?php echo htmlspecialchars($acampante['nombre']); ?>
                            </h6>
                            <span class="badge bg-<?php echo $acampante['sexo']==='masculino' ? 'primary' : 'danger'; ?> ms-1">
                                <i class="fas fa-<?php echo $acampante['sexo']==='masculino' ? 'mars' : 'venus'; ?>"></i>
                            </span>
                        </div>

                        <!-- Datos básicos -->
                        <table class="table table-borderless table-sm small mb-2">
                            <tr>
                                <td class="text-muted ps-0" style="width:30%">
                                    <i class="fas fa-church"></i>
                                </td>
                                <td class="ps-0"><?php echo htmlspecialchars($acampante['iglesia'] ?? '—'); ?></td>
                            </tr>
                            <?php if (!empty($acampante['edad'])): ?>
                            <tr>
                                <td class="text-muted ps-0">
                                    <i class="fas fa-birthday-cake"></i>
                                </td>
                                <td class="ps-0"><?php echo $acampante['edad']; ?> años</td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($acampante['primera_vez_campamento']): ?>
                            <tr>
                                <td class="text-muted ps-0">
                                    <i class="fas fa-star text-warning"></i>
                                </td>
                                <td class="ps-0">
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-star fa-xs"></i> Primera vez
                                    </span>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($acampante['contacto'])): ?>
                            <tr>
                                <td class="text-muted ps-0">
                                    <i class="fas fa-phone"></i>
                                </td>
                                <td class="ps-0"><?php echo htmlspecialchars($acampante['contacto']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>

                        <!-- Estado espiritual -->
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            <?php if ($acampante['recibio_cristo_semana']): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-cross"></i> Recibió a Cristo
                                </span>
                            <?php endif; ?>
                            <?php if ($acampante['consagro_vida_fogata']): ?>
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-fire"></i> Consagró vida
                                </span>
                            <?php endif; ?>
                            <?php if ($acampante['asiste_iglesia']): ?>
                                <span class="badge bg-info">
                                    <i class="fas fa-church"></i> Asiste iglesia
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Consejero responsable -->
                        <?php if (!empty($acampante['consejero_responsable'])): ?>
                        <div class="mb-1">
                            <span class="badge bg-warning text-dark">
                                <i class="fas fa-user-shield"></i>
                                <?php echo htmlspecialchars($acampante['consejero_responsable']); ?>
                            </span>
                        </div>
                        <?php else: ?>
                        <div class="mb-1">
                            <span class="badge bg-light text-muted border">
                                <i class="fas fa-user-shield"></i> Sin responsable asignado
                            </span>
                        </div>
                        <?php endif; ?>

                        <!-- Badge consejerías -->
                        <span class="badge bg-<?php echo $colorBadge; ?>">
                            <i class="fas fa-comments"></i>
                            <?php echo $consejerias; ?>/3 Consejerías
                        </span>
                    </div>

                    <div class="card-footer d-flex gap-2 p-2">
                        <!-- Botón ver detalle (modal) -->
                        <button type="button"
                                class="btn btn-outline-secondary btn-sm flex-grow-1"
                                data-bs-toggle="modal"
                                data-bs-target="#modalAcampante"
                                onclick="abrirModal(<?php echo htmlspecialchars(json_encode($acampante)); ?>)">
                            <i class="fas fa-eye"></i> Ver detalle
                        </button>
                        <!-- Botón consejería -->
                        <a href="consejerias.php?acampante_id=<?php echo $acampante['id']; ?>"   
                           class="btn btn-primary btn-sm flex-grow-1">  
                            <i class="fas fa-plus"></i> Consejería
                        </a>  
                    </div>
                </div>  
            </div>  
            <?php endforeach; ?>  
        </div>
        <?php endif; ?>  
    </div>  
</div>

<!-- ══════════ MODAL DETALLE ACAMPANTE ══════════ -->
<div class="modal fade" id="modalAcampante" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header" id="modalHeader">
                <h5 class="modal-title" id="modalNombre">—</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-0">
                <div class="row g-0">

                    <!-- Foto -->
                    <div class="col-md-3 bg-light d-flex align-items-center
                                justify-content-center text-center p-3">
                        <div>
                            <img id="modalFoto" src="" alt=""
                                 class="img-fluid rounded mb-2"
                                 style="max-height:180px; display:none;">
                            <i id="modalFotoIcon"
                               class="fas fa-user-circle fa-5x text-secondary opacity-50"></i>
                            <p class="small text-muted mt-2 mb-0" id="modalEdad"></p>
                            <span id="modalSexoBadge" class="badge mt-1"></span>
                        </div>
                    </div>

                    <!-- Datos -->
                    <div class="col-md-9 p-3">

                        <!-- Alerta alergia -->
                        <div id="modalAlerta" class="alert alert-danger py-2 small mb-3"
                             style="display:none;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>ALERGIA / ENFERMEDAD:</strong>
                            <span id="modalAlergiaTexto"></span>
                        </div>

                        <div class="row g-3">

                            <!-- Datos personales -->
                            <div class="col-sm-6">
                                <h6 class="border-bottom pb-1 mb-2 text-primary">
                                    <i class="fas fa-user"></i> Datos Personales
                                </h6>
                                <table class="table table-borderless table-sm small mb-0">
                                    <tr>
                                        <td class="text-muted">Iglesia</td>
                                        <td id="modalIglesia">—</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><?php echo PAIS_DIVISION; ?></td>
                                        <td id="modalEstado">—</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Contacto</td>
                                        <td id="modalContacto">—</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Campamento</td>
                                        <td id="modalPrimeraVez">—</td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Observaciones -->
                            <div class="col-12" id="modalObsContainer" style="display:none;">
                                <h6 class="border-bottom pb-1 mb-2 text-secondary">
                                    <i class="fas fa-sticky-note"></i> Observaciones
                                </h6>
                                <p class="small mb-0" id="modalObs"></p>
                            </div>

                            <!-- Estado espiritual -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-1 mb-2 text-success">
                                    <i class="fas fa-cross"></i> Estado Espiritual
                                </h6>
                                <div id="modalEspiritual" class="d-flex flex-wrap gap-1"></div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i> Cerrar
                </button>
                <a id="modalBtnConsejeria" href="#" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Nueva Consejería
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// ── Búsqueda en tiempo real ───────────────────────────────────
document.getElementById('buscarAcampante')?.addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.tarjeta-acampante').forEach(card => {
        card.style.display = card.dataset.nombre.includes(q) ? '' : 'none';
    });
});

// ── Abrir modal con datos del acampante ───────────────────────
function abrirModal(a) {
    // Nombre y header
    document.getElementById('modalNombre').textContent = a.nombre ?? '—';

    const header = document.getElementById('modalHeader');
    header.className = 'modal-header bg-' +
        (a.sexo === 'masculino' ? 'primary' : 'danger') + ' text-white';

    // Sexo badge
    const sexoBadge = document.getElementById('modalSexoBadge');
    sexoBadge.className = 'badge bg-' + (a.sexo === 'masculino' ? 'light text-primary' : 'light text-danger') + ' mt-1';
    sexoBadge.innerHTML = '<i class="fas fa-' + (a.sexo === 'masculino' ? 'mars' : 'venus') + '"></i> ' +
        (a.sexo ? a.sexo.charAt(0).toUpperCase() + a.sexo.slice(1) : '—');

    // Foto
    const fotoEl   = document.getElementById('modalFoto');
    const iconEl   = document.getElementById('modalFotoIcon');
    if (a.foto) {
        fotoEl.src          = '../' + a.foto;
        fotoEl.style.display = 'block';
        iconEl.style.display = 'none';
    } else {
        fotoEl.style.display = 'none';
        iconEl.style.display = 'block';
    }

    // Edad
    document.getElementById('modalEdad').textContent =
        a.edad ? a.edad + ' años' : '';

    // Datos personales
    document.getElementById('modalIglesia').textContent  = a.iglesia  || '—';
    document.getElementById('modalEstado').textContent   = a.estado_origen || '—';
    document.getElementById('modalContacto').textContent = a.contacto || '—';
    
    document.getElementById('modalPrimeraVez').innerHTML =
        a.primera_vez_campamento == 1
            ? '<span class="badge bg-warning text-dark"><i class="fas fa-star fa-xs"></i> Primera vez</span>'
            : '<span class="text-muted small">Ya ha asistido</span>';

    // Alerta alergia
    const alertaEl = document.getElementById('modalAlerta');
    if (a.alergias_enfermedades) {
        document.getElementById('modalAlergiaTexto').textContent = a.alergias_enfermedades;
        alertaEl.style.display = 'block';
    } else {
        alertaEl.style.display = 'none';
    }

    // Observaciones
    const obsContainer = document.getElementById('modalObsContainer');
    if (a.observaciones) {
        document.getElementById('modalObs').textContent = a.observaciones;
        obsContainer.style.display = 'block';
    } else {
        obsContainer.style.display = 'none';
    }

    // Estado espiritual
    const espEl = document.getElementById('modalEspiritual');
    let espHtml = '';
    if (a.primera_vez_campamento == 1) espHtml += '<span class="badge bg-warning text-dark"><i class="fas fa-star"></i> Primera vez en campamento</span>';
    if (a.recibio_cristo_semana == 1) espHtml += '<span class="badge bg-success"><i class="fas fa-cross"></i> Recibió a Cristo</span>';
    if (a.consagro_vida_fogata  == 1) espHtml += '<span class="badge bg-warning text-dark"><i class="fas fa-fire"></i> Consagró vida</span>';
    if (a.era_creyente_antes    == 1) espHtml += '<span class="badge bg-primary"><i class="fas fa-bible"></i> Era creyente</span>';
    if (a.asiste_iglesia        == 1) espHtml += '<span class="badge bg-info"><i class="fas fa-church"></i> Asiste a iglesia</span>';
    espEl.innerHTML = espHtml || '<em class="text-muted small">Sin registro espiritual</em>';

    // Botón consejería
    document.getElementById('modalBtnConsejeria').href =
        'consejerias.php?acampante_id=' + a.id;
}
</script>

<?php include '../includes/footer.php'; ?>