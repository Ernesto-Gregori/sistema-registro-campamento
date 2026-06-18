<?php  
session_start();  
require_once 'config/database.php';  
require_once 'includes/functions.php';  
  
$error = '';  
  
if ($_POST) {  
    $username = limpiarDatos($_POST['username']);  
    $password = $_POST['password'];  
      
    if ($username && $password) {  
        try {  
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = ? AND activo = 1");  
            $stmt->execute([$username]);  
            $usuario = $stmt->fetch();  
              
            if ($usuario && verificarPassword($password, $usuario['password'])) {
                $_SESSION['user_id']  = $usuario['id'];
                $_SESSION['username'] = $usuario['username'];
                $_SESSION['rol']      = $usuario['rol'];
                $_SESSION['cabana_id']= $usuario['cabana_id'];
            
                // ── LOG: login exitoso ──
                registrarLog($pdo, 'login_exitoso',
                    "Login exitoso — rol: {$usuario['rol']}",
                    'auth', 'success');
            
                if ($usuario['rol'] === 'administrador') {
                    header('Location: admin/dashboard.php');
                } elseif ($usuario['rol'] === 'encargado_consejeros') {
                    header('Location: encargado_consejeros/dashboard.php');
                } elseif ($usuario['rol'] === 'administracion') {
                    header('Location: administracion/dashboard.php');
                } elseif ($usuario['rol'] === 'consejero') {
                    header('Location: consejero/dashboard.php');
                } elseif ($usuario['rol'] === 'apoyo') {
                    header('Location: apoyo/dashboard.php');
                } elseif ($usuario['rol'] === 'admisiones') {
                    header('Location: admisiones/dashboard.php');
                }
                exit();  
            } else {
                $stmt = $pdo->prepare("SELECT * FROM cabanas WHERE nombre_cabana = ? AND activa = 1");
                $stmt->execute([$username]);
                $cabana = $stmt->fetch();

                if ($cabana && verificarPassword($password, $cabana['password_cabana'])) {
                    $_SESSION['user_id']  = 'cabana_' . $cabana['id'];
                    $_SESSION['username'] = $cabana['nombre_cabana'];
                    $_SESSION['rol']      = 'consejero';
                    $_SESSION['cabana_id']= $cabana['id'];

                    // ── LOG: login cabaña ──
                    registrarLog($pdo, 'login_exitoso',
                        "Login por cabaña: {$cabana['nombre_cabana']}",
                        'auth', 'success');

                    header('Location: consejero/dashboard.php');
                    exit();
                }
            }

            // ── LOG: login fallido ──
            registrarLog($pdo, 'login_fallido',
                "Intento fallido con username: '{$username}'",
                'auth', 'warning');
            $error = 'Usuario o contraseña incorrectos';  
        } catch (Exception $e) {  
            $error = 'Error en el sistema: ' . $e->getMessage();  
        }  
    } else {  
        $error = 'Por favor complete todos los campos';  
    }  
}  
?>  
<!DOCTYPE html>  
<html lang="es">  
<head>  
    <meta charset="UTF-8">  
    <meta name="viewport" content="width=device-width, initial-scale=1.0">  
    <title>Iniciar Sesión — Campamento Palabra de Vida</title>  

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">  
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts: Merriweather -->
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&display=swap" rel="stylesheet">

    <style>
        /* ══════════════════════════════════════════════════════
           LOGIN — Word of Life Brand Colors
           Variables locales (página standalone, sin style.css)
           #004f68 Dark Blue · #007ea1 Mid Blue · #73d1f5 Light Blue
        ══════════════════════════════════════════════════════ */
        :root {
            --wol-dark-blue:  #004f68;
            --wol-mid-blue:   #007ea1;
            --wol-light-blue: #73d1f5;
            --wol-orange:     #e99531;
            --wol-black-90:   #414042;
            --wol-black-50:   #939598;
            --wol-black-10:   #e6e7e8;
            --wol-white:      #ffffff;
            --font-primary:   'Helvetica Neue', 'Nimbus Sans', Arial, sans-serif;
            --font-secondary: 'Merriweather', Georgia, serif;
            --shadow-md:      0 8px 30px rgba(0, 79, 104, 0.25);
            --radius-md:      10px;
            --radius-lg:      15px;
            --transition:     all 0.25s ease;
        }

        /* ── Base ── */
        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: var(--font-primary);
            background-color: var(--wol-dark-blue);
            background-image:
                radial-gradient(circle at 20% 50%, rgba(0, 126, 161, 0.4) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(115, 209, 245, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 60% 80%, rgba(0, 79, 104, 0.6) 0%, transparent 50%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        /* ── Card principal ── */
        .login-card {
            background: var(--wol-white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 420px;
        }

        /* ── Header ── */
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-icon-wrap {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, var(--wol-dark-blue), var(--wol-mid-blue));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
            box-shadow: 0 4px 16px rgba(0, 79, 104, 0.3);
        }

        .login-icon-wrap i {
            font-size: 2rem;
            color: var(--wol-light-blue);
        }

        .login-header h2 {
            font-family: var(--font-primary);
            font-weight: 700;
            font-size: 1.35rem;
            color: var(--wol-dark-blue);
            margin-bottom: 0.25rem;
            line-height: 1.3;
        }

        .login-header p {
            color: var(--wol-black-50);
            font-size: 0.9rem;
            margin-bottom: 0;
        }

        /* ── Divisor ── */
        .login-divider {
            border-color: var(--wol-black-10);
            margin: 0 0 1.75rem;
        }

        /* ── Labels ── */
        .form-label {
            font-weight: 600;
            font-size: 0.88rem;
            color: var(--wol-black-90);
            margin-bottom: 0.35rem;
        }

        /* ── Inputs ── */
        .form-control {
            font-family: var(--font-primary);
            font-size: 0.93rem;
            border-color: var(--wol-black-10);
            color: var(--wol-black-90);
            border-radius: 0 6px 6px 0;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--wol-mid-blue);
            box-shadow: 0 0 0 0.2rem rgba(0, 126, 161, 0.2);
        }

        .form-control::placeholder {
            color: var(--wol-black-50);
            font-size: 0.88rem;
        }

        /* ── Input group ── */
        .input-group-text {
            background-color: var(--wol-dark-blue);
            border-color: var(--wol-dark-blue);
            color: var(--wol-light-blue);
            border-radius: 6px 0 0 6px;
            width: 42px;
            justify-content: center;
        }

        /* ── Toggle password ── */
        .btn-toggle-pass {
            background-color: var(--wol-black-10);
            border-color: var(--wol-black-10);
            border-left: none;
            color: var(--wol-black-50);
            border-radius: 0 6px 6px 0;
            transition: var(--transition);
        }

        .btn-toggle-pass:hover {
            background-color: var(--wol-mid-blue);
            border-color: var(--wol-mid-blue);
            color: var(--wol-white);
        }

        /* ── Hint bajo el campo ── */
        .field-hint {
            font-size: 0.78rem;
            color: var(--wol-black-50);
            margin-top: 0.3rem;
            line-height: 1.5;
        }

        .field-hint i {
            color: var(--wol-mid-blue);
            margin-right: 3px;
        }

        /* ── Botón submit ── */
        .btn-login {
            background-color: var(--wol-dark-blue);
            border-color: var(--wol-dark-blue);
            color: var(--wol-white);
            font-family: var(--font-primary);
            font-weight: 700;
            font-size: 0.95rem;
            padding: 0.65rem;
            border-radius: 8px;
            letter-spacing: 0.3px;
            transition: var(--transition);
            width: 100%;
        }

        .btn-login:hover {
            background-color: var(--wol-mid-blue);
            border-color: var(--wol-mid-blue);
            color: var(--wol-white);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 79, 104, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login i {
            margin-right: 6px;
        }

        /* ── Alerta de error ── */
        .alert-danger {
            background-color: #fce4e6;
            border-left: 4px solid #c42a36;
            border-radius: var(--radius-md);
            color: #6b1219;
            font-size: 0.88rem;
            padding: 0.75rem 1rem;
        }

        /* ── Footer info ── */
        .login-footer {
            text-align: center;
            margin-top: 1.75rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--wol-black-10);
        }

        .login-footer small {
            color: var(--wol-black-50);
            font-size: 0.78rem;
            line-height: 1.6;
        }

        .login-footer i {
            color: var(--wol-mid-blue);
        }

        /* ── Branding inferior ── */
        .login-brand-footer {
            text-align: center;
            margin-top: 1.5rem;
        }

        .login-brand-footer span {
            color: rgba(255, 255, 255, 0.45);
            font-size: 0.78rem;
            letter-spacing: 0.3px;
        }

        .login-brand-footer a {
            color: rgba(115, 209, 245, 0.7);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        .login-brand-footer a:hover {
            color: var(--wol-light-blue);
        }

        /* ── Responsive ── */
        @media (max-width: 480px) {
            .login-card {
                padding: 2rem 1.25rem;
                border-radius: var(--radius-md);
            }
            .login-header h2 {
                font-size: 1.2rem;
            }
            .login-icon-wrap {
                width: 60px;
                height: 60px;
            }
            .login-icon-wrap i {
                font-size: 1.6rem;
            }
        }
    </style>
</head>
<body>

    <div class="w-100" style="max-width: 480px;">

        <!-- ══ LOGIN CARD ══ -->
        <div class="login-card">

            <!-- Header -->
            <div class="login-header">
                <div class="login-icon-wrap">
                    <i class="fas fa-campground"></i>
                </div>
                <h2>Campamento Palabra de Vida</h2>
                <p>Sistema de Gestión</p>
            </div>

            <hr class="login-divider">

            <!-- Error -->
            <?php if ($error): ?>
            <div class="alert alert-danger mb-3">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <!-- Formulario -->
            <form method="POST" autocomplete="off">

                <!-- Usuario / Cabaña -->
                <div class="mb-3">
                    <label for="username" class="form-label">
                        <i class="fas fa-user me-1" style="color: var(--wol-mid-blue);"></i>
                        Usuario / Cabaña
                    </label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-user"></i>
                        </span>
                        <input type="text" class="form-control" id="username" name="username"
                               placeholder="admin o nombre de cabaña"
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                               autocomplete="username" required autofocus>
                    </div>
                    <div class="field-hint">
                        <i class="fas fa-info-circle"></i>
                        Administradores: usar su nombre de usuario<br>
                        <i class="fas fa-home"></i>
                        Consejeros: usar el nombre de su cabaña
                    </div>
                </div>

                <!-- Contraseña -->
                <div class="mb-4">
                    <label for="password" class="form-label">
                        <i class="fas fa-lock me-1" style="color: var(--wol-mid-blue);"></i>
                        Contraseña
                    </label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" class="form-control" id="password"
                               name="password"  autocomplete="current-password" required>
                        <button class="btn btn-toggle-pass" type="button" id="togglePass"
                                title="Mostrar/ocultar contraseña">
                            <i class="fas fa-eye" id="togglePassIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Submit -->
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
                </button>
            </form>

            <!-- Footer info -->
            <div class="login-footer">
                <small>
                    <i class="fas fa-shield-alt"></i>
                    Acceso restringido al personal autorizado del campamento
                </small>
            </div>

        </div>
        <!-- ══ FIN LOGIN CARD ══ -->

        <!-- Branding -->
        <div class="login-brand-footer">
            <span>
                <a href="https://www.wol.org" target="_blank">Word of Life</a>
                · Campamento PV · <?php echo date('Y'); ?>
            </span>
        </div>

    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // ── Toggle mostrar/ocultar contraseña ──
    document.getElementById('togglePass')?.addEventListener('click', function () {
        const input = document.getElementById('password');
        const icon  = document.getElementById('togglePassIcon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
    </script>

</body>
</html>