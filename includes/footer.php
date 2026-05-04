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
                            Campamento PV
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
                        global $pdo;
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
                        if (esEncargadoConsejeros()):
                            echo '<strong><i class="fas fa-cog me-1"></i>Encargado consejeros</strong>';
                        elseif (esConsejero()):
                            echo '<strong><i class="fas fa-user-friends me-1"></i>Consejero</strong>';
                        else:
                            echo '<strong><i class="fas fa-clipboard-list me-1"></i>Apoyo</strong>';
                        endif;
                        ?>
                        <span class="footer-dot">·</span>
                        <span>v2.0</span>
                    </div>
                </div>

            </div>
        </div>
    </footer>
    <!-- ══ FIN FOOTER ══ -->

    <!-- JS personalizado -->
    <script src="../assets/js/script.js"></script>

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