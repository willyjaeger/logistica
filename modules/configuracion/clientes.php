<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!es_admin()) { header('Location: ' . url('index.php')); exit; }

$db  = db();
$eid = empresa_id();

$q = trim($_GET['q'] ?? '');

$where  = ['c.empresa_id = ?'];
$params = [$eid];
if ($q !== '') {
    $where[] = '(c.nombre LIKE ? OR c.cuit LIKE ? OR c.localidad LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}

$sql = "
    SELECT c.id, c.nombre, c.cuit, c.direccion, c.localidad, c.telefono, c.email, c.activo,
           COUNT(r.id) AS nro_remitos
    FROM clientes c
    LEFT JOIN remitos r ON r.cliente_id = c.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY c.id
    ORDER BY c.nombre
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$clientes = $stmt->fetchAll();

$nav_modulo = 'config';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clientes — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        body { background: #eef1f6; }
        .card { border: none !important; box-shadow: 0 2px 8px rgba(0,0,0,.10) !important; }
        thead th {
            background: #2c3e50; color: #fff;
            font-size: .78rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .05em; border: none;
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0"><i class="bi bi-people me-2 text-primary"></i>Clientes</h5>
        <a href="<?= url('modules/configuracion/clientes_form.php') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Nuevo cliente
        </a>
    </div>

    <?php if (isset($_GET['ok'])): ?>
    <div class="alert alert-success alert-dismissible py-2 mb-3">
        <i class="bi bi-check-circle me-2"></i>Guardado correctamente.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['borrado'])): ?>
    <div class="alert alert-warning alert-dismissible py-2 mb-3">
        <i class="bi bi-trash me-2"></i>Cliente eliminado.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['error']) && $_GET['error'] === 'en_uso'): ?>
    <div class="alert alert-danger alert-dismissible py-2 mb-3">
        <i class="bi bi-exclamation-circle me-2"></i>No se puede eliminar: el cliente tiene remitos asociados.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="q" class="form-control form-control-sm" style="max-width:320px"
                       placeholder="Buscar por nombre, CUIT o localidad..." value="<?= h($q) ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-search"></i>
                </button>
                <?php if ($q !== ''): ?>
                <a href="<?= url('modules/configuracion/clientes.php') ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i>
                </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($clientes)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-people fs-2 d-block mb-2"></i>
                <?= $q !== '' ? 'No se encontraron clientes para esa búsqueda.' : 'No hay clientes cargados aún.' ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>CUIT</th>
                            <th>Localidad</th>
                            <th>Teléfono</th>
                            <th>Email</th>
                            <th class="text-center">Remitos</th>
                            <th class="text-center">Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clientes as $c): ?>
                    <tr>
                        <td class="fw-semibold"><?= h($c['nombre']) ?></td>
                        <td class="text-muted font-monospace"><?= h($c['cuit'] ?: '—') ?></td>
                        <td class="text-muted"><?= h($c['localidad'] ?: '—') ?></td>
                        <td class="text-muted"><?= h($c['telefono'] ?: '—') ?></td>
                        <td class="text-muted"><?= h($c['email'] ?: '—') ?></td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= (int)$c['nro_remitos'] ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($c['activo']): ?>
                            <span class="badge bg-success">Activo</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
                            <a href="<?= url('modules/configuracion/clientes_form.php') ?>?id=<?= $c['id'] ?>"
                               class="btn btn-sm btn-outline-primary me-1" title="Editar">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="<?= url('modules/configuracion/clientes_eliminar.php') ?>"
                                  class="d-inline"
                                  onsubmit="return confirm('¿Eliminar a <?= h(addslashes($c['nombre'])) ?>?')">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
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
</body>
</html>
