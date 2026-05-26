<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $_nombre_sistema = '';
    try {
        $_nombre_sistema = obtenerConfig($pdo, 'nombre_sistema', 'ConectaPV');
    } catch (Exception $_e) {
        $_nombre_sistema = 'ConectaPV';
    }
    ?>
    <title><?php echo (isset($titulo) ? htmlspecialchars($titulo) . ' — ' : '') . htmlspecialchars($_nombre_sistema); ?></title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
    <!-- CSS personalizado WOL -->
    <link href="/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/header.css" rel="stylesheet">
    <!-- PWA -->
    <link rel="manifest" href="/manifest.json?v=2">
    <meta name="theme-color" content="#004f68">
    <link rel="apple-touch-icon" href="/assets/img/icon-192.png">

    <!-- Offline Sync -->
    <script src="/assets/js/offline-sync.js?v=3" defer></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body class="bg-light">

<?php
// ── Detectar página activa ──────────────────────────────────
$paginaActual = basename($_SERVER['PHP_SELF']);

function navActivo(string $pagina): string {
    global $paginaActual;
    return ($paginaActual === $pagina) ? ' active' : '';
}

// ── Semana activa para el pill ──────────────────────────────
$_semana_nav = null;
try {
    global $pdo;
    if (isset($pdo)) {
        $_stmt_nav  = $pdo->query("SELECT nombre FROM semanas_campamento WHERE activa = 1 LIMIT 1");
        $_semana_nav = $_stmt_nav->fetch()['nombre'] ?? null;
    }
} catch (Exception $_e) { /* silencioso */ }

// ── Rol actual ──────────────────────────────────────────────
$_esAdmin      = esAdministrador();
$_esEncargado  = esEncargadoConsejeros();
$_esConsejero  = esConsejero();
$_esApoyo      = esApoyo();
$_esAdmisiones = esAdmisiones();

$_rolLabel = $_esAdmin      ? 'Administrador' :
            ($_esEncargado  ? 'Encargado'     :
            ($_esConsejero  ? 'Consejero'     :
            ($_esAdmisiones ? 'Admisiones'    : 'Apoyo')));

$_rolBadgeClass = $_esAdmin      ? 'rol-badge-admin'      :
                 ($_esEncargado  ? 'rol-badge-encargado'  :
                 ($_esConsejero  ? 'rol-badge-consejero'  :
                 ($_esAdmisiones ? 'rol-badge-admisiones' : 'rol-badge-apoyo')));
?>

<!-- ══ NAVBAR ══════════════════════════════════════════════ -->
<nav class="navbar navbar-expand-lg navbar-custom">
    <div class="container">

        <!-- Brand -->
        <?php
        // Verificar si hay logo subido
        $_logo_path  = '/assets/img/logo_sistema.png';
        $_logo_disco = $_SERVER['DOCUMENT_ROOT'] . $_logo_path;
        $_tiene_logo = file_exists($_logo_disco);
        ?>
        
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
            <?php if ($_tiene_logo): ?>
                <img src="<?php echo $_logo_path; ?>?v=<?php echo filemtime($_logo_disco); ?>"
                     alt="Logo"
                     style="height:36px; width:auto; object-fit:contain;">
            <?php else: ?>
                <span class="brand-icon">
                    <i class="fas fa-campground"></i>
                </span>
                <?php echo htmlspecialchars($_nombre_sistema); ?>
            <?php endif; ?>
        
            <?php if ($_semana_nav): ?>
            <span class="semana-pill">
                <i class="fas fa-broadcast-tower"></i>
                <?php echo htmlspecialchars($_semana_nav); ?>
            </span>
            <?php endif; ?>
        </a>

        <!-- Toggler móvil -->
        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Menú">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">

            <!-- ══ LINKS SEGÚN ROL ══ -->
            <ul class="navbar-nav me-auto align-items-lg-stretch">
                <?php if ($_esAdmin): ?>
                <!-- ── ADMINISTRADOR ── -->
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('dashboard.php'); ?>" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?php echo in_array($paginaActual,
                        ['gestionar_usuarios.php','gestionar_contrasenas.php']) ? ' active' : ''; ?>"
                       href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-users-cog"></i> Usuarios
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="gestionar_usuarios.php">
                                <i class="fas fa-users"></i> Gestionar Usuarios
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="gestionar_contrasenas.php">
                                <i class="fas fa-key"></i> Contraseñas
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?php echo in_array($paginaActual,
                        ['reporte_anual.php','reporte_mensual.php','logs.php']) ? ' active' : ''; ?>"
                       href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-chart-bar"></i> Reportes
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="reporte_anual.php">
                                <i class="fas fa-calendar-alt"></i> Reporte Anual
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="reporte_mensual.php">
                                <i class="fas fa-calendar-week"></i> Reporte Mensual
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="logs.php">
                                <i class="fas fa-file-alt"></i> Logs del Sistema
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?php echo in_array($paginaActual,
                        ['mantenimiento.php','backups.php','configuracion.php']) ? ' active' : ''; ?>"
                       href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-cogs"></i> Sistema
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="configuracion.php">
                                <i class="fas fa-sliders-h"></i> Configuración Global
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="backups.php">
                                <i class="fas fa-database"></i> Gestor de Backups
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="mantenimiento.php">
                                <i class="fas fa-tools"></i> Mantenimiento
                            </a>
                        </li>
                    </ul>
                </li>
            
                <?php elseif ($_esEncargado): ?>
                <!-- ── ENCARGADO CONSEJERO ── -->
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('dashboard.php'); ?>" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?php echo in_array($paginaActual,
                        ['acampantes.php','importar_acampantes.php']) ? ' active' : ''; ?>"
                       href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-users"></i> Acampantes
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="acampantes.php">
                                <i class="fas fa-list"></i> Lista de Acampantes
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="acampantes.php?action=add">
                                <i class="fas fa-user-plus"></i> Nuevo Acampante
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="importar_acampantes.php">
                                <i class="fas fa-file-upload"></i> Importar Masivo
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?php echo in_array($paginaActual,
                        ['cabanas.php','consejeros_semana.php','semanas.php','equipos.php']) ? ' active' : ''; ?>"
                       href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-home"></i> Campamento
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="semanas.php">
                                <i class="fas fa-calendar-week"></i> Semanas
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="cabanas.php">
                                <i class="fas fa-home"></i> Cabañas
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="consejeros_semana.php">
                                <i class="fas fa-users-cog"></i> Asignar Consejeros
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="equipos.php">
                                <i class="fas fa-palette"></i> Equipos
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('recursos.php'); ?>" href="recursos.php">
                        <i class="fas fa-folder-open"></i> Recursos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('reportes.php'); ?>" href="reportes.php">
                        <i class="fas fa-chart-bar"></i> Reportes
                    </a>
                </li>
            
                <?php elseif ($_esAdmisiones): ?>
                <!-- ── ADMISIONES ── -->
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('dashboard.php'); ?>" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('lista_acampantes.php'); ?>" href="lista_acampantes.php">
                        <i class="fas fa-users"></i> Acampantes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('inscribir.php'); ?>" href="inscribir.php">
                        <i class="fas fa-user-plus"></i> Inscribir
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('lista_acampantes.php'); ?>" 
                       href="lista_acampantes.php?filtro_pago=sin_pago">
                        <i class="fas fa-dollar-sign"></i> Pagos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('semanas.php'); ?>" href="semanas.php">
                        <i class="fas fa-calendar-alt"></i> Semanas
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('estadisticas.php'); ?>" href="estadisticas.php">
                        <i class="fas fa-chart-bar"></i> Estadísticas
                    </a>
                </li>
            
                <?php elseif ($_esConsejero): ?>
                <!-- ── CONSEJERO ── -->
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('dashboard.php'); ?>" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('mis_acampantes.php'); ?>" href="mis_acampantes.php">
                        <i class="fas fa-users"></i> Mis Acampantes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('subir_foto.php'); ?>" href="../consejero/subir_foto.php">
                        <i class="fas fa-camera"></i> Fotos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('estadisticas.php'); ?>" href="estadisticas.php">
                        <i class="fas fa-chart-pie"></i> Estadísticas
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('recursos.php'); ?>" href="recursos.php">
                        <i class="fas fa-folder-open"></i> Recursos
                    </a>
                </li>
            
                <?php elseif ($_esApoyo): ?>
                <!-- ── APOYO ── -->
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('dashboard.php'); ?>" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('registrar_acampante.php'); ?>" href="registrar_acampante.php">
                        <i class="fas fa-user-plus"></i> Registro
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('lista_acampantes.php'); ?>" href="lista_acampantes.php">
                        <i class="fas fa-users"></i> Acampantes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('estadisticas.php'); ?>" href="estadisticas.php">
                        <i class="fas fa-chart-pie"></i> Estadísticas
                    </a>
                </li>
            
                <?php endif; ?>
            </ul>

            <!-- ══ USUARIO DROPDOWN ══ -->
            <ul class="navbar-nav align-items-lg-center">
                <li class="nav-item dropdown">
                    <a class="nav-link user-btn dropdown-toggle" href="#"
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="user-avatar">
                            <i class="fas fa-user"></i>
                        </span>
                        <span>
                            <?php echo htmlspecialchars($_SESSION['username']); ?>
                            <span class="rol-badge <?php echo $_rolBadgeClass; ?> ms-1">
                                <?php echo $_rolLabel; ?>
                            </span>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <span class="dropdown-item-text small text-muted px-3 py-2">
                                <i class="fas fa-circle text-success" style="font-size:8px;"></i>
                                Sesión activa
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php if ($_esEncargado): ?>
                        <li>
                            <a class="dropdown-item" href="usuarios.php">
                                <i class="fas fa-user-shield"></i> Usuarios de apoyo
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <?php if ($_esAdmisiones): ?>
                        <li>
                            <a class="dropdown-item" href="importar.php">
                                <i class="fas fa-file-csv"></i> Importar CSV
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <?php if ($_esAdmin): ?>
                        <li>
                            <a class="dropdown-item" href="cambiar_password.php">
                                <i class="fas fa-key"></i> Cambiar Contraseña
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="mantenimiento.php">
                                <i class="fas fa-tools"></i> Mantenimiento
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <li>
                            <a class="dropdown-item text-danger" href="../logout.php">
                                <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>

        </div><!-- /collapse -->
    </div><!-- /container -->
</nav>
<!-- ══ FIN NAVBAR ══ -->

<!-- ══ CONTENIDO PRINCIPAL ══ -->
<div class="container main-container">