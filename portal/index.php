<?php
// Portal de consulta para usuarios de proveedor (ej. Sanesa):
// ven sus remitos, el estado de entrega y el comprobante firmado.
require_once __DIR__ . '/../config/auth.php';
require_login();
if (usuario_rol() !== 'proveedor') { header('Location: ' . url('index.php')); exit; }

$db   = db();
$eid  = empresa_id();
$prov = proveedor_id();

$q        = trim($_GET['q'] ?? '');
$desde    = $_GET['desde'] ?? '';
$hasta    = $_GET['hasta'] ?? '';
$con_comp = !empty($_GET['con_comp']);
$pag      = max(1, (int)($_GET['pag'] ?? 1));
$por_pag  = 50;

if ($desde !== '' && !DateTime::createFromFormat('Y-m-d', $desde)) $desde = '';
if ($hasta !== '' && !DateTime::createFromFormat('Y-m-d', $hasta)) $hasta = '';

$where  = ['r.empresa_id = ?', 'r.proveedor_id = ?'];
$params = [$eid, $prov];
if ($q !== '') {
    $where[]  = '(r.nro_remito_propio LIKE ? OR r.nro_remito_proveedor LIKE ? OR r.nro_oc LIKE ? OR c.nombre LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($desde !== '') { $where[] = 'DATE(i.fecha_ingreso) >= ?'; $params[] = $desde; }
if ($hasta !== '') { $where[] = 'DATE(i.fecha_ingreso) <= ?'; $params[] = $hasta; }
if ($con_comp)     { $where[] = 'EXISTS (SELECT 1 FROM remito_comprobantes rc WHERE rc.remito_id = r.id)'; }
$where_sql = implode(' AND ', $where);

$from = "
    FROM remitos r
    JOIN clientes c ON c.id = r.cliente_id
    JOIN ingresos i ON i.id = r.ingreso_id
";

$st = $db->prepare("SELECT COUNT(*) $from WHERE $where_sql");
$st->execute($params);
$total   = (int)$st->fetchColumn();
$paginas = max(1, (int)ceil($total / $por_pag));
$pag     = min($pag, $paginas);
$offset  = ($pag - 1) * $por_pag;

$st = $db->prepare("
    SELECT r.id, r.nro_remito_propio, r.nro_remito_proveedor, r.nro_oc, r.estado, r.fecha_entrega,
           r.total_pallets, DATE(i.fecha_ingreso) AS fecha_ingreso, c.nombre AS cliente
    $from
    WHERE $where_sql
    ORDER BY i.fecha_ingreso DESC, r.id DESC
    LIMIT $por_pag OFFSET $offset
");
$st->execute($params);
$remitos = $st->fetchAll();

$comps = [];
if ($remitos) {
    $ids = implode(',', array_map('intval', array_column($remitos, 'id')));
    $rc  = $db->prepare("SELECT id, remito_id, mime FROM remito_comprobantes WHERE empresa_id = ? AND remito_id IN ($ids) ORDER BY id");
    $rc->execute([$eid]);
    foreach ($rc->fetchAll() as $c) $comps[$c['remito_id']][] = $c;
}

$estado_label = [
    'pendiente'              => ['badge-estado-pendiente',              'En depósito'],
    'turnado'                => ['badge-estado-turnado',                'Turnado'],
    'programado'             => ['badge-estado-programado',             'Programado'],
    'en_camino'              => ['badge-estado-en_camino',              'En camino'],
    'entregado'              => ['badge-estado-entregado',              'Entregado'],
    'parcialmente_entregado' => ['badge-estado-parcialmente_entregado', 'Entrega parcial'],
    'en_stock'               => ['badge-estado-en_stock',               'En stock'],
    'cancelado'              => ['badge-estado-cancelado',              'Cancelado'],
];

function fmt_fecha(?string $f): string
{
    if (!$f) return '—';
    [$y, $m, $d] = explode('-', substr($f, 0, 10));
    return "$d/$m/$y";
}

$filtros = array_filter(['q' => $q, 'desde' => $desde, 'hasta' => $hasta, 'con_comp' => $con_comp ? 1 : '']);
$nav_modulo = '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobantes de entrega — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        body { background: #eef1f6; }
        #visor-cuerpo img { max-width: 100%; height: auto; }
        #visor-cuerpo iframe { width: 100%; height: 80vh; border: 0; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4">

    <h5 class="fw-bold mb-3"><i class="bi bi-file-earmark-check me-2 text-primary"></i>Remitos y comprobantes de entrega</h5>

    <form method="GET" class="row g-2 mb-3 align-items-end">
        <div class="col-sm-4 col-lg-3">
            <label class="form-label small text-muted mb-0">Buscar</label>
            <input type="text" name="q" class="form-control form-control-sm" value="<?= h($q) ?>"
                   placeholder="Nro remito / OC / cliente">
        </div>
        <div class="col-sm-2">
            <label class="form-label small text-muted mb-0">Ingreso desde</label>
            <input type="date" name="desde" class="form-control form-control-sm" value="<?= h($desde) ?>">
        </div>
        <div class="col-sm-2">
            <label class="form-label small text-muted mb-0">Ingreso hasta</label>
            <input type="date" name="hasta" class="form-control form-control-sm" value="<?= h($hasta) ?>">
        </div>
        <div class="col-auto">
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" name="con_comp" value="1" id="chk-comp" <?= $con_comp ? 'checked' : '' ?>>
                <label class="form-check-label small" for="chk-comp">Solo con comprobante</label>
            </div>
        </div>
        <div class="col-auto d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Buscar</button>
            <?php if ($filtros): ?>
            <a href="<?= url('portal/index.php') ?>" class="btn btn-sm btn-link text-muted">Limpiar</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <?php if (!$remitos): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-2 d-block mb-2"></i>No hay remitos para mostrar.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-muted">
                        <tr>
                            <th>Ingreso</th>
                            <th>Nro remito</th>
                            <th>Remito proveedor</th>
                            <th>OC</th>
                            <th>Cliente</th>
                            <th class="text-center">Pallets</th>
                            <th>Estado</th>
                            <th>Fecha entrega</th>
                            <th>Comprobante</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($remitos as $r):
                        [$cls, $lbl] = $estado_label[$r['estado']] ?? ['bg-secondary', $r['estado']];
                        $cs = $comps[$r['id']] ?? [];
                    ?>
                    <tr>
                        <td class="small"><?= fmt_fecha($r['fecha_ingreso']) ?></td>
                        <td class="font-monospace fw-semibold"><?= h($r['nro_remito_propio']) ?></td>
                        <td class="small"><?= h($r['nro_remito_proveedor'] ?? '') ?: '—' ?></td>
                        <td class="small"><?= h($r['nro_oc'] ?? '') ?: '—' ?></td>
                        <td><?= h($r['cliente']) ?></td>
                        <td class="text-center small"><?= $r['total_pallets'] > 0 ? number_format((float)$r['total_pallets'], 1) : '—' ?></td>
                        <td><span class="badge <?= $cls ?>"><?= $lbl ?></span></td>
                        <td class="small"><?= in_array($r['estado'], ['entregado', 'parcialmente_entregado']) ? fmt_fecha($r['fecha_entrega']) : '—' ?></td>
                        <td>
                            <?php if ($cs): ?>
                                <?php foreach ($cs as $n => $c): ?>
                                <button type="button" class="btn btn-sm btn-success mb-1"
                                        data-ver="<?= h(url('portal/ver.php?id=' . $c['id'])) ?>"
                                        data-pdf="<?= $c['mime'] === 'application/pdf' ? 1 : 0 ?>"
                                        data-titulo="<?= h($r['nro_remito_propio'] . (count($cs) > 1 ? ' — hoja ' . ($n + 1) : '')) ?>">
                                    <i class="bi bi-file-earmark-check me-1"></i><?= count($cs) > 1 ? 'Hoja ' . ($n + 1) : 'Ver' ?>
                                </button>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
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

    <div class="d-flex justify-content-between align-items-center mt-2 small text-muted">
        <span><?= $total ?> remito<?= $total === 1 ? '' : 's' ?></span>
        <?php if ($paginas > 1): ?>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = max(1, $pag - 3); $p <= min($paginas, $pag + 3); $p++): ?>
                <li class="page-item <?= $p === $pag ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= h(http_build_query($filtros + ['pag' => $p])) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Visor de comprobante -->
<div class="modal fade" id="visor" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title font-monospace" id="visor-titulo"></h6>
                <a id="visor-descargar" class="btn btn-sm btn-outline-primary ms-auto me-2" href="#">
                    <i class="bi bi-download me-1"></i>Descargar
                </a>
                <button type="button" class="btn-close ms-0" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center" id="visor-cuerpo"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const visor = new bootstrap.Modal(document.getElementById('visor'));
document.addEventListener('click', e => {
    const b = e.target.closest('[data-ver]');
    if (!b) return;
    const cuerpo = document.getElementById('visor-cuerpo');
    const el = document.createElement(b.dataset.pdf === '1' ? 'iframe' : 'img');
    el.src = b.dataset.ver;
    cuerpo.replaceChildren(el);
    document.getElementById('visor-titulo').textContent = b.dataset.titulo;
    document.getElementById('visor-descargar').href = b.dataset.ver + '&dl=1';
    visor.show();
});
</script>
</body>
</html>
