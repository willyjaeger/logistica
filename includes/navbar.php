<?php
// $nav_modulo: 'panel' | 'ingresos' | 'remitos' | 'entregas' | 'transportistas' | 'stock' | 'reportes' | 'config'
// Debe estar definida antes de incluir este archivo.
if (!isset($nav_modulo)) $nav_modulo = '';
?>
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
                    <a class="nav-link<?= $nav_modulo === 'panel' ? ' active' : '' ?>" href="<?= url('index.php') ?>">
                        <i class="bi bi-speedometer2 me-1"></i>Panel
                    </a>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link<?= in_array($nav_modulo, ['ingresos','remitos']) ? ' active' : '' ?> dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-file-earmark-text me-1"></i>Remitos
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= url('modules/remitos_lista.php') ?>">
                            <i class="bi bi-list-ul me-2"></i>Ver todos
                        </a></li>
                        <li><a class="dropdown-item" href="<?= url('modules/remitos_form.php') ?>">
                            <i class="bi bi-plus-circle me-2"></i>Nuevo remito
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-muted" href="<?= url('modules/remitos_lista.php') ?>?estado=pendiente">
                            <i class="bi bi-clock me-2"></i>Pendientes
                        </a></li>
                    </ul>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link<?= in_array($nav_modulo, ['entregas','transportistas']) ? ' active' : '' ?> dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-truck me-1"></i>Entregas
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= url('modules/entregas_lista.php') ?>">
                            <i class="bi bi-list-ul me-2"></i>Ver todas
                        </a></li>
                        <li><a class="dropdown-item" href="<?= url('modules/entregas_form.php') ?>">
                            <i class="bi bi-plus-circle me-2"></i>Armar entrega
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= url('modules/transportistas_lista.php') ?>">
                            <i class="bi bi-person-vcard me-2"></i>Transportistas
                        </a></li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a class="nav-link<?= $nav_modulo === 'stock' ? ' active' : '' ?>" href="<?= url('modules/stock/lista.php') ?>">
                        <i class="bi bi-archive me-1"></i>Stock
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link<?= $nav_modulo === 'reportes' ? ' active' : '' ?>" href="<?= url('modules/reportes/camiones.php') ?>">
                        <i class="bi bi-bar-chart me-1"></i>Reportes
                    </a>
                </li>

                <?php if (es_admin()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link<?= $nav_modulo === 'config' ? ' active' : '' ?> dropdown-toggle" href="#" data-bs-toggle="dropdown">
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
                        <li><a class="dropdown-item text-danger" href="<?= url('logout.php') ?>">
                            <i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>
