<?php
require_once __DIR__ . '/config/auth.php';
require_login();

$eid = empresa_id();
$db  = db();

// Ingresos del día
$stmt = $db->prepare("SELECT COUNT(*) FROM ingresos WHERE empresa_id = ? AND DATE(fecha_ingreso) = CURDATE()");
$stmt->execute([$eid]);
$ingresos_hoy = (int) $stmt->fetchColumn();

// Remitos pendientes
$stmt = $db->prepare("SELECT COUNT(*) FROM remitos WHERE empresa_id = ? AND estado IN ('pendiente','parcialmente_entregado')");
$stmt->execute([$eid]);
$remitos_pendientes = (int) $stmt->fetchColumn();

// Entregas en camino
$stmt = $db->prepare("SELECT COUNT(*) FROM entregas WHERE empresa_id = ? AND estado = 'en_camino'");
$stmt->execute([$eid]);
$entregas_en_camino = (int) $stmt->fetchColumn();

// Items en stock disponible
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

// Últimos 5 ingresos
$stmt = $db->prepare("
    SELECT i.id, i.fecha_ingreso, i.transportista, i.patente_camion_ext,
           COUNT(r.id) AS total_remitos
    FROM ingresos i
    LEFT JOIN remitos r ON r.ingreso_id = i.id
    WHERE i.empresa_id = ?
    GROUP BY i.id
    ORDER BY i.fecha_ingreso DESC
    LIMIT 5
");
$stmt->execute([$eid]);
$ultimos_ingresos = $stmt->fetchAll();
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

<!-- ── NAVBAR ── -->
<nav class="navbar navbar-dark bg-primary navbar-expand-lg sticky-top shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?= url('index.php') ?>">
            <i class="bi bi-truck-front-fill"></i>
            <?= h(APP_NAME) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav me-auto gap-1">
                <li class="nav-item">
                    <a class="nav-link active" href="<?= url('index.php') ?>">
                        <i class="bi bi-speedometer2 me-1"></i>Panel
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-box-arrow-in-down me-1"></i>Ingresos
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= url('modules/ingresos/lista.php') ?>"><i class="bi bi-list-ul me-2"></i>Ver todos</a></li>
                        <li><a class="dropdown-item" href="<?= url('modules/ingresos/nuevo.php') ?>"><i class="bi bi-plus-circle me-2"></i>Registrar ingreso</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-file-earmark-text me-1"></i>Remitos
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= url('modules/ingresos/remitos.php') ?>"><i class="bi bi-list-ul me-2"></i>Pendientes</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-truck me-1"></i>Entregas
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= url('modules/entregas/lista.php') ?>"><i class="bi bi-list-ul me-2"></i>Ver todas</a></li>
                        <li><a class="dropdown-item" href="<?= url('modules/entregas/nueva.php') ?>"><i class="bi bi-plus-circle me-2"></i>Armar entrega</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= url('modules/stock/lista.php') ?>">
                        <i class="bi bi-archive me-1"></i>Stock
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= url('modules/reportes/camiones.php') ?>">
                        <i class="bi bi-bar-chart me-1"></i>Reportes
                    </a>
                </li>
                <?php if (es_admin()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-gear me-1"></i>Config
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= url('modules/configuracion/clientes.php') ?>">Clientes</a></li>
                        <li><a class="dropdown-item" href="<?= url('modules/configuracion/proveedores.php') ?>">Proveedores</a></li>
                        <li><a class="dropdown-item" href="<?= url('modules/configuracion/choferes.php') ?>">Choferes</a></li>
                        <li><a class="dropdown-item" href="<?= url('modules/configuracion/camiones.php') ?>">Camiones</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= url('modules/configuracion/usuarios.php') ?>">Usuarios</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-3">
                <span class="text-white-50 small d-none d-lg-inline">
                    <i class="bi bi-building me-1"></i><?= h(empresa_nombre()) ?>
                </span>
                <div class="dropdown">
                    <button class="btn btn-outline-light btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i><?= h(usuario_nombre()) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text text-muted small"><?= h(empresa_nombre()) ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- ── CONTENIDO ── -->
<div class="container-fluid py-4 px-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h5 class="fw-bold mb-0">Buen día, <?= h(usuario_nombre()) ?></h5>
            <small class="text-muted"><?= date('l j \d\e F \d\e Y') ?></small>
        </div>
        <a href="<?= url('modules/ingresos/nuevo.php') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-2"></i>Registrar ingreso
        </a>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100 stat-card stat-azul">
                <div class="card-body">
                    <div class="stat-icon"><i class="bi bi-box-arrow-in-down"></i></div>
                    <div class="stat-numero"><?= $ingresos_hoy ?></div>
                    <div class="stat-label">Ingresos hoy</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100 stat-card stat-naranja">
                <div class="card-body">
                    <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                    <div class="stat-numero"><?= $remitos_pendientes ?></div>
                    <div class="stat-label">Remitos pendientes</div>
                </div>
            </div>
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

    <!-- Acciones + últimos ingresos -->
    <div class="row g-3">

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold border-0 pb-0">
                    <i class="bi bi-lightning-charge-fill text-warning me-2"></i>Acciones rápidas
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="<?= url('modules/ingresos/nuevo.php') ?>" class="btn btn-outline-primary text-start">
                        <i class="bi bi-plus-circle me-2"></i>Registrar ingreso de camión
                    </a>
                    <a href="<?= url('modules/ingresos/remitos.php') ?>" class="btn btn-outline-warning text-start">
                        <i class="bi bi-file-earmark-text me-2"></i>Ver remitos pendientes
                    </a>
                    <a href="<?= url('modules/entregas/nueva.php') ?>" class="btn btn-outline-success text-start">
                        <i class="bi bi-truck me-2"></i>Armar nueva entrega
                    </a>
                    <a href="<?= url('modules/stock/lista.php') ?>" class="btn btn-outline-danger text-start">
                        <i class="bi bi-archive me-2"></i>Consultar stock
                    </a>
                    <a href="<?= url('modules/reportes/camiones.php') ?>" class="btn btn-outline-secondary text-start">
                        <i class="bi bi-bar-chart me-2"></i>Reporte de camiones usados
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold border-0 pb-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history text-primary me-2"></i>Últimos ingresos</span>
                    <a href="<?= url('modules/ingresos/lista.php') ?>" class="btn btn-sm btn-outline-primary">Ver todos</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($ultimos_ingresos)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            No hay ingresos registrados todavía.
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Fecha</th>
                                    <th>Transportista</th>
                                    <th>Patente ext.</th>
                                    <th class="text-center">Remitos</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ultimos_ingresos as $ing): ?>
                                <tr>
                                    <td class="text-muted small"><?= $ing['id'] ?></td>
                                    <td><?= fecha_legible($ing['fecha_ingreso']) ?></td>
                                    <td><?= h($ing['transportista'] ?? '—') ?></td>
                                    <td><span class="badge bg-secondary"><?= h($ing['patente_camion_ext'] ?? '—') ?></span></td>
                                    <td class="text-center">
                                        <span class="badge bg-primary rounded-pill"><?= $ing['total_remitos'] ?></span>
                                    </td>
                                    <td>
                                        <a href="<?= url('modules/ingresos/ver.php') ?>?id=<?= $ing['id'] ?>"
                                           class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-eye"></i>
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
