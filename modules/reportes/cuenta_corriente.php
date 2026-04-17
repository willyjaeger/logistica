<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

// ── Filtros ───────────────────────────────────────────────────
$proveedor_id  = (int)($_GET['proveedor_id']  ?? 0);
$mes           = max(1, min(12, (int)($_GET['mes']    ?? date('n'))));
$anio          = max(2020, (int)($_GET['anio']        ?? date('Y')));
$precio_pos    = (float)str_replace(',', '.', $_GET['precio_pos']   ?? '0');
$precio_viaje  = (float)str_replace(',', '.', $_GET['precio_viaje'] ?? '0');

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
        WHERE r.empresa_id   = ?
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
    $total_pal_viajes = 0.0;
    $cursor = new DateTime($inicio);
    $finDt  = new DateTime($fin);

    while ($cursor <= $finDt) {
        $d         = $cursor->format('Y-m-d');
        $stock     = 0.0;
        $pal_sal   = 0.0;  // pallets que salieron este día (agrupado, sin revelar detalle)
        $entradas  = [];   // sí se muestran individualmente

        foreach ($remitos_periodo as $r) {
            $pal = (float)$r['total_pallets'];
            if ($r['fecha_ingreso'] <= $d && ($r['fecha_salida_real'] === null || $r['fecha_salida_real'] > $d)) {
                $stock += $pal;
            }
            if ($r['fecha_ingreso'] === $d)     $entradas[] = $r;
            if ($r['fecha_salida_real'] === $d) $pal_sal   += $pal;
        }

        $total_posiciones += $stock;
        $total_pal_viajes += $pal_sal;

        $dias[$d] = [
            'stock'       => $stock,
            'entradas'    => $entradas,
            'pal_sal'     => $pal_sal,
            'costo_pos'   => $precio_pos   > 0 ? $stock   * $precio_pos   : null,
            'costo_viaje' => $precio_viaje > 0 ? $pal_sal * $precio_viaje : null,
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

    $total_costo_pos    = $precio_pos   > 0 ? $total_posiciones * $precio_pos   : null;
    $total_costo_viajes = $precio_viaje > 0 ? $total_pal_viajes * $precio_viaje : null;
    $total_general      = ($total_costo_pos ?? 0) + ($total_costo_viajes ?? 0);

    $datos = compact(
        'dias', 'total_posiciones', 'total_ingresado', 'total_salido',
        'stock_actual', 'inicio', 'fin', 'remitos_periodo',
        'total_pal_viajes', 'total_costo_pos', 'total_costo_viajes', 'total_general'
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
    $nombres = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
    return $nombres[(int)(new DateTime($ymd))->format('w')];
}

$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
          'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$anios = range(date('Y'), 2024, -1);

// Columnas variables según precios configurados
$con_pos   = $precio_pos   > 0;
$con_viaje = $precio_viaje > 0;
$con_total = $con_pos && $con_viaje;
// Total de columnas fijas + opcionales
$ncols = 6 + ($con_pos ? 1 : 0) + ($con_viaje ? 1 : 0) + ($con_total ? 1 : 0);

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
        .row-entrada { background: #f0fff4; }
        .row-dia     { background: #f8f9fa; }
        .row-dia td  { border-top: 2px solid #dee2e6 !important; }
        .row-viaje   { background: #fff8f0; }
        .col-pos     { color: #5a29a3; font-weight: 700; }
        .col-viaje   { color: #c45200; font-weight: 700; }
        .col-costo   { color: #0a6640; font-weight: 700; }
        .total-row td { background: #2c3e50 !important; color: #fff !important;
                        font-weight: 700; font-size: .9rem; }
        .subtotal-row td { background: #f0f2f5; font-weight: 600; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4" style="max-width:1150px">

    <div class="d-flex align-items-center justify-content-between mb-3 no-print">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-journal-text me-2 text-primary"></i>Cuenta corriente — Proveedores
        </h5>
        <?php if ($datos && !empty($datos['remitos_periodo'])): ?>
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

                <div class="col-6 col-sm-2 col-lg-2">
                    <label class="form-label form-label-sm fw-semibold mb-1">Mes</label>
                    <select name="mes" class="form-select form-select-sm">
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?= $i ?>" <?= $i === $mes ? 'selected' : '' ?>><?= $meses[$i] ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-6 col-sm-2 col-lg-1">
                    <label class="form-label form-label-sm fw-semibold mb-1">Año</label>
                    <select name="anio" class="form-select form-select-sm">
                        <?php foreach ($anios as $a): ?>
                        <option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-3 col-lg-2">
                    <label class="form-label form-label-sm fw-semibold mb-1">
                        <i class="bi bi-box-seam me-1 text-purple" style="color:#5a29a3"></i>$ posición / día
                    </label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" name="precio_pos" class="form-control form-control-sm"
                               step="0.01" min="0" value="<?= $precio_pos > 0 ? $precio_pos : '' ?>"
                               placeholder="0.00">
                    </div>
                </div>

                <div class="col-sm-3 col-lg-2">
                    <label class="form-label form-label-sm fw-semibold mb-1">
                        <i class="bi bi-truck me-1" style="color:#c45200"></i>$ por pallet entregado
                    </label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" name="precio_viaje" class="form-control form-control-sm"
                               step="0.01" min="0" value="<?= $precio_viaje > 0 ? $precio_viaje : '' ?>"
                               placeholder="0.00">
                    </div>
                </div>

                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1 invisible d-none d-lg-block">.</label>
                    <button type="submit" class="btn btn-primary btn-sm d-block">
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
        <div class="text-muted fs-6">
            <?= h($prov_nombre) ?> &nbsp;·&nbsp; <?= $meses[$mes] ?> <?= $anio ?>
            <?php if ($precio_pos > 0): ?> &nbsp;·&nbsp; Almacenaje: $<?= number_format($precio_pos, 2, ',', '.') ?>/pos./día<?php endif; ?>
            <?php if ($precio_viaje > 0): ?> &nbsp;·&nbsp; Distribución: $<?= number_format($precio_viaje, 2, ',', '.') ?>/pal.<?php endif; ?>
        </div>
        <hr>
    </div>

    <!-- ── Resumen ─────────────────────────────────────────── -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-md-2">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Ingresados</div>
                    <div class="fs-4 fw-bold text-success">+<?= fmtPal($datos['total_ingresado']) ?></div>
                    <div class="text-muted" style="font-size:.72rem">pallets</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Entregados</div>
                    <div class="fs-4 fw-bold text-danger">−<?= fmtPal($datos['total_salido']) ?></div>
                    <div class="text-muted" style="font-size:.72rem">pallets</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">En stock</div>
                    <div class="fs-4 fw-bold text-primary"><?= fmtPal($datos['stock_actual']) ?></div>
                    <div class="text-muted" style="font-size:.72rem">pallets</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Posiciones</div>
                    <div class="fs-4 fw-bold" style="color:#5a29a3"><?= number_format($datos['total_posiciones'], 1) ?></div>
                    <?php if ($con_pos): ?>
                    <div class="fw-semibold text-success small mt-1"><?= fmtMoney($datos['total_costo_pos']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-2">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Distribución</div>
                    <div class="fs-4 fw-bold" style="color:#c45200"><?= fmtPal($datos['total_pal_viajes']) ?></div>
                    <?php if ($con_viaje): ?>
                    <div class="fw-semibold text-success small mt-1"><?= fmtMoney($datos['total_costo_viajes']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($con_pos || $con_viaje): ?>
        <div class="col-6 col-md-2">
            <div class="card h-100 border-success" style="border:2px solid #198754 !important">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Total a cobrar</div>
                    <div class="fs-4 fw-bold text-success"><?= fmtMoney($datos['total_general']) ?></div>
                    <div class="text-muted" style="font-size:.72rem">
                        <?php if ($con_pos && $con_viaje): ?>alm. + distrib.<?php
                        elseif ($con_pos): ?>almacenaje<?php
                        else: ?>distribución<?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
        <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-calendar3 me-2 text-primary"></i>
                <?= h($prov_nombre) ?> — <?= $meses[$mes] ?> <?= $anio ?>
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="tabla-cc">
                    <thead>
                        <tr>
                            <th style="width:95px">Fecha</th>
                            <th>Concepto</th>
                            <th>Remito / Cliente</th>
                            <th class="text-end">Pal. entrada</th>
                            <th class="text-end">Pal. salida</th>
                            <th class="text-end">Stock</th>
                            <th class="text-end">Posiciones</th>
                            <?php if ($con_pos):   ?><th class="text-end">$ Almacenaje</th><?php endif; ?>
                            <?php if ($con_viaje): ?><th class="text-end">$ Distribución</th><?php endif; ?>
                            <?php if ($con_total): ?><th class="text-end">$ Total día</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($datos['dias'] as $dia => $info):
                        $tiene_entradas = !empty($info['entradas']);
                        $tiene_salidas  = $info['pal_sal'] > 0;
                        $tiene_movs     = $tiene_entradas || $tiene_salidas;
                        if ($info['stock'] == 0 && !$tiene_movs) continue;

                        $sem     = diaSemana($dia);
                        $esFinde = in_array($sem, ['Sáb','Dom']);
                        $fmtFecha = '<span class="fw-semibold' . ($esFinde ? ' text-muted fst-italic' : '') . '">'
                                  . $sem . ' ' . fmtDia($dia) . '</span>';
                    ?>

                    <?php // ── Una fila por ingreso (se muestra el detalle del remito) ── ?>
                    <?php foreach ($info['entradas'] as $idx => $r): ?>
                    <tr class="row-entrada">
                        <td class="small"><?= $idx === 0 ? $fmtFecha : '' ?></td>
                        <td><span class="badge bg-success">Ingreso</span></td>
                        <td class="small">
                            <span class="font-monospace"><?= h($r['nro_remito_propio']) ?></span>
                            <span class="text-muted ms-1"><?= h($r['cliente']) ?></span>
                        </td>
                        <td class="text-end text-success fw-semibold">+<?= fmtPal((float)$r['total_pallets']) ?></td>
                        <td></td><td></td><td></td>
                        <?php if ($con_pos):   ?><td></td><?php endif; ?>
                        <?php if ($con_viaje): ?><td></td><?php endif; ?>
                        <?php if ($con_total): ?><td></td><?php endif; ?>
                    </tr>
                    <?php endforeach; ?>

                    <?php // ── Fila agrupada de salidas + stock EOD (sin revelar remitos) ── ?>
                    <tr class="<?= $tiene_salidas ? 'row-viaje' : 'row-dia' ?>">
                        <td class="small">
                            <?php if (!$tiene_entradas): echo $fmtFecha; endif; ?>
                        </td>
                        <td>
                            <?php if ($tiene_salidas): ?>
                            <span class="badge" style="background:#f97316">Entrega del día</span>
                            <?php elseif ($tiene_movs): ?>
                            <span class="text-muted small fst-italic">Cierre del día</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small fst-italic">
                            <?php if (!$tiene_movs && $info['stock'] > 0): ?>
                                Stock en depósito
                            <?php endif; ?>
                        </td>
                        <td></td>
                        <td class="text-end <?= $tiene_salidas ? 'col-viaje' : 'text-muted' ?>">
                            <?= $tiene_salidas ? '−' . fmtPal($info['pal_sal']) : '—' ?>
                        </td>
                        <td class="text-end col-pos"><?= fmtPal($info['stock']) ?></td>
                        <td class="text-end col-pos"><?= fmtPal($info['stock']) ?></td>
                        <?php if ($con_pos): ?>
                        <td class="text-end col-costo">
                            <?= $info['stock'] > 0 && $info['costo_pos'] !== null ? fmtMoney($info['costo_pos']) : '—' ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($con_viaje): ?>
                        <td class="text-end col-viaje">
                            <?= $tiene_salidas && $info['costo_viaje'] !== null ? fmtMoney($info['costo_viaje']) : '—' ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($con_total): ?>
                        <td class="text-end col-costo">
                            <?php
                            $tot_dia = ($info['costo_pos'] ?? 0) + ($info['costo_viaje'] ?? 0);
                            echo $tot_dia > 0 ? fmtMoney($tot_dia) : '—';
                            ?>
                        </td>
                        <?php endif; ?>
                    </tr>

                    <?php endforeach; ?>

                    <!-- ── Subtotales ───────────────────────────────── -->
                    <?php if ($con_pos || $con_viaje): ?>
                    <tr class="subtotal-row">
                        <td colspan="6" class="text-end pe-3 text-muted small text-uppercase">Subtotal almacenaje</td>
                        <td class="text-end col-pos"><?= number_format($datos['total_posiciones'], 1) ?> pos.</td>
                        <?php if ($con_pos): ?>
                        <td class="text-end col-costo"><?= fmtMoney($datos['total_costo_pos']) ?></td>
                        <?php endif; ?>
                        <?php if ($con_viaje): ?><td></td><?php endif; ?>
                        <?php if ($con_total): ?><td></td><?php endif; ?>
                    </tr>
                    <tr class="subtotal-row">
                        <td colspan="4" class="text-end pe-3 text-muted small text-uppercase">Subtotal distribución</td>
                        <td class="text-end col-viaje"><?= fmtPal($datos['total_pal_viajes']) ?> pal.</td>
                        <td colspan="2"></td>
                        <?php if ($con_pos):   ?><td></td><?php endif; ?>
                        <?php if ($con_viaje): ?>
                        <td class="text-end col-viaje"><?= fmtMoney($datos['total_costo_viajes'] ?? 0) ?></td>
                        <?php endif; ?>
                        <?php if ($con_total): ?><td></td><?php endif; ?>
                    </tr>
                    <?php endif; ?>

                    <!-- ── Total general ────────────────────────────── -->
                    <tr class="total-row">
                        <td colspan="<?= $ncols - ($con_pos || $con_viaje ? 1 : 0) - ($con_total ? 1 : 0) ?>"
                            class="text-end pe-3">TOTAL <?= strtoupper($meses[$mes]) ?> <?= $anio ?></td>
                        <?php if ($con_pos && !$con_viaje): ?>
                        <td class="text-end"><?= fmtMoney($datos['total_costo_pos']) ?></td>
                        <?php elseif (!$con_pos && $con_viaje): ?>
                        <td class="text-end"><?= fmtMoney($datos['total_costo_viajes']) ?></td>
                        <?php elseif ($con_total): ?>
                        <td class="text-end"><?= fmtMoney($datos['total_costo_pos']) ?></td>
                        <td class="text-end"><?= fmtMoney($datos['total_costo_viajes']) ?></td>
                        <td class="text-end"><?= fmtMoney($datos['total_general']) ?></td>
                        <?php else: ?>
                        <td></td>
                        <?php endif; ?>
                    </tr>

                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($con_pos || $con_viaje): ?>
    <div class="card mt-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-center text-center">
                <?php if ($con_pos): ?>
                <div class="col-auto text-muted small">
                    Almacenaje: <strong><?= number_format($datos['total_posiciones'], 1) ?> pos.</strong>
                    × <strong>$<?= number_format($precio_pos, 2, ',', '.') ?></strong>
                    = <strong class="col-costo"><?= fmtMoney($datos['total_costo_pos']) ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($con_pos && $con_viaje): ?>
                <div class="col-auto text-muted">+</div>
                <?php endif; ?>
                <?php if ($con_viaje): ?>
                <div class="col-auto text-muted small">
                    Distribución: <strong><?= fmtPal($datos['total_pal_viajes']) ?> pal.</strong>
                    × <strong>$<?= number_format($precio_viaje, 2, ',', '.') ?></strong>
                    = <strong class="col-viaje"><?= fmtMoney($datos['total_costo_viajes']) ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($con_pos || $con_viaje): ?>
                <div class="col-auto ms-auto">
                    <span class="fs-5 fw-bold text-success">
                        Total: <?= fmtMoney($datos['total_general']) ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // remitos_periodo ?>
    <?php endif; // datos ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
