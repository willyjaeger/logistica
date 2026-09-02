<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

$q = trim($_GET['q'] ?? '');

$where  = ["r.empresa_id = ?", "r.estado = 'pendiente'"];
$params = [$eid];
if ($q !== '') {
    $where[]  = "(r.nro_remito_propio LIKE ? OR c.nombre LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%";
}

$stmt = $db->prepare("
    SELECT r.id, r.nro_remito_propio, r.total_pallets, DATE(i.fecha_ingreso) AS fecha_ingreso,
           c.nombre AS cliente
    FROM remitos r
    JOIN clientes c ON c.id = r.cliente_id
    JOIN ingresos i ON i.id = r.ingreso_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.fecha_ingreso ASC, c.nombre, r.nro_remito_propio
");
$stmt->execute($params);
$remitos = $stmt->fetchAll();

$hoy = new DateTime();
foreach ($remitos as &$r) {
    $r['dias'] = $hoy->diff(new DateTime($r['fecha_ingreso']))->days;
}
unset($r);

$nav_modulo = 'remitos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pasar a stock — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        body { background: #eef1f6; padding-bottom: 90px; }
        .rem-item { border: 2px solid #e2e8f0; border-radius: .5rem; padding: .6rem .9rem;
                    display: flex; align-items: center; gap: .75rem; cursor: pointer; }
        .rem-item:hover { border-color: #6c757d; background: #f8f9fa; }
        .rem-item.chk { border-color: #0d6efd; background: #eef5ff; }
        .rem-pallets { color: #7c3aed; font-weight: 700; font-size: .82rem; margin-left: auto; }
        .barra-fija { position: fixed; bottom: 0; left: 0; right: 0; background: #1e293b;
                      border-top: 3px solid #0d6efd; padding: .7rem 1.5rem; z-index: 1030; }
        .barra-fija .stat-label { font-size: .72rem; color: #94a3b8; text-transform: uppercase; }
        .barra-fija .stat-val   { font-size: 1.3rem; font-weight: 700; color: #fff; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4">

    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0"><i class="bi bi-archive me-2 text-primary"></i>Pasar remitos a stock</h5>
        <a href="<?= url('modules/remitos_lista.php') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Volver a remitos
        </a>
    </div>

    <p class="text-muted small">
        Seleccioná los remitos pendientes que no van a salir en un viaje y pasalos a stock.
        Quedan marcados como <span class="badge bg-secondary">En stock</span> y dejan de figurar como pendientes.
    </p>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-sm-5 col-md-3">
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="Buscar nro remito / cliente..." value="<?= h($q) ?>">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-search me-1"></i>Filtrar
            </button>
        </div>
        <?php if ($q): ?>
        <div class="col-auto">
            <a href="<?= url('modules/remitos_a_stock.php') ?>" class="btn btn-sm btn-link text-muted">Limpiar</a>
        </div>
        <?php endif; ?>
    </form>

    <?php if (empty($remitos)): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
        <?= $q ? 'Sin resultados para ese filtro.' : 'No hay remitos pendientes.' ?>
    </div>
    <?php else: ?>

    <form method="POST" action="<?= url('modules/remitos_a_stock_guardar.php') ?>" id="form-stock">
        <input type="hidden" name="q" value="<?= h($q) ?>">

        <div class="d-flex align-items-center gap-2 mb-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="chk-todos" onclick="toggleTodos(this)">
                <label class="form-check-label small text-muted" for="chk-todos">Seleccionar todos (<?= count($remitos) ?>)</label>
            </div>
        </div>

        <div class="d-flex flex-column gap-2">
            <?php foreach ($remitos as $r):
                $dias = $r['dias'];
                $dias_cls = $dias >= 30 ? 'bg-danger' : ($dias >= 15 ? 'bg-warning text-dark' : 'bg-secondary');
                [$y,$m,$d] = explode('-', $r['fecha_ingreso']);
            ?>
            <label class="rem-item" data-item>
                <input type="checkbox" name="remito_ids[]" value="<?= $r['id'] ?>"
                       class="form-check-input flex-shrink-0" data-pallets="<?= $r['total_pallets'] ?>" onchange="actualizar()">
                <div>
                    <div class="fw-semibold"><?= h($r['cliente']) ?></div>
                    <div class="small text-muted font-monospace"><?= h($r['nro_remito_propio']) ?>
                        · ingresó <?= "$d/$m/$y" ?>
                        <span class="badge <?= $dias_cls ?> ms-1" style="font-size:.65rem"><?= $dias ?> día<?= $dias !== 1 ? 's' : '' ?></span>
                    </div>
                </div>
                <span class="rem-pallets"><?= number_format($r['total_pallets'], 1) ?> pal</span>
            </label>
            <?php endforeach; ?>
        </div>

        <div class="barra-fija">
            <div class="container-fluid d-flex align-items-center gap-4 flex-wrap px-0">
                <div>
                    <div class="stat-label">Seleccionados</div>
                    <div class="stat-val" id="cant-sel">0</div>
                </div>
                <div>
                    <div class="stat-label">Pallets</div>
                    <div class="stat-val" id="pal-sel">0.0</div>
                </div>
                <button type="submit" class="btn btn-primary ms-auto" id="btn-pasar" disabled>
                    <i class="bi bi-archive me-1"></i>Pasar a stock
                </button>
            </div>
        </div>
    </form>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function actualizar() {
    const boxes = document.querySelectorAll('input[name="remito_ids[]"]');
    let cant = 0, pal = 0;
    boxes.forEach(b => {
        b.closest('[data-item]').classList.toggle('chk', b.checked);
        if (b.checked) { cant++; pal += parseFloat(b.dataset.pallets) || 0; }
    });
    document.getElementById('cant-sel').textContent = cant;
    document.getElementById('pal-sel').textContent = pal.toFixed(1);
    const btn = document.getElementById('btn-pasar');
    btn.disabled = cant === 0;
    btn.innerHTML = cant > 0
        ? '<i class="bi bi-archive me-1"></i>Pasar ' + cant + ' remito' + (cant !== 1 ? 's' : '') + ' a stock'
        : '<i class="bi bi-archive me-1"></i>Pasar a stock';
}
function toggleTodos(master) {
    document.querySelectorAll('input[name="remito_ids[]"]').forEach(b => b.checked = master.checked);
    actualizar();
}
document.getElementById('form-stock')?.addEventListener('submit', function(e) {
    const cant = document.querySelectorAll('input[name="remito_ids[]"]:checked').length;
    if (cant === 0) { e.preventDefault(); return; }
    if (!confirm('¿Pasar ' + cant + ' remito' + (cant !== 1 ? 's' : '') + ' a stock? Dejan de figurar como pendientes.')) {
        e.preventDefault();
    }
});
</script>
</body>
</html>
