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

// ── Cargar consejeros asignados a esta cabaña (semana activa) ──
$consejeros_cabana = [];
$consejero_principal_cabana = null;
try {
    if ($semana_id_activa) {
        $stmt_cons = $pdo->prepare("SELECT nombre_consejero, rol
                                    FROM consejeros_semana
                                    WHERE cabana_id = ? AND semana_id = ?
                                    AND rol IN ('principal','asistente')
                                    ORDER BY 
                                        CASE rol
                                            WHEN 'principal' THEN 1
                                            WHEN 'asistente' THEN 2
                                        END");
        $stmt_cons->execute([$cabana_id, $semana_id_activa]);
        $consejeros_cabana = $stmt_cons->fetchAll();

        foreach ($consejeros_cabana as $c) {
            if ($c['rol'] === 'principal') {
                $consejero_principal_cabana = $c['nombre_consejero'];
                break;
            }
        }
    }
} catch (Exception $e) {
    $consejeros_cabana = [];
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

                        <!-- Consejero responsable (asignable desde aquí) -->
                        <?php
                        $responsable_actual = $acampante['consejero_responsable'] ?? '';
                        $tiene_responsable  = !empty($responsable_actual);
                        ?>
                        <div class="mb-2 p-2 rounded border
                             <?php echo $tiene_responsable ? 'border-warning bg-warning bg-opacity-10' : 'border-light bg-light'; ?>">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <small class="fw-bold text-muted" style="font-size:11px;">
                                    <i class="fas fa-user-shield"></i> Responsable
                                </small>
                                <button type="button"
                                        class="btn btn-sm btn-link p-0 text-decoration-none"
                                        onclick="toggleSelectorResponsable(<?php echo $acampante['id']; ?>)"
                                        title="Cambiar responsable">
                                    <i class="fas fa-edit text-primary" style="font-size:11px;"></i>
                                </button>
                            </div>
                        
                            <!-- Nombre visible del responsable -->
                            <div id="respLabel_<?php echo $acampante['id']; ?>"
                                 class="small fw-semibold <?php echo $tiene_responsable ? 'text-dark' : 'text-muted'; ?>">
                                <?php echo $tiene_responsable
                                    ? htmlspecialchars($responsable_actual)
                                    : '<em>Sin asignar</em>'; ?>
                            </div>
                        
                            <!-- Selector desplegable (oculto por defecto) -->
                            <div id="respSelector_<?php echo $acampante['id']; ?>" class="mt-2" style="display:none;">
                                <?php if (!empty($consejeros_cabana)): ?>
                                <div class="d-flex flex-wrap gap-1 mb-2">
                                    <?php foreach ($consejeros_cabana as $cons):
                                        $rolLabel = $cons['rol'] === 'principal' ? 'Principal' : 'Asistente';
                                        $esActual = $responsable_actual === $cons['nombre_consejero'];
                                    ?>
                                    <button type="button"
                                            class="btn btn-sm <?php echo $esActual ? 'btn-warning' : 'btn-outline-secondary'; ?>"
                                            style="font-size:11px; padding:2px 8px;"
                                            onclick="asignarResponsable(<?php echo $acampante['id']; ?>,
                                                '<?php echo htmlspecialchars($cons['nombre_consejero'], ENT_QUOTES); ?>')">
                                        <?php echo htmlspecialchars($cons['nombre_consejero']); ?>
                                        <span class="badge bg-<?php echo $cons['rol']==='principal'?'primary':'secondary'; ?>"
                                              style="font-size:8px;"><?php echo $rolLabel; ?></span>
                                    </button>
                                    <?php endforeach; ?>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            style="font-size:11px; padding:2px 8px;"
                                            onclick="asignarResponsable(<?php echo $acampante['id']; ?>, '')"
                                            title="Quitar responsable">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                        
                                <!-- Campo libre por si no está en la lista -->
                                <div class="input-group input-group-sm">
                                    <input type="text"
                                           class="form-control form-control-sm"
                                           id="respInput_<?php echo $acampante['id']; ?>"
                                           placeholder="Escribir nombre..."
                                           value="<?php echo htmlspecialchars($responsable_actual); ?>"
                                           style="font-size:12px;">
                                    <button type="button"
                                            class="btn btn-success btn-sm"
                                            onclick="guardarResponsableInput(<?php echo $acampante['id']; ?>)">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Badge consejerías -->
                        <span class="badge bg-<?php echo $colorBadge; ?>">
                            <i class="fas fa-comments"></i>
                            <?php echo $consejerias; ?>/3 Consejerías
                        </span>
                    </div>
                    
                    <div class="card-footer p-2">
                        <!-- Fila 1: ver detalle + consejería -->
                        <div class="d-flex gap-2 mb-2">
                            <button type="button"
                                    class="btn btn-outline-secondary btn-sm flex-grow-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalAcampante"
                                    onclick="abrirModal(<?php echo htmlspecialchars(json_encode($acampante)); ?>)">
                                <i class="fas fa-eye"></i> Detalle
                            </button>
                            <a href="consejerias.php?acampante_id=<?php echo $acampante['id']; ?>"
                               class="btn btn-primary btn-sm flex-grow-1">
                                <i class="fas fa-plus"></i> Consejería
                            </a>
                        </div>
                    
                        <!-- Fila 2: botón toggle apoyo hora silenciosa -->
                        <button type="button"
                                id="btnApoyo_<?php echo $acampante['id']; ?>"
                                class="btn btn-sm w-100
                                    <?php echo $acampante['necesita_apoyo_silenciosa']
                                        ? 'btn-warning' : 'btn-outline-warning'; ?>"
                                onclick="toggleApoyoSilenciosa(<?php echo $acampante['id']; ?>)">
                            <i class="fas
                                <?php echo $acampante['necesita_apoyo_silenciosa'] ? 'fa-bell' : 'fa-bell-slash'; ?>"></i>
                            <span id="textoApoyo_<?php echo $acampante['id']; ?>">
                                <?php echo $acampante['necesita_apoyo_silenciosa']
                                    ? 'Cancelar solicitud de apoyo'
                                    : 'Pedir apoyo (hora silenciosa)'; ?>
                            </span>
                        </button>
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

// ── Toggle apoyo en hora silenciosa ────────────────────────────
function toggleApoyoSilenciosa(acampanteId) {
    const btn   = document.getElementById('btnApoyo_' + acampanteId);
    const texto = document.getElementById('textoApoyo_' + acampanteId);
    if (!btn) return;

    // Estado visual original para revertir si falla
    const eraActivo = btn.classList.contains('btn-warning');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';

    fetch('toggle_apoyo_silenciosa.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'acampante_id=' + acampanteId
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            const activo = data.estado === 1;
            // Actualizar botón
            btn.classList.remove('btn-warning', 'btn-outline-warning');
            btn.classList.add(activo ? 'btn-warning' : 'btn-outline-warning');
            btn.innerHTML = (activo
                ? '<i class="fas fa-bell"></i> <span>Cancelar solicitud de apoyo</span>'
                : '<i class="fas fa-bell-slash"></i> <span>Pedir apoyo (hora silenciosa)</span>'
            );

            // Toast de confirmación
            mostrarToast(activo
                ? 'Solicitud de apoyo enviada para ' + data.acampante
                : 'Solicitud cancelada para ' + data.acampante,
                activo ? 'warning' : 'success');
        } else {
            alert('Error: ' + (data.error || 'desconocido'));
            // Revertir
            btn.classList.remove('btn-warning', 'btn-outline-warning');
            btn.classList.add(eraActivo ? 'btn-warning' : 'btn-outline-warning');
            btn.innerHTML = eraActivo
                ? '<i class="fas fa-bell"></i> <span>Cancelar solicitud de apoyo</span>'
                : '<i class="fas fa-bell-slash"></i> <span>Pedir apoyo (hora silenciosa)</span>';
        }
    })
    .catch(err => {
        alert('Error de conexión: ' + err.message);
        btn.classList.remove('btn-warning', 'btn-outline-warning');
        btn.classList.add(eraActivo ? 'btn-warning' : 'btn-outline-warning');
        btn.innerHTML = eraActivo
            ? '<i class="fas fa-bell"></i> <span>Cancelar solicitud de apoyo</span>'
            : '<i class="fas fa-bell-slash"></i> <span>Pedir apoyo (hora silenciosa)</span>';
    })
    .finally(() => {
        btn.disabled = false;
    });
}

// ── Toast temporal ─────────────────────────────────────────────
function mostrarToast(mensaje, tipo = 'success') {
    const toast = document.createElement('div');
    toast.className = 'position-fixed bottom-0 end-0 m-3 alert alert-' + tipo
                      + ' py-2 px-3 shadow';
    toast.style.zIndex = 9999;
    toast.innerHTML = '<i class="fas fa-check-circle"></i> ' + mensaje;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// ── Asignar consejero responsable desde la tarjeta ────────────
function toggleSelectorResponsable(acampanteId) {
    const sel = document.getElementById('respSelector_' + acampanteId);
    if (sel) sel.style.display = (sel.style.display === 'none') ? 'block' : 'none';
}

function asignarResponsable(acampanteId, nombre) {
    guardarResponsable(acampanteId, nombre);
}

function guardarResponsableInput(acampanteId) {
    const input = document.getElementById('respInput_' + acampanteId);
    if (!input) return;
    guardarResponsable(acampanteId, input.value.trim());
}

function guardarResponsable(acampanteId, nombre) {
    fetch('asignar_responsable.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'acampante_id=' + acampanteId + '&consejero_responsable=' + encodeURIComponent(nombre)
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            // Actualizar etiqueta visible
            const label = document.getElementById('respLabel_' + acampanteId);
            if (label) {
                if (data.responsable) {
                    label.innerHTML = htmlspecialchars(data.responsable);
                    label.className = 'small fw-semibold text-dark';
                } else {
                    label.innerHTML = '<em>Sin asignar</em>';
                    label.className = 'small fw-semibold text-muted';
                }
            }

            // Actualizar botones de la lista
            document.querySelectorAll('#respSelector_' + acampanteId + ' .btn').forEach(btn => {
                // Saltar el botón de guardar del input
                if (btn.classList.contains('btn-success')) return;
            });

            // Actualizar borde del contenedor
            const cont = label ? label.closest('.border') : null;
            if (cont) {
                if (data.responsable) {
                    cont.className = 'mb-2 p-2 rounded border border-warning bg-warning bg-opacity-10';
                } else {
                    cont.className = 'mb-2 p-2 rounded border border-light bg-light';
                }
            }

            // Ocultar selector
            const sel = document.getElementById('respSelector_' + acampanteId);
            if (sel) sel.style.display = 'none';

            // Toast
            mostrarToast(
                data.responsable
                    ? 'Responsable asignado: ' + data.responsable
                    : 'Responsable quitado para ' + data.acampante,
                data.responsable ? 'success' : 'warning'
            );
        } else {
            alert('Error: ' + (data.error || 'desconocido'));
        }
    })
    .catch(err => alert('Error de conexión: ' + err.message));
}

// Helper para escapar HTML en JS
function htmlspecialchars(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}
</script>

<?php include '../includes/footer.php'; ?>