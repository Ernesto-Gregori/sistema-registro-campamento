<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esEncargadoConsejeros()) {
    header('Location: ../consejero/dashboard.php');
    exit();
}

$tipo      = $_GET['tipo']      ?? 'general';
$year      = $_GET['year']      ?? obtenerAnioCampamento();
$semana_id = (int)($_GET['semana_id'] ?? 0) ?: null;
$cabana_id = (int)($_GET['cabana_id'] ?? 0) ?: null;

// Cargar datos según tipo
try {

    // Semana seleccionada
    $semana = null;
    if ($semana_id) {
        $stmt = $pdo->prepare("SELECT * FROM semanas_campamento WHERE id = ?");
        $stmt->execute([$semana_id]);
        $semana = $stmt->fetch();
    }

    $where  = $semana_id ? "a.semana_id = ?"       : "a.year_campamento = ?";
    $param  = $semana_id ? $semana_id               : $year;
    $whereC = $semana_id ? "AND a.semana_id = ?"    : "AND a.year_campamento = ?";

    // ── Datos generales (siempre se cargan) ──────────────────
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                AS total,
            COUNT(CASE WHEN a.sexo='masculino'           THEN 1 END) AS masculino,
            COUNT(CASE WHEN a.sexo='femenino'            THEN 1 END) AS femenino,
            COUNT(CASE WHEN a.recibio_cristo_semana  = 1 THEN 1 END) AS recibio_cristo,
            COUNT(CASE WHEN a.consagro_vida_fogata   = 1 THEN 1 END) AS consagro_vida,
            COUNT(CASE WHEN a.era_creyente_antes     = 1 THEN 1 END) AS era_creyente,
            COUNT(CASE WHEN a.asiste_iglesia         = 1 THEN 1 END) AS asiste_iglesia,
            COUNT(CASE WHEN a.primera_vez_campamento = 1 THEN 1 END) AS primera_vez
        FROM acampantes a
        WHERE $where AND a.estado = 'activo'
    ");
    $stmt->execute([$param]);
    $general = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT a.id)                                         AS total_acampantes,
            COUNT(DISTINCT CASE WHEN sc.id IS NOT NULL THEN a.id END)    AS con_consejeria,
            COUNT(DISTINCT sc.id)                                        AS total_sesiones
        FROM acampantes a
        LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
        WHERE $where AND a.estado = 'activo'
    ");
    $stmt->execute([$param]);
    $cons = $stmt->fetch();

    // ── Datos por cabaña ─────────────────────────────────────
    $cabanas = [];
    if (in_array($tipo, ['general', 'cabanas', 'completo', 'cabana'])) {
        $stmt = $pdo->prepare("
            SELECT c.id, c.nombre_cabana, c.consejero_principal,
                   c.capacidad_maxima, c.genero, c.equipo,
                   COUNT(DISTINCT a.id)                                          AS total_acampantes,
                   COUNT(DISTINCT sc.id)                                         AS total_sesiones,
                   COUNT(DISTINCT CASE WHEN sc.id IS NOT NULL THEN a.id END)     AS con_consejeria,
                   COUNT(DISTINCT CASE WHEN a.recibio_cristo_semana=1 THEN a.id END) AS recibio_cristo,
                   COUNT(DISTINCT CASE WHEN a.consagro_vida_fogata =1 THEN a.id END) AS consagro_vida
            FROM cabanas c
            LEFT JOIN acampantes a ON c.id = a.cabana_id
                AND a.estado = 'activo' $whereC
            LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
            WHERE c.activa = 1
            GROUP BY c.id
            ORDER BY c.equipo, c.genero, c.nombre_cabana
        ");
        $stmt->execute([$param]);
        $cabanas = $stmt->fetchAll();
    }

    // ── Acampantes de una cabaña específica ──────────────────
    $cabana_info   = null;
    $acampantes_cb = [];
    if ($tipo === 'cabana' && $cabana_id) {
        $stmt = $pdo->prepare("SELECT * FROM cabanas WHERE id = ?");
        $stmt->execute([$cabana_id]);
        $cabana_info = $stmt->fetch();

        $stmt = $pdo->prepare("
            SELECT a.*,
                   COUNT(DISTINCT sc.numero_sesion) AS sesiones_realizadas,
                   MAX(sc.fecha_sesion)             AS ultima_sesion
            FROM acampantes a
            LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
            WHERE a.cabana_id = ? AND $where AND a.estado = 'activo'
            GROUP BY a.id
            ORDER BY a.nombre
        ");
        $stmt->execute([$cabana_id, $param]);
        $acampantes_cb = $stmt->fetchAll();
    }

    // ── Por iglesia ──────────────────────────────────────────
    $por_iglesia = [];
    if ($tipo === 'iglesia') {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(a.iglesia),''), 'Sin iglesia') AS iglesia,
                COUNT(DISTINCT a.id)                                  AS total,
                COUNT(DISTINCT CASE WHEN a.sexo='masculino' THEN a.id END) AS masculino,
                COUNT(DISTINCT CASE WHEN a.sexo='femenino'  THEN a.id END) AS femenino,
                COUNT(DISTINCT CASE WHEN a.recibio_cristo_semana=1 THEN a.id END) AS recibio_cristo,
                COUNT(DISTINCT CASE WHEN a.consagro_vida_fogata =1 THEN a.id END) AS consagro_vida,
                COUNT(DISTINCT CASE WHEN a.primera_vez_campamento=1 THEN a.id END) AS primera_vez,
                COUNT(DISTINCT sc.id)                                 AS total_sesiones
            FROM acampantes a
            LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
            WHERE $where AND a.estado = 'activo'
            GROUP BY iglesia
            ORDER BY total DESC
        ");
        $stmt->execute([$param]);
        $por_iglesia = $stmt->fetchAll();
    }

    // ── Individual: todos con detalle ────────────────────────
    $todos_acampantes = [];
    if ($tipo === 'individual') {
        $filtro_cab = $cabana_id ? "AND a.cabana_id = $cabana_id" : "";
        $stmt = $pdo->prepare("
            SELECT a.*, c.nombre_cabana,
                   COUNT(DISTINCT sc.id)   AS total_sesiones,
                   MAX(sc.fecha_sesion)    AS ultima_sesion
            FROM acampantes a
            LEFT JOIN cabanas c ON a.cabana_id = c.id
            LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
            WHERE $where AND a.estado = 'activo' $filtro_cab
            GROUP BY a.id
            ORDER BY c.nombre_cabana, a.nombre
        ");
        $stmt->execute([$param]);
        $todos_acampantes = $stmt->fetchAll();
    }

    $capacidad_total = array_sum(array_column($cabanas, 'capacidad_maxima'));
    
    // ── Detalle de iglesia para imprimir ─────────────────────
    $iglesia_filtro_imp   = trim($_GET['iglesia_filtro'] ?? '');
    $acampantes_igl_print = [];
    $resumen_iglesia      = null;   // NUEVO

    if ($tipo === 'iglesia_detalle' && $iglesia_filtro_imp) {
        $iglesia_cond = $iglesia_filtro_imp === 'Sin iglesia registrada'
            ? "(a.iglesia IS NULL OR TRIM(a.iglesia) = '')"
            : "TRIM(a.iglesia) = ?";

        $params_igl_p = [$param];
        if ($iglesia_filtro_imp !== 'Sin iglesia registrada') {
            $params_igl_p[] = $iglesia_filtro_imp;
        }

        // Acampantes con temas
        $stmt = $pdo->prepare("
            SELECT a.nombre, a.edad, a.sexo,
                   a.recibio_cristo_semana, a.consagro_vida_fogata,
                   a.era_creyente_antes, a.primera_vez_campamento,
                   c.nombre_cabana,
                   COUNT(DISTINCT sc.id) AS total_sesiones,
                   GROUP_CONCAT(
                       DISTINCT COALESCE(tc.tema, sc.tema_personalizado)
                       ORDER BY sc.numero_sesion
                       SEPARATOR ' · '
                   ) AS temas_tratados
            FROM acampantes a
            LEFT JOIN cabanas c             ON a.cabana_id    = c.id
            LEFT JOIN sesiones_consejeria sc ON sc.acampante_id = a.id
            LEFT JOIN temas_consejeria tc   ON sc.tema_id     = tc.id
            WHERE $where AND a.estado = 'activo'
              AND $iglesia_cond
            GROUP BY a.id
            ORDER BY a.nombre
        ");
        $stmt->execute($params_igl_p);
        $acampantes_igl_print = $stmt->fetchAll();

        // NUEVO: resumen propio de la iglesia
        $params_res = [$param];
        if ($iglesia_filtro_imp !== 'Sin iglesia registrada') {
            $params_res[] = $iglesia_filtro_imp;
        }
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)                                                  AS total,
                COUNT(CASE WHEN sexo = 'masculino'            THEN 1 END) AS masculino,
                COUNT(CASE WHEN sexo = 'femenino'             THEN 1 END) AS femenino,
                COUNT(CASE WHEN recibio_cristo_semana  = 1    THEN 1 END) AS recibio_cristo,
                COUNT(CASE WHEN consagro_vida_fogata   = 1    THEN 1 END) AS consagro_vida,
                COUNT(CASE WHEN era_creyente_antes     = 1    THEN 1 END) AS era_creyente,
                COUNT(CASE WHEN primera_vez_campamento = 1    THEN 1 END) AS primera_vez
            FROM acampantes a
            WHERE $where AND estado = 'activo'
              AND $iglesia_cond
        ");
        $stmt->execute($params_res);
        $resumen_iglesia = $stmt->fetch();
    }

} catch (Exception $e) {
    die("Error al generar reporte: " . $e->getMessage());
}

// Título del reporte
$titulos = [
    'general'    => 'Reporte General',
    'cabanas'    => 'Reporte por Cabañas',
    'cabana'     => 'Reporte de Cabaña: ' . ($cabana_info['nombre_cabana'] ?? ''),
    'iglesia'    => 'Reporte por Iglesia',
    'individual' => 'Reporte Individual',
    'completo'   => 'Reporte Completo',
];
$titulo_reporte = $titulos[$tipo] ?? 'Reporte';
$periodo = $semana ? htmlspecialchars($semana['nombre']) : "Campamento $year";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= $titulo_reporte ?> — <?= $periodo ?></title>
    <style>
        /* ── Reset e impresión ─────────────────────────────── */
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #000;
            background: #fff;
            padding: 20px;
        }

        /* ── Encabezado ────────────────────────────────────── */
        .report-header {
            text-align: center;
            border-bottom: 3px solid #1a3a5c;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }
        .report-header h1 {
            font-size: 18px;
            color: #1a3a5c;
            margin-bottom: 4px;
        }
        .report-header .periodo {
            font-size: 12px;
            color: #555;
        }
        .report-header .fecha-gen {
            font-size: 10px;
            color: #888;
            margin-top: 4px;
        }

        /* ── Secciones ─────────────────────────────────────── */
        .seccion {
            margin-bottom: 22px;
        }
        .seccion-titulo {
            font-size: 13px;
            font-weight: bold;
            color: #1a3a5c;
            border-bottom: 1px solid #1a3a5c;
            padding-bottom: 4px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ── Tarjetas de resumen ───────────────────────────── */
        .resumen-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 16px;
        }
        .resumen-card {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 8px;
            text-align: center;
        }
        .resumen-card .numero {
            font-size: 22px;
            font-weight: bold;
            color: #1a3a5c;
        }
        .resumen-card .etiqueta {
            font-size: 10px;
            color: #555;
            margin-top: 2px;
        }
        .resumen-card .sub {
            font-size: 9px;
            color: #888;
        }

        /* ── Tablas ────────────────────────────────────────── */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            font-size: 10px;
        }
        thead th {
            background: #1a3a5c;
            color: #fff;
            padding: 5px 6px;
            text-align: left;
            font-size: 10px;
        }
        thead th.center { text-align: center; }
        tbody td {
            padding: 4px 6px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        tbody tr:nth-child(even) td { background: #f8f8f8; }
        tfoot td {
            padding: 5px 6px;
            border-top: 2px solid #1a3a5c;
            font-weight: bold;
            font-size: 10px;
        }
        td.center { text-align: center; }
        .badge-ok   { color: #198754; font-weight: bold; }
        .badge-warn { color: #d97706; font-weight: bold; }
        .badge-no   { color: #dc3545; font-weight: bold; }

        /* ── Barra de progreso ─────────────────────────────── */
        .barra-wrap {
            background: #e9ecef;
            border-radius: 3px;
            height: 7px;
            width: 100%;
        }
        .barra-fill {
            height: 7px;
            border-radius: 3px;
            background: #1a3a5c;
        }

        /* ── Tarjeta de cabaña individual ─────────────────── */
        .cabana-card {
            border: 1px solid #bbb;
            border-radius: 4px;
            padding: 10px 12px;
            margin-bottom: 10px;
            page-break-inside: avoid;
        }
        .cabana-card-header {
            font-weight: bold;
            font-size: 12px;
            color: #1a3a5c;
            border-bottom: 1px solid #ddd;
            padding-bottom: 4px;
            margin-bottom: 6px;
        }

        /* ── Botón imprimir (solo pantalla) ────────────────── */
        .print-btn {
            display: inline-block;
            background: #1a3a5c;
            color: #fff;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            margin-bottom: 16px;
            text-decoration: none;
        }
        .back-btn {
            display: inline-block;
            background: #6c757d;
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            margin-bottom: 16px;
            margin-right: 8px;
            text-decoration: none;
        }

        /* ── Solo impresión ────────────────────────────────── */
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; font-size: 10px; }
            .report-header { border-color: #000; }
            .seccion-titulo { color: #000; border-color: #000; }
            thead th { background: #333 !important; -webkit-print-color-adjust: exact; }
            .resumen-card .numero { color: #000; }
            a { color: #000 !important; text-decoration: none; }
            .cabana-card { page-break-inside: avoid; }
        }

        @page {
            margin: 1.5cm;
            size: A4;
        }
    </style>
</head>
<body>

<!-- Botones (no se imprimen) -->
<div class="no-print" style="margin-bottom:12px;">
    <a href="reportes.php?semana_id=<?= $semana_id ?>&tipo=<?= $tipo ?>"
       class="back-btn">← Volver</a>
    <button onclick="window.print()" class="print-btn">🖨️ Imprimir / Guardar PDF</button>
</div>

<!-- Encabezado del reporte -->
<div class="report-header">
    <h1>Campamento Palabra de Vida — <?= htmlspecialchars($titulo_reporte) ?></h1>
    <div class="periodo">
        <?= $periodo ?>
        <?php if ($semana): ?>
            &nbsp;|&nbsp;
            <?= date('d/m/Y', strtotime($semana['fecha_inicio'])) ?>
            al
            <?= date('d/m/Y', strtotime($semana['fecha_fin'])) ?>
        <?php endif; ?>
    </div>
    <div class="fecha-gen">
        Generado el <?= date('d/m/Y H:i') ?>
    </div>
</div>

<?php
// Porcentajes reutilizables
$total       = $general['total'] ?: 1;
$pct_cristo  = round(($general['recibio_cristo'] / $total) * 100, 1);
$pct_cons_v  = round(($general['consagro_vida']  / $total) * 100, 1);
$pct_cons_s  = $cons['total_acampantes'] > 0
    ? round(($cons['con_consejeria'] / $cons['total_acampantes']) * 100, 1) : 0;
$cap_total   = array_sum(array_column($cabanas, 'capacidad_maxima')) ?: 1;
$pct_ocup    = round(($general['total'] / $cap_total) * 100, 1);
?>

<?php
// Si es detalle de iglesia, usar resumen propio. Si no, usar el general del campamento.
$res = ($tipo === 'iglesia_detalle' && $resumen_iglesia) ? $resumen_iglesia : $general;
$cons_total_res = ($tipo === 'iglesia_detalle' && $resumen_iglesia)
    ? array_sum(array_column($acampantes_igl_print, 'total_sesiones'))
    : ($cons['total_sesiones'] ?? 0);
$con_consejeria_res = ($tipo === 'iglesia_detalle' && $resumen_iglesia)
    ? count(array_filter($acampantes_igl_print, fn($a) => $a['total_sesiones'] > 0))
    : ($cons['con_consejeria'] ?? 0);

$total_res      = $res['total'] ?: 1;
$pct_cristo_res = round(($res['recibio_cristo'] / $total_res) * 100, 1);
$pct_cons_v_res = round(($res['consagro_vida']  / $total_res) * 100, 1);
$pct_cons_s_res = $res['total'] > 0
    ? round(($con_consejeria_res / $res['total']) * 100, 1) : 0;
$pct_ocup_res   = ($tipo === 'iglesia_detalle')
    ? null   // no aplica ocupación para una iglesia
    : round(($general['total'] / $cap_total) * 100, 1);
?>

<!-- ══ RESUMEN ══ -->
<div class="seccion">
    <div class="seccion-titulo">
        <?= $tipo === 'iglesia_detalle'
            ? 'Resumen: ' . htmlspecialchars($iglesia_filtro_imp)
            : 'Resumen General' ?>
    </div>
    <div class="resumen-grid">
        <div class="resumen-card">
            <div class="numero"><?= $res['total'] ?></div>
            <div class="etiqueta">Acampantes</div>
            <div class="sub">
                <?= $pct_ocup_res !== null ? $pct_ocup_res . '% ocupación' : 'de esta iglesia' ?>
            </div>
        </div>
        <div class="resumen-card">
            <div class="numero"><?= $con_consejeria_res ?></div>
            <div class="etiqueta">Con Consejería</div>
            <div class="sub"><?= $pct_cons_s_res ?>% del total</div>
        </div>
        <div class="resumen-card">
            <div class="numero"><?= $res['recibio_cristo'] ?></div>
            <div class="etiqueta">Recibieron a Cristo</div>
            <div class="sub"><?= $pct_cristo_res ?>%</div>
        </div>
        <div class="resumen-card">
            <div class="numero"><?= $res['consagro_vida'] ?></div>
            <div class="etiqueta">Consagraciones</div>
            <div class="sub"><?= $pct_cons_v_res ?>%</div>
        </div>
    </div>
    <div class="resumen-grid">
        <div class="resumen-card">
            <div class="numero"><?= $res['masculino'] ?></div>
            <div class="etiqueta">Masculino</div>
        </div>
        <div class="resumen-card">
            <div class="numero"><?= $res['femenino'] ?></div>
            <div class="etiqueta">Femenino</div>
        </div>
        <div class="resumen-card">
            <div class="numero"><?= $res['primera_vez'] ?></div>
            <div class="etiqueta">Primera vez</div>
        </div>
        <div class="resumen-card">
            <div class="numero"><?= $cons_total_res ?></div>
            <div class="etiqueta">Sesiones de consejería</div>
        </div>
    </div>
</div>

<!-- ══ TABLA CABAÑAS (general, cabanas, completo) ══ -->
<?php if (in_array($tipo, ['general', 'cabanas', 'completo']) && !empty($cabanas)): ?>
<div class="seccion">
    <div class="seccion-titulo">Estadísticas por Cabaña</div>
    <table>
        <thead>
            <tr>
                <th>Cabaña</th>
                <th>Consejero</th>
                <th class="center">Acamp.</th>
                <th class="center">Cap.</th>
                <th class="center">Ocup.</th>
                <th class="center">Sesiones</th>
                <th class="center">% Cons.</th>
                <th class="center">✝ Cristo</th>
                <th class="center">Consagr.</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($cabanas as $cab):
            $pct_o = $cab['capacidad_maxima'] > 0
                ? round(($cab['total_acampantes'] / $cab['capacidad_maxima']) * 100) : 0;
            $pct_c = $cab['total_acampantes'] > 0
                ? round(($cab['con_consejeria'] / $cab['total_acampantes']) * 100) : 0;
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($cab['nombre_cabana']) ?></strong></td>
            <td><?= htmlspecialchars($cab['consejero_principal'] ?: '—') ?></td>
            <td class="center"><?= $cab['total_acampantes'] ?></td>
            <td class="center"><?= $cab['capacidad_maxima'] ?></td>
            <td class="center"><?= $pct_o ?>%</td>
            <td class="center"><?= $cab['total_sesiones'] ?></td>
            <td class="center">
                <span class="<?= $pct_c >= 80 ? 'badge-ok' : ($pct_c >= 50 ? 'badge-warn' : 'badge-no') ?>">
                    <?= $pct_c ?>%
                </span>
            </td>
            <td class="center"><?= $cab['recibio_cristo'] ?></td>
            <td class="center"><?= $cab['consagro_vida'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">TOTAL</td>
                <td class="center"><?= $general['total'] ?></td>
                <td class="center"><?= $cap_total ?></td>
                <td class="center"><?= $pct_ocup ?>%</td>
                <td class="center"><?= $cons['total_sesiones'] ?></td>
                <td class="center"><?= $pct_cons_s ?>%</td>
                <td class="center"><?= $general['recibio_cristo'] ?></td>
                <td class="center"><?= $general['consagro_vida'] ?></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

<!-- ══ ACAMPANTES DE UNA CABAÑA ══ -->
<?php if ($tipo === 'cabana' && $cabana_info): ?>
<div class="seccion">
    <div class="seccion-titulo">
        Cabaña: <?= htmlspecialchars($cabana_info['nombre_cabana']) ?>
        — Consejero: <?= htmlspecialchars($cabana_info['consejero_principal'] ?: 'Sin asignar') ?>
    </div>
    <?php if (empty($acampantes_cb)): ?>
        <p style="color:#888;">Sin acampantes registrados en esta cabaña.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Edad</th>
                <th>Iglesia</th>
                <th class="center">Sesiones</th>
                <th class="center">✝ Cristo</th>
                <th class="center">Consagr.</th>
                <th class="center">1ra vez</th>
                <th>Última sesión</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($acampantes_cb as $ac):
            $color_ses = $ac['sesiones_realizadas'] >= 3 ? 'badge-ok'
                : ($ac['sesiones_realizadas'] >= 1 ? 'badge-warn' : 'badge-no');
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($ac['nombre']) ?></strong></td>
            <td class="center"><?= $ac['edad'] ?? '-' ?></td>
            <td><?= htmlspecialchars($ac['iglesia'] ?? '-') ?></td>
            <td class="center">
                <span class="<?= $color_ses ?>">
                    <?= $ac['sesiones_realizadas'] ?>/3
                </span>
            </td>
            <td class="center">
                <?= $ac['recibio_cristo_semana'] ? '<span class="badge-ok">Sí</span>' : 'No' ?>
            </td>
            <td class="center">
                <?= $ac['consagro_vida_fogata'] ? '<span class="badge-ok">Sí</span>' : 'No' ?>
            </td>
            <td class="center">
                <?= $ac['primera_vez_campamento'] ? 'Sí' : 'No' ?>
            </td>
            <td>
                <?= $ac['ultima_sesion']
                    ? date('d/m/Y', strtotime($ac['ultima_sesion']))
                    : '<span class="badge-no">Sin sesiones</span>' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Total: <?= count($acampantes_cb) ?> acampantes</td>
                <td></td>
                <td class="center"><?= array_sum(array_column($acampantes_cb,'recibio_cristo_semana')) ?></td>
                <td class="center"><?= array_sum(array_column($acampantes_cb,'consagro_vida_fogata')) ?></td>
                <td></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ POR IGLESIA ══ -->
<?php if ($tipo === 'iglesia' && !empty($por_iglesia)): ?>
<div class="seccion">
    <div class="seccion-titulo">Distribución por Iglesia</div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Iglesia</th>
                <th class="center">Total</th>
                <th class="center">Masc.</th>
                <th class="center">Fem.</th>
                <th class="center">1ra vez</th>
                <th class="center">✝ Cristo</th>
                <th class="center">Consagr.</th>
                <th class="center">Sesiones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($por_iglesia as $i => $igl): ?>
        <tr>
            <td class="center"><?= $i + 1 ?></td>
            <td><strong><?= htmlspecialchars($igl['iglesia']) ?></strong></td>
            <td class="center"><?= $igl['total'] ?></td>
            <td class="center"><?= $igl['masculino'] ?></td>
            <td class="center"><?= $igl['femenino'] ?></td>
            <td class="center"><?= $igl['primera_vez'] ?></td>
            <td class="center">
                <?php if ($igl['recibio_cristo'] > 0): ?>
                    <span class="badge-ok"><?= $igl['recibio_cristo'] ?></span>
                <?php else: ?>0<?php endif; ?>
            </td>
            <td class="center">
                <?php if ($igl['consagro_vida'] > 0): ?>
                    <span class="badge-ok"><?= $igl['consagro_vida'] ?></span>
                <?php else: ?>0<?php endif; ?>
            </td>
            <td class="center"><?= $igl['total_sesiones'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td></td>
                <td>TOTAL (<?= count($por_iglesia) ?> iglesias)</td>
                <td class="center"><?= $general['total'] ?></td>
                <td class="center"><?= $general['masculino'] ?></td>
                <td class="center"><?= $general['femenino'] ?></td>
                <td class="center"><?= $general['primera_vez'] ?></td>
                <td class="center"><?= $general['recibio_cristo'] ?></td>
                <td class="center"><?= $general['consagro_vida'] ?></td>
                <td class="center"><?= $cons['total_sesiones'] ?></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

<!-- ══ INDIVIDUAL ══ -->
<?php if ($tipo === 'individual' && !empty($todos_acampantes)): ?>
<div class="seccion">
    <div class="seccion-titulo">
        Detalle Individual
        <?= $cabana_id && isset($cabana_info['nombre_cabana'])
            ? '— ' . htmlspecialchars($cabana_info['nombre_cabana']) : '' ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Edad</th>
                <th>Sexo</th>
                <th>Iglesia</th>
                <th>Cabaña</th>
                <th class="center">Sesiones</th>
                <th class="center">✝ Cristo</th>
                <th class="center">Consagr.</th>
                <th class="center">1ra vez</th>
                <th>Última sesión</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($todos_acampantes as $ac):
            $color_ses = $ac['total_sesiones'] >= 3 ? 'badge-ok'
                : ($ac['total_sesiones'] >= 1 ? 'badge-warn' : 'badge-no');
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($ac['nombre']) ?></strong></td>
            <td class="center"><?= $ac['edad'] ?? '-' ?></td>
            <td class="center">
                <?= $ac['sexo'] === 'masculino' ? 'M' : 'F' ?>
            </td>
            <td><?= htmlspecialchars($ac['iglesia'] ?? '-') ?></td>
            <td><?= htmlspecialchars($ac['nombre_cabana'] ?? 'Sin asignar') ?></td>
            <td class="center">
                <span class="<?= $color_ses ?>"><?= $ac['total_sesiones'] ?>/3</span>
            </td>
            <td class="center">
                <?= $ac['recibio_cristo_semana'] ? '<span class="badge-ok">Sí</span>' : 'No' ?>
            </td>
            <td class="center">
                <?= $ac['consagro_vida_fogata'] ? '<span class="badge-ok">Sí</span>' : 'No' ?>
            </td>
            <td class="center">
                <?= $ac['primera_vez_campamento'] ? 'Sí' : 'No' ?>
            </td>
            <td>
                <?= $ac['ultima_sesion']
                    ? date('d/m/Y', strtotime($ac['ultima_sesion']))
                    : '<span class="badge-no">Sin sesiones</span>' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5">Total: <?= count($todos_acampantes) ?> acampantes</td>
                <td></td>
                <td class="center"><?= array_sum(array_column($todos_acampantes,'recibio_cristo_semana')) ?></td>
                <td class="center"><?= array_sum(array_column($todos_acampantes,'consagro_vida_fogata')) ?></td>
                <td></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

<!-- ══ DETALLE IGLESIA (impresión) ══ -->
<?php if ($tipo === 'iglesia_detalle' && !empty($acampantes_igl_print)): ?>
<div class="seccion">
    <div class="seccion-titulo">
        Iglesia: <?= htmlspecialchars($iglesia_filtro_imp) ?>
        — <?= count($acampantes_igl_print) ?> acampantes
    </div>
    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th class="center">Edad</th>
                <th class="center">Sexo</th>
                <th>Cabaña</th>
                <th>Temas tratados</th>
                <th class="center">¿Creyó / ya creía?</th>
                <th class="center">Consagración</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($acampantes_igl_print as $ac): ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($ac['nombre']) ?></strong>
                <?= $ac['primera_vez_campamento'] ? ' <small>(1ra vez)</small>' : '' ?>
            </td>
            <td class="center"><?= $ac['edad'] ?? '-' ?></td>
            <td class="center"><?= $ac['sexo'] === 'masculino' ? 'M' : 'F' ?></td>
            <td><?= htmlspecialchars($ac['nombre_cabana'] ?? 'Sin asignar') ?></td>
            <td>
                <small><?= htmlspecialchars($ac['temas_tratados'] ?? 'Sin sesiones') ?></small>
            </td>
            <td class="center">
                <?php if ($ac['recibio_cristo_semana']): ?>
                    <span class="badge-ok">Creyó esta semana</span>
                <?php elseif ($ac['era_creyente_antes']): ?>
                    <span style="color:#0d6efd; font-weight:bold;">Ya creía</span>
                <?php else: ?>
                    No registrado
                <?php endif; ?>
            </td>
            <td class="center">
                <?= $ac['consagro_vida_fogata']
                    ? '<span class="badge-ok">Sí</span>'
                    : 'No' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">Total: <?= count($acampantes_igl_print) ?> acampantes</td>
                <td></td>
                <td class="center">
                    <?= array_sum(array_column($acampantes_igl_print,'recibio_cristo_semana')) ?> creyeron
                </td>
                <td class="center">
                    <?= array_sum(array_column($acampantes_igl_print,'consagro_vida_fogata')) ?> consagraron
                </td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

</body>
</html>