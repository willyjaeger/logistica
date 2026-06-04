<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!es_admin()) { header('Location: ' . url('index.php')); exit; }

$db  = db();
$eid = empresa_id();

// ── Filtros ───────────────────────────────────────────────────
$proveedor_id = (int)($_GET['proveedor_id'] ?? 0);
$desde        = $_GET['desde'] ?? '';
$hasta        = $_GET['hasta'] ?? '';

// Proveedores para el select
$pq = $db->prepare("SELECT id, nombre FROM proveedores WHERE empresa_id=? AND activo=1 ORDER BY nombre");
$pq->execute([$eid]);
$proveedores = $pq->fetchAll();

// ── Query ─────────────────────────────────────────────────────
$where  = ['r.empresa_id = ?', "r.estado = 'entregado'", 'r.fecha_entrega IS NOT NULL'];
$params = [$eid];

if ($proveedor_id > 0) {
    $where[]  = 'r.proveedor_id = ?';
    $params[] = $proveedor_id;
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
    SELECT r.id,
           r.nro_remito_propio,
           r.total_pallets,
           c.nombre                              AS cliente,
           p.nombre                              AS proveedor,
           DATE(i.fecha_ingreso)                 AS fecha_ingreso,
           r.fecha_entrega,
           DATEDIFF(r.fecha_entrega, DATE(i.fecha_ingreso)) AS dias
    FROM remitos r
    JOIN clientes   c ON c.id = r.cliente_id
    LEFT JOIN proveedores p ON p.id = r.proveedor_id
    JOIN ingresos   i ON i.id = r.ingreso_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.fecha_entrega DESC, r.id DESC
    LIMIT 500
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$remitos = $stmt->fetchAll();

// Promedio general
$dias_validos = array_filter(array_column($remitos, 'dias'), fn($d) => $d >= 0);
$promedio     = count($dias_validos) ? round(array_sum($dias_validos) / count($dias_validos), 1) : null;

$nav_modulo = 'reportes';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plazo de entrega — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        body { background: #eef1f6; }
        .card { border: none !important; box-shadow: 0 2px 8px rgba(0,0,0,.10) !important; }
        #tabla-plazos thead th {
            background: #2c3e50; color: #fff;
            font-size: .75rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: .05em;
        }
        .dias-badge {
            display: inline-block; min-width: 36px; text-align: center;
            border-radius: 999px; font-weight: 700; padding: 2px 10px; font-size: .85rem;
        }
        .dias-ok   { background: #d1fae5; color: #065f46; }
        .dias-med  { background: #fef9c3; color: #854d0e; }
        .dias-alto { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4" style="max-width:1100px">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-clock-history me-2 text-primary"></i>Plazo de entrega
        </h5>
        <?php if ($remitos): ?>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
            <i class="bi bi-printer me-1"></i>Imprimir
        </button>
        <?php endif; ?>
    </div>

    <!-- Filtros -->
    <form method="GET" class="row g-2 mb-3 align-items-end no-print">
        <div class="col-sm-4 col-lg-3">
            <label class="form-label form-label-sm fw-semibold mb-1">Proveedor</label>
            <select name="proveedor_id" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($proveedores as $p): ?>
                <option value="<?= $p['id'] ?>" <?= (int)$p['id'] === $proveedor_id ? 'selected' : '' ?>>
                    <?= h($p['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-2 col-lg-2">
            <label class="form-label form-label-sm fw-semibold mb-1">Ingreso desde</label>
            <input type="date" name="desde" class="form-control form-control-sm" value="<?= h($desde) ?>">
        </div>
        <div class="col-sm-2 col-lg-2">
            <label class="form-label form-label-sm fw-semibold mb-1">Ingreso hasta</label>
            <input type="date" name="hasta" class="form-control form-control-sm" value="<?= h($hasta) ?>">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-search me-1"></i>Ver
            </button>
            <?php if ($proveedor_id || $desde || $hasta): ?>
            <a href="<?= url('modules/reportes/plazo_entrega.php') ?>" class="btn btn-sm btn-link text-muted">Limpiar</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($remitos): ?>

    <!-- Resumen -->
    <?php if ($promedio !== null): ?>
    <div class="alert alert-info py-2 mb-3">
        <i class="bi bi-bar-chart me-2"></i>
        <strong><?= count($remitos) ?></strong> remitos entregados
        &nbsp;·&nbsp;
        Promedio: <strong><?= $promedio ?> días</strong>
        <?php if ($desde || $hasta): ?>
        &nbsp;·&nbsp; <span class="text-muted small"><?= $desde ? 'desde '.$desde : '' ?> <?= $hasta ? 'hasta '.$hasta : '' ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Tabla -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-sm" id="tabla-plazos">
                    <thead>
                        <tr>
                            <th>Ingreso</th>
                            <th>Entrega</th>
                            <th class="text-center">Días</th>
                            <th>Remito</th>
                            <th>Cliente</th>
                            <th>Proveedor</th>
                            <th class="text-center">Pallets</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($remitos as $r):
                        $dias = (int)$r['dias'];
                        $cls  = $dias <= 7 ? 'dias-ok' : ($dias <= 15 ? 'dias-med' : 'dias-alto');
                        [$yi,$mi,$di] = explode('-', $r['fecha_ingreso']);
                        [$ye,$me,$de] = explode('-', $r['fecha_entrega']);
                    ?>
                    <tr>
                        <td class="small"><?= "$di/$mi/$yi" ?></td>
                        <td class="small"><?= "$de/$me/$ye" ?></td>
                        <td class="text-center">
                            <span class="dias-badge <?= $cls ?>"><?= $dias ?></span>
                        </td>
                        <td class="fw-semibold font-monospace small"><?= h($r['nro_remito_propio']) ?></td>
                        <td><?= h($r['cliente']) ?></td>
                        <td class="small text-muted"><?= h($r['proveedor'] ?? '—') ?></td>
                        <td class="text-center">
                            <?php if ($r['total_pallets'] > 0): ?>
                            <span class="badge bg-primary rounded-pill"><?= number_format($r['total_pallets'],1) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-muted small px-3 py-2">
                <?= count($remitos) ?> remito<?= count($remitos) !== 1 ? 's' : '' ?>
                <?= count($remitos) >= 500 ? '(mostrando hasta 500)' : '' ?>
            </div>
        </div>
    </div>

    <?php elseif ($_GET): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>No hay remitos entregados para ese filtro.
    </div>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
