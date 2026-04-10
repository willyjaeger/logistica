<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

// ── Filtros ───────────────────────────────────────────────────
$q        = trim($_GET['q']        ?? '');
$estado   = $_GET['estado']        ?? '';
$desde    = $_GET['desde']         ?? '';
$hasta    = $_GET['hasta']         ?? '';

// ── Construir query ───────────────────────────────────────────
$where  = ['r.empresa_id = ?'];
$params = [$eid];

if ($q !== '') {
    $where[]  = '(r.nro_remito_propio LIKE ? OR c.nombre LIKE ? OR r.nro_oc LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
if ($estado !== '') {
    $where[]  = 'r.estado = ?';
    $params[] = $estado;
}
if ($desde !== '') {
    $where[]  = 'DATE(i.fecha_ingreso) >= ?';
    $params[] = $desde;
}
if ($hasta !== '') {
    $where[]  = 'DATE(i.fecha_ingreso) <= ?';
    $params[] = $hasta;
}

$sql = "
    SELECT r.id, r.nro_remito_propio, r.fecha_remito, r.estado,
           r.total_pallets, r.nro_oc, r.observaciones, r.fecha_entrega,
           c.nombre     AS cliente,
           p.nombre     AS proveedor,
           i.fecha_ingreso, i.transportista, i.patente_camion_ext
    FROM remitos r
    JOIN clientes c ON r.cliente_id = c.id
    LEFT JOIN proveedores p ON r.proveedor_id = p.id
    JOIN ingresos i ON r.ingreso_id = i.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.fecha_ingreso DESC, r.id DESC
    LIMIT 100
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$remitos = $stmt->fetchAll();

// ── Cargar ítems de los remitos visibles ──────────────────────
$items_map = [];
if ($remitos) {
    $ids = implode(',', array_column($remitos, 'id'));
    $ri  = $db->query("
        SELECT ri.remito_id, ri.descripcion, ri.cantidad, ri.pallets, ri.estado,
               a.codigo, a.presentacion
        FROM remito_items ri
        LEFT JOIN articulos a ON ri.articulo_id = a.id
        WHERE ri.remito_id IN ($ids)
        ORDER BY ri.id
    ");
    foreach ($ri->fetchAll() as $row) {
        $items_map[$row['remito_id']][] = $row;
    }
}

// ── Etiquetas estado ──────────────────────────────────────────
$estado_label = [
    'pendiente'             => ['bg-warning text-dark', 'Pendiente'],
    'parcialmente_entregado'=> ['bg-info text-dark',    'Parcial'],
    'entregado'             => ['bg-success',            'Entregado'],
    'en_stock'              => ['bg-secondary',          'En stock'],
];

$nav_modulo = 'remitos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Remitos — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #eef1f6; }

        /* Tabla principal */
        #tabla-remitos thead th {
            background: #2c3e50;
            color: #fff;
            font-size: .78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
            border: none;
        }
        #tabla-remitos tbody tr.remito-row {
            border-bottom: 1px solid #dde3ec;
        }
        #tabla-remitos tbody tr.remito-row:hover {
            background: #dde8f7;
        }
        #tabla-remitos td {
            color: #212529;
        }
        .font-monospace { color: #1a1a2e; font-weight: 700; }

        /* Fila expandida de ítems */
        .row-items { background: #f0f4fb; }
        .row-items td { padding: .6rem 1rem .6rem 3.5rem; border-top: none; }
        .row-items table { font-size: .85rem; }
        .row-items thead th { background: #d5dff0; color: #374151; font-size: .75rem; text-transform: uppercase; }

        /* Botón expandir */
        .btn-expand { width: 28px; height: 28px; padding: 0; }
        .btn-expand .bi { transition: transform .2s; }
        .btn-expand.open .bi { transform: rotate(90deg); }

        /* Tarjeta sin sombra suave */
        .card { border: none !important; box-shadow: 0 2px 8px rgba(0,0,0,.10) !important; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4">

    <!-- Encabezado -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Remitos</h5>
        <a href="<?= url('modules/remitos/form.php') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Nuevo remito
        </a>
    </div>

    <?php if (isset($_GET['ok'])): ?>
    <div class="alert alert-success alert-dismissible py-2 mb-3">
        <i class="bi bi-check-circle me-2"></i>Remito guardado correctamente.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['borrado'])): ?>
    <div class="alert alert-info alert-dismissible py-2 mb-3">
        <i class="bi bi-trash me-2"></i>Remito eliminado.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Filtros -->
    <form method="GET" class="row g-2 mb-3 align-items-end">
        <div class="col-sm-4 col-lg-3">
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="Buscar nro remito / cliente / OC..."
                   value="<?= h($q) ?>">
        </div>
        <div class="col-sm-3 col-lg-2">
            <select name="estado" class="form-select form-select-sm">
                <option value="">Todos los estados</option>
                <?php foreach ($estado_label as $val => [$cls, $lbl]): ?>
                <option value="<?= $val ?>" <?= $estado === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-2 col-lg-2">
            <input type="date" name="desde" class="form-control form-control-sm"
                   value="<?= h($desde) ?>" placeholder="Desde">
        </div>
        <div class="col-sm-2 col-lg-2">
            <input type="date" name="hasta" class="form-control form-control-sm"
                   value="<?= h($hasta) ?>" placeholder="Hasta">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-search me-1"></i>Filtrar
            </button>
            <?php if ($q || $estado || $desde || $hasta): ?>
            <a href="<?= url('modules/remitos/lista.php') ?>" class="btn btn-sm btn-link text-muted">Limpiar</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Tabla -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <?php if (empty($remitos)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                <?= $q || $estado ? 'Sin resultados para ese filtro.' : 'No hay remitos ingresados todavía.' ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tabla-remitos">
                    <thead class="table-light small text-muted">
                        <tr>
                            <th style="width:28px"></th>
                            <th>Fecha ingreso</th>
                            <th>Nro remito</th>
                            <th>Cliente</th>
                            <th>Proveedor</th>
                            <th class="text-center">Pallets</th>
                            <th>Estado</th>
                            <th>Fecha entrega</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($remitos as $r):
                        $items   = $items_map[$r['id']] ?? [];
                        [$cls, $lbl] = $estado_label[$r['estado']] ?? ['bg-secondary', $r['estado']];
                        $fecha_i = substr($r['fecha_ingreso'], 0, 10);
                        [$y,$m,$d] = explode('-', $fecha_i);
                        $fecha_fmt = "$d/$m/$y";
                    ?>
                    <tr class="remito-row">
                        <td class="ps-2">
                            <?php if ($items): ?>
                            <button type="button"
                                    class="btn btn-expand btn-sm btn-outline-secondary rounded-circle"
                                    onclick="toggleItems(this, <?= $r['id'] ?>)">
                                <i class="bi bi-chevron-right"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= $fecha_fmt ?></td>
                        <td class="fw-semibold font-monospace"><?= h($r['nro_remito_propio']) ?></td>
                        <td><?= h($r['cliente']) ?></td>
                        <td class="small text-muted"><?= h($r['proveedor'] ?? '—') ?></td>
                        <td class="text-center">
                            <?php if ($r['total_pallets'] > 0): ?>
                            <span class="badge bg-primary rounded-pill"><?= number_format($r['total_pallets'], 1) ?></span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= $cls ?>"><?= $lbl ?></span></td>
                        <td class="small text-muted">
                            <?php if ($r['fecha_entrega']): ?>
                                <?php [$ye,$me,$de] = explode('-', $r['fecha_entrega']); echo "$de/$me/$ye"; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
                            <a href="<?= url("modules/remitos/form.php?id={$r['id']}") ?>"
                               class="btn btn-sm btn-outline-primary" title="Editar">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="<?= url('modules/remitos/eliminar.php') ?>"
                                  class="d-inline"
                                  onsubmit="return confirm('¿Eliminar el remito <?= h($r['nro_remito_propio']) ?>?')">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php if ($items): ?>
                    <tr id="items-<?= $r['id'] ?>" class="row-items d-none">
                        <td colspan="9">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr class="text-muted">
                                        <th>Código</th>
                                        <th>Descripción</th>
                                        <th>Presentación</th>
                                        <th class="text-end">Cantidad</th>
                                        <th class="text-end">Pallets</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($items as $it):
                                    [$ic, $il] = $estado_label[$it['estado']] ?? ['bg-secondary', $it['estado']];
                                ?>
                                <tr>
                                    <td class="font-monospace text-muted"><?= h($it['codigo'] ?? '') ?></td>
                                    <td><?= h($it['descripcion']) ?></td>
                                    <td class="text-muted"><?= h($it['presentacion'] ?? '') ?></td>
                                    <td class="text-end"><?= number_format($it['cantidad'], 0) ?></td>
                                    <td class="text-end"><?= $it['pallets'] > 0 ? number_format($it['pallets'], 2) : '—' ?></td>
                                    <td><span class="badge <?= $ic ?>"><?= $il ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-muted small px-3 py-2">
                <?= count($remitos) ?> remito<?= count($remitos) !== 1 ? 's' : '' ?> encontrado<?= count($remitos) !== 1 ? 's' : '' ?>
                <?= count($remitos) >= 100 ? '(mostrando hasta 100)' : '' ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleItems(btn, id) {
    const row = document.getElementById('items-' + id);
    const open = !row.classList.contains('d-none');
    row.classList.toggle('d-none', open);
    btn.classList.toggle('open', !open);
}
</script>
</body>
</html>
