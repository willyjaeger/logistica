<?php
require_once __DIR__ . '/config/auth.php';
require_login();

$eid = empresa_id();
$db  = db();

// Remitos ingresados hoy
$stmt = $db->prepare("
    SELECT COUNT(*) FROM remitos r
    JOIN ingresos i ON r.ingreso_id = i.id
    WHERE r.empresa_id = ? AND DATE(i.fecha_ingreso) = CURDATE()
");
$stmt->execute([$eid]);
$remitos_hoy = (int) $stmt->fetchColumn();

// Remitos pendientes
$stmt = $db->prepare("SELECT COUNT(*) FROM remitos WHERE empresa_id = ? AND estado IN ('pendiente','parcialmente_entregado')");
$stmt->execute([$eid]);
$remitos_pendientes = (int) $stmt->fetchColumn();

// Entregas en camino
$stmt = $db->prepare("SELECT COUNT(*) FROM entregas WHERE empresa_id = ? AND estado = 'en_camino'");
$stmt->execute([$eid]);
$entregas_en_camino = (int) $stmt->fetchColumn();

// Ítems en stock
$stmt = $db->prepare("SELECT COUNT(*) FROM stock WHERE empresa_id = ? AND estado = 'disponible'");
$stmt->execute([$eid]);
$items_stock = (int) $stmt->fetchColumn();

// Entregas completadas este mes
$stmt = $db->prepare("
    SELECT COUNT(*) FROM entregas
    WHERE empresa_id = ? AND estado = 'completada'
      AND YEAR(fecha_salida) = YEAR(CURDATE())
      AND MONTH(fecha_salida) = MONTH(CURDATE())
");
$stmt->execute([$eid]);
$entregas_mes = (int) $stmt->fetchColumn();

// Últimos 8 remitos
$stmt = $db->prepare("
    SELECT r.id, r.nro_remito_propio, r.estado, r.total_pallets,
           c.nombre AS cliente,
           i.fecha_ingreso
    FROM remitos r
    JOIN clientes c ON r.cliente_id = c.id
    JOIN ingresos i ON r.ingreso_id = i.id
    WHERE r.empresa_id = ?
    ORDER BY i.fecha_ingreso DESC, r.id DESC
    LIMIT 8
");
$stmt->execute([$eid]);
$ultimos_remitos = $stmt->fetchAll();

$estado_label = [
    'pendiente'             => ['bg-warning text-dark', 'Pendiente'],
    'parcialmente_entregado'=> ['bg-info text-dark',    'Parcial'],
    'entregado'             => ['bg-success',            'Entregado'],
    'en_stock'              => ['bg-secondary',          'En stock'],
];

$nav_modulo = 'panel';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
</head>
<body>

<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<div class="container-fluid py-4 px-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h5 class="fw-bold mb-0">Buen día, <?= h(usuario_nombre()) ?></h5>
            <small class="text-muted"><?= strftime('%A %e de %B de %Y') ?: date('d/m/Y') ?></small>
        </div>
        <a href="<?= url('modules/remitos_form.php') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-2"></i>Nuevo remito
        </a>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100 stat-card stat-azul">
                <div class="card-body">
                    <div class="stat-icon"><i class="bi bi-file-earmark-plus"></i></div>
                    <div class="stat-numero"><?= $remitos_hoy ?></div>
                    <div class="stat-label">Remitos hoy</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-xl-2">
            <a href="<?= url('modules/remitos_lista.php') ?>?estado=pendiente" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 stat-card stat-naranja">
                <div class="card-body">
                    <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                    <div class="stat-numero"><?= $remitos_pendientes ?></div>
                    <div class="stat-label">Pendientes</div>
                </div>
            </div>
            </a>
        </div>

        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100 stat-card stat-verde">
                <div class="card-body">
                    <div class="stat-icon"><i class="bi bi-truck"></i></div>
                    <div class="stat-numero"><?= $entregas_en_camino ?></div>
                    <div class="stat-label">En camino</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100 stat-card stat-rojo">
                <div class="card-body">
                    <div class="stat-icon"><i class="bi bi-archive"></i></div>
                    <div class="stat-numero"><?= $items_stock ?></div>
                    <div class="stat-label">Ítems en stock</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100 stat-card stat-violeta">
                <div class="card-body">
                    <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
                    <div class="stat-numero"><?= $entregas_mes ?></div>
                    <div class="stat-label">Entregas este mes</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-xl-2">
            <a href="<?= url('modules/reportes/camiones.php') ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 stat-card stat-gris">
                <div class="card-body">
                    <div class="stat-icon"><i class="bi bi-bar-chart-line"></i></div>
                    <div class="stat-numero"><i class="bi bi-arrow-right-circle fs-4"></i></div>
                    <div class="stat-label">Ver reportes</div>
                </div>
            </div>
            </a>
        </div>

    </div>

    <!-- Acciones + últimos remitos -->
    <div class="row g-3">

        <div class="col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold border-0 pb-0">
                    <i class="bi bi-lightning-charge-fill text-warning me-2"></i>Acciones rápidas
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="<?= url('modules/remitos_form.php') ?>" class="btn btn-outline-primary text-start">
                        <i class="bi bi-plus-circle me-2"></i>Nuevo remito
                    </a>
                    <a href="<?= url('modules/remitos_lista.php') ?>?estado=pendiente" class="btn btn-outline-warning text-start">
                        <i class="bi bi-hourglass-split me-2"></i>Remitos pendientes
                    </a>
                    <a href="<?= url('modules/entregas/nueva.php') ?>" class="btn btn-outline-success text-start">
                        <i class="bi bi-truck me-2"></i>Armar entrega
                    </a>
                    <a href="<?= url('modules/stock/lista.php') ?>" class="btn btn-outline-danger text-start">
                        <i class="bi bi-archive me-2"></i>Consultar stock
                    </a>
                    <a href="<?= url('modules/reportes/camiones.php') ?>" class="btn btn-outline-secondary text-start">
                        <i class="bi bi-bar-chart me-2"></i>Reportes
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-9">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold border-0 pb-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history text-primary me-2"></i>Últimos remitos</span>
                    <a href="<?= url('modules/remitos_lista.php') ?>" class="btn btn-sm btn-outline-primary">Ver todos</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($ultimos_remitos)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        No hay remitos ingresados todavía.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light small text-muted">
                                <tr>
                                    <th>Fecha ingreso</th>
                                    <th>Nro remito</th>
                                    <th>Cliente</th>
                                    <th class="text-center">Pallets</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($ultimos_remitos as $r):
                                [$cls, $lbl] = $estado_label[$r['estado']] ?? ['bg-secondary', $r['estado']];
                                $fi = substr($r['fecha_ingreso'], 0, 10);
                                [$y,$m,$d] = explode('-', $fi);
                            ?>
                            <tr>
                                <td class="small text-muted"><?= "$d/$m/$y" ?></td>
                                <td class="fw-semibold font-monospace"><?= h($r['nro_remito_propio']) ?></td>
                                <td><?= h($r['cliente']) ?></td>
                                <td class="text-center">
                                    <?php if ($r['total_pallets'] > 0): ?>
                                    <span class="badge bg-primary rounded-pill"><?= number_format($r['total_pallets'], 1) ?></span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td><span class="badge <?= $cls ?>"><?= $lbl ?></span></td>
                                <td>
                                    <a href="<?= url("modules/remitos_form.php?id={$r['id']}") ?>"
                                       class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
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

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
