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
    <!-- CSS personalizado -->
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
        $_stmt_nav   = $pdo->query("SELECT nombre FROM semanas_campamento WHERE activa = 1 LIMIT 1");
        $_semana_nav = $_stmt_nav->fetch()['nombre'] ?? null;
    }
} catch (Exception $_e) { /* silencioso */ }

// ── Roles actuales ──────────────────────────────────────────
$_esAdmin           = esAdministrador();
$_esEncargado       = esEncargadoConsejeros();
$_esConsejero       = esConsejero();
$_esApoyo           = esApoyo();
$_esAdmisiones      = esAdmisiones();
$_esAdministracion  = esAdministracion();  

// ── Label y badge según rol ─────────────────────────────────
$_rolLabel =
    $_esAdmin          ? 'Administrador'       :
   ($_esEncargado      ? 'Encargado'           :
   ($_esConsejero      ? 'Consejero'           :
   ($_esAdmisiones     ? 'Admisiones'          :
   ($_esAdministracion ? 'Administración'      :
   (esRol('direccion_campamento') ? 'Dirección' :
                         'Apoyo')))));

$_rolBadgeClass =
    $_esAdmin          ? 'rol-badge-admin'          :
   ($_esEncargado      ? 'rol-badge-encargado'      :
   ($_esConsejero      ? 'rol-badge-consejero'      :
   ($_esAdmisiones     ? 'rol-badge-admisiones'     :
   ($_esAdministracion ? 'rol-badge-administracion' :
   (esRol('direccion_campamento') ? 'rol-badge-direccion' :
                         'rol-badge-apoyo')))));

// ── Dashboard home según rol ────────────────────────────────
$_dashboardHome =
    $_esAdmin          ? '/admin/dashboard.php'          :
   ($_esEncargado      ? '/encargado_consejeros/dashboard.php' :
   ($_esConsejero      ? '/consejero/dashboard.php'      :
   ($_esAdmisiones     ? '/admisiones/dashboard.php'     :
   ($_esAdministracion ? '/administracion/dashboard.php' :
                         '/apoyo/dashboard.php'))));
?>

<!-- ══ NAVBAR ══════════════════════════════════════════════ -->
<nav class="navbar navbar-expand-lg navbar-custom">
    <div class="container">

        <!-- Brand — siempre apunta al dashboard del rol actual -->
        <?php
        $_logo_path  = '/assets/img/logo_sistema.png';
        $_logo_disco = $_SERVER['DOCUMENT_ROOT'] . $_logo_path;
        $_tiene_logo = file_exists($_logo_disco);
        ?>
        <a class="navbar-brand d-flex align-items-center gap-2"
           href="<?php echo $_dashboardHome; ?>">
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
                <!-- ── ADMINISTRADOR ─────────────────────────────── -->
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('dashboard.php'); ?>"
                       href="/admin/dashboard.php">
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
                            <a class="dropdown-item" href="/admin/gestionar_usuarios.php">
                                <i class="fas fa-users"></i> Gestionar Usuarios
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/admin/gestionar_contrasenas.php">
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
                            <a class="dropdown-item" href="/admin/reporte_anual.php">
                                <i class="fas fa-calendar-alt"></i> Reporte Anual
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/admin/reporte_mensual.php">
                                <i class="fas fa-calendar-week"></i> Reporte Mensual
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="/admin/logs.php">
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
                            <a class="dropdown-item" href="/admin/configuracion.php">
                                <i class="fas fa-sliders-h"></i> Configuración Global
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/admin/backups.php">
                                <i class="fas fa-database"></i> Gestor de Backups
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/admin/mantenimiento.php">
                                <i class="fas fa-tools"></i> Mantenimiento
                            </a>
                        </li>
                    </ul>
                </li>

                <?php elseif ($_esEncargado): ?>
                <!-- ── ENCARGADO CONSEJEROS ──────────────────────── -->
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('dashboard.php'); ?>"
                       href="/encargado_consejeros/dashboard.php">
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
                            <a class="dropdown-item" href="/encargado_consejeros/acampantes.php">
                                <i class="fas fa-list"></i> Lista de Acampantes
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/encargado_consejeros/acampantes.php?action=add">
                                <i class="fas fa-user-plus"></i> Nuevo Acampante
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/encargado_consejeros/importar_acampantes.php">
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
                            <a class="dropdown-item" href="/encargado_consejeros/semanas.php">
                                <i class="fas fa-calendar-week"></i> Semanas
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/encargado_consejeros/cabanas.php">
                                <i class="fas fa-home"></i> Cabañas
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/encargado_consejeros/consejeros_semana.php">
                                <i class="fas fa-users-cog"></i> Asignar Consejeros
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/encargado_consejeros/equipos.php">
                                <i class="fas fa-palette"></i> Equipos
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('recursos.php'); ?>"
                       href="/encargado_consejeros/recursos.php">
                        <i class="fas fa-folder-open"></i> Recursos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('reportes.php'); ?>"
                       href="/encargado_consejeros/reportes.php">
                        <i class="fas fa-chart-bar"></i> Reportes
                    </a>
                </li>

                <?php elseif ($_esAdmisiones): ?>
                <!-- ── INSCRIPCIÓN (antes Admisiones) ────────────── -->
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('dashboard.php'); ?>"
                       href="/admisiones/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('lista_acampantes.php'); ?>"
                       href="/admisiones/lista_acampantes.php">
                        <i class="fas fa-users"></i> Acampantes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('lista_grupos.php'); ?>"
                       href="/admisiones/grupos/lista_grupos.php">
                        <i class="fas fa-users-cog"></i> Grupos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('inscribir.php'); ?>"
                       href="/admisiones/inscribir.php">
                        <i class="fas fa-user-plus"></i> Inscribir
                    </a>
                </li>
                <!-- Pagos eliminado de Inscripción — ahora es de Administración -->
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('estadisticas.php'); ?>"
                       href="/admisiones/estadisticas.php">
                        <i class="fas fa-chart-bar"></i> Estadísticas
                    </a>
                </li>

                <?php elseif ($_esAdministracion): ?>
                <!-- ── ADMINISTRACIÓN (caja / pagos) ─────────────────────────── -->
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('dashboard.php'); ?>"
                       href="/administracion/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('lista_pagos.php'); ?>"
                       href="/administracion/lista_pagos.php">
                        <i class="fas fa-cash-register"></i> Caja
                    </a>
                </li>
                
                <!-- ── NUEVO: Grupos ── -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?php echo in_array($paginaActual,
                        ['lista_grupos_admin.php', 'ver_grupo_admin.php']) ? ' active' : ''; ?>"
                       href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-users"></i> Grupos
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="/administracion/lista_grupos_admin.php">
                                <i class="fas fa-list"></i> Todos los grupos
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item"
                               href="/administracion/lista_grupos_admin.php?filtro=pendiente">
                                <i class="fas fa-exclamation-circle text-warning"></i> Con saldo pendiente
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item"
                               href="/administracion/lista_grupos_admin.php?filtro=completo">
                                <i class="fas fa-check-circle text-success"></i> Pagados completos
                            </a>
                        </li>
                    </ul>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('historial.php'); ?>"
                       href="/administracion/historial.php">
                        <i class="fas fa-history"></i> Historial
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('estadisticas.php'); ?>"
                       href="/administracion/estadisticas.php">
                        <i class="fas fa-chart-bar"></i> Estadísticas
                    </a>
                </li>

                <?php elseif ($_esConsejero): ?>
                <!-- ── CONSEJERO ─────────────────────────────────── -->
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('dashboard.php'); ?>"
                       href="/consejero/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('mis_acampantes.php'); ?>"
                       href="/consejero/mis_acampantes.php">
                        <i class="fas fa-users"></i> Mis Acampantes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('subir_foto.php'); ?>"
                       href="/consejero/subir_foto.php">
                        <i class="fas fa-camera"></i> Fotos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('estadisticas.php'); ?>"
                       href="/consejero/estadisticas.php">
                        <i class="fas fa-chart-pie"></i> Estadísticas
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('recursos.php'); ?>"
                       href="/consejero/recursos.php">
                        <i class="fas fa-folder-open"></i> Recursos
                    </a>
                </li>

                <?php elseif ($_esApoyo): ?>
                <!-- ── APOYO ─────────────────────────────────────── -->
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('dashboard.php'); ?>"
                       href="/apoyo/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('registrar_acampante.php'); ?>"
                       href="/apoyo/registrar_acampante.php">
                        <i class="fas fa-user-plus"></i> Registro
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('lista_acampantes.php'); ?>"
                       href="/apoyo/lista_acampantes.php">
                        <i class="fas fa-users"></i> Acampantes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('config_edad_cabanas.php'); ?>"
                        href="/apoyo/config_edad_cabanas.php">
                        <i class="fas fa-sliders-h"></i> Edades por Cabaña
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo navActivo('estadisticas.php'); ?>"
                       href="/apoyo/estadisticas.php">
                        <i class="fas fa-chart-pie"></i> Estadísticas
                    </a>
                </li>

                <?php endif; ?>
            </ul>
            <!-- ── FIN LINKS ── -->

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
                            <a class="dropdown-item" href="/encargado_consejeros/usuarios.php">
                                <i class="fas fa-user-shield"></i> Usuarios de apoyo
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>

                        <?php if ($_esAdmisiones): ?>
                        <li>
                            <a class="dropdown-item" href="/admisiones/importar.php">
                                <i class="fas fa-file-csv"></i> Importar CSV/XLSX
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>

                        <?php if ($_esAdministracion): ?>
                        <li>
                            <a class="dropdown-item" href="/administracion/historial.php">
                                <i class="fas fa-history"></i> Historial de pagos
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>

                        <?php if ($_esAdmin): ?>
                        <li>
                            <a class="dropdown-item" href="/admin/cambiar_password.php">
                                <i class="fas fa-key"></i> Cambiar Contraseña
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/admin/mantenimiento.php">
                                <i class="fas fa-tools"></i> Mantenimiento
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>

                        <li>
                            <a class="dropdown-item text-danger" href="/logout.php">
                                <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
            <!-- ── FIN USUARIO ── -->

        </div><!-- /collapse -->
    </div><!-- /container -->
</nav>
<!-- ══ FIN NAVBAR ══ -->

<!-- ══ CONTENIDO PRINCIPAL ══ -->
<div class="container main-container">