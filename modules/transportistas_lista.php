<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

$transportistas = $db->prepare("
    SELECT t.id, t.nombre, t.cuit, t.telefono, t.activo,
           COUNT(DISTINCT c.id) AS nro_camiones,
           COUNT(DISTINCT ch.id) AS nro_choferes
    FROM transportistas t
    LEFT JOIN camiones c  ON c.transportista_id = t.id AND c.activo = 1
    LEFT JOIN choferes ch ON ch.transportista_id = t.id AND ch.activo = 1
    WHERE t.empresa_id = ?
    GROUP BY t.id
    ORDER BY t.nombre
");
$transportistas->execute([$eid]);
$lista = $transportistas->fetchAll();

$nav_modulo = 'transportistas';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transportistas — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0"><i class="bi bi-person-vcard me-2 text-primary"></i>Transportistas</h5>
        <a href="<?= url('modules/transportistas_form.php') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Nuevo transportista
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
        <i class="bi bi-trash me-2"></i>Transportista eliminado.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($lista)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-person-vcard fs-2 d-block mb-2"></i>
                No hay transportistas cargados aún.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>CUIT</th>
                            <th>Teléfono</th>
                            <th class="text-center">Camiones</th>
                            <th class="text-center">Choferes</th>
                            <th class="text-center">Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lista as $t): ?>
                    <tr>
                        <td class="fw-semibold"><?= h($t['nombre']) ?></td>
                        <td class="text-muted font-monospace"><?= h($t['cuit'] ?? '—') ?></td>
                        <td class="text-muted"><?= h($t['telefono'] ?? '—') ?></td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $t['nro_camiones'] ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $t['nro_choferes'] ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($t['activo']): ?>
                            <span class="badge bg-success">Activo</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
                            <a href="<?= url('modules/transportistas_form.php') ?>?id=<?= $t['id'] ?>"
                               class="btn btn-sm btn-outline-primary me-1" title="Editar">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="<?= url('modules/transportistas_eliminar.php') ?>"
                                  class="d-inline"
                                  onsubmit="return confirm('¿Eliminar <?= h(addslashes($t['nombre'])) ?>? Se eliminarán también sus camiones y choferes.')">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
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
