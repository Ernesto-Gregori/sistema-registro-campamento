<?php
// admisiones/lista_acampantes.php
require_once '../config/database.php';
require_once '../includes/functions.php';

verificarLogin();
if (!esAdmisiones() && !esAdministrador() && !esEncargadoConsejeros()) {
    header('Location: ../login.php');
    exit();
}

$titulo      = "Lista de Acampantes";
$year        = obtenerAnioCampamento();
$semana_id   = $_GET['semana_id'] ?? null;

$filtro_pago = $_GET['pago']    ?? '';
$filtro_sexo = $_GET['sexo']    ?? '';
$filtro_curp = $_GET['curp']    ?? '';
$search      = trim($_GET['search'] ?? '');

// ── Paginación ───────────────────────────────────────────────────────────────
$por_pagina = 25;
$pagina_act = max(1, (int)($_GET['pagina'] ?? 1));

// ── Semanas ───────────────────────────────────────────────────────────────────
$stmt_sem = $pdo->prepare("SELECT * FROM semanas_campamento WHERE year_campamento = ? ORDER BY fecha_inicio");
$stmt_sem->execute([$year]);
$semanas = $stmt_sem->fetchAll();

if (!$semana_id && !empty($semanas)) {
    foreach ($semanas as $s) {
        if ($s['activa']) { $semana_id = $s['id']; break; }
    }
    if (!$semana_id) $semana_id = $semanas[0]['id'];
}
$semana_id = (int)$semana_id;

// ── Bloque de filtros WHERE reutilizable ─────────────────────────────────────
// Se usa tanto en el COUNT como en el SELECT principal
function buildWhereFiltros(
    int    $semana_id,
    string $filtro_sexo,
    string $search,
    string $filtro_curp
): array {
    $where  = "a.semana_id = ? AND a.estado = 'activo' AND (a.grupo_id IS NULL OR a.grupo_id = 0)";
    $params = [$semana_id];

    if ($filtro_sexo !== '') {
        $where   .= " AND a.sexo = ?";
        $params[] = $filtro_sexo;
    }
    if ($search !== '') {
        $where   .= " AND (a.nombre LIKE ? OR a.curp LIKE ? OR a.iglesia LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($filtro_curp === 'sin_curp') {
        $where .= " AND (a.curp IS NULL OR a.curp = '')";
    } elseif ($filtro_curp === 'con_curp') {
        $where .= " AND (a.curp IS NOT NULL AND a.curp != '')";
    }

    return [$where, $params];
}

[$where_base, $params_base] = buildWhereFiltros(
    $semana_id, $filtro_sexo, $search, $filtro_curp
);

// ── HAVING según filtro de pago ───────────────────────────────────────────────
$having = '';
if ($filtro_pago === 'completo')
    $having = "HAVING (saldo <= 0 OR a.costo_total = 0)";
elseif ($filtro_pago === 'parcial')
    $having = "HAVING total_pagado > 0 AND saldo > 0 AND a.costo_total > 0";
elseif ($filtro_pago === 'sin_pago')
    $having = "HAVING total_pagado = 0 AND a.costo_total > 0";

// ── Query de CONTEO total (para paginación) ───────────────────────────────────
if ($filtro_pago === '') {
    // Sin filtro de pago: COUNT directo
    $sql_count = "SELECT COUNT(DISTINCT a.id)
                  FROM acampantes a
                  LEFT JOIN pagos_acampante p ON p.acampante_id = a.id
                  WHERE $where_base";
    $params_count = $params_base;
} else {
    // Con filtro de pago: necesita subquery porque HAVING usa alias
    $sql_count = "
        SELECT COUNT(*) FROM (
            SELECT a.id,
                   COALESCE(SUM(p.monto), 0)                 AS total_pagado,
                   a.costo_total - COALESCE(SUM(p.monto), 0) AS saldo
            FROM acampantes a
            LEFT JOIN pagos_acampante p ON p.acampante_id = a.id
            WHERE $where_base
            GROUP BY a.id
            $having
        ) AS sub
    ";
    $params_count = $params_base;
}

$stmt_count      = $pdo->prepare($sql_count);
$stmt_count->execute($params_count);
$total_registros = (int)$stmt_count->fetchColumn();
$total_paginas   = max(1, (int)ceil($total_registros / $por_pagina));
$pagina_act      = min($pagina_act, $total_paginas);
$offset          = ($pagina_act - 1) * $por_pagina;

// ── Query principal con paginación ───────────────────────────────────────────
$sql_main = "
    SELECT a.*,
           COALESCE(SUM(p.monto), 0)                 AS total_pagado,
           a.costo_total - COALESCE(SUM(p.monto), 0) AS saldo,
           c.nombre_cabana,
           u.username  AS registrado_por_nombre,
           ud.username AS docs_revisados_por_nombre
    FROM acampantes a
    LEFT JOIN pagos_acampante p ON p.acampante_id = a.id
    LEFT JOIN cabanas c         ON c.id = a.cabana_id
    LEFT JOIN usuarios u        ON u.id = a.registrado_por
    LEFT JOIN usuarios ud       ON ud.id = a.documentos_revisados_por
    WHERE $where_base
    GROUP BY a.id
    $having
    ORDER BY a.nombre
    LIMIT ? OFFSET ?
";

$stmt_main = $pdo->prepare($sql_main);
// Bind parámetros de filtro
foreach ($params_base as $i => $v) {
    $stmt_main->bindValue($i + 1, $v);
}
// Bind LIMIT y OFFSET como enteros — crítico para MySQL/PDO
$stmt_main->bindValue(count($params_base) + 1, $por_pagina, PDO::PARAM_INT);
$stmt_main->bindValue(count($params_base) + 2, $offset,     PDO::PARAM_INT);
$stmt_main->execute();
$acampantes = $stmt_main->fetchAll();

// ── Stats generales de la semana ─────────────────────────────────────────────
$stats = $semana_id ? resumenPagosSemana($pdo, $semana_id) : [];

// ── Conteo en grupos ─────────────────────────────────────────────────────────
$stmt_en_grupos = $pdo->prepare("
    SELECT COUNT(*) FROM acampantes
    WHERE semana_id = ? AND estado = 'activo'
      AND grupo_id IS NOT NULL AND grupo_id > 0
");
$stmt_en_grupos->execute([$semana_id]);
$total_en_grupos = (int)$stmt_en_grupos->fetchColumn();

// ── Acampantes sin CURP ───────────────────────────────────────────────────────
$stmt_sin_curp = $pdo->prepare("
    SELECT COUNT(*) FROM acampantes
    WHERE semana_id = ? AND estado = 'activo'
      AND (grupo_id IS NULL OR grupo_id = 0)
      AND (curp IS NULL OR curp = '')
");
$stmt_sin_curp->execute([$semana_id]);
$total_sin_curp = (int)$stmt_sin_curp->fetchColumn();

// ── Helpers de paginación ────────────────────────────────────────────────────
function urlPagina(int $p): string {
    $q = $_GET;
    $q['pagina'] = $p;
    return '?' . http_build_query($q);
}

function renderPaginacion(int $actual, int $total): string {
    if ($total <= 1) return '';

    $html  = '<nav aria-label="Paginación acampantes">';
    $html .= '<ul class="pagination pagination-sm mb-0">';

    // Anterior
    $html .= '<li class="page-item ' . ($actual <= 1 ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . ($actual > 1 ? urlPagina($actual - 1) : '#') . '">'
           . '<i class="fas fa-chevron-left fa-xs"></i></a></li>';

    // Ventana deslizante
    $inicio = max(1, $actual - 2);
    $fin    = min($total, $actual + 2);

    if ($inicio > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . urlPagina(1) . '">1</a></li>';
        if ($inicio > 2)
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
    }
    for ($p = $inicio; $p <= $fin; $p++) {
        $html .= '<li class="page-item ' . ($p === $actual ? 'active' : '') . '">';
        $html .= '<a class="page-link" href="' . urlPagina($p) . '">' . $p . '</a></li>';
    }
    if ($fin < $total) {
        if ($fin < $total - 1)
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        $html .= '<li class="page-item"><a class="page-link" href="' . urlPagina($total) . '">'
               . $total . '</a></li>';
    }

    // Siguiente
    $html .= '<li class="page-item ' . ($actual >= $total ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . ($actual < $total ? urlPagina($actual + 1) : '#') . '">'
           . '<i class="fas fa-chevron-right fa-xs"></i></a></li>';

    $html .= '</ul></nav>';
    return $html;
}

include '../includes/header.php';
?>

<!-- ── Cabecera ──────────────────────────────────────────────────────────── -->
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1>
                <i class="fas fa-user"></i> <?= $titulo ?>
                <span class="badge bg-secondary fs-6 align-middle ms-1"
                      title="Solo acampantes sin grupo asignado">
                    <i class="fas fa-user-check fa-xs"></i> Individuales
                </span>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Lista Individuales</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="inscribir.php<?= $semana_id ? "?semana_id=$semana_id" : '' ?>"
               class="btn btn-success btn-sm">
                <i class="fas fa-plus"></i> Inscribir
            </a>
            <a href="importar.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-file-csv"></i> Importar CSV
            </a>
            <a href="exportar.php?semana_id=<?= $semana_id ?>"
               class="btn btn-outline-dark btn-sm">
                <i class="fas fa-download"></i> Exportar
            </a>
            <?php if ($total_en_grupos > 0): ?>
            <a href="grupos/lista_grupos.php<?= $semana_id ? "?semana_id=$semana_id" : '' ?>"
               class="btn btn-outline-primary btn-sm">
                <i class="fas fa-users"></i> Ver Grupos
                <span class="badge bg-primary ms-1"><?= $total_en_grupos ?></span>
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Selector de semana ────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="fw-bold text-muted small">
                <i class="fas fa-calendar-week"></i>
            </span>
            <?php foreach ($semanas as $s): ?>
            <a href="?semana_id=<?= $s['id'] ?>"
               class="btn btn-sm <?= $semana_id == $s['id'] ? 'btn-dark' : 'btn-outline-secondary' ?>">
                <?= htmlspecialchars($s['nombre']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── Alerta grupos excluidos ───────────────────────────────────────────── -->
<?php if ($total_en_grupos > 0): ?>
<div class="alert alert-info alert-dismissible fade show py-2 small mb-3">
    <i class="fas fa-info-circle me-1"></i>
    Esta lista muestra <strong>solo acampantes individuales</strong>.
    Hay <strong><?= $total_en_grupos ?></strong>
    acampante<?= $total_en_grupos > 1 ? 's' : '' ?> en grupos que no se muestran aquí.
    <a href="grupos/lista_grupos.php<?= $semana_id ? "?semana_id=$semana_id" : '' ?>"
       class="alert-link ms-1">
        <i class="fas fa-users fa-xs"></i> Ver Grupos
    </a>
    <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Alerta sin CURP ────────────────────────────────────────────────────── -->
<?php if ($total_sin_curp > 0): ?>
<div class="alert alert-warning alert-dismissible fade show py-2 small mb-3">
    <i class="fas fa-id-card me-1"></i>
    <strong><?= $total_sin_curp ?></strong>
    acampante<?= $total_sin_curp > 1 ? 's' : '' ?> sin CURP registrado en esta semana.
    <a href="?semana_id=<?= $semana_id ?>&curp=sin_curp" class="alert-link ms-1">Ver listado</a>
    <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Mini stats ─────────────────────────────────────────────────────────── -->
<?php if (!empty($stats)): ?>
<div class="row g-2 mb-3">
    <?php
    $mini = [
        ['label' => 'Total semana',   'val' => $stats['total_inscritos'],  'color' => 'primary'],
        ['label' => 'Pago completo',  'val' => $stats['pagados_completo'], 'color' => 'success'],
        ['label' => 'Llegaron',       'val' => $stats['total_llegaron'],   'color' => 'warning'],
        ['label' => 'Individuales',   'val' => $total_registros,           'color' => 'secondary'],
    ];
    foreach ($mini as $m): ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 text-center">
                <div class="fw-bold fs-5 text-<?= $m['color'] ?>">
                    <?= $m['val'] ?? 0 ?>
                </div>
                <small class="text-muted"><?= $m['label'] ?></small>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Filtros ────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end"
              onsubmit="document.getElementById('inputPagina').value=1;">
            <input type="hidden" name="semana_id" value="<?= $semana_id ?>">
            <input type="hidden" name="pagina" value="1" id="inputPagina">

            <!-- Buscador -->
            <div class="col-12 col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light">
                        <i class="fas fa-search text-muted"></i>
                    </span>
                    <input type="text"
                           class="form-control <?= $search ? 'border-primary' : '' ?>"
                           name="search"
                           placeholder="Nombre, CURP o iglesia..."
                           value="<?= htmlspecialchars($search) ?>"
                           autocomplete="off"
                           id="inputBusqueda">
                    <?php if ($search): ?>
                    <a href="?semana_id=<?= $semana_id ?>"
                       class="btn btn-outline-secondary" title="Limpiar búsqueda">
                        <i class="fas fa-times"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php if ($search): ?>
                <div class="small text-primary mt-1">
                    <i class="fas fa-filter fa-xs"></i>
                    Buscando: <strong>"<?= htmlspecialchars($search) ?>"</strong>
                    — <?= number_format($total_registros) ?>
                    resultado<?= $total_registros !== 1 ? 's' : '' ?> en todos los registros
                </div>
                <?php endif; ?>
            </div>

            <!-- Filtro pago -->
            <div class="col-6 col-md-2">
                <select name="pago" class="form-select form-select-sm">
                    <option value="">💰 Todos los pagos</option>
                    <option value="completo" <?= $filtro_pago==='completo' ?'selected':'' ?>>✅ Pago completo</option>
                    <option value="parcial"  <?= $filtro_pago==='parcial'  ?'selected':'' ?>>⚠️ Pago parcial</option>
                    <option value="sin_pago" <?= $filtro_pago==='sin_pago' ?'selected':'' ?>>❌ Sin pago</option>
                </select>
            </div>

            <!-- Filtro sexo -->
            <div class="col-6 col-md-1">
                <select name="sexo" class="form-select form-select-sm">
                    <option value="">⚥ Todos</option>
                    <option value="masculino" <?= $filtro_sexo==='masculino' ?'selected':'' ?>>♂ H</option>
                    <option value="femenino"  <?= $filtro_sexo==='femenino'  ?'selected':'' ?>>♀ M</option>
                </select>
            </div>

            <!-- Filtro CURP -->
            <div class="col-6 col-md-2">
                <select name="curp" class="form-select form-select-sm">
                    <option value="">🪪 CURP: todos</option>
                    <option value="con_curp" <?= $filtro_curp==='con_curp' ?'selected':'' ?>>✅ Con CURP</option>
                    <option value="sin_curp" <?= $filtro_curp==='sin_curp' ?'selected':'' ?>>⚠️ Sin CURP</option>
                </select>
            </div>

            <!-- Botones -->
            <div class="col-12 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                    <i class="fas fa-search"></i> Buscar
                </button>
                <?php if ($search || $filtro_pago || $filtro_sexo || $filtro_curp): ?>
                <a href="?semana_id=<?= $semana_id ?>"
                   class="btn btn-outline-secondary btn-sm" title="Limpiar todos los filtros">
                    <i class="fas fa-times"></i> Limpiar
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ── Barra de estado + paginación superior ─────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <div class="small text-muted">
        <?php if ($total_registros === 0): ?>
            Sin resultados
        <?php else: ?>
            Mostrando
            <strong><?= number_format($offset + 1) ?></strong>–<strong><?= number_format(min($offset + $por_pagina, $total_registros)) ?></strong>
            de <strong><?= number_format($total_registros) ?></strong>
            acampante<?= $total_registros !== 1 ? 's' : '' ?>
            <?php if ($search): ?>
                · buscando <em class="text-primary">"<?= htmlspecialchars($search) ?>"</em> en todos los registros
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php if ($total_paginas > 1): ?>
    <?= renderPaginacion($pagina_act, $total_paginas) ?>
    <?php endif; ?>
</div>

<!-- ── Tabla principal ───────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nombre / CURP</th>
                        <th>Sexo / Edad</th>
                        <th>Iglesia</th>
                        <th>Cabaña</th>
                        <th>Pago</th>
                        <th>Saldo</th>
                        <th>Estado / Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($acampantes as $a):
                    $es_beca     = ((float)$a['costo_total'] == 0);
                    $pct         = $es_beca ? 100 : min(100, round($a['total_pagado'] / $a['costo_total'] * 100));
                    $saldo_color = ($es_beca || $a['saldo'] <= 0) ? 'success'
                                 : ($a['total_pagado'] > 0 ? 'warning' : 'danger');
                    $tiene_curp  = !empty($a['curp']);
                ?>
                <tr>
                    <!-- Nombre / CURP -->
                    <td>
                        <div class="fw-bold"><?= htmlspecialchars($a['nombre']) ?></div>
                        <?php if ($tiene_curp): ?>
                        <small class="text-muted font-monospace">
                            <i class="fas fa-id-card fa-xs me-1"></i><?= htmlspecialchars($a['curp']) ?>
                        </small>
                        <?php else: ?>
                        <small class="text-danger">
                            <i class="fas fa-exclamation-circle fa-xs me-1"></i>Sin CURP
                        </small>
                        <?php endif; ?>
                    </td>

                    <!-- Sexo / Edad -->
                    <td class="small">
                        <?= $a['sexo'] === 'masculino'
                            ? '<span class="badge bg-info">♂</span>'
                            : '<span class="badge bg-danger">♀</span>' ?>
                        <?= $a['edad'] ?? '—' ?>
                    </td>

                    <!-- Iglesia con resaltado -->
                    <td class="small">
                        <?php
                        $iglesia_txt = $a['iglesia'] ?? '';
                        if ($iglesia_txt && $search && mb_stripos($iglesia_txt, $search) !== false) {
                            $iglesia_html = htmlspecialchars($iglesia_txt);
                            $iglesia_html = preg_replace(
                                '/(' . preg_quote(htmlspecialchars($search), '/') . ')/i',
                                '<mark class="px-0 rounded">$1</mark>',
                                $iglesia_html
                            );
                            echo $iglesia_html;
                        } else {
                            echo htmlspecialchars($iglesia_txt ?: '—');
                        }
                        ?>
                    </td>

                    <!-- Cabaña -->
                    <td class="small">
                        <?= $a['nombre_cabana']
                            ? '<span class="badge bg-secondary">' . htmlspecialchars($a['nombre_cabana']) . '</span>'
                            : '<span class="text-muted">Sin asignar</span>' ?>
                    </td>

                    <!-- Pago progress -->
                    <td style="min-width:110px;">
                        <div class="progress mb-1" style="height:5px;">
                            <div class="progress-bar bg-<?= $saldo_color ?>"
                                 style="width:<?= $pct ?>%"></div>
                        </div>
                        <small class="text-muted">
                            <?php if ($es_beca): ?>
                                <span class="badge bg-info">
                                    <i class="fas fa-award fa-xs"></i> Beca
                                </span>
                            <?php else: ?>
                                $<?= number_format($a['total_pagado'], 0) ?>
                                / $<?= number_format($a['costo_total'], 0) ?>
                            <?php endif; ?>
                        </small>
                    </td>

                    <!-- Saldo -->
                    <td>
                        <?php if ($es_beca): ?>
                        <span class="badge bg-success">
                            <i class="fas fa-award fa-xs"></i> $0
                        </span>
                        <?php else: ?>
                        <span class="badge bg-<?= $saldo_color ?>">
                            $<?= number_format(max(0, $a['saldo']), 0) ?>
                        </span>
                        <?php endif; ?>
                    </td>

                    <!-- Estado / Acciones -->
                    <td>
                        <div class="d-flex gap-1 align-items-center flex-wrap">

                            <a href="editar.php?id=<?= $a['id'] ?>&semana_id=<?= $semana_id ?>"
                               class="btn btn-sm btn-outline-primary" title="Editar">
                                <i class="fas fa-edit"></i>
                            </a>

                            <?php if ($a['documentos_revisados']): ?>
                                <span class="badge bg-success py-1 px-2"
                                      title="Verificado el <?= date('d/m/Y H:i', strtotime($a['documentos_revisados_at'])) ?>
                            por: <?= htmlspecialchars($a['docs_revisados_por_nombre'] ?? 'Sistema') ?>">
                                    <i class="fas fa-check-double fa-xs"></i> Docs OK
                                </span>
                                <small class="text-muted d-block" style="font-size:.7rem; line-height:1.2;">
                                    <i class="fas fa-user fa-xs"></i>
                                    <?= htmlspecialchars($a['docs_revisados_por_nombre'] ?? '—') ?>
                                    <br>
                                    <i class="fas fa-clock fa-xs"></i>
                                    <?= date('d/m H:i', strtotime($a['documentos_revisados_at'])) ?>
                                </small>
                            <?php else: ?>
                                <a href="marcar_docs.php?id=<?= $a['id'] ?>&semana_id=<?= $semana_id ?>"
                                   class="btn btn-sm btn-outline-success"
                                   onclick="return confirm('¿Documentos de <?= htmlspecialchars($a['nombre'], ENT_QUOTES) ?> verificados?')">
                                    <i class="fas fa-clipboard-check fa-xs"></i> Revisar
                                </a>
                            <?php endif; ?>

                            <?php if ($a['llego']): ?>
                                <span class="badge bg-primary py-1 px-2"
                                      title="Llegó el <?= $a['fecha_llegada'] ? date('d/m/Y H:i', strtotime($a['fecha_llegada'])) : '' ?>">
                                    <i class="fas fa-sign-in-alt fa-xs"></i> Check-in ✓
                                </span>
                            <?php elseif ($a['documentos_revisados']): ?>
                                <span class="badge bg-warning text-dark py-1 px-2"
                                      title="Esperando pago en Administración">
                                    <i class="fas fa-clock fa-xs"></i> En caja
                                </span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border py-1 px-2">
                                    <i class="fas fa-hourglass-start fa-xs"></i> Sin revisar
                                </span>
                            <?php endif; ?>

                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($acampantes)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        <i class="fas fa-search me-2"></i>
                        No se encontraron acampantes con los filtros aplicados
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginación inferior -->
        <?php if ($total_paginas > 1): ?>
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top bg-light">
            <small class="text-muted">
                Página <strong><?= $pagina_act ?></strong>
                de <strong><?= $total_paginas ?></strong>
                &nbsp;·&nbsp; <?= number_format($total_registros) ?> registros totales
            </small>
            <?= renderPaginacion($pagina_act, $total_paginas) ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>