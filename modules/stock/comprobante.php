<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$db      = db();
$eid     = empresa_id();
$lote_id = (int)($_GET['lote'] ?? 0);

if (!$lote_id) { header('Location: ' . url('modules/stock/lista.php')); exit; }

// Movimientos del lote
$stmt = $db->prepare("
    SELECT m.*, a.codigo, a.descripcion AS art_desc, a.presentacion, a.bultos_por_pallet
    FROM stock_movimientos m
    LEFT JOIN articulos a ON a.id = m.articulo_id
    WHERE m.lote_id = ? AND m.empresa_id = ?
    ORDER BY m.id
");
$stmt->execute([$lote_id, $eid]);
$movs = $stmt->fetchAll();

if (!$movs) { header('Location: ' . url('modules/stock/lista.php')); exit; }

$tipo    = $movs[0]['tipo'];
$fecha   = $movs[0]['fecha'];
$obs     = $movs[0]['observaciones'] ?? '';
$usuario = $movs[0]['usuario_id'];

$tipo_labels = [
    'carga_inicial'     => ['Carga inicial de stock',            true,  '#16a34a'],
    'ingreso_remito'    => ['Ingreso — Remito Sanesa',           true,  '#16a34a'],
    'ingreso_devolucion'=> ['Ingreso — Devolución de cliente',   true,  '#16a34a'],
    'ingreso_expreso'   => ['Ingreso — Desde expreso / tercero', true,  '#16a34a'],
    'ingreso_stock_seg' => ['Ingreso — Stock de seguridad',      true,  '#0891b2'],
    'salida_entrega'    => ['Salida — Entrega a cliente',        false, '#f97316'],
    'salida_consumo'    => ['Salida — Consumo stock (virtual)',  false, '#dc2626'],
    'ajuste_positivo'   => ['Ajuste positivo',                   true,  '#2563eb'],
    'ajuste_negativo'   => ['Ajuste negativo',                   false, '#4b5563'],
];

[$tipo_nombre, $es_entrada, $tipo_color] = $tipo_labels[$tipo] ?? [$tipo, true, '#6b7280'];

$total_bultos  = array_sum(array_column($movs, 'cantidad'));
$total_pallets = 0;
foreach ($movs as $m) {
    $bpp = (float)($m['bultos_por_pallet'] ?? 1);
    if ($bpp > 0) $total_pallets += (float)$m['cantidad'] / $bpp;
}

function fmtFechaComp(string $ymd): string {
    [$y,$mo,$d] = explode('-', $ymd);
    $M = ['','enero','febrero','marzo','abril','mayo','junio',
          'julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return (int)$d . ' de ' . $M[(int)$mo] . ' de ' . $y;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante #<?= $lote_id ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        @page { margin: 1.2cm 1.4cm; }
        body { background: #eef1f6; font-size: .92rem; }
        @media print {
            body      { background: #fff !important; font-size: .78rem; }
            .no-print { display: none !important; }
            .card     { box-shadow: none !important; border: none !important; }
        }
        .comp-header  { border-bottom: 3px solid; padding-bottom: .6rem; margin-bottom: 1rem; }
        .comp-numero  { font-size: 1.4rem; font-weight: 800; }
        .comp-empresa { font-size: 1rem; font-weight: 700; color: #1a3a6b; }
        .comp-tipo    { display: inline-block; padding: .3em .8em; border-radius: .4rem;
                        color: #fff; font-weight: 700; font-size: .9rem; }
        .obs-box      { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: .4rem;
                        padding: .6rem .9rem; font-size: .85rem; white-space: pre-wrap;
                        word-break: break-word; }
        .tabla th     { background: #1a3a6b; color: #fff; padding: .3rem .55rem;
                        font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; }
        .tabla td     { padding: .32rem .55rem; border-bottom: 1px solid #dee2e6; vertical-align: middle; }
        .tabla tr:last-child td { border-bottom: 2px solid #1a3a6b; }
        .total-row td { font-weight: 700; background: #eef3fb; }
        .firmas       { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2rem; }
        .firma-box    { border-top: 2px solid #374151; padding-top: .4rem; }
        .firma-label  { font-size: .68rem; text-transform: uppercase; font-weight: 600;
                        letter-spacing: .06em; color: #6b7280; }
        .firma-espacio { height: 48px; }
        .hoja { max-width: 820px; margin: 0 auto; }
    </style>
</head>
<body>

<!-- Toolbar -->
<div class="no-print bg-white border-bottom py-2 px-3 d-flex align-items-center gap-3 sticky-top shadow-sm">
    <a href="<?= url('modules/stock/lista.php') ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-archive me-1"></i>Stock
    </a>
    <a href="<?= url('modules/stock/movimientos.php') ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-clock-history me-1"></i>Historial
    </a>
    <span class="text-muted small">Comprobante #<?= $lote_id ?></span>
    <button onclick="window.print()" class="btn btn-primary btn-sm ms-auto">
        <i class="bi bi-printer me-1"></i>Imprimir
    </button>
</div>

<div class="container py-3 hoja">
<div class="card p-4">

    <!-- Encabezado -->
    <div class="comp-header d-flex justify-content-between align-items-start"
         style="border-color:<?= $tipo_color ?>">
        <div>
            <div class="comp-empresa"><?= h(empresa_nombre()) ?></div>
            <div class="comp-numero" style="color:<?= $tipo_color ?>">
                Comprobante de stock <span style="color:#f97316">#<?= $lote_id ?></span>
            </div>
            <div class="mt-1">
                <span class="comp-tipo" style="background:<?= $tipo_color ?>">
                    <?= $es_entrada ? '▲' : '▼' ?> <?= h($tipo_nombre) ?>
                </span>
            </div>
        </div>
        <div class="text-end text-muted small">
            <div><?= fmtFechaComp($fecha) ?></div>
        </div>
    </div>

    <!-- Origen / Observaciones -->
    <?php if ($obs): ?>
    <div class="mb-3">
        <div class="fw-semibold small text-muted mb-1 text-uppercase" style="letter-spacing:.05em">
            <?= $es_entrada ? 'Origen / Procedencia' : 'Destino / Observaciones' ?>
        </div>
        <div class="obs-box"><?= h($obs) ?></div>
    </div>
    <?php endif; ?>

    <!-- Tabla artículos -->
    <table class="tabla w-100 mb-3" style="border-collapse:collapse">
        <thead>
            <tr>
                <th style="width:130px">Código</th>
                <th>Descripción</th>
                <th>Presentación</th>
                <th style="width:90px" class="text-center">Bultos</th>
                <th style="width:90px" class="text-center">Pallets</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($movs as $m):
            $bpp     = (float)($m['bultos_por_pallet'] ?? 1);
            $pallets = $bpp > 0 ? (float)$m['cantidad'] / $bpp : 0;
        ?>
        <tr>
            <td class="font-monospace fw-semibold small"><?= h($m['codigo'] ?? '—') ?></td>
            <td class="fw-semibold"><?= h($m['art_desc'] ?? $m['descripcion']) ?></td>
            <td class="small text-muted"><?= h($m['presentacion'] ?? '') ?></td>
            <td class="text-center fw-bold"><?= number_format((float)$m['cantidad'], 0, ',', '.') ?></td>
            <td class="text-center" style="color:#7c3aed; font-weight:600">
                <?= $pallets > 0 ? number_format($pallets, 2, ',', '.') : '—' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td colspan="3" class="text-end">TOTAL</td>
            <td class="text-center"><?= number_format($total_bultos, 0, ',', '.') ?></td>
            <td class="text-center" style="color:#7c3aed"><?= number_format($total_pallets, 2, ',', '.') ?></td>
        </tr>
        </tbody>
    </table>

    <!-- Firmas -->
    <div class="firmas">
        <div class="firma-box">
            <div class="firma-espacio"></div>
            <div class="firma-label">Firma quien <?= $es_entrada ? 'recibe' : 'entrega' ?></div>
        </div>
        <div class="firma-box">
            <div class="firma-espacio"></div>
            <div class="firma-label">Firma y sello — <?= h(empresa_nombre()) ?></div>
        </div>
    </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
