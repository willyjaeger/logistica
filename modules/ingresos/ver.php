<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$eid = empresa_id();
$db  = db();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . url('modules/ingresos/lista.php'));
    exit;
}

// Cargar ingreso
$stmt = $db->prepare("SELECT * FROM ingresos WHERE id = ? AND empresa_id = ?");
$stmt->execute([$id, $eid]);
$ingreso = $stmt->fetch();
if (!$ingreso) {
    header('Location: ' . url('modules/ingresos/lista.php'));
    exit;
}

// Cargar remitos con cliente y proveedor
$stmt = $db->prepare("
    SELECT r.*,
           c.nombre  AS cliente_nombre,
           c.direccion AS cliente_direccion,
           p.nombre  AS proveedor_nombre
    FROM remitos r
    JOIN clientes c  ON r.cliente_id  = c.id
    LEFT JOIN proveedores p ON r.proveedor_id = p.id
    WHERE r.ingreso_id = ? AND r.empresa_id = ?
    ORDER BY r.id
");
$stmt->execute([$id, $eid]);
$remitos = $stmt->fetchAll();

$ok = isset($_GET['ok']);

$nav_modulo = 'ingresos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingreso #<?= $id ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
</head>
<body>

<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-4 px-4">

    <!-- Encabezado -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="d-flex align-items-center">
            <a href="<?= url('modules/ingresos/lista.php') ?>" class="btn btn-sm btn-outline-secondary me-3"
               title="Volver a la lista">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div>
                <h5 class="fw-bold mb-0">
                    <i class="bi bi-box-arrow-in-down me-2 text-primary"></i>Ingreso #<?= $id ?>
                </h5>
                <small class="text-muted"><?= fecha_legible($ingreso['fecha_ingreso']) ?></small>
            </div>
        </div>
        <a href="<?= url('modules/ingresos/nuevo.php') ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-2"></i>Nuevo ingreso
        </a>
    </div>

    <?php if ($ok): ?>
    <div class="alert alert-success alert-dismissible d-flex align-items-center mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5"></i>
        <div><strong>Ingreso registrado correctamente.</strong> Ya podés agregar ítems a cada remito.</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Datos del camión -->
        <div class="col-md-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold border-0 pb-0">
                    <i class="bi bi-truck-front-fill text-primary me-2"></i>Datos del camión
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted fw-normal">Fecha / hora</dt>
                        <dd class="col-7"><?= fecha_legible($ingreso['fecha_ingreso']) ?></dd>

                        <dt class="col-5 text-muted fw-normal">Transportista</dt>
                        <dd class="col-7"><?= h($ingreso['transportista'] ?? '—') ?></dd>

                        <dt class="col-5 text-muted fw-normal">Patente</dt>
                        <dd class="col-7">
                            <?php if ($ingreso['patente_camion_ext']): ?>
                                <span class="badge bg-secondary fs-6"><?= h($ingreso['patente_camion_ext']) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </dd>

                        <dt class="col-5 text-muted fw-normal">Chofer</dt>
                        <dd class="col-7"><?= h($ingreso['chofer_externo'] ?? '—') ?></dd>

                        <?php if ($ingreso['observaciones']): ?>
                        <dt class="col-5 text-muted fw-normal">Observaciones</dt>
                        <dd class="col-7"><?= h($ingreso['observaciones']) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Remitos -->
        <div class="col-md-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold border-0 pb-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-file-earmark-text text-primary me-2"></i>Remitos</span>
                    <span class="badge bg-primary rounded-pill"><?= count($remitos) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($remitos)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>Sin remitos cargados.
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Nro propio</th>
                                    <th>Nro proveedor</th>
                                    <th>Cliente</th>
                                    <th>Proveedor</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($remitos as $r): ?>
                                <tr>
                                    <td><strong><?= h($r['nro_remito_propio']) ?></strong></td>
                                    <td class="text-muted small"><?= h($r['nro_remito_proveedor'] ?? '—') ?></td>
                                    <td>
                                        <?= h($r['cliente_nombre']) ?>
                                        <?php if ($r['cliente_direccion']): ?>
                                        <br><small class="text-muted"><?= h($r['cliente_direccion']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?= h($r['proveedor_nombre'] ?? '—') ?></td>
                                    <td>
                                        <span class="badge badge-estado-<?= $r['estado'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $r['estado'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?= url('modules/ingresos/remito_items.php') ?>?id=<?= $r['id'] ?>"
                                           class="btn btn-sm btn-outline-primary" title="Ver / agregar ítems">
                                            <i class="bi bi-list-check"></i>
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
