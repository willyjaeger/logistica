<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!es_admin()) { header('Location: ' . url('index.php')); exit; }

$db  = db();
$eid = empresa_id();

// ── Filtros (default: mes actual) ────────────────────────────────
$desde            = $_GET['desde'] ?? date('Y-m-01');
$hasta            = $_GET['hasta'] ?? date('Y-m-d');
$transportista_id = (int)($_GET['transportista_id'] ?? 0);

// Transportistas activos para el select
$tq = $db->prepare("SELECT id, nombre FROM transportistas WHERE empresa_id=? AND activo=1 ORDER BY nombre");
$tq->execute([$eid]);
$transportistas_list = $tq->fetchAll();

// ── Query principal ───────────────────────────────────────────────
$where  = ['e.empresa_id = ?'];
$params = [$eid];
if ($desde !== '') { $where[] = 'COALESCE(e.fecha, DATE(e.fecha_salida)) >= ?'; $params[] = $desde; }
if ($hasta !== '') { $where[] = 'COALESCE(e.fecha, DATE(e.fecha_salida)) <= ?'; $params[] = $hasta; }
if ($transportista_id > 0) { $where[] = 'e.transportista_id = ?'; $params[] = $transportista_id; }

$sql = "
    SELECT e.id,
           COALESCE(e.fecha, DATE(e.fecha_salida))   AS fecha,
           e.estado, e.costo_transporte,
           COALESCE(e.transportista_id, 0)            AS transportista_id,
           COALESCE(t.nombre, '(Sin transportista)')  AS transportista,
           cam.patente,
           ch.nombre                                   AS chofer,
           COUNT(er.remito_id)                         AS nro_remitos,
           COALESCE(SUM(r.total_pallets), 0)           AS total_pallets
    FROM entregas e
    LEFT JOIN transportistas t   ON t.id  = e.transportista_id
    LEFT JOIN camiones cam       ON cam.id = e.camion_id
    LEFT JOIN choferes ch        ON ch.id  = e.chofer_id
    LEFT JOIN entrega_remitos er ON er.entrega_id = e.id
    LEFT JOIN remitos r          ON r.id = er.remito_id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY e.id, e.empresa_id, e.fecha, e.fecha_salida, e.estado, e.costo_transporte,
             e.transportista_id, t.nombre, cam.patente, ch.nombre
    ORDER BY COALESCE(t.nombre, '~'), COALESCE(e.fecha, DATE(e.fecha_salida)) DESC, e.id DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$entregas_raw = $stmt->fetchAll();

// ── Detalle de clientes / localidad de entrega por viaje ──────────
$detalle_map = [];
$ids = array_column($entregas_raw, 'id');
if ($ids) {
    $idsStr = implode(',', array_map('intval', $ids));
    $dq = $db->query("
        SELECT er.entrega_id, c.nombre AS cliente, c.localidad
        FROM entrega_remitos er
        JOIN remitos r  ON r.id = er.remito_id
        JOIN clientes c ON c.id = r.cliente_id
        WHERE er.entrega_id IN ($idsStr)
        ORDER BY c.nombre
    ");
    foreach ($dq->fetchAll() as $row) {
        $eid2 = $row['entrega_id'];
        if (!isset($detalle_map[$eid2])) $detalle_map[$eid2] = ['clientes' => [], 'localidades' => []];
        if ($row['cliente'] && !in_array($row['cliente'], $detalle_map[$eid2]['clientes'], true)) {
            $detalle_map[$eid2]['clientes'][] = $row['cliente'];
        }
        if (!empty($row['localidad']) && !in_array($row['localidad'], $detalle_map[$eid2]['localidades'], true)) {
            $detalle_map[$eid2]['localidades'][] = $row['localidad'];
        }
    }
}

// ── Agrupar por transportista → mes ──────────────────────────────
$grupos             = [];
$total_viajes_gral  = 0;
$total_pallets_gral = 0.0;
$total_costo_gral   = 0.0;

foreach ($entregas_raw as $e) {
    $tid   = (int)$e['transportista_id'];
    $tnom  = $e['transportista'];
    $mes   = $e['fecha'] ? substr($e['fecha'], 0, 7) : '0000-00';
    $costo = (float)($e['costo_transporte'] ?? 0);
    $e['clientes']    = $detalle_map[$e['id']]['clientes']    ?? [];
    $e['localidades'] = $detalle_map[$e['id']]['localidades'] ?? [];

    if (!isset($grupos[$tid])) {
        $grupos[$tid] = ['nombre' => $tnom, 'meses' => [], 'total_viajes' => 0, 'total_pallets' => 0.0, 'total_costo' => 0.0];
    }
    if (!isset($grupos[$tid]['meses'][$mes])) {
        $grupos[$tid]['meses'][$mes] = ['viajes' => [], 'total_viajes' => 0, 'total_pallets' => 0.0, 'total_costo' => 0.0];
    }
    $grupos[$tid]['meses'][$mes]['viajes'][]       = $e;
    $grupos[$tid]['meses'][$mes]['total_viajes']  += 1;
    $grupos[$tid]['meses'][$mes]['total_pallets'] += (float)$e['total_pallets'];
    $grupos[$tid]['meses'][$mes]['total_costo']   += $costo;
    $grupos[$tid]['total_viajes']                 += 1;
    $grupos[$tid]['total_pallets']                += (float)$e['total_pallets'];
    $grupos[$tid]['total_costo']                  += $costo;
    $total_viajes_gral  += 1;
    $total_pallets_gral += (float)$e['total_pallets'];
    $total_costo_gral   += $costo;
}

// Dentro de cada mes, ordenar los viajes de forma cronológica ascendente
// (día 1 → día 31), que es como suelen venir las planillas del transportista.
foreach ($grupos as &$g) {
    krsort($g['meses']); // meses: más reciente primero
    foreach ($g['meses'] as &$md) {
        usort($md['viajes'], function ($a, $b) {
            return [$a['fecha'], $a['id']] <=> [$b['fecha'], $b['id']];
        });
    }
    unset($md);
}
unset($g);

// ── Helpers ───────────────────────────────────────────────────────
$meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
             'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

function mesLabel(string $ym): string {
    global $meses_es;
    if ($ym === '0000-00') return 'Sin fecha';
    [$y, $m] = explode('-', $ym);
    return $meses_es[(int)$m] . ' ' . $y;
}

function estadoBadge(string $estado): string {
    $map = [
        'armando'         => ['secondary', 'Armando'],
        'en_camino'       => ['primary',   'En camino'],
        'completada'      => ['success',   'Completada'],
        'con_incidencias' => ['warning',   'Con incidencias'],
    ];
    [$cls, $lbl] = $map[$estado] ?? ['secondary', $estado];
    return "<span class=\"badge bg-$cls\">$lbl</span>";
}

function fmtFechaVT(?string $f): string {
    if (!$f) return '—';
    [$y, $m, $d] = explode('-', substr($f, 0, 10));
    return "$d/$m/$y";
}

function fmtMoney(float $v): string {
    return '$' . number_format($v, 2, ',', '.');
}

// ── Export Excel ──────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $fname = 'Viajes_' . str_replace('-', '', $desde) . '_' . str_replace('-', '', $hasta) . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office"
               xmlns:x="urn:schemas-microsoft-com:office:excel">
<head><meta charset="UTF-8">
<style>
  td,th  { font-family:Calibri,Arial,sans-serif; font-size:10pt; }
  .hdr   { background:#1a2a3a; color:#fff; font-weight:bold; }
  .tit   { background:#2c5282; color:#fff; font-weight:bold; font-size:11pt; }
  .mes   { background:#dbeafe; font-weight:bold; }
  .sub   { background:#f0f4ff; font-weight:bold; }
  .tot   { background:#1a2a3a; color:#fff; font-weight:bold; }
</style></head><body>';
    echo '<table border="1" cellspacing="0" cellpadding="4">';
    echo '<tr><td colspan="8" style="font-size:13pt;font-weight:bold;background:#1a2a3a;color:#fff">Viajes por Transporte</td></tr>';
    echo '<tr><td colspan="8">' . fmtFechaVT($desde) . ' — ' . fmtFechaVT($hasta) . '</td></tr>';
    echo '<tr><td colspan="8"></td></tr>';
    echo '<tr class="hdr"><th>Transportista</th><th>Mes</th><th>Fecha</th><th>Patente</th>'
       . '<th>Chofer</th><th>Remitos</th><th>Pallets</th><th>Estado</th></tr>';
    $estados_txt = ['armando'=>'Armando','en_camino'=>'En camino',
                    'completada'=>'Completada','con_incidencias'=>'Con incidencias'];
    foreach ($grupos as $g) {
        foreach ($g['meses'] as $mes => $md) {
            foreach ($md['viajes'] as $e) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($g['nombre']) . '</td>';
                echo '<td>' . mesLabel($mes) . '</td>';
                echo '<td>' . fmtFechaVT($e['fecha']) . '</td>';
                echo '<td>' . htmlspecialchars($e['patente'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($e['chofer'] ?? '') . '</td>';
                echo '<td style="text-align:right">' . (int)$e['nro_remitos'] . '</td>';
                echo '<td style="text-align:right">' . number_format((float)$e['total_pallets'], 1) . '</td>';
                echo '<td>' . htmlspecialchars($estados_txt[$e['estado']] ?? $e['estado']) . '</td>';
                echo '</tr>';
            }
            $nv = $md['total_viajes'];
            echo '<tr class="sub"><td colspan="5" style="text-align:right">Subtotal ' . mesLabel($mes) . '</td>';
            echo '<td style="text-align:right">' . $nv . '</td>';
            echo '<td style="text-align:right">' . number_format($md['total_pallets'], 1) . '</td>';
            echo '<td>' . $nv . ' viaje' . ($nv === 1 ? '' : 's') . '</td></tr>';
        }
        $ngt = $g['total_viajes'];
        echo '<tr class="tit"><td colspan="5" style="text-align:right">Total ' . htmlspecialchars($g['nombre']) . '</td>';
        echo '<td style="text-align:right">' . $ngt . '</td>';
        echo '<td style="text-align:right">' . number_format($g['total_pallets'], 1) . '</td>';
        echo '<td>' . $ngt . ' viaje' . ($ngt === 1 ? '' : 's') . '</td></tr>';
        echo '<tr><td colspan="8"></td></tr>';
    }
    echo '<tr class="tot"><td colspan="5" style="text-align:right">TOTAL GENERAL</td>';
    echo '<td style="text-align:right">' . $total_viajes_gral . '</td>';
    echo '<td style="text-align:right">' . number_format($total_pallets_gral, 1) . '</td>';
    echo '<td>' . $total_viajes_gral . ' viaje' . ($total_viajes_gral === 1 ? '' : 's') . '</td></tr>';
    echo '</table></body></html>';
    exit;
}

$nav_modulo = 'reportes';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Viajes por Transporte — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        body { background: #eef1f6; }
        .card { border: none !important; box-shadow: 0 2px 8px rgba(0,0,0,.10) !important; }

        /* Cabecera de transportista */
        .th-card-hdr {
            background: #2c3e50;
            color: #fff;
            font-weight: 700;
            font-size: .95rem;
            border-radius: 6px 6px 0 0;
        }

        /* Fila de mes */
        .row-mes td {
            background: #dbeafe;
            font-weight: 700;
            font-size: .82rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #1e3a8a;
            border-top: 2px solid #93c5fd !important;
        }

        /* Fila de viaje */
        .row-viaje td { font-size: .88rem; }
        .row-viaje:hover td { background: #f8faff; }

        /* Subtotal de mes */
        .row-sub-mes td {
            background: #f0f4ff;
            font-weight: 600;
            font-size: .84rem;
            border-top: 1px solid #c7d7f7 !important;
        }

        /* Total de transportista */
        .row-total-t td {
            background: #1a2a3a;
            color: #fff;
            font-weight: 700;
            font-size: .88rem;
        }

        /* Total general */
        .row-total-gral td {
            background: #0f1e2d;
            color: #fff;
            font-weight: 800;
            font-size: .95rem;
        }

        /* Shortcuts de fecha */
        .btn-shortcut { font-size: .78rem; padding: .2rem .55rem; }

        @media print {
            body { background: #fff; }
            nav, .navbar, .no-print { display: none !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
            .row-mes td, .row-total-t td, .row-total-gral td {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .only-print { display: table-cell !important; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4" style="max-width:1200px">

    <div class="d-flex align-items-center justify-content-between mb-3 no-print">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-truck me-2 text-primary"></i>Viajes por Transporte
        </h5>
        <div class="d-flex gap-2">
            <?php if ($total_viajes_gral > 0): ?>
            <?php
            $excel_params = http_build_query([
                'desde' => $desde, 'hasta' => $hasta,
                'transportista_id' => $transportista_id, 'export' => 'excel',
            ]);
            ?>
            <a href="<?= url('modules/reportes/viajes_transporte.php') ?>?<?= $excel_params ?>"
               class="btn btn-outline-success btn-sm">
                <i class="bi bi-file-earmark-excel me-1"></i>Excel
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Imprimir
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Filtros ──────────────────────────────────────────────── -->
    <form method="GET" class="card mb-3 no-print">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">

                <div class="col-sm-6 col-md-3">
                    <label class="form-label form-label-sm fw-semibold mb-1">Desde</label>
                    <input type="date" name="desde" id="inp-desde" class="form-control form-control-sm"
                           value="<?= h($desde) ?>">
                </div>

                <div class="col-sm-6 col-md-3">
                    <label class="form-label form-label-sm fw-semibold mb-1">Hasta</label>
                    <input type="date" name="hasta" id="inp-hasta" class="form-control form-control-sm"
                           value="<?= h($hasta) ?>">
                </div>

                <div class="col-sm-6 col-md-3">
                    <label class="form-label form-label-sm fw-semibold mb-1">Transportista</label>
                    <select name="transportista_id" class="form-select form-select-sm">
                        <option value="0">— Todos —</option>
                        <?php foreach ($transportistas_list as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= (int)$t['id'] === $transportista_id ? 'selected' : '' ?>>
                            <?= h($t['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1 invisible d-none d-md-block">.</label>
                    <button type="submit" class="btn btn-primary btn-sm d-block">
                        <i class="bi bi-search me-1"></i>Ver
                    </button>
                </div>

            </div>

            <!-- Accesos rápidos -->
            <div class="mt-2 d-flex gap-1 flex-wrap">
                <span class="text-muted small align-self-center me-1">Período:</span>
                <button type="button" class="btn btn-outline-secondary btn-shortcut"
                        onclick="setMes(0)">Este mes</button>
                <button type="button" class="btn btn-outline-secondary btn-shortcut"
                        onclick="setMes(-1)">Mes anterior</button>
                <button type="button" class="btn btn-outline-secondary btn-shortcut"
                        onclick="setMeses(3)">Últimos 3 meses</button>
                <button type="button" class="btn btn-outline-secondary btn-shortcut"
                        onclick="setMeses(6)">Últimos 6 meses</button>
                <button type="button" class="btn btn-outline-secondary btn-shortcut"
                        onclick="setAnio()">Este año</button>
            </div>
        </div>
    </form>

    <?php if (empty($entregas_raw)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        No se encontraron viajes para el rango seleccionado.
    </div>
    <?php else: ?>

    <!-- ── Tarjetas resumen ──────────────────────────────────────── -->
    <div class="row g-3 mb-4 no-print">
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Total viajes</div>
                    <div class="fs-3 fw-bold text-primary"><?= $total_viajes_gral ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Total pallets</div>
                    <div class="fs-3 fw-bold text-success"><?= number_format($total_pallets_gral, 1) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Transportistas</div>
                    <div class="fs-3 fw-bold text-secondary"><?= count($grupos) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Promedio pal./viaje</div>
                    <div class="fs-3 fw-bold" style="color:#7c3aed">
                        <?= $total_viajes_gral > 0 ? number_format($total_pallets_gral / $total_viajes_gral, 1) : '—' ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Costo total</div>
                    <div class="fs-3 fw-bold text-dark" id="card-costo-gral"><?= fmtMoney($total_costo_gral) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Tabla principal ──────────────────────────────────────── -->
    <div class="card">
        <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" id="tabla-viajes">
            <thead>
                <tr style="background:#2c3e50; color:#fff">
                    <th style="font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; padding:.6rem .75rem">Fecha</th>
                    <th style="font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em">Cliente</th>
                    <th style="font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em">Localidad</th>
                    <th style="font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em">Patente</th>
                    <th style="font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em">Chofer</th>
                    <th class="text-center" style="font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em">Remitos</th>
                    <th class="text-end" style="font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em">Pallets</th>
                    <th class="text-center" style="font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em">Estado</th>
                    <th class="text-end" style="font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; width:130px">Costo</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($grupos as $tid => $g):
                $ngt = $g['total_viajes'];
            ?>
                <!-- Transportista header -->
                <tr class="th-card-hdr">
                    <td colspan="6" style="padding:.55rem .75rem">
                        <i class="bi bi-building me-2"></i><?= h($g['nombre']) ?>
                    </td>
                    <td class="text-end"><?= number_format($g['total_pallets'], 1) ?> pal.</td>
                    <td class="text-center"><?= $ngt ?> viaje<?= $ngt === 1 ? '' : 's' ?></td>
                    <td class="text-end" id="tot-costo-t-<?= $tid ?>"><?= fmtMoney($g['total_costo']) ?></td>
                </tr>

                <?php foreach ($g['meses'] as $mes => $md):
                    $nvm = $md['total_viajes'];
                    $mes_id = $tid . '-' . str_replace(['-', '~'], ['_', 'x'], $mes);
                ?>
                <!-- Mes header -->
                <tr class="row-mes">
                    <td colspan="9" style="padding:.4rem .75rem">
                        <i class="bi bi-calendar3 me-2"></i><?= mesLabel($mes) ?>
                        <span class="ms-3 fw-normal" style="color:#3b5bbd">
                            <?= $nvm ?> viaje<?= $nvm === 1 ? '' : 's' ?>
                            &nbsp;·&nbsp; <?= number_format($md['total_pallets'], 1) ?> pallets
                        </span>
                    </td>
                </tr>

                <?php foreach ($md['viajes'] as $e):
                    $cli_str = implode(', ', $e['clientes']);
                    $loc_str = implode(', ', $e['localidades']);
                ?>
                <tr class="row-viaje">
                    <td style="padding:.4rem .75rem; white-space:nowrap">
                        <?= fmtFechaVT($e['fecha']) ?>
                    </td>
                    <td class="small" style="max-width:160px">
                        <?php if ($cli_str !== ''): ?>
                        <span title="<?= h($cli_str) ?>"><?= h(mb_strimwidth($cli_str, 0, 28, '…')) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted" style="max-width:140px">
                        <?php if ($loc_str !== ''): ?>
                        <span title="<?= h($loc_str) ?>"><?= h(mb_strimwidth($loc_str, 0, 24, '…')) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($e['patente']): ?>
                        <span class="badge bg-secondary fw-normal"><?= h($e['patente']) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($e['chofer'] ?? '—') ?></td>
                    <td class="text-center">
                        <?= (int)$e['nro_remitos'] > 0
                            ? '<span class="badge bg-light text-dark border">' . (int)$e['nro_remitos'] . '</span>'
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-end fw-semibold">
                        <?= (float)$e['total_pallets'] > 0 ? number_format((float)$e['total_pallets'], 1) : '—' ?>
                    </td>
                    <td class="text-center"><?= estadoBadge($e['estado']) ?></td>
                    <td class="no-print">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text px-1">$</span>
                            <input type="number" step="0.01" min="0"
                                   class="form-control input-costo text-end"
                                   data-id="<?= $e['id'] ?>"
                                   data-tid="<?= $tid ?>"
                                   data-mes="<?= $mes_id ?>"
                                   value="<?= $e['costo_transporte'] !== null ? number_format((float)$e['costo_transporte'], 2, '.', '') : '' ?>"
                                   placeholder="—">
                        </div>
                    </td>
                    <td class="only-print text-end d-none"><?= $e['costo_transporte'] !== null ? fmtMoney((float)$e['costo_transporte']) : '—' ?></td>
                </tr>
                <?php endforeach; // viajes ?>

                <!-- Subtotal mes -->
                <tr class="row-sub-mes">
                    <td colspan="5" class="text-end pe-3" style="color:#1e3a8a">
                        Subtotal <?= mesLabel($mes) ?>
                    </td>
                    <td class="text-center" style="color:#1e3a8a"><?= $nvm ?></td>
                    <td class="text-end" style="color:#1e3a8a"><?= number_format($md['total_pallets'], 1) ?></td>
                    <td></td>
                    <td class="text-end" style="color:#1e3a8a" id="tot-costo-m-<?= $mes_id ?>"><?= fmtMoney($md['total_costo']) ?></td>
                </tr>

                <?php endforeach; // meses ?>

                <!-- Total transportista -->
                <tr class="row-total-t">
                    <td colspan="5" class="text-end pe-3">Total <?= h($g['nombre']) ?></td>
                    <td class="text-center"><?= $ngt ?></td>
                    <td class="text-end"><?= number_format($g['total_pallets'], 1) ?></td>
                    <td></td>
                    <td class="text-end" id="tot-costo-t2-<?= $tid ?>"><?= fmtMoney($g['total_costo']) ?></td>
                </tr>

            <?php endforeach; // grupos ?>

                <!-- Total general -->
                <tr class="row-total-gral">
                    <td colspan="5" class="text-end pe-3">TOTAL GENERAL</td>
                    <td class="text-center"><?= $total_viajes_gral ?></td>
                    <td class="text-end"><?= number_format($total_pallets_gral, 1) ?></td>
                    <td></td>
                    <td class="text-end" id="tot-costo-gral"><?= fmtMoney($total_costo_gral) ?></td>
                </tr>
            </tbody>
        </table>
        </div>
        </div>
    </div>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Accesos rápidos de período
function setMes(offset) {
    var d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() + offset);
    var y = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2,'0');
    var ultimo = new Date(y, d.getMonth() + 1, 0).getDate();
    document.getElementById('inp-desde').value = y + '-' + m + '-01';
    document.getElementById('inp-hasta').value = y + '-' + m + '-' + String(ultimo).padStart(2,'0');
    document.querySelector('form').submit();
}

function setMeses(n) {
    var hoy  = new Date();
    var ini  = new Date(hoy.getFullYear(), hoy.getMonth() - (n - 1), 1);
    var y1 = ini.getFullYear(), m1 = String(ini.getMonth() + 1).padStart(2,'0');
    var y2 = hoy.getFullYear(), m2 = String(hoy.getMonth() + 1).padStart(2,'0'),
        d2 = String(hoy.getDate()).padStart(2,'0');
    document.getElementById('inp-desde').value = y1 + '-' + m1 + '-01';
    document.getElementById('inp-hasta').value = y2 + '-' + m2 + '-' + d2;
    document.querySelector('form').submit();
}

function setAnio() {
    var y = new Date().getFullYear();
    var hoy = new Date();
    var m = String(hoy.getMonth()+1).padStart(2,'0'), d = String(hoy.getDate()).padStart(2,'0');
    document.getElementById('inp-desde').value = y + '-01-01';
    document.getElementById('inp-hasta').value = y + '-' + m + '-' + d;
    document.querySelector('form').submit();
}

// ── Costo de transporte: edición inline + pegado desde Excel ──────
document.querySelectorAll('.input-costo').forEach(function (inp) {
    inp.addEventListener('change', function () { guardarCosto(this); });
    inp.addEventListener('paste', function (e) {
        const texto = (e.clipboardData || window.clipboardData).getData('text');
        const valores = texto.split(/\r\n|\r|\n/).map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
        if (valores.length <= 1) return; // valor único: dejar que el navegador pegue normalmente
        e.preventDefault();
        const inputs  = Array.from(document.querySelectorAll('.input-costo'));
        const desde   = inputs.indexOf(this);
        const tid     = this.dataset.tid;
        for (let i = 0; i < valores.length; i++) {
            const destino = inputs[desde + i];
            if (!destino || destino.dataset.tid !== tid) break; // no cruzar a otro transportista
            const num = valores[i].replace(',', '.').replace(/[^0-9.]/g, '');
            destino.value = num;
            guardarCosto(destino);
        }
    });
});

function guardarCosto(input) {
    const id    = input.dataset.id;
    const costo = input.value;
    input.classList.remove('is-invalid', 'border-success');
    fetch('<?= url('modules/entrega_costo_guardar.php') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(id) + '&costo=' + encodeURIComponent(costo)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.ok) {
            input.classList.add('border-success');
            setTimeout(function () { input.classList.remove('border-success'); }, 1200);
            recalcTotalesCosto();
        } else {
            input.classList.add('is-invalid');
        }
    })
    .catch(function () { input.classList.add('is-invalid'); });
}

function fmtMoneyJs(v) {
    return '$' + v.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function recalcTotalesCosto() {
    const porTransportista = {};
    const porMes = {};
    let total = 0;
    document.querySelectorAll('.input-costo').forEach(function (inp) {
        const v = parseFloat((inp.value || '0').replace(',', '.')) || 0;
        porTransportista[inp.dataset.tid] = (porTransportista[inp.dataset.tid] || 0) + v;
        porMes[inp.dataset.mes] = (porMes[inp.dataset.mes] || 0) + v;
        total += v;
    });
    Object.keys(porTransportista).forEach(function (tid) {
        const val = fmtMoneyJs(porTransportista[tid]);
        ['tot-costo-t-', 'tot-costo-t2-'].forEach(function (pfx) {
            const el = document.getElementById(pfx + tid);
            if (el) el.textContent = val;
        });
    });
    Object.keys(porMes).forEach(function (mes) {
        const el = document.getElementById('tot-costo-m-' + mes);
        if (el) el.textContent = fmtMoneyJs(porMes[mes]);
    });
    const gEl = document.getElementById('tot-costo-gral');
    if (gEl) gEl.textContent = fmtMoneyJs(total);
    const cardEl = document.getElementById('card-costo-gral');
    if (cardEl) cardEl.textContent = fmtMoneyJs(total);
}
</script>
</body>
</html>
