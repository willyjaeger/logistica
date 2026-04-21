<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

$filtro_tipo = $_GET['tipo']        ?? '';
$filtro_art  = (int)($_GET['articulo_id'] ?? 0);
$filtro_desde = $_GET['desde']      ?? '';
$filtro_hasta = $_GET['hasta']      ?? '';

$tipo_labels = [
    'carga_inicial'     => ['Carga inicial',           'badge-estado-en_stock'],
    'ingreso_remito'    => ['Ingreso (remito)',         'badge-estado-entregado'],
    'ingreso_devolucion'=> ['Devolución cliente',       'badge-estado-entregado'],
    'ingreso_expreso'   => ['Ingreso expreso',          'badge-estado-entregado'],
    'ingreso_stock_seg' => ['Stock de seguridad',       'badge-estado-programado'],
    'salida_entrega'    => ['Salida entrega',           'badge-estado-en_camino'],
    'salida_consumo'    => ['Consumo (virtual)',        'badge-estado-con_incidencias'],
    'ajuste_positivo'   => ['Ajuste +',                'badge-estado-turnado'],
    'ajuste_negativo'   => ['Ajuste −',                'badge-estado-cancelado'],
];

$es_entrada_tipos = ['carga_inicial','ingreso_remito','ingreso_devolucion',
                     'ingreso_expreso','ingreso_stock_seg','ajuste_positivo'];

$where  = ['m.empresa_id = ?'];
$params = [$eid];

if ($filtro_tipo) { $where[] = 'm.tipo = ?'; $params[] = $filtro_tipo; }
if ($filtro_art)  { $where[] = 'm.articulo_id = ?'; $params[] = $filtro_art; }
if ($filtro_desde){ $where[] = 'm.fecha >= ?'; $params[] = $filtro_desde; }
if ($filtro_hasta){ $where[] = 'm.fecha <= ?'; $params[] = $filtro_hasta; }

$stmt = $db->prepare("
    SELECT m.*, a.codigo, a.descripcion AS art_desc, a.presentacion, a.bultos_por_pallet,
           COALESCE(a.descripcion, m.descripcion) AS display_desc
    FROM stock_movimientos m
    LEFT JOIN articulos a ON a.id = m.articulo_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY m.fecha DESC, m.id DESC
    LIMIT 500
");
$stmt->execute($params);
$movimientos = $stmt->fetchAll();

// Nombre artículo pre-seleccionado para título
$art_nombre = '';
if ($filtro_art) {
    $sa = $db->prepare("SELECT descripcion, presentacion FROM articulos WHERE id = ?");
    $sa->execute([$filtro_art]);
    $ra = $sa->fetch();
    if ($ra) $art_nombre = $ra['descripcion'] . ' ' . $ra['presentacion'];
}

$nav_modulo = 'stock';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Movimientos de stock — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body class="bg-light">
<?php include __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-3">

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= url('modules/stock/lista.php') ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Stock
    </a>
    <h5 class="mb-0 fw-bold">
        <i class="bi bi-clock-history me-2 text-primary"></i>Historial de movimientos
        <?php if ($art_nombre): ?>
        <small class="text-muted fw-normal">— <?= h($art_nombre) ?></small>
        <?php endif; ?>
    </h5>
    <a href="<?= url('modules/stock/movimiento_form.php' . ($filtro_art ? '?articulo_id='.$filtro_art : '')) ?>"
       class="btn btn-sm btn-primary ms-auto">
        <i class="bi bi-plus-lg me-1"></i>Nuevo movimiento
    </a>
</div>

<!-- Filtros -->
<form method="GET" class="card border-0 shadow-sm p-3 mb-3">
    <input type="hidden" name="articulo_id" value="<?= $filtro_art ?: '' ?>">
    <div class="row g-2 align-items-end">
        <div class="col-sm-3 col-md-2">
            <label class="form-label form-label-sm mb-1">Tipo</label>
            <select name="tipo" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($tipo_labels as $v => [$lbl,]): ?>
                <option value="<?= $v ?>" <?= $filtro_tipo === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-3 col-md-2">
            <label class="form-label form-label-sm mb-1">Desde</label>
            <input type="date" name="desde" class="form-control form-control-sm" value="<?= h($filtro_desde) ?>">
        </div>
        <div class="col-sm-3 col-md-2">
            <label class="form-label form-label-sm mb-1">Hasta</label>
            <input type="date" name="hasta" class="form-control form-control-sm" value="<?= h($filtro_hasta) ?>">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
            <a href="<?= url('modules/stock/movimientos.php') ?>" class="btn btn-sm btn-outline-secondary ms-1">Limpiar</a>
        </div>
    </div>
</form>

<!-- Tabla -->
<div class="card border-0 shadow-sm">
<div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light">
        <tr>
            <th>Fecha</th>
            <th>Tipo</th>
            <th>Artículo</th>
            <th class="text-end">Bultos</th>
            <th class="text-end">Pallets</th>
            <th>Observaciones</th>
            <th style="width:40px"></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($movimientos as $m):
        [$lbl, $badge] = $tipo_labels[$m['tipo']] ?? [$m['tipo'], 'bg-secondary'];
        $entrada = in_array($m['tipo'], $es_entrada_tipos);
        $bultos  = (float)$m['cantidad'];
        $bpp     = (float)($m['bultos_por_pallet'] ?? 1);
        $pallets = $bpp > 0 ? $bultos / $bpp : 0;
        [$y,$mo,$d] = explode('-', $m['fecha']);
    ?>
    <tr>
        <td class="text-nowrap small"><?= "$d/$mo/$y" ?></td>
        <td><span class="badge <?= $badge ?>"><?= $lbl ?></span></td>
        <td>
            <div class="small fw-semibold"><?= h($m['art_desc'] ?? $m['descripcion']) ?></div>
            <?php if ($m['presentacion']): ?>
            <div class="text-muted" style="font-size:.72rem"><?= h($m['presentacion']) ?></div>
            <?php endif; ?>
        </td>
        <td class="text-end fw-bold <?= $entrada ? 'text-success' : 'text-danger' ?>">
            <?= $entrada ? '+' : '−' ?><?= number_format($bultos, 0, ',', '.') ?>
        </td>
        <td class="text-end small" style="color:#7c3aed">
            <?= $pallets > 0 ? ($entrada ? '+' : '−') . number_format($pallets, 2, ',', '.') : '—' ?>
        </td>
        <td class="small text-muted" style="max-width:200px;white-space:pre-wrap;word-break:break-word">
            <?= h(mb_substr($m['observaciones'] ?? '', 0, 120)) ?>
            <?= mb_strlen($m['observaciones'] ?? '') > 120 ? '…' : '' ?>
        </td>
        <td>
            <?php if ($m['lote_id']): ?>
            <a href="<?= url('modules/stock/comprobante.php?lote=' . $m['lote_id']) ?>"
               class="btn btn-xs btn-outline-secondary py-0 px-1" style="font-size:.7rem" title="Ver comprobante">
                <i class="bi bi-file-text"></i>
            </a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$movimientos): ?>
    <tr><td colspan="7" class="text-center text-muted py-4">Sin movimientos.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
