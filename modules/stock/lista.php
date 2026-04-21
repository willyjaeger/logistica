<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

// ── Tipos que suman / restan ──────────────────────────────────
function es_entrada(string $tipo): bool {
    return in_array($tipo, [
        'carga_inicial','ingreso_remito','ingreso_devolucion',
        'ingreso_expreso','ingreso_stock_seg','ajuste_positivo',
    ]);
}

// ── Stock actual por artículo ─────────────────────────────────
$stmt = $db->prepare("
    SELECT
        a.id, a.codigo, a.descripcion, a.presentacion,
        a.bultos_por_pallet,
        COALESCE(SUM(
            CASE WHEN m.tipo IN (
                'carga_inicial','ingreso_remito','ingreso_devolucion',
                'ingreso_expreso','ingreso_stock_seg','ajuste_positivo'
            ) THEN m.cantidad ELSE -m.cantidad END
        ), 0) AS bultos_stock
    FROM articulos a
    LEFT JOIN stock_movimientos m
           ON m.articulo_id = a.id AND m.empresa_id = ?
    WHERE a.empresa_id = ? AND a.activo = 1
    GROUP BY a.id
    ORDER BY a.descripcion, a.presentacion
");
$stmt->execute([$eid, $eid]);
$articulos = $stmt->fetchAll();

$total_bultos  = array_sum(array_column($articulos, 'bultos_stock'));
$total_pallets = 0;
foreach ($articulos as $a) {
    if ($a['bultos_por_pallet'] > 0)
        $total_pallets += $a['bultos_stock'] / $a['bultos_por_pallet'];
}

$nav_modulo = 'stock';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stock — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body class="bg-light">
<?php include __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-3">

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <h5 class="mb-0 fw-bold"><i class="bi bi-archive me-2 text-primary"></i>Stock actual</h5>
    <div class="ms-auto d-flex gap-2 flex-wrap">
        <a href="<?= url('modules/stock/movimientos.php') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-clock-history me-1"></i>Historial
        </a>
        <a href="<?= url('modules/stock/movimiento_form.php?tipo=carga_inicial') ?>" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-upload me-1"></i>Carga inicial
        </a>
        <a href="<?= url('modules/stock/movimiento_form.php') ?>" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Registrar movimiento
        </a>
    </div>
</div>

<?php if ($msg = ($_GET['ok'] ?? '')): ?>
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    <?= $msg === 'guardado' ? 'Movimiento registrado correctamente.' : h($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Totales -->
<div class="row g-2 mb-3">
    <div class="col-sm-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-2">
            <div class="stat-numero text-primary"><?= number_format($total_bultos, 0, ',', '.') ?></div>
            <div class="stat-label text-muted">Bultos en stock</div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-2">
            <div class="stat-numero text-success"><?= number_format($total_pallets, 1, ',', '.') ?></div>
            <div class="stat-label text-muted">Pallets equiv.</div>
        </div>
    </div>
</div>

<!-- Tabla artículos -->
<div class="card border-0 shadow-sm">
<div class="table-responsive">
<table class="table table-hover table-sm align-middle mb-0">
    <thead class="table-light">
        <tr>
            <th>Código</th>
            <th>Descripción</th>
            <th>Presentación</th>
            <th class="text-end">Bultos</th>
            <th class="text-end">Pallets</th>
            <th class="text-end" style="width:110px"></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($articulos as $a):
        $bultos  = (float)$a['bultos_stock'];
        $pallets = $a['bultos_por_pallet'] > 0 ? $bultos / $a['bultos_por_pallet'] : 0;
        $rowcls  = $bultos < 0 ? 'table-danger' : ($bultos === 0.0 ? 'text-muted' : '');
    ?>
    <tr class="<?= $rowcls ?>">
        <td class="font-monospace small fw-semibold"><?= h($a['codigo']) ?></td>
        <td><?= h($a['descripcion']) ?></td>
        <td class="small text-muted"><?= h($a['presentacion']) ?></td>
        <td class="text-end fw-bold"><?= number_format($bultos, 0, ',', '.') ?></td>
        <td class="text-end" style="color:#7c3aed; font-weight:600">
            <?= $pallets != 0 ? number_format($pallets, 2, ',', '.') : '—' ?>
        </td>
        <td class="text-end">
            <a href="<?= url('modules/stock/movimientos.php?articulo_id=' . $a['id']) ?>"
               class="btn btn-xs btn-outline-secondary py-0 px-2 me-1" style="font-size:.7rem" title="Historial">
                <i class="bi bi-clock-history"></i>
            </a>
            <a href="<?= url('modules/stock/movimiento_form.php?articulo_id=' . $a['id']) ?>"
               class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size:.7rem" title="Registrar movimiento">
                <i class="bi bi-plus-lg"></i>
            </a>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$articulos): ?>
    <tr><td colspan="6" class="text-center text-muted py-4">Sin artículos en catálogo.</td></tr>
    <?php endif; ?>
    </tbody>
    <tfoot class="table-light fw-bold">
        <tr>
            <td colspan="3" class="text-end">TOTAL</td>
            <td class="text-end"><?= number_format($total_bultos, 0, ',', '.') ?></td>
            <td class="text-end" style="color:#7c3aed"><?= number_format($total_pallets, 2, ',', '.') ?></td>
            <td></td>
        </tr>
    </tfoot>
</table>
</div>
</div>

</div><!-- /container -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
