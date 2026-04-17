<?php
// $nav_modulo: 'panel' | 'ingresos' | 'remitos' | 'entregas' | 'transportistas' | 'agenda' | 'stock' | 'reportes' | 'config'
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

                <li class="nav-item">
                    <a class="nav-link<?= in_array($nav_modulo, ['ingresos','remitos']) ? ' active' : '' ?>" href="<?= url('modules/remitos_lista.php') ?>">
                        <i class="bi bi-file-earmark-text me-1"></i>Remitos
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link<?= in_array($nav_modulo, ['entregas','transportistas']) ? ' active' : '' ?>" href="<?= url('modules/entregas_lista.php') ?>">
                        <i class="bi bi-truck me-1"></i>Salidas
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link<?= $nav_modulo === 'agenda' ? ' active' : '' ?>" href="<?= url('modules/agenda.php') ?>">
                        <i class="bi bi-calendar3 me-1"></i>Agenda
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link<?= $nav_modulo === 'stock' ? ' active' : '' ?>" href="<?= url('modules/stock/lista.php') ?>">
                        <i class="bi bi-archive me-1"></i>Stock
                    </a>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link<?= $nav_modulo === 'reportes' ? ' active' : '' ?> dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-bar-chart me-1"></i>Reportes
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= url('modules/reportes/camiones.php') ?>">
                            <i class="bi bi-truck me-2"></i>Reporte camiones
                        </a></li>
                        <li><a class="dropdown-item" href="<?= url('modules/reportes/cuenta_corriente.php') ?>">
                            <i class="bi bi-journal-text me-2"></i>Cuenta corriente
                        </a></li>
                    </ul>
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
<script>
// ── Enter avanza al siguiente campo ──────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    const t = e.target;
    if (t.tagName === 'TEXTAREA') return;
    if (t.tagName === 'BUTTON' || t.tagName === 'A') return;
    if (t.type === 'submit') return;
    e.preventDefault();
    const campos = Array.from(document.querySelectorAll(
        'input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([disabled]):not([tabindex="-1"]),' +
        'select:not([disabled]):not([tabindex="-1"]),textarea:not([disabled]):not([tabindex="-1"])'
    )).filter(el => el.offsetParent !== null && !el.readOnly);
    const idx = campos.indexOf(t);
    if (idx >= 0 && idx < campos.length - 1) {
        campos[idx + 1].focus();
    } else if (idx === campos.length - 1) {
        // Último campo: buscar el botón submit del mismo form
        const form = t.closest('form');
        if (form) {
            const btn = form.querySelector('[type=submit]');
            if (btn) btn.focus();
        }
    }
});

// ── Seleccionar todo al enfocar un campo de texto ────────────────
document.addEventListener('focusin', function(e) {
    const t = e.target;
    if ((t.tagName === 'INPUT') &&
        !['checkbox','radio','file','range','color'].includes(t.type)) {
        t.select();
    }
});
</script>
