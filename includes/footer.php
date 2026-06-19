    </div><!-- /container main-container -->

    <!-- ══ FOOTER ══ -->
    <footer class="footer-custom">

        <hr class="footer-divider">

        <div class="container py-3">
            <div class="row align-items-center g-2">

                <!-- ── Marca ── -->
                <div class="col-md-4 text-md-start text-center">
                    <div class="footer-brand">
                        <span class="fi"><i class="fas fa-campground"></i></span>
                        <span>
                            <?php
                            // $_nombre_sistema viene del header; fallback por si acaso
                            $__nombre_footer = $_nombre_sistema ?? '';
                            if (empty($__nombre_footer)) {
                                try {
                                    $__nombre_footer = obtenerConfig($pdo, 'nombre_sistema', 'ConectaPV');
                                } catch (Exception $__e) {
                                    $__nombre_footer = 'ConectaPV';
                                }
                            }
                            echo htmlspecialchars($__nombre_footer);
                            ?>
                            <span class="footer-copyright">
                                © <?php echo date('Y'); ?>
                            </span>
                        </span>
                    </div>
                </div>

                <!-- ── Semana activa ── -->
                <div class="col-md-4 text-center">
                    <?php
                    $_semana_footer = null;
                    try {
                        if (isset($pdo)) {
                            $_sf = $pdo->query(
                                "SELECT nombre, fecha_fin
                                 FROM semanas_campamento
                                 WHERE activa = 1 LIMIT 1"
                            );
                            $_semana_footer = $_sf->fetch();
                        }
                    } catch (Exception $_ef) {}
                    ?>
                    <?php if ($_semana_footer): ?>
                        <span class="semana-badge">
                            <i class="fas fa-broadcast-tower"></i>
                            <?php echo htmlspecialchars($_semana_footer['nombre']); ?>
                            <span class="fecha">
                                · hasta <?php echo date('d/m', strtotime($_semana_footer['fecha_fin'])); ?>
                            </span>
                        </span>
                    <?php else: ?>
                        <span class="no-semana">
                            <i class="fas fa-calendar-times me-1"></i>Sin semana activa
                        </span>
                    <?php endif; ?>
                </div>

                <!-- ── Rol + versión ── -->
                <div class="col-md-4 text-md-end text-center">
                    <div class="rol-info">
                        <?php
                        $__icon  = 'fa-user';
                        $__label = 'Usuario';

                        if     (isset($_esAdmin)          ? $_esAdmin          : esAdministrador())      { $__icon = 'fa-shield-alt';     $__label = 'Administrador'; }
                        elseif (isset($_esEncargado)       ? $_esEncargado      : esEncargadoConsejeros()) { $__icon = 'fa-user-tie';        $__label = 'Encargado Consejeros'; }
                        elseif (isset($_esConsejero)       ? $_esConsejero      : esConsejero())           { $__icon = 'fa-user-friends';    $__label = 'Consejero'; }
                        elseif (isset($_esAdmisiones)      ? $_esAdmisiones     : esAdmisiones())          { $__icon = 'fa-clipboard-list';  $__label = 'Admisiones'; }
                        elseif (isset($_esAdministracion)  ? $_esAdministracion : esAdministracion())      { $__icon = 'fa-cash-register';   $__label = 'Administración'; }
                        elseif (function_exists('esRol') && esRol('direccion_campamento'))                  { $__icon = 'fa-star';            $__label = 'Dirección Campamento'; }
                        elseif (isset($_esApoyo)           ? $_esApoyo          : esApoyo())               { $__icon = 'fa-hands-helping';   $__label = 'Apoyo'; }
                        ?>
                        <strong>
                            <i class="fas <?php echo $__icon; ?> me-1"></i>
                            <?php echo $__label; ?>
                        </strong>
                        <span class="footer-dot">·</span>
                        <span>v2.0</span>
                    </div>
                </div>

            </div>
        </div>
    </footer>
    <!-- ══ FIN FOOTER ══ -->

    <!-- JS personalizado -->
    <script src="/assets/js/script.js"></script>

    <script>
    // ── Marcar nav-link activo automáticamente ──────────────────
    (function() {
        const current = window.location.pathname.split('/').pop();
        document.querySelectorAll('.navbar-custom .nav-link').forEach(link => {
            const href = (link.getAttribute('href') || '').split('?')[0].split('/').pop();
            if (href && href === current) {
                link.classList.add('active');
            }
        });
    })();
    </script>
</body>
</html>