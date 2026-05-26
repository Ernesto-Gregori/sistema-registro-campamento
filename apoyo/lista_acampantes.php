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
$search = $_GET['search'] ?? '';
$cabana_filter = $_GET['cabana_id'] ?? '';

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

// Query acampantes — solo los que ya hicieron check-in (llego = 1)
$params = [];
if ($semana_id_activa) {
    $sql = "SELECT a.*, c.nombre_cabana 
            FROM acampantes a 
            LEFT JOIN cabanas c ON a.cabana_id = c.id
            WHERE a.semana_id = ? AND a.estado = 'activo' AND a.llego = 1";
    $params[] = $semana_id_activa;
} else {
    $sql = "SELECT a.*, c.nombre_cabana 
            FROM acampantes a 
            LEFT JOIN cabanas c ON a.cabana_id = c.id
            WHERE a.year_campamento = ? AND a.estado = 'activo' AND a.llego = 1";
    $params[] = obtenerAnioCampamento();
}

// Filtrar por género de acceso del usuario
if ($genero_acceso !== 'ambos') {
    $sql .= " AND c.genero = ?";
    $params[] = $genero_acceso;
}

if ($search) {
    $sql .= " AND (a.nombre LIKE ? OR a.iglesia LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

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
        <h1><i class="fas fa-users"></i> <?php echo $titulo; ?></h1>
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
    <strong>Semana activa:</strong> <?php echo htmlspecialchars($semana_activa['nombre']); ?>
    <span class="text-muted ms-2">
        <?php echo date('d/m/Y', strtotime($semana_activa['fecha_inicio'])); ?> -
        <?php echo date('d/m/Y', strtotime($semana_activa['fecha_fin'])); ?>
    </span>
</div>
<?php else: ?>
<div class="alert alert-warning mb-4">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Sin semana activa.</strong> Mostrando datos del año actual.
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-map-marker-alt"></i> Acampantes en Campamento
            <span class="badge bg-success ms-2"><?php echo count($acampantes); ?></span>
            <small class="text-muted fw-normal ms-2" style="font-size:12px;">
                Solo con check-in confirmado
            </small>
        </h5>
        <a href="registrar_acampante.php" class="btn btn-success btn-sm <?php echo !$semana_activa ? 'disabled' : ''; ?>">
            <i class="fas fa-plus"></i> Nuevo Registro
        </a>
    </div>
    <div class="card-body">
        <!-- Filtros -->
        <form method="GET" class="row mb-3">
            <div class="col-md-4 mb-2">
                <input type="text" class="form-control" name="search"
                       placeholder="Buscar por nombre o iglesia..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3 mb-2">
                <select name="cabana_id" class="form-select">
                    <option value="">Todas las cabañas</option>
                    <?php foreach ($cabanas as $cab): ?>
                    <option value="<?php echo $cab['id']; ?>"
                            <?php echo ($cabana_filter == $cab['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cab['nombre_cabana']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Filtrar
                </button>
            </div>
            <div class="col-md-2 mb-2">
                <a href="lista_acampantes.php" class="btn btn-secondary w-100">
                    <i class="fas fa-refresh"></i> Limpiar
                </a>
            </div>
        </form>

        <!-- Tabla -->
        <div class="table-responsive">
            <table class="table table-hover">
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
                            <i class="fas fa-users fa-3x mb-3"></i><br>
                            No se encontraron acampantes
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($acampantes as $camp): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($camp['nombre']); ?></strong></td>
                        <td><?php echo $camp['edad'] ?? '-'; ?></td>
                        <td>
                            <span class="badge bg-<?php echo $camp['sexo'] === 'masculino' ? 'primary' : 'danger'; ?>">
                                <i class="fas fa-<?php echo $camp['sexo'] === 'masculino' ? 'mars' : 'venus'; ?>"></i>
                                <?php echo ucfirst($camp['sexo'] ?? 'N/A'); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($camp['iglesia']); ?></td>
                        <td><?php echo htmlspecialchars($camp['estado_origen'] ?? '-'); ?></td>
                        <td>
                            <span class="badge bg-primary">
                                <?php echo htmlspecialchars($camp['nombre_cabana'] ?? 'Sin asignar'); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($camp['contacto_emergencia_nombre'])): ?>
                                <small>
                                    <strong><?php echo htmlspecialchars($camp['contacto_emergencia_nombre']); ?></strong><br>
                                    <?php echo htmlspecialchars($camp['contacto_emergencia_telefono'] ?? ''); ?>
                                </small>
                            <?php else: ?>
                                <span class="text-muted small">No registrado</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <a href="ver_acampante.php?id=<?php echo $camp['id']; ?>"
                                   class="btn btn-sm btn-outline-info" title="Ver detalle">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="editar_acampante.php?id=<?php echo $camp['id']; ?>"
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