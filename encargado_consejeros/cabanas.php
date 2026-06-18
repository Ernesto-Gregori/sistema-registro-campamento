<?php  
require_once '../config/database.php';  
require_once '../includes/functions.php';  
  
verificarLogin();  
if (!esEncargadoConsejeros()) { 
    header('Location: ../consejero/dashboard.php');  
    exit();  
}  
  
$titulo  = "Gestión de Cabañas";  
$action  = $_GET['action'] ?? 'list';  
$id      = $_GET['id'] ?? null;  
$message = '';  
$error   = '';  

// ── Procesar acciones ──────────────────────────────────────────
if ($_POST) {    
    try {    
        if ($action === 'change_password') {    
            $nueva_password     = $_POST['nueva_password'];    
            $confirmar_password = $_POST['confirmar_password'];    
            if ($nueva_password !== $confirmar_password)    
                throw new Exception("Las contraseñas no coinciden");    
            if (strlen($nueva_password) < 6)    
                throw new Exception("La contraseña debe tener al menos 6 caracteres");    
            $password_hash = hashPassword($nueva_password);    
            $stmt = $pdo->prepare("UPDATE cabanas SET password_cabana = ? WHERE id = ?");    
            $stmt->execute([$password_hash, $id]);    
            $message = "Contraseña actualizada exitosamente";    
            header("Location: cabanas.php?message=" . urlencode($message));    
            exit();    
                
        } elseif ($action === 'add' || $action === 'edit') {
            $nombre_cabana    = limpiarDatos($_POST['nombre_cabana']);    
            $capacidad_maxima = (int)$_POST['capacidad_maxima'];    
            $genero           = $_POST['genero'] ?? null;    
            $equipo           = !empty($_POST['equipo']) ? $_POST['equipo'] : null;
                
            if (empty($nombre_cabana))    
                throw new Exception("El nombre de la cabaña es obligatorio");    
            if ($capacidad_maxima < 1)    
                throw new Exception("La capacidad debe ser al menos 1");    
            if (!in_array($genero, ['masculino', 'femenino']))    
                throw new Exception("Debes seleccionar el género de la cabaña");    
            if ($equipo && !in_array($equipo, ['verde', 'azul']))    
                throw new Exception("Equipo inválido");    
                
            if ($action === 'add') {    
                $password           = $_POST['password_cabana'];    
                $confirmar_password = $_POST['confirmar_password'] ?? $_POST['password_cabana'];    
                if (empty($password))    
                    throw new Exception("La contraseña es obligatoria");    
                if (strlen($password) < 6)    
                    throw new Exception("La contraseña debe tener al menos 6 caracteres");    
                $password_hash = hashPassword($password);    
                $stmt = $pdo->prepare("SELECT id FROM cabanas WHERE nombre_cabana = ?");    
                $stmt->execute([$nombre_cabana]);    
                if ($stmt->fetch())    
                    throw new Exception("Ya existe una cabaña con ese nombre");    
                $stmt = $pdo->prepare("INSERT INTO cabanas    
                    (nombre_cabana, capacidad_maxima, genero, equipo,
                     password_cabana, activa)    
                    VALUES (?, ?, ?, ?, ?, 1)");    
                $stmt->execute([
                    $nombre_cabana, $capacidad_maxima, $genero, $equipo,
                    $password_hash
                ]);   
                $message = "Cabaña creada exitosamente";    
                    
            } else {
                if (!empty($_POST['password_cabana'])) {
                    $password = $_POST['password_cabana'];    
                    if (strlen($password) < 6)    
                        throw new Exception("La contraseña debe tener al menos 6 caracteres");    
                    $password_hash = hashPassword($password);    
                    $stmt = $pdo->prepare("UPDATE cabanas    
                        SET nombre_cabana=?, capacidad_maxima=?, genero=?, equipo=?,
                            password_cabana=?
                        WHERE id=?");    
                    $stmt->execute([
                        $nombre_cabana, $capacidad_maxima, $genero, $equipo,
                        $password_hash, $id
                    ]);
                } else {
                    $stmt = $pdo->prepare("UPDATE cabanas    
                        SET nombre_cabana=?, capacidad_maxima=?, genero=?, equipo=?
                        WHERE id=?");    
                    $stmt->execute([
                        $nombre_cabana, $capacidad_maxima, $genero, $equipo,
                        $id
                    ]);
                }
                $message = "Cabaña actualizada exitosamente";    
            }    
            header("Location: cabanas.php?message=" . urlencode($message));    
            exit();    
        }    
    } catch (Exception $e) {    
        $error = "Error: " . $e->getMessage();    
    }    
}    
  
// ── Toggle estado ──────────────────────────────────────────────
if ($action === 'toggle' && $id) {  
    try {  
        $stmt = $pdo->prepare("UPDATE cabanas SET activa = NOT activa WHERE id = ?");  
        $stmt->execute([$id]);  
        header("Location: cabanas.php?message=" . urlencode("Estado de cabaña actualizado"));  
        exit();  
    } catch (Exception $e) {  
        $error = "Error al cambiar estado: " . $e->getMessage();  
    }  
}
  
// ── Datos para editar ──────────────────────────────────────────
$cabana = null;  
if ($action === 'edit' && $id) {  
    $stmt = $pdo->prepare("SELECT * FROM cabanas WHERE id = ?");  
    $stmt->execute([$id]);  
    $cabana = $stmt->fetch();  
}  
  
// ── Lista de cabañas ───────────────────────────────────────────
$semana_activa    = null;
$semana_id_activa = null;

if ($action === 'list') {
    $stmt_semana      = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
    $semana_activa    = $stmt_semana->fetch();
    $semana_id_activa = $semana_activa['id'] ?? null;

    if ($semana_id_activa) {
        $stmt = $pdo->prepare("SELECT c.*,
                               COUNT(a.id) as acampantes_activos
                               FROM cabanas c
                               LEFT JOIN acampantes a ON c.id = a.cabana_id
                                   AND a.semana_id = ? AND a.estado = 'activo'
                               GROUP BY c.id
                               ORDER BY c.equipo, c.nombre_cabana");
        $stmt->execute([$semana_id_activa]);
    } else {
        $stmt = $pdo->prepare("SELECT c.*,
                               COUNT(a.id) as acampantes_activos
                               FROM cabanas c
                               LEFT JOIN acampantes a ON c.id = a.cabana_id
                                   AND a.year_campamento = ? AND a.estado = 'activo'
                               GROUP BY c.id
                               ORDER BY c.equipo, c.nombre_cabana");
        $stmt->execute([obtenerAnioCampamento()]);
    }
    $cabanas = $stmt->fetchAll();

    // ── Cargar consejeros de consejeros_semana (si existe la tabla) ──
    $consejeros_semana_data = []; // [cabana_id][rol] = nombre
    $capitanes_semana       = []; // [equipo][rol]    = nombre

    if ($semana_id_activa) {
        try {
            $stmt_cs = $pdo->prepare("SELECT cs.cabana_id, cs.rol, cs.nombre_consejero, c.equipo
                                      FROM consejeros_semana cs
                                      JOIN cabanas c ON cs.cabana_id = c.id
                                      WHERE cs.semana_id = ?");
            $stmt_cs->execute([$semana_id_activa]);
            foreach ($stmt_cs->fetchAll() as $row) {
                if (in_array($row['rol'], ['capitan', 'capitana'])) {
                    $capitanes_semana[$row['equipo']][$row['rol']] = $row['nombre_consejero'];
                } else {
                    $consejeros_semana_data[$row['cabana_id']][$row['rol']] = $row['nombre_consejero'];
                }
            }
        } catch (Exception $e) {
            // Tabla aún no existe — ignorar silenciosamente
        }
    }

    // Agrupar por equipo
    $porEquipo = [];
    foreach ($cabanas as $cab) {
        $eq = $cab['equipo'] ?? 'sin_equipo';
        $porEquipo[$eq][] = $cab;
    }
}
// ── Config de equipos — siempre disponible ────────────────────
$equipos_config = obtenerEquipos($pdo);

if (isset($_GET['message'])) $message = $_GET['message'];  
  
include '../includes/header.php';
?>  
  
<div class="row mb-4">  
    <div class="col-12">  
        <h1><i class="fas fa-home"></i> <?php echo $titulo; ?></h1>  
        <nav aria-label="breadcrumb">  
            <ol class="breadcrumb">  
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>  
                <li class="breadcrumb-item active">Cabañas</li>  
            </ol>  
        </nav>  
    </div>  
</div>  
  
<?php if ($message): ?>  
<div class="alert alert-success alert-dismissible fade show">  
    <i class="fas fa-check"></i> <?php echo $message; ?>  
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>  
</div>  
<?php endif; ?>  
<?php if ($error): ?>  
<div class="alert alert-danger alert-dismissible fade show">  
    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>  
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>  
</div>  
<?php endif; ?>  
  
<?php if ($action === 'list'): ?>  

<!-- Banner semana -->
<?php if ($semana_activa): ?>
<div class="alert alert-success border-0 mb-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <i class="fas fa-broadcast-tower fa-2x text-success"></i>
            <div>
                <h5 class="mb-0">
                    <span class="badge bg-success me-2">ACTIVA</span>
                    <?php echo htmlspecialchars($semana_activa['nombre']); ?>
                </h5>
                <small class="text-muted">
                    <?php
                    $tipos = ['mayores'=>'Mayores','ninos'=>'Niños','adolescentes'=>'Adolescentes'];
                    echo $tipos[$semana_activa['tipo_acampante']] ?? $semana_activa['tipo_acampante'];
                    ?> |
                    <?php echo date('d/m/Y', strtotime($semana_activa['fecha_inicio'])); ?> -
                    <?php echo date('d/m/Y', strtotime($semana_activa['fecha_fin'])); ?>
                </small>
            </div>
        </div>
        <a href="semanas.php" class="btn btn-outline-success btn-sm">
            <i class="fas fa-calendar-week"></i> Gestionar Semanas
        </a>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning border-0 mb-4">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Sin semana activa.</strong>
    <a href="semanas.php" class="btn btn-warning btn-sm ms-2">
        <i class="fas fa-play"></i> Activar Semana
    </a>
</div>
<?php endif; ?>

<!-- ══ RESUMEN POR EQUIPOS ══ -->
<?php if (!empty($porEquipo)): ?>
<div class="row mb-4">
    <?php foreach ($porEquipo as $equipo => $cabanas_equipo):
        $eqData  = $equipos_config[$equipo] ?? null;
        $hexEq   = $eqData['color_hex'] ?? '#6c757d';
        $emojiEq = $eqData['emoji']     ?? '⚪';
        $labelEq = $equipo === 'sin_equipo' ? 'Sin equipo' : ($eqData['nombre'] ?? ucfirst($equipo));
        $masc    = array_filter($cabanas_equipo, fn($c) => $c['genero'] === 'masculino');
        $fem     = array_filter($cabanas_equipo, fn($c) => $c['genero'] === 'femenino');
        $totalAcampantes = array_sum(array_column($cabanas_equipo, 'acampantes_activos'));
        $totalCapacidad  = array_sum(array_column($cabanas_equipo, 'capacidad_maxima'));
        $pct = $totalCapacidad > 0 ? round(($totalAcampantes / $totalCapacidad) * 100) : 0;
        $capitan_eq  = $capitanes_semana[$equipo]['capitan']  ?? null;
        $capitana_eq = $capitanes_semana[$equipo]['capitana'] ?? null;
    ?>
    <div class="col-md-6 mb-3">
        <div class="card h-100" style="border: 2px solid <?php echo $hexEq; ?>;">
            <div class="card-header text-white d-flex justify-content-between align-items-center"
                 style="background-color: <?php echo $hexEq; ?>;">
                <h5 class="mb-0"><?php echo $emojiEq; ?> <?php echo $labelEq; ?></h5>
                <span class="badge bg-white" style="color: <?php echo $hexEq; ?>;">
                    <?php echo $totalAcampantes; ?>/<?php echo $totalCapacidad; ?> acampantes
                </span>
            </div>
            <div class="card-body pb-2">

                <!-- Capitán y Capitana del equipo -->
                <?php if ($capitan_eq || $capitana_eq): ?>
                <div class="alert alert-warning py-2 mb-3">
                    <i class="fas fa-star text-warning"></i>
                    <?php if ($capitan_eq): ?>
                        <strong>Capitán:</strong>
                        <?php echo htmlspecialchars($capitan_eq); ?>
                    <?php endif; ?>
                    <?php if ($capitan_eq && $capitana_eq): ?>
                        &nbsp;|&nbsp;
                    <?php endif; ?>
                    <?php if ($capitana_eq): ?>
                        <strong>Capitana:</strong>
                        <?php echo htmlspecialchars($capitana_eq); ?>
                    <?php endif; ?>
                </div>
                <?php elseif ($equipo !== 'sin_equipo'): ?>
                <div class="alert alert-light py-2 mb-3 border">
                    <small class="text-muted">
                        <i class="fas fa-star"></i>
                        Sin capitán/a asignado —
                        <a href="consejeros_semana.php<?php echo $semana_id_activa ? '?semana_id='.$semana_id_activa : ''; ?>"
                           class="text-decoration-none">Asignar</a>
                    </small>
                </div>
                <?php endif; ?>

                <div class="progress mb-1" style="height:8px;">
                    <div class="progress-bar"
                         style="width:<?php echo $pct; ?>%;
                                background-color:<?php echo $pct>=90?'#dc3545':($pct>=70?'#ffc107':$hexEq); ?>;">
                    </div>
                </div>
                <small class="text-muted d-block mb-3"><?php echo $pct; ?>% de ocupación</small>

                <!-- Cabañas por género -->
                <div class="row g-2">
                    <?php foreach ([['masc', $masc, 'primary', 'mars', 'Masculino'],
                                    ['fem',  $fem,  'danger',  'venus', 'Femenino']] as [$key, $lista, $color, $icon, $label]):
                        if (empty($lista)) continue;
                    ?>
                    <div class="col-12">
                        <small class="text-<?php echo $color; ?> fw-bold d-block mb-1">
                            <i class="fas fa-<?php echo $icon; ?>"></i> <?php echo $label; ?>
                        </small>
                        <?php foreach ($lista as $c):
                            $cp_sem  = $consejeros_semana_data[$c['id']]['principal'] ?? $c['consejero_principal'] ?? null;
                            $ca_sem  = $consejeros_semana_data[$c['id']]['asistente'] ?? $c['consejero_asistente'] ?? null;
                            $pctC    = $c['capacidad_maxima'] > 0
                                ? round(($c['acampantes_activos'] / $c['capacidad_maxima']) * 100) : 0;
                        ?>
                        <div class="card border-<?php echo $color; ?> mb-2">
                            <div class="card-body py-2 px-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="small">
                                        <?php echo htmlspecialchars($c['nombre_cabana']); ?>
                                    </strong>
                                    <span class="badge bg-<?php echo $color; ?>">
                                        <?php echo $c['acampantes_activos']; ?>/<?php echo $c['capacidad_maxima']; ?>
                                    </span>
                                </div>
                                <div class="progress mb-1" style="height:4px;">
                                    <div class="progress-bar bg-<?php echo $pctC>=90?'danger':($pctC>=70?'warning':$color); ?>"
                                         style="width:<?php echo $pctC; ?>%"></div>
                                </div>
                                <div class="small mt-1">
                                    <?php if ($cp_sem): ?>
                                    <div>
                                        <i class="fas fa-user text-<?php echo $color; ?>"></i>
                                        <strong>Principal:</strong>
                                        <?php echo htmlspecialchars($cp_sem); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($ca_sem): ?>
                                    <div class="text-muted">
                                        <i class="fas fa-user-friends"></i>
                                        <strong>Asistente:</strong>
                                        <?php echo htmlspecialchars($ca_sem); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!$cp_sem && !$ca_sem): ?>
                                    <em class="text-muted">Sin consejeros asignados</em>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ LISTA COMPLETA DE CABAÑAS ══ -->
<div class="card">  
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">  
        <h5 class="mb-0">
            <i class="fas fa-list"></i> Todas las Cabañas (<?php echo count($cabanas); ?>)
            <?php if ($semana_activa): ?>
            <small class="text-muted fs-6"> — <?php echo htmlspecialchars($semana_activa['nombre']); ?></small>
            <?php endif; ?>
        </h5>
        <div class="d-flex gap-2 flex-wrap">
            <a href="equipos.php" class="btn btn-outline-dark">
                <i class="fas fa-palette"></i> Configurar Equipos
            </a>
            <a href="consejeros_semana.php<?php echo $semana_id_activa ? '?semana_id='.$semana_id_activa : ''; ?>"
               class="btn btn-warning">
                <i class="fas fa-users-cog"></i> Asignar Consejeros
            </a>
            <a href="cabanas.php?action=add" class="btn btn-success">
                <i class="fas fa-plus"></i> Nueva Cabaña
            </a>
        </div>
    </div>
    <div class="card-body">  
        <div class="row">  
            <?php foreach ($cabanas as $cab):
                $cp_sem  = $consejeros_semana_data[$cab['id']]['principal'] ?? $cab['consejero_principal'] ?? null;
                $ca_sem  = $consejeros_semana_data[$cab['id']]['asistente'] ?? $cab['consejero_asistente'] ?? null;
                $pctCab  = $cab['capacidad_maxima'] > 0
                    ? ($cab['acampantes_activos'] / $cab['capacidad_maxima']) * 100 : 0;
                $colorBarra = $pctCab > 90 ? 'bg-danger' : ($pctCab > 70 ? 'bg-warning' : 'bg-success');
                $eqCabData  = !empty($cab['equipo']) ? ($equipos_config[$cab['equipo']] ?? null) : null;
                $hexEqCab   = $eqCabData['color_hex'] ?? '#6c757d';
                $emojiEqCab = $eqCabData['emoji']     ?? '⚪';
                $nombreEqCab = $eqCabData['nombre']   ?? ucfirst($cab['equipo'] ?? '');
                $cap_eq  = isset($cab['equipo']) ? ($capitanes_semana[$cab['equipo']]['capitan']  ?? null) : null;
                $capa_eq = isset($cab['equipo']) ? ($capitanes_semana[$cab['equipo']]['capitana'] ?? null) : null;
            ?>  
            <div class="col-md-6 col-lg-4 mb-4">  
                <div class="card h-100 <?php echo $cab['activa'] ? 'border-success' : 'border-secondary'; ?>">  
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                            <h6 class="mb-0">
                                <i class="fas fa-home"></i>
                                <?php echo htmlspecialchars($cab['nombre_cabana']); ?>
                            </h6>
                            <div class="d-flex gap-1 flex-wrap">
                                <span class="badge bg-<?php echo $cab['genero']==='masculino'?'primary':'danger'; ?>">
                                    <i class="fas fa-<?php echo $cab['genero']==='masculino'?'mars':'venus'; ?>"></i>
                                    <?php echo ucfirst($cab['genero']); ?>
                                </span>
                                <?php if (!empty($cab['equipo'])): ?>
                                <span class="badge" style="background-color: <?php echo $hexEqCab; ?>;">
                                    <?php echo $emojiEqCab; ?>
                                    <?php echo htmlspecialchars($nombreEqCab); ?>
                                </span>
                                <?php endif; ?>
                                <span class="badge <?php echo $cab['activa']?'bg-success':'bg-secondary'; ?>">
                                    <?php echo $cab['activa']?'Activa':'Inactiva'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Ocupación -->
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted">Acampantes</small>
                            <strong><?php echo $cab['acampantes_activos']; ?>/<?php echo $cab['capacidad_maxima']; ?></strong>
                        </div>
                        <div class="progress mb-3" style="height:8px;">
                            <div class="progress-bar <?php echo $colorBarra; ?>"
                                 style="width:<?php echo min(100,$pctCab); ?>%"></div>
                        </div>

                        <!-- Consejeros -->
                        <div class="border rounded p-2 bg-light">
                            <small class="text-muted fw-bold d-block mb-1">
                                <i class="fas fa-users"></i> Consejeros
                                <?php if ($semana_activa): ?>
                                <span class="text-success">— <?php echo htmlspecialchars($semana_activa['nombre']); ?></span>
                                <?php endif; ?>
                            </small>
                            <?php if ($cap_eq || $capa_eq): ?>
                            <div class="d-flex align-items-center gap-1 mb-1 flex-wrap">
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-star"></i> Capitán/a
                                </span>
                                <small>
                                    <?php if ($cap_eq): ?>
                                        <?php echo htmlspecialchars($cap_eq); ?>
                                    <?php endif; ?>
                                    <?php if ($cap_eq && $capa_eq): ?> / <?php endif; ?>
                                    <?php if ($capa_eq): ?>
                                        <?php echo htmlspecialchars($capa_eq); ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            <?php if ($cp_sem): ?>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-<?php echo $cab['genero']==='masculino'?'primary':'danger'; ?>">
                                    <i class="fas fa-user"></i> Principal
                                </span>
                                <small><?php echo htmlspecialchars($cp_sem); ?></small>
                            </div>
                            <?php endif; ?>
                            <?php if ($ca_sem): ?>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-secondary">
                                    <i class="fas fa-user-friends"></i> Asistente
                                </span>
                                <small><?php echo htmlspecialchars($ca_sem); ?></small>
                            </div>
                            <?php endif; ?>
                            <?php if (!$cp_sem && !$ca_sem && !$cap_eq && !$capa_eq): ?>
                            <a href="consejeros_semana.php<?php echo $semana_id_activa ? '?semana_id='.$semana_id_activa : ''; ?>"
                               class="text-muted small text-decoration-none">
                                <i class="fas fa-plus-circle"></i> Asignar consejeros
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="btn-group w-100" role="group">
                            <a href="cabanas.php?action=edit&id=<?php echo $cab['id']; ?>"
                               class="btn btn-sm btn-outline-primary" title="Editar cabaña">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="cabanas.php?action=toggle&id=<?php echo $cab['id']; ?>"
                               class="btn btn-sm btn-outline-<?php echo $cab['activa']?'warning':'success'; ?>"
                               title="<?php echo $cab['activa']?'Desactivar':'Activar'; ?>"
                               onclick="return confirm('¿Cambiar estado de esta cabaña?')">
                                <i class="fas fa-<?php echo $cab['activa']?'pause':'play'; ?>"></i>
                            </a>
                            <a href="../encargado_consejeros/acampantes.php?cabana=<?php echo $cab['id']; ?><?php echo $semana_id_activa ? '&semana_id='.$semana_id_activa : ''; ?>"
                               class="btn btn-sm btn-outline-info" title="Ver acampantes">
                                <i class="fas fa-users"></i>
                            </a>
                        </div>
                    </div>
                </div>  
            </div>  
            <?php endforeach; ?>  
        </div>  
    </div>  
</div>  
  
<?php elseif ($action === 'add' || $action === 'edit'): ?>

<!-- ══ FORMULARIO ADD / EDIT ══ -->
<div class="card">  
    <div class="card-header">  
        <h5>  
            <i class="fas fa-<?php echo $action==='add'?'plus':'edit'; ?>"></i>  
            <?php echo $action==='add'?'Nueva':'Editar'; ?> Cabaña  
        </h5>  
    </div>  
    <div class="card-body">  
        <form method="POST" id="formCabana">
            <div class="row g-4">

                <!-- ── COL 1: Info cabaña ── -->
                <div class="col-md-6">
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-home"></i> Información de la Cabaña
                    </h6>
                    <div class="mb-3">  
                        <label class="form-label">Nombre de la Cabaña *</label>  
                        <input type="text" class="form-control" name="nombre_cabana" required  
                               value="<?php echo htmlspecialchars($cabana['nombre_cabana'] ?? ''); ?>"  
                               placeholder="Ej: Cabaña Galilea">  
                    </div>  
                    <div class="mb-3">  
                        <label class="form-label">Capacidad Máxima *</label>  
                        <input type="number" class="form-control" name="capacidad_maxima"   
                               min="1" max="50" required  
                               value="<?php echo $cabana['capacidad_maxima'] ?? ''; ?>">  
                    </div>
                    <div class="mb-3">  
                        <label class="form-label"><strong>Género *</strong></label>  
                        <select class="form-select" name="genero" required>  
                            <option value="">-- Seleccionar --</option>  
                            <option value="masculino" <?php echo ($cabana['genero']??'')==='masculino'?'selected':''; ?>>
                                ♂ Masculino
                            </option>  
                            <option value="femenino" <?php echo ($cabana['genero']??'')==='femenino'?'selected':''; ?>>
                                ♀ Femenino
                            </option>  
                        </select>
                    </div>
                    <div class="mb-3">  
                        <label class="form-label"><strong>Equipo</strong></label>  
                        <select class="form-select" name="equipo">  
                            <option value="">-- Sin equipo --</option>
                            <?php
                            // Mapa interno: clave BD => clave equipos_config
                            $mapaEquipos = ['verde' => 'verde', 'azul' => 'azul'];
                            foreach ($mapaEquipos as $claveBD => $claveConfig):
                                $eqOpt     = $equipos_config[$claveConfig] ?? null;
                                $emojiOpt  = $eqOpt['emoji']  ?? '⚪';
                                $nombreOpt = $eqOpt['nombre'] ?? ucfirst($claveBD);
                                $selOpt    = ($cabana['equipo'] ?? '') === $claveBD ? 'selected' : '';
                            ?>
                            <option value="<?php echo $claveBD; ?>" <?php echo $selOpt; ?>>
                                <?php echo $emojiOpt; ?> <?php echo htmlspecialchars($nombreOpt); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            Configura los nombres y colores en
                            <a href="equipos.php">Configuración de Equipos</a>
                        </small>
                    </div>
                    <div class="mb-3">  
                        <label class="form-label">
                            Contraseña de Acceso
                            <?php echo $action==='edit'
                                ? '<small class="text-muted">(vacío = no cambiar)</small>'
                                : '<span class="text-danger">*</span>'; ?>
                        </label>  
                        <div class="input-group">  
                            <input type="password" class="form-control" id="password_cabana"
                                   name="password_cabana"  
                                   <?php echo $action==='add'?'required':''; ?>  
                                   placeholder="Contraseña para consejeros">  
                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">  
                                <i class="fas fa-eye"></i>  
                            </button>  
                        </div>  
                        <small class="text-muted">Los consejeros usarán esta contraseña para acceder</small>
                    </div>
                </div>

                <!-- ── COL 2: Info adicional ── -->
                <div class="col-md-6">
                    <h6 class="border-bottom pb-2 mb-3">
                        <i class="fas fa-info-circle"></i> Información Adicional
                    </h6>

                    <div class="alert alert-info small mb-3">
                        <i class="fas fa-users-cog"></i>
                        <strong>Asignación de consejeros:</strong> Se gestiona en
                        <a href="consejeros_semana.php" class="fw-bold">Asignar Consejeros</a>
                        por semana (con capitán/a, principal y asistente).
                    </div>

                    <?php if ($action === 'edit' && $cabana): ?>
                    <div class="card bg-light border-0">
                        <div class="card-body py-2">
                            <small class="text-muted">
                                <i class="fas fa-home"></i>
                                <strong><?php echo htmlspecialchars($cabana['nombre_cabana']); ?></strong>
                                · <?php echo ucfirst($cabana['genero']); ?>
                                · Cap. <?php echo $cabana['capacidad_maxima']; ?>
                                · <?php echo $cabana['activa'] ? '✅ Activa' : '⚫ Inactiva'; ?>
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
              
            <hr>  
            <div class="d-flex justify-content-between">  
                <a href="cabanas.php" class="btn btn-secondary">  
                    <i class="fas fa-arrow-left"></i> Volver  
                </a>  
                <button type="submit" class="btn btn-success">  
                    <i class="fas fa-save"></i>   
                    <?php echo $action==='add'?'Crear':'Actualizar'; ?> Cabaña  
                </button>  
            </div>  
        </form>  
    </div>  
</div>

<?php endif; ?>
  
<script>
document.getElementById('togglePassword')?.addEventListener('click', function () {
    const f = document.getElementById('password_cabana');
    const i = this.querySelector('i');
    if (f.type === 'password') {
        f.type = 'text';
        i.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        f.type = 'password';
        i.classList.replace('fa-eye-slash', 'fa-eye');
    }
});

document.addEventListener('DOMContentLoaded', function () {
    // Solo activar protección si hay un formulario de edición/creación
    const formCabana = document.getElementById('formCabana');
    if (formCabana) {
        setTimeout(activarProteccionFormulario, 300);
    }
});
</script>
  
<?php include '../includes/footer.php'; ?>