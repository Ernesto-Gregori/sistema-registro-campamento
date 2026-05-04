<?php
// offline.php — No requiere DB ni sesión
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sin conexión — Campamento PV</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --wol-dark-blue:  #004f68;
            --wol-mid-blue:   #007ea1;
            --wol-light-blue: #73d1f5;
            --wol-orange:     #e99531;
            --wol-white:      #ffffff;
        }
        body {
            background-color: var(--wol-dark-blue);
            background-image:
                radial-gradient(circle at 20% 50%, rgba(0,126,161,0.4) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(115,209,245,0.1) 0%, transparent 40%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Helvetica Neue', Arial, sans-serif;
            color: var(--wol-white);
        }
        .offline-card {
            text-align: center;
            padding: 3rem 2rem;
            max-width: 460px;
            width: 100%;
        }
        .offline-icon {
            width: 90px;
            height: 90px;
            background: rgba(115,209,245,0.1);
            border: 2px solid rgba(115,209,245,0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        .offline-icon i {
            font-size: 2.5rem;
            color: var(--wol-light-blue);
        }
        .offline-card h1 {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--wol-white);
            margin-bottom: 0.5rem;
        }
        .offline-card p {
            color: rgba(255,255,255,0.65);
            font-size: 0.95rem;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .offline-tips {
            background: rgba(115,209,245,0.08);
            border: 1px solid rgba(115,209,245,0.2);
            border-radius: 10px;
            padding: 1.25rem;
            text-align: left;
            margin-bottom: 2rem;
        }
        .offline-tips h6 {
            color: var(--wol-light-blue);
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.75rem;
        }
        .offline-tips li {
            color: rgba(255,255,255,0.7);
            font-size: 0.88rem;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .offline-tips li i {
            color: var(--wol-light-blue);
            width: 16px;
        }
        .btn-retry {
            background-color: var(--wol-mid-blue);
            border: none;
            color: var(--wol-white);
            font-weight: 700;
            padding: 0.65rem 2rem;
            border-radius: 8px;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-retry:hover {
            background-color: var(--wol-dark-blue);
            transform: translateY(-1px);
        }
        .btn-back {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.2);
            color: rgba(255,255,255,0.6);
            font-size: 0.88rem;
            padding: 0.5rem 1.25rem;
            border-radius: 8px;
            cursor: pointer;
            margin-left: 0.75rem;
            transition: all 0.25s ease;
        }
        .btn-back:hover {
            border-color: rgba(255,255,255,0.4);
            color: var(--wol-white);
        }
        .connection-status {
            margin-top: 1.5rem;
            font-size: 0.8rem;
            color: rgba(255,255,255,0.4);
        }
        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: var(--wol-orange);
            margin-right: 5px;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.3; }
        }
    </style>
</head>
<body>
    <div class="offline-card">

        <!-- Ícono -->
        <div class="offline-icon">
            <i class="fas fa-wifi-slash"></i>
        </div>

        <!-- Título -->
        <h1>Sin conexión</h1>
        <p>
            No hay conexión a internet en este momento.<br>
            Los datos guardados localmente se sincronizarán<br>
            automáticamente cuando vuelva la conexión.
        </p>

        <!-- Tips -->
        <div class="offline-tips">
            <h6><i class="fas fa-lightbulb me-1"></i> Mientras tanto puedes:</h6>
            <ul class="list-unstyled mb-0">
                <li>
                    <i class="fas fa-check-circle"></i>
                    Ir a páginas que visitaste recientemente
                </li>
                <li>
                    <i class="fas fa-check-circle"></i>
                    Llenar formularios — se guardan localmente
                </li>
                <li>
                    <i class="fas fa-check-circle"></i>
                    Al volver online, todo se sincroniza solo
                </li>
                <li>
                    <i class="fas fa-times-circle" style="color: rgba(255,255,255,0.3);"></i>
                    Ver datos nuevos del servidor (requiere conexión)
                </li>
            </ul>
        </div>

        <!-- Botones -->
        <div>
            <button class="btn-retry" onclick="window.location.reload()">
                <i class="fas fa-redo"></i> Reintentar
            </button>
            <button class="btn-back" onclick="window.history.back()">
                <i class="fas fa-arrow-left"></i> Volver
            </button>
        </div>

        <!-- Estado -->
        <div class="connection-status">
            <span class="status-dot"></span>
            Verificando conexión automáticamente...
        </div>

    </div>

    <script>
    // Auto-reintentar cada 10 segundos
    setInterval(() => {
        if (navigator.onLine) {
            window.history.back();
        }
    }, 10000);
    </script>
</body>
</html>