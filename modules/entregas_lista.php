<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

$desde    = $_GET['desde']    ?? '';
$hasta    = $_GET['hasta']    ?? '';
$prov_fil = (int)($_GET['proveedor_id'] ?? 0);

$where  = ['e.empresa_id = ?'];
$params = [$eid];

if ($desde) { $where[] = 'DATE(e.fecha_salida) >= ?'; $params[] = $desde; }
if ($hasta) { $where[] = 'DATE(e.fecha_salida) <= ?'; $params[] = $hasta; }

$sql = "
    SELECT e.id,
           COALESCE(e.fecha, DATE(e.fecha_salida)) AS fecha,
           e.estado, e.observaciones,
           t.nombre  AS transportista,
           cam.patente,
           ch.nombre AS chofer,
           COUNT(er.remito_id) AS nro_remitos,
           SUM(r.total_pallets) AS total_pallets
    FROM entregas e
    LEFT JOIN entrega_remitos er ON er.entrega_id = e.id
    LEFT JOIN remitos r           ON r.id = er.remito_id
    LEFT JOIN transportistas t   ON t.id = e.transportista_id
    LEFT JOIN camiones cam        ON cam.id = e.camion_id
    LEFT JOIN choferes ch         ON ch.id = e.chofer_id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY e.id
    ORDER BY COALESCE(e.fecha, DATE(e.fecha_salida)) DESC, e.id DESC
    LIMIT 200
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$entregas = $stmt->fetchAll();

// Detalle de remitos por entrega
$items_map = [];
if ($entregas) {
    $ids = implode(',', array_column($entregas, 'id'));
    $prov_cond = $prov_fil ? "AND r.proveedor_id = $prov_fil" : '';
    $ri = $db->query("
        SELECT er.entrega_id,
               r.id AS remito_id, r.nro_remito_propio, r.total_pallets, r.fecha_entrega,
               c.nombre AS cliente,
               p.nombre AS proveedor
        FROM entrega_remitos er
        JOIN remitos r   ON r.id = er.remito_id
        JOIN clientes c  ON c.id = r.cliente_id
        LEFT JOIN proveedores p ON p.id = r.proveedor_id
        WHERE er.entrega_id IN ($ids) $prov_cond
        ORDER BY er.entrega_id, r.id
    ");
    foreach ($ri->fetchAll() as $row) {
        $items_map[$row['entrega_id']][] = $row;
    }
    if ($prov_fil) {
        $entregas = array_filter($entregas, fn($e) => isset($items_map[$e['id']]));
    }
}

$provs = $db->query("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();

// ── Exportar a Excel (CSV) ────────────────────────────────────
if (isset($_GET['export'])) {
    $estado_txt = [
        'pendiente'       => 'Pendiente',
        'armando'         => 'Armando',
        'en_camino'       => 'En camino',
        'completada'      => 'Completada',
        'entregado'       => 'Entregada',
        'con_incidencias' => 'Con incidencias',
    ];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="entregas_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM para Excel
    fputcsv($out, ['Fecha','Estado','Transportista','Patente','Chofer','Remitos','Pallets'], ';');
    foreach ($entregas as $e) {
        [$y,$m,$d] = array_pad(explode('-', $e['fecha'] ?? '0000-00-00'), 3, '00');
        fputcsv($out, [
            "$d/$m/$y",
            $estado_txt[$e['estado']] ?? $e['estado'],
            $e['transportista'] ?? '',
            $e['patente'] ?? '',
            $e['chofer'] ?? '',
            $e['nro_remitos'],
            number_format((float)($e['total_pallets'] ?? 0), 1, ',', '.'),
        ], ';');
    }
    fclose($out);
    exit;
}

$estado_badge = [
    'pendiente'       => ['bg-warning text-dark', 'Pendiente'],
    'armando'         => ['bg-warning text-dark', 'Armando'],
    'en_camino'       => ['bg-info text-dark',    'En camino'],
    'completada'      => ['bg-success',           'Completada'],
    'entregado'       => ['bg-success',            'Entregada'],
    'con_incidencias' => ['bg-danger',             'Con incidencias'],
];

$nav_modulo = 'entregas';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entregas — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #eef1f6; }
        #tabla-entregas thead th {
            background: #2c3e50; color: #fff;
            font-size: .78rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .05em; border: none;
        }
        #tabla-entregas tbody tr.entrega-row { border-bottom: 1px solid #dde3ec; cursor: pointer; }
        #tabla-entregas tbody tr.entrega-row:hover { background: #dde8f7; }
        #tabla-entregas td { color: #212529; }
        .row-remitos { background: #f0f4fb; }
        .row-remitos td { padding: .5rem 1rem .5rem 3rem; border-top: none; }
        .row-remitos table { font-size: .85rem; }
        .row-remitos thead th { background: #d5dff0; color: #374151; font-size: .75rem; text-transform: uppercase; }
        .card { border: none !important; box-shadow: 0 2px 8px rgba(0,0,0,.10) !important; }
        .btn-expand { width: 28px; height: 28px; padding: 0; }
        .btn-expand .bi { transition: transform .2s; }
        .btn-expand.open .bi { transform: rotate(90deg); }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .card { box-shadow: none !important; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex align-items-center gap-2">
            <a href="<?= url('modules/agenda.php') ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Volver
            </a>
            <h5 class="fw-bold mb-0"><i class="bi bi-truck me-2 text-success"></i>Entregas</h5>
        </div>
        <div class="d-flex gap-2 no-print">
            <?php if ($prov_fil): ?>
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer me-1"></i>Imprimir
            </button>
            <?php endif; ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['export'=>1])) ?>"
               class="btn btn-sm btn-outline-success">
                <i class="bi bi-file-earmark-excel me-1"></i>Excel
            </a>
            <a href="<?= url('modules/entregas_form.php') ?>" class="btn btn-success">
                <i class="bi bi-plus-lg me-1"></i>Nueva entrega
            </a>
        </div>
    </div>

    <?php if (isset($_GET['ok'])): ?>
    <div class="alert alert-success alert-dismissible py-2 mb-3 no-print">
        <i class="bi bi-check-circle me-2"></i>Entrega confirmada correctamente.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Filtros -->
    <form method="GET" class="row g-2 mb-3 align-items-end no-print">
        <div class="col-sm-3 col-lg-2">
            <label class="form-label form-label-sm mb-1">Desde</label>
            <input type="date" name="desde" class="form-control form-control-sm" value="<?= h($desde) ?>">
        </div>
        <div class="col-sm-3 col-lg-2">
            <label class="form-label form-label-sm mb-1">Hasta</label>
            <input type="date" name="hasta" class="form-control form-control-sm" value="<?= h($hasta) ?>">
        </div>
        <div class="col-sm-4 col-lg-3">
            <label class="form-label form-label-sm mb-1">Proveedor <span class="text-muted">(reporte)</span></label>
            <select name="proveedor_id" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($provs as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $prov_fil == $p['id'] ? 'selected' : '' ?>>
                    <?= h($p['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-search me-1"></i>Filtrar
            </button>
            <?php if ($desde || $hasta || $prov_fil): ?>
            <a href="<?= url('modules/entregas_lista.php') ?>" class="btn btn-sm btn-link text-muted">Limpiar</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($prov_fil && !empty($provs)): ?>
    <?php $prov_nombre = ''; foreach($provs as $p) if ($p['id'] == $prov_fil) $prov_nombre = $p['nombre']; ?>
    <div class="alert alert-info py-2 mb-3">
        <i class="bi bi-filter me-1"></i>
        Reporte de entregas — proveedor: <strong><?= h($prov_nombre) ?></strong>
        <?php if ($desde || $hasta): ?>
        — período: <?= $desde ? h($desde) : '…' ?> al <?= $hasta ? h($hasta) : 'hoy' ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($entregas)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-truck fs-2 d-block mb-2"></i>
                No hay entregas para los filtros aplicados.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tabla-entregas">
                    <thead>
                        <tr>
                            <th style="width:28px" class="no-print"></th>
                            <th>Fecha</th>
                            <th>Estado</th>
                            <th>Transportista</th>
                            <th>Patente</th>
                            <th>Chofer</th>
                            <th class="text-center">Remitos</th>
                            <th class="text-center">Pallets</th>
                            <th class="no-print"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entregas as $e):
                        $items = $items_map[$e['id']] ?? [];
                        [$y,$m,$d] = explode('-', $e['fecha']);
                    ?>
                    <?php
                        [$eb_cls, $eb_lbl] = $estado_badge[$e['estado']] ?? ['bg-secondary', $e['estado'] ?: '—'];
                    ?>
                    <tr class="entrega-row" onclick="toggleItems(this, <?= $e['id'] ?>)">
                        <td class="ps-2 no-print">
                            <button type="button" class="btn btn-expand btn-sm btn-outline-secondary rounded-circle">
                                <i class="bi bi-chevron-right"></i>
                            </button>
                        </td>
                        <td class="fw-semibold"><?= "$d/$m/$y" ?></td>
                        <td><span class="badge <?= $eb_cls ?>"><?= $eb_lbl ?></span></td>
                        <td><?= h($e['transportista'] ?? '—') ?></td>
                        <td class="font-monospace"><?= h($e['patente'] ?? '—') ?></td>
                        <td class="text-muted small"><?= h($e['chofer'] ?? '—') ?></td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $e['nro_remitos'] ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($e['total_pallets'] > 0): ?>
                            <span class="badge bg-success"><?= number_format($e['total_pallets'], 1) ?></span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3 no-print" onclick="event.stopPropagation()">
                            <a href="<?= url('modules/entrega_dia_form.php') ?>?id=<?= $e['id'] ?>&back=lista"
                               class="btn btn-sm btn-outline-primary me-1" title="Editar">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="<?= url('modules/entregas_eliminar.php') ?>"
                                  class="d-inline"
                                  onsubmit="return confirm('¿Eliminar esta entrega?')">
                                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <tr id="items-<?= $e['id'] ?>" class="row-remitos d-none">
                        <td colspan="9">
                            <?php if ($items): ?>
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Nro remito</th>
                                        <th>Cliente</th>
                                        <th>Proveedor</th>
                                        <th class="text-end">Pallets</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($items as $it): ?>
                                <tr>
                                    <td class="fw-semibold font-monospace"><?= h($it['nro_remito_propio']) ?></td>
                                    <td><?= h($it['cliente']) ?></td>
                                    <td class="text-muted"><?= h($it['proveedor'] ?? '—') ?></td>
                                    <td class="text-end"><?= $it['total_pallets'] > 0 ? number_format($it['total_pallets'], 1) : '—' ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <?php $total_pal = array_sum(array_column($items, 'total_pallets')); ?>
                                <tfoot class="fw-bold">
                                    <tr>
                                        <td colspan="3" class="text-end">Total</td>
                                        <td class="text-end"><?= number_format($total_pal, 1) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                            <?php else: ?>
                            <span class="text-muted small">Sin remitos para este proveedor en esta entrega.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleItems(row, id) {
    const detail = document.getElementById('items-' + id);
    const btn    = row.querySelector('.btn-expand');
    if (!detail) return;
    const open = !detail.classList.contains('d-none');
    detail.classList.toggle('d-none', open);
    if (btn) btn.classList.toggle('open', !open);
}
</script>
</body>
</html>
