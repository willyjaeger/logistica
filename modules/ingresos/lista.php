<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$eid = empresa_id();
$db  = db();

// Filtros opcionales
$buscar = trim($_GET['q'] ?? '');
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$por_pagina = 20;
$offset = ($pagina - 1) * $por_pagina;

$where  = 'WHERE i.empresa_id = ?';
$params = [$eid];

if ($buscar !== '') {
    $where  .= ' AND (i.transportista LIKE ? OR i.patente_camion_ext LIKE ? OR i.chofer_externo LIKE ?)';
    $like    = "%$buscar%";
    $params  = array_merge($params, [$like, $like, $like]);
}

// Total para paginación
$stmtCount = $db->prepare("SELECT COUNT(*) FROM ingresos i $where");
$stmtCount->execute($params);
$total = (int) $stmtCount->fetchColumn();
$total_paginas = max(1, (int) ceil($total / $por_pagina));

// Resultados
$stmt = $db->prepare("
    SELECT i.id, i.fecha_ingreso, i.transportista, i.patente_camion_ext, i.chofer_externo,
           COUNT(r.id) AS total_remitos
    FROM ingresos i
    LEFT JOIN remitos r ON r.ingreso_id = i.id
    $where
    GROUP BY i.id
    ORDER BY i.fecha_ingreso DESC
    LIMIT $por_pagina OFFSET $offset
");
$stmt->execute($params);
$ingresos = $stmt->fetchAll();

$nav_modulo = 'ingresos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresos — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
</head>
<body>

<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-4 px-4">

    <!-- Encabezado -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-box-arrow-in-down me-2 text-primary"></i>Ingresos de camiones
        </h5>
        <a href="<?= url('modules/ingresos/nuevo.php') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-2"></i>Registrar ingreso
        </a>
    </div>

    <!-- Buscador -->
    <form method="GET" class="mb-4" id="form-buscar">
        <div class="input-group" style="max-width: 420px">
            <input type="text" name="q" class="form-control" placeholder="Buscar por transportista, patente o chofer…"
                   value="<?= h($buscar) ?>" autocomplete="off">
            <button class="btn btn-outline-secondary" type="submit">
                <i class="bi bi-search"></i>
            </button>
            <?php if ($buscar): ?>
            <a href="lista.php" class="btn btn-outline-danger" title="Limpiar búsqueda">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Tabla -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <?php if (empty($ingresos)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                <?= $buscar ? 'Sin resultados para "' . h($buscar) . '".' : 'No hay ingresos registrados todavía.' ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Fecha / hora</th>
                            <th>Transportista</th>
                            <th>Patente ext.</th>
                            <th>Chofer ext.</th>
                            <th class="text-center">Remitos</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ingresos as $ing): ?>
                        <tr>
                            <td class="text-muted small"><?= $ing['id'] ?></td>
                            <td><?= fecha_legible($ing['fecha_ingreso']) ?></td>
                            <td><?= h($ing['transportista'] ?? '—') ?></td>
                            <td>
                                <?php if ($ing['patente_camion_ext']): ?>
                                <span class="badge bg-secondary"><?= h($ing['patente_camion_ext']) ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= h($ing['chofer_externo'] ?? '—') ?></td>
                            <td class="text-center">
                                <span class="badge bg-primary rounded-pill"><?= $ing['total_remitos'] ?></span>
                            </td>
                            <td>
                                <a href="ver.php?id=<?= $ing['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-eye me-1"></i>Ver
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($total_paginas > 1): ?>
        <div class="card-footer bg-white border-0 d-flex justify-content-between align-items-center">
            <small class="text-muted">
                Mostrando <?= count($ingresos) ?> de <?= $total ?> ingreso<?= $total !== 1 ? 's' : '' ?>
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $total_paginas; $p++): ?>
                    <li class="page-item <?= $p === $pagina ? 'active' : '' ?>">
                        <a class="page-link"
                           href="?p=<?= $p ?><?= $buscar ? '&q=' . urlencode($buscar) : '' ?>">
                            <?= $p ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/forms.js"></script>
</body>
</html>
