<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esApoyo()) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$titulo = "Lista de Acampantes";

// Obtener semana activa
$stmt = $pdo->query("SELECT * FROM semanas_campamento WHERE activa = 1 LIMIT 1");
$semana_activa = $stmt->fetch();
$semana_id_activa = $semana_activa['id'] ?? null;

// Filtros
$search        = $_GET['search']     ?? '';
$cabana_filter = $_GET['cabana_id']  ?? '';
$asignacion    = $_GET['asignacion'] ?? '';

// Obtener genero_acceso del usuario actual
$stmt = $pdo->prepare("SELECT genero_acceso FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$usuario_actual = $stmt->fetch();
$genero_acceso = $usuario_actual['genero_acceso'] ?? 'ambos';

// Obtener cabañas según género permitido
if ($genero_acceso === 'ambos') {
    $stmt = $pdo->query("SELECT * FROM cabanas WHERE activa = 1 ORDER BY nombre_cabana");
} else {
    $stmt = $pdo->prepare("SELECT * FROM cabanas WHERE activa = 1 AND genero = ? ORDER BY nombre_cabana");
    $stmt->execute([$genero_acceso]);
}
$cabanas = $stmt->fetchAll();

// Filtro de género sobre cabañas
$where_genero_cab = $genero_acceso !== 'ambos'
    ? "AND c.genero = " . $pdo->quote($genero_acceso)
    : "";

// Query base
$params = [];
if ($semana_id_activa) {
    $sql = "SELECT a.*, c.nombre_cabana
            FROM acampantes a
            LEFT JOIN cabanas c ON a.cabana_id = c.id
            WHERE a.semana_id = ? AND a.estado = 'activo'";
    $params[] = $semana_id_activa;
} else {
    $sql = "SELECT a.*, c.nombre_cabana
            FROM acampantes a
            LEFT JOIN cabanas c ON a.cabana_id = c.id
            WHERE a.year_campamento = ? AND a.estado = 'activo'";
    $params[] = obtenerAnioCampamento();
}

// Filtro por género de acceso
if ($genero_acceso !== 'ambos') {
    $sql .= " AND (a.sexo = ? OR (a.cabana_id IS NOT NULL AND c.genero = ?))";
    $params[] = $genero_acceso;
    $params[] = $genero_acceso;
}

// Filtro por asignación de cabaña
if ($asignacion === 'sin_cabana') {
    $sql .= " AND a.cabana_id IS NULL";
} elseif ($asignacion === 'con_cabana') {
    $sql .= " AND a.cabana_id IS NOT NULL";
}

// Filtro por búsqueda de texto
if ($search) {
    $sql .= " AND (a.nombre LIKE ? OR a.iglesia LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Filtro por cabaña específica
if ($cabana_filter) {
    $sql .= " AND a.cabana_id = ?";
    $params[] = (int)$cabana_filter;
}

$sql .= " ORDER BY a.nombre";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$acampantes = $stmt->fetchAll();

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1><i class="fas fa-users"></i> <?= $titulo ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Lista de Acampantes</li>
            </ol>
        </nav>
    </div>
</div>

<!-- Banner semana -->
<?php if ($semana_activa): ?>
<div class="alert alert-success border-0 mb-4">
    <i class="fas fa-broadcast-tower"></i>
    <strong>Semana activa:</strong> <?= htmlspecialchars($semana_activa['nombre']) ?>
    <span class="text-muted ms-2">
        <?= date('d/m/Y', strtotime($semana_activa['fecha_inicio'])) ?> -
        <?= date('d/m/Y', strtotime($semana_activa['fecha_fin'])) ?>
    </span>
</div>
<?php else: ?>
<div class="alert alert-warning mb-4">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Sin semana activa.</strong> Mostrando datos del año actual.
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0">
            <i class="fas fa-map-marker-alt"></i> Acampantes
            <span class="badge bg-success ms-2"><?= count($acampantes) ?></span>
            <?php if ($asignacion === 'sin_cabana'): ?>
                <span class="badge bg-warning text-dark ms-1">Sin cabaña</span>
            <?php elseif ($asignacion === 'con_cabana'): ?>
                <span class="badge bg-primary ms-1">Con cabaña</span>
            <?php endif; ?>
        </h5>
        <a href="registrar_acampante.php"
           class="btn btn-success btn-sm <?= !$semana_activa ? 'disabled' : '' ?>">
            <i class="fas fa-plus"></i> Nuevo Registro
        </a>
    </div>

    <div class="card-body">

        <!-- Filtros -->
        <form method="GET" class="row g-2 mb-3">

            <div class="col-12 col-md-3">
                <input type="text" class="form-control" name="search"
                       placeholder="Buscar por nombre o iglesia..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="col-6 col-md-2">
                <select name="cabana_id" class="form-select">
                    <option value="">Todas las cabañas</option>
                    <?php foreach ($cabanas as $cab): ?>
                    <option value="<?= $cab['id'] ?>"
                            <?= ($cabana_filter == $cab['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cab['nombre_cabana']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <select name="asignacion" class="form-select">
                    <option value="">Todos</option>
                    <option value="sin_cabana"
                            <?= $asignacion === 'sin_cabana' ? 'selected' : '' ?>>
                        ⚠️ Sin cabaña
                    </option>
                    <option value="con_cabana"
                            <?= $asignacion === 'con_cabana' ? 'selected' : '' ?>>
                        ✅ Con cabaña
                    </option>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Filtrar
                </button>
            </div>

            <div class="col-6 col-md-2">
                <a href="lista_acampantes.php" class="btn btn-secondary w-100">
                    <i class="fas fa-times"></i> Limpiar
                </a>
            </div>

        </form>

        <!-- Alerta cuando se filtra sin cabaña -->
        <?php if ($asignacion === 'sin_cabana' && count($acampantes) > 0): ?>
        <div class="alert alert-warning py-2 mb-3">
            <i class="fas fa-exclamation-triangle"></i>
            <strong><?= count($acampantes) ?> acampante<?= count($acampantes) > 1 ? 's' : '' ?></strong>
            sin cabaña asignada. Edítalos para asignarles una.
        </div>
        <?php endif; ?>

        <!-- Tabla -->
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Nombre</th>
                        <th>Edad</th>
                        <th>Sexo</th>
                        <th>Iglesia</th>
                        <th>Estado/Dpto.</th>
                        <th>Cabaña</th>
                        <th>Contacto Emergencia</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($acampantes)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="fas fa-users fa-3x mb-3 d-block"></i>
                            No se encontraron acampantes
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($acampantes as $camp): ?>
                    <tr <?= !$camp['nombre_cabana'] ? 'class="table-warning"' : '' ?>>
                        <td><strong><?= htmlspecialchars($camp['nombre']) ?></strong></td>
                        <td><?= $camp['edad'] ?? '-' ?></td>
                        <td>
                            <span class="badge bg-<?= $camp['sexo'] === 'masculino' ? 'primary' : 'danger' ?>">
                                <i class="fas fa-<?= $camp['sexo'] === 'masculino' ? 'mars' : 'venus' ?>"></i>
                                <?= ucfirst($camp['sexo'] ?? 'N/A') ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($camp['iglesia'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($camp['estado_origen'] ?? '-') ?></td>
                        <td>
                            <?php if ($camp['nombre_cabana']): ?>
                                <span class="badge bg-primary">
                                    <i class="fas fa-home me-1"></i>
                                    <?= htmlspecialchars($camp['nombre_cabana']) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    Sin asignar
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($camp['contacto_emergencia_nombre'])): ?>
                                <small>
                                    <strong><?= htmlspecialchars($camp['contacto_emergencia_nombre']) ?></strong><br>
                                    <?= htmlspecialchars($camp['contacto_emergencia_telefono'] ?? '') ?>
                                </small>
                            <?php else: ?>
                                <span class="text-muted small">No registrado</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <a href="ver_acampante.php?id=<?= $camp['id'] ?>"
                                   class="btn btn-sm btn-outline-info" title="Ver detalle">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="editar_acampante.php?id=<?= $camp['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>