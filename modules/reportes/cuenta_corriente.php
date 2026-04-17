<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

// ── Filtros ───────────────────────────────────────────────────
$proveedor_id = (int)($_GET['proveedor_id'] ?? 0);
$mes          = max(1, min(12, (int)($_GET['mes']   ?? date('n'))));
$anio         = max(2020, (int)($_GET['anio']       ?? date('Y')));
$precio       = (float)str_replace(',', '.', $_GET['precio'] ?? '0');

// ── Lista de proveedores ──────────────────────────────────────
$pq = $db->prepare("SELECT id, nombre FROM proveedores WHERE empresa_id=? AND activo=1 ORDER BY nombre");
$pq->execute([$eid]);
$proveedores = $pq->fetchAll();

$prov_nombre = '';
foreach ($proveedores as $p) {
    if ((int)$p['id'] === $proveedor_id) { $prov_nombre = $p['nombre']; break; }
}

// ── Cálculo ───────────────────────────────────────────────────
$datos = null;
if ($proveedor_id > 0) {
    $inicio = sprintf('%04d-%02d-01', $anio, $mes);
    $fin    = date('Y-m-t', strtotime($inicio));

    // Remitos del proveedor que estuvieron en stock en algún momento del mes
    $stmt = $db->prepare("
        SELECT r.id, r.nro_remito_propio, r.total_pallets,
               c.nombre              AS cliente,
               DATE(i.fecha_ingreso) AS fecha_ingreso,
               ef.fecha_salida_real
        FROM remitos r
        JOIN ingresos i ON i.id = r.ingreso_id
        JOIN clientes c ON c.id = r.cliente_id
        LEFT JOIN (
            SELECT er.remito_id, DATE(MAX(en.fecha_salida)) AS fecha_salida_real
            FROM entrega_remitos er
            JOIN entregas en ON en.id = er.entrega_id
            WHERE en.estado IN ('completada','entregado','con_incidencias')
            GROUP BY er.remito_id
        ) ef ON ef.remito_id = r.id
        WHERE r.empresa_id  = ?
          AND r.proveedor_id = ?
          AND DATE(i.fecha_ingreso) <= ?
          AND (ef.fecha_salida_real IS NULL OR ef.fecha_salida_real >= ?)
        ORDER BY DATE(i.fecha_ingreso), r.id
    ");
    $stmt->execute([$eid, $proveedor_id, $fin, $inicio]);
    $remitos_periodo = $stmt->fetchAll();

    // ── Día a día ─────────────────────────────────────────────
    $dias             = [];
    $total_posiciones = 0.0;
    $cursor = new DateTime($inicio);
    $finDt  = new DateTime($fin);

    while ($cursor <= $finDt) {
        $d        = $cursor->format('Y-m-d');
        $stock    = 0.0;
        $entradas = [];
        $salidas  = [];

        foreach ($remitos_periodo as $r) {
            $pal = (float)$r['total_pallets'];
            // Pallet "durmió" si ingresó en o antes de D, y aún no salió (o salió después de D)
            if ($r['fecha_ingreso'] <= $d && ($r['fecha_salida_real'] === null || $r['fecha_salida_real'] > $d)) {
                $stock += $pal;
            }
            if ($r['fecha_ingreso'] === $d)          $entradas[] = $r;
            if ($r['fecha_salida_real'] === $d)      $salidas[]  = $r;
        }

        $total_posiciones += $stock;
        $dias[$d] = [
            'stock'    => $stock,
            'entradas' => $entradas,
            'salidas'  => $salidas,
            'costo'    => $precio > 0 ? $stock * $precio : null,
        ];
        $cursor->modify('+1 day');
    }

    // ── Resumen ───────────────────────────────────────────────
    $total_ingresado = 0.0;
    $total_salido    = 0.0;
    $stock_actual    = 0.0;
    foreach ($remitos_periodo as $r) {
        $pal = (float)$r['total_pallets'];
        if ($r['fecha_ingreso'] >= $inicio && $r['fecha_ingreso'] <= $fin) $total_ingresado += $pal;
        if ($r['fecha_salida_real'] !== null
            && $r['fecha_salida_real'] >= $inicio
            && $r['fecha_salida_real'] <= $fin)                             $total_salido    += $pal;
        if ($r['fecha_salida_real'] === null)                               $stock_actual    += $pal;
    }

    $datos = compact(
        'dias', 'total_posiciones', 'total_ingresado', 'total_salido',
        'stock_actual', 'inicio', 'fin', 'remitos_periodo'
    );
}

// ── Helpers ───────────────────────────────────────────────────
function fmtPal(float $p): string {
    return $p > 0 ? number_format($p, 1) : '—';
}
function fmtMoney(float $v): string {
    return '$&nbsp;' . number_format($v, 2, ',', '.');
}
function fmtDia(string $ymd): string {
    [$y,$m,$d] = explode('-', $ymd);
    return "$d/$m/$y";
}
function diaSemana(string $ymd): string {
    $dias = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
    return $dias[(int)(new DateTime($ymd))->format('w')];
}

$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
          'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

$anios = range(date('Y'), 2024, -1);

$nav_modulo = 'reportes';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cuenta corriente — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        body { background: #eef1f6; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
        }
        .card { border: none !important; box-shadow: 0 2px 8px rgba(0,0,0,.10) !important; }
        #tabla-cc thead th {
            background: #2c3e50; color: #fff;
            font-size: .76rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: .05em; border: none;
        }
        .row-entrada  { background: #f0fff4; }
        .row-salida   { background: #fff5f5; }
        .row-stock    { background: #f8f9fa; font-weight: 600; }
        .row-stock td { border-top: 1px solid #dee2e6 !important; }
        .col-pal-pos { color: #5a29a3; font-weight: 700; }
        .col-costo   { color: #0a6640; font-weight: 700; }
        .total-row td { background: #2c3e50 !important; color: #fff !important;
                        font-weight: 700; font-size: .95rem; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4" style="max-width:1100px">

    <div class="d-flex align-items-center justify-content-between mb-3 no-print">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-journal-text me-2 text-primary"></i>Cuenta corriente — Proveedores
        </h5>
        <?php if ($datos): ?>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Imprimir
        </button>
        <?php endif; ?>
    </div>

    <!-- ── Filtros ─────────────────────────────────────────── -->
    <form method="GET" class="card mb-4 no-print">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label form-label-sm fw-semibold mb-1">Proveedor</label>
                    <select name="proveedor_id" class="form-select form-select-sm" required>
                        <option value="">— Seleccionar —</option>
                        <?php foreach ($proveedores as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= (int)$p['id'] === $proveedor_id ? 'selected' : '' ?>>
                            <?= h($p['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-2 col-lg-2">
                    <label class="form-label form-label-sm fw-semibold mb-1">Mes</label>
                    <select name="mes" class="form-select form-select-sm">
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?= $i ?>" <?= $i === $mes ? 'selected' : '' ?>><?= $meses[$i] ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-sm-2 col-lg-1">
                    <label class="form-label form-label-sm fw-semibold mb-1">Año</label>
                    <select name="anio" class="form-select form-select-sm">
                        <?php foreach ($anios as $a): ?>
                        <option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3 col-lg-2">
                    <label class="form-label form-label-sm fw-semibold mb-1">
                        Precio por posición/día
                    </label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" name="precio" class="form-control form-control-sm"
                               step="0.01" min="0" value="<?= $precio > 0 ? $precio : '' ?>"
                               placeholder="0.00">
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-search me-1"></i>Ver
                    </button>
                </div>
            </div>
        </div>
    </form>

    <?php if ($datos): ?>

    <!-- ── Encabezado de impresión ─────────────────────────── -->
    <div class="d-none d-print-block mb-3">
        <h4 class="fw-bold mb-0"><?= APP_NAME ?> — Cuenta corriente</h4>
        <div class="text-muted">
            <?= h($prov_nombre) ?> &nbsp;|&nbsp;
            <?= $meses[$mes] ?> <?= $anio ?>
            <?php if ($precio > 0): ?> &nbsp;|&nbsp; $<?= number_format($precio, 2, ',', '.') ?>/posición<?php endif; ?>
        </div>
        <hr>
    </div>

    <!-- ── Resumen ─────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Pal. ingresados</div>
                    <div class="fs-4 fw-bold text-success">+<?= fmtPal($datos['total_ingresado']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Pal. entregados</div>
                    <div class="fs-4 fw-bold text-danger">−<?= fmtPal($datos['total_salido']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">En stock actual</div>
                    <div class="fs-4 fw-bold text-primary"><?= fmtPal($datos['stock_actual']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Total posiciones</div>
                    <div class="fs-4 fw-bold text-purple" style="color:#5a29a3">
                        <?= number_format($datos['total_posiciones'], 1) ?>
                    </div>
                    <?php if ($precio > 0): ?>
                    <div class="fw-bold text-success small mt-1">
                        <?= fmtMoney($datos['total_posiciones'] * $precio) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($datos['remitos_periodo'])): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        No hay remitos registrados para <strong><?= h($prov_nombre) ?></strong>
        en <?= $meses[$mes] ?> <?= $anio ?>.
    </div>
    <?php else: ?>

    <!-- ── Tabla día a día ─────────────────────────────────── -->
    <div class="card">
        <div class="card-header bg-white fw-semibold py-2">
            <i class="bi bi-calendar3 me-2 text-primary"></i>
            Detalle — <?= h($prov_nombre) ?> — <?= $meses[$mes] ?> <?= $anio ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="tabla-cc">
                    <thead>
                        <tr>
                            <th style="width:90px">Fecha</th>
                            <th>Movimiento</th>
                            <th>Nro remito</th>
                            <th>Cliente</th>
                            <th class="text-end">Pal. entrada</th>
                            <th class="text-end">Pal. salida</th>
                            <th class="text-end">Stock EOD</th>
                            <th class="text-end">Posiciones</th>
                            <?php if ($precio > 0): ?>
                            <th class="text-end">$ día</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $saldo_acum = 0.0;
                    foreach ($datos['dias'] as $dia => $info):
                        $tiene_movs = !empty($info['entradas']) || !empty($info['salidas']);
                        // Saltar días sin stock y sin movimientos
                        if ($info['stock'] == 0 && !$tiene_movs) { continue; }

                        $sem = diaSemana($dia);
                        $esFinde = in_array($sem, ['Sáb','Dom']);
                    ?>

                    <?php // ── Entradas del día ─────────────────────────── ?>
                    <?php foreach ($info['entradas'] as $r): ?>
                    <tr class="row-entrada">
                        <td class="small fw-semibold <?= $esFinde ? 'text-muted' : '' ?>">
                            <?= $sem ?> <?= fmtDia($dia) ?>
                        </td>
                        <td><span class="badge bg-success">Ingreso</span></td>
                        <td class="font-monospace small"><?= h($r['nro_remito_propio']) ?></td>
                        <td class="small text-muted"><?= h($r['cliente']) ?></td>
                        <td class="text-end text-success fw-semibold">+<?= fmtPal((float)$r['total_pallets']) ?></td>
                        <td class="text-end text-muted">—</td>
                        <td></td><td></td>
                        <?php if ($precio > 0): ?><td></td><?php endif; ?>
                    </tr>
                    <?php endforeach; ?>

                    <?php // ── Salidas del día ──────────────────────────── ?>
                    <?php foreach ($info['salidas'] as $r): ?>
                    <tr class="row-salida">
                        <td class="small fw-semibold <?= $esFinde ? 'text-muted' : '' ?>">
                            <?php if (empty($info['entradas'])): ?>
                                <?= $sem ?> <?= fmtDia($dia) ?>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-danger">Salida</span></td>
                        <td class="font-monospace small"><?= h($r['nro_remito_propio']) ?></td>
                        <td class="small text-muted"><?= h($r['cliente']) ?></td>
                        <td class="text-end text-muted">—</td>
                        <td class="text-end text-danger fw-semibold">−<?= fmtPal((float)$r['total_pallets']) ?></td>
                        <td></td><td></td>
                        <?php if ($precio > 0): ?><td></td><?php endif; ?>
                    </tr>
                    <?php endforeach; ?>

                    <?php // ── Fila de stock EOD y posiciones ───────────── ?>
                    <tr class="row-stock">
                        <td class="small fw-semibold text-muted <?= $esFinde ? 'text-muted fst-italic' : '' ?>">
                            <?php if (!$tiene_movs): ?>
                                <?= $sem ?> <?= fmtDia($dia) ?>
                            <?php else: ?>
                                &nbsp;
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small fst-italic">
                            <?= $tiene_movs ? 'Cierre del día' : '' ?>
                        </td>
                        <td colspan="2"></td>
                        <td></td>
                        <td></td>
                        <td class="text-end col-pal-pos"><?= fmtPal($info['stock']) ?></td>
                        <td class="text-end col-pal-pos"><?= fmtPal($info['stock']) ?></td>
                        <?php if ($precio > 0): ?>
                        <td class="text-end col-costo">
                            <?= $info['stock'] > 0 ? fmtMoney($info['costo']) : '—' ?>
                        </td>
                        <?php endif; ?>
                    </tr>

                    <?php endforeach; ?>

                    <!-- ── Fila de totales ──────────────────────────── -->
                    <tr class="total-row">
                        <td colspan="6" class="text-end pe-3">TOTAL DEL MES</td>
                        <td class="text-end"><?= fmtPal($datos['stock_actual']) ?></td>
                        <td class="text-end"><?= number_format($datos['total_posiciones'], 1) ?> pos.</td>
                        <?php if ($precio > 0): ?>
                        <td class="text-end"><?= fmtMoney($datos['total_posiciones'] * $precio) ?></td>
                        <?php endif; ?>
                    </tr>

                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($precio > 0): ?>
    <div class="alert alert-light border mt-3 d-flex justify-content-between align-items-center">
        <span>
            <strong><?= number_format($datos['total_posiciones'], 1) ?></strong> posiciones
            × <strong>$<?= number_format($precio, 2, ',', '.') ?></strong>/posición/día
        </span>
        <span class="fs-5 fw-bold text-success">
            Total: <?= fmtMoney($datos['total_posiciones'] * $precio) ?>
        </span>
    </div>
    <?php endif; ?>

    <?php endif; // remitos_periodo ?>
    <?php endif; // datos ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
