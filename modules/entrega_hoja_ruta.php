<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db         = db();
$eid        = empresa_id();
$entrega_id = (int)($_GET['id'] ?? 0);
$back       = $_GET['back'] ?? 'agenda';
$back_url   = $back === 'lista' ? url('modules/entregas_lista.php') : url('modules/agenda.php');
$autoprint  = !empty($_GET['print']); // auto-trigger window.print()

if (!$entrega_id) { header('Location: ' . $back_url); exit; }

// ── Entrega ───────────────────────────────────────────────────
$st = $db->prepare("
    SELECT e.*,
           tr.nombre AS trans_nombre, tr.cuit AS trans_cuit,
           cam.patente, cam.marca,
           ch.nombre AS chofer_nombre, ch.telefono AS chofer_tel
    FROM entregas e
    LEFT JOIN transportistas tr  ON tr.id = e.transportista_id
    LEFT JOIN camiones       cam ON cam.id = e.camion_id
    LEFT JOIN choferes       ch  ON ch.id  = e.chofer_id
    WHERE e.id = ? AND e.empresa_id = ?
");
$st->execute([$entrega_id, $eid]);
$entrega = $st->fetch();
if (!$entrega) { header('Location: ' . $back_url); exit; }

// ── Remitos de la entrega ─────────────────────────────────────
$sr = $db->prepare("
    SELECT r.id, r.nro_remito_propio, r.total_pallets,
           c.nombre AS cliente, c.direccion AS cli_dir
    FROM entrega_remitos er
    JOIN remitos  r ON r.id = er.remito_id
    JOIN clientes c ON c.id = r.cliente_id
    WHERE er.entrega_id = ?
    ORDER BY c.nombre, r.nro_remito_propio
");
$sr->execute([$entrega_id]);
$remitos = $sr->fetchAll();

// ── Items de cada remito ──────────────────────────────────────
$remito_ids = array_column($remitos, 'id');
$items_map  = [];
if ($remito_ids) {
    $in  = implode(',', array_fill(0, count($remito_ids), '?'));
    $si  = $db->prepare("
        SELECT remito_id, descripcion, cantidad, pallets
        FROM remito_items
        WHERE remito_id IN ($in)
        ORDER BY id
    ");
    $si->execute($remito_ids);
    foreach ($si->fetchAll() as $it) {
        $items_map[$it['remito_id']][] = $it;
    }
}

$total_pallets = array_sum(array_column($remitos, 'total_pallets'));

function fmtFecha(string $ymd): string {
    [$y,$m,$d] = explode('-', $ymd);
    $M = ['','enero','febrero','marzo','abril','mayo','junio',
          'julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return (int)$d . ' de ' . $M[(int)$m] . ' de ' . $y;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hoja de ruta #<?= $entrega_id ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        @page { margin: 1.2cm 1.4cm; }

        body { background: #eef1f6; font-size: .92rem; }

        @media print {
            body            { background: #fff !important; font-size: .78rem; }
            .no-print       { display: none !important; }
            .card           { box-shadow: none !important; border: none !important;
                              padding: 0 !important; }
            a               { color: inherit !important; text-decoration: none !important; }
            .hr-header      { margin-bottom: .5rem; padding-bottom: .4rem; }
            .hr-numero      { font-size: 1.1rem; }
            .hr-empresa     { font-size: .85rem; }
            .info-grid      { margin-bottom: .6rem; gap: .15rem 1rem; }
            .tabla-remitos td { padding: .22rem .45rem; }
            .tabla-remitos th { padding: .22rem .45rem; }
            .firmas         { margin-top: 1.2rem; gap: 1.5rem; }
            .firma-espacio  { height: 38px; }
            .conformidad    { margin-top: .8rem; padding: .3rem .55rem; }
        }

        .hoja { max-width: 820px; margin: 0 auto; }

        /* Encabezado */
        .hr-header  { border-bottom: 3px solid #1a3a6b; padding-bottom: .6rem; margin-bottom: 1rem; }
        .hr-numero  { font-size: 1.5rem; font-weight: 800; color: #1a3a6b; }
        .hr-empresa { font-size: 1rem; font-weight: 700; color: #1a3a6b; }

        /* Info vehículo */
        .info-grid  { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: .3rem 1.2rem;
                      font-size: .83rem; margin-bottom: 1rem; }
        .info-label { color: #6b7280; font-size: .68rem; text-transform: uppercase;
                      font-weight: 600; letter-spacing: .04em; }
        .info-val   { font-weight: 600; border-bottom: 1px solid #e5e7eb; padding-bottom: 2px;
                      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Tabla remitos */
        .tabla-remitos { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
        .tabla-remitos th { background: #1a3a6b; color: #fff; padding: .3rem .55rem;
                            font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; }
        .tabla-remitos td { padding: .32rem .55rem; border-bottom: 1px solid #dee2e6;
                            vertical-align: top; }
        .tabla-remitos tr:last-child td { border-bottom: 2px solid #1a3a6b; }
        .tabla-remitos .total-row td    { font-weight: 700; background: #eef3fb; }
        .items-inline { font-size: .73rem; color: #4b5563; margin-top: .15rem; line-height: 1.4; }

        /* Firmas */
        .firmas      { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2rem; }
        .firma-box   { border-top: 2px solid #374151; padding-top: .4rem; }
        .firma-label { font-size: .68rem; text-transform: uppercase; font-weight: 600;
                       letter-spacing: .06em; color: #6b7280; }
        .firma-espacio { height: 52px; }

        /* Conformidad */
        .conformidad { font-size: .72rem; color: #6b7280; border: 1px solid #d1d5db;
                       border-radius: .3rem; padding: .45rem .7rem; margin-top: 1rem; }
    </style>
</head>
<body>

<!-- Barra de herramientas (no imprime) ────────────────────────── -->
<div class="no-print bg-white border-bottom py-2 px-3 d-flex align-items-center gap-3 sticky-top shadow-sm">
    <a href="<?= $back_url ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Volver
    </a>
    <span class="text-muted small">Hoja de ruta #<?= $entrega_id ?></span>
    <button onclick="window.print()" class="btn btn-primary btn-sm ms-auto">
        <i class="bi bi-printer me-1"></i>Imprimir
    </button>
</div>

<div class="container py-3 hoja">
<div class="card p-4">

    <!-- ── Encabezado ─────────────────────────────────────────── -->
    <div class="hr-header d-flex justify-content-between align-items-end">
        <div>
            <div class="hr-empresa"><?= h(empresa_nombre()) ?></div>
            <div class="hr-numero">Hoja de ruta <span style="color:#f97316">#<?= $entrega_id ?></span></div>
        </div>
        <div class="text-end text-muted small">
            <div><?= fmtFecha($entrega['fecha']) ?></div>
            <div class="fw-semibold">
                <?php
                $estados = [
                    'armando'        => 'Armando',
                    'en_camino'      => 'En camino',
                    'completada'     => 'Completada',
                    'con_incidencias'=> 'Con incidencias',
                ];
                echo $estados[$entrega['estado']] ?? h($entrega['estado']);
                ?>
            </div>
        </div>
    </div>

    <!-- ── Datos del vehículo (4 columnas) ──────────────────────── -->
    <div class="info-grid">
        <div>
            <div class="info-label">Transportista</div>
            <div class="info-val"><?= h($entrega['trans_nombre'] ?? '—') ?></div>
        </div>
        <div>
            <div class="info-label">Camión</div>
            <div class="info-val"><?= $entrega['patente'] ? h($entrega['patente']) . ($entrega['marca'] ? ' · '.h($entrega['marca']) : '') : '—' ?></div>
        </div>
        <div>
            <div class="info-label">Chofer</div>
            <div class="info-val"><?= h($entrega['chofer_nombre'] ?? '—') ?></div>
        </div>
        <div>
            <div class="info-label">Tel. chofer</div>
            <div class="info-val"><?= h($entrega['chofer_tel'] ?? '—') ?></div>
        </div>
    </div>

    <!-- ── Tabla de remitos ───────────────────────────────────── -->
    <table class="tabla-remitos">
        <thead>
            <tr>
                <th style="width:140px">Remito</th>
                <th>Cliente / Destino</th>
                <th style="width:70px" class="text-center">Pallets</th>
                <th style="width:80px" class="text-center">Recibido</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($remitos as $r):
            $items = $items_map[$r['id']] ?? [];
            $items_txt = implode(' · ', array_map(function($it) {
                $s = h($it['descripcion']);
                if ((float)$it['cantidad'] > 0) $s .= ' (' . number_format((float)$it['cantidad'], 0) . ' u.)';
                return $s;
            }, $items));
        ?>
            <tr>
                <td class="font-monospace fw-semibold small"><?= h($r['nro_remito_propio']) ?></td>
                <td>
                    <div class="fw-semibold"><?= h($r['cliente']) ?></div>
                    <?php if ($r['cli_dir']): ?>
                    <div class="text-muted" style="font-size:.75rem"><?= h($r['cli_dir']) ?></div>
                    <?php endif; ?>
                    <?php if ($items_txt): ?>
                    <div class="items-inline"><?= $items_txt ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-center fw-bold" style="color:#7c3aed">
                    <?= number_format((float)$r['total_pallets'], 1) ?>
                </td>
                <td class="text-center">□</td>
            </tr>
        <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="2" class="text-end">TOTAL</td>
                <td class="text-center" style="color:#7c3aed"><?= number_format((float)$total_pallets, 1) ?></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <!-- ── Conformidad ────────────────────────────────────────── -->
    <div class="conformidad">
        El abajo firmante declara haber recibido la mercadería detallada en este documento en perfectas condiciones,
        conforme al detalle indicado.
    </div>

    <!-- ── Firmas ─────────────────────────────────────────────── -->
    <div class="firmas">
        <div class="firma-box">
            <div class="firma-espacio"></div>
            <div class="firma-label">Firma y aclaración del chofer</div>
            <div class="text-muted small mt-1"><?= h($entrega['chofer_nombre'] ?? '') ?></div>
        </div>
        <div class="firma-box">
            <div class="firma-espacio"></div>
            <div class="firma-label">Firma y sello — <?= h(empresa_nombre()) ?></div>
        </div>
    </div>

</div><!-- /card -->
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($autoprint): ?>
<script>window.addEventListener('load', () => window.print());</script>
<?php endif; ?>
</body>
</html>
