<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!es_admin()) { header('Location: ' . url('index.php')); exit; }

$db  = db();
$eid = empresa_id();

// ── Actualizar timeout de sesión (PRG) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['session_timeout_min'])) {
    $timeout = max(1, min(480, (int)$_POST['session_timeout_min']));
    $db->prepare("UPDATE empresas SET session_timeout_min = ? WHERE id = ?")
       ->execute([$timeout, $eid]);
    $_SESSION['session_timeout_min'] = $timeout;
    header('Location: ' . url('modules/configuracion/usuarios.php') . '?ok=1');
    exit;
}

// ── Cargar timeout actual ─────────────────────────────────────
$timeout_actual = (int)($db->prepare("SELECT session_timeout_min FROM empresas WHERE id = ?")
    ->execute([$eid]) ? $db->query("SELECT session_timeout_min FROM empresas WHERE id = $eid")->fetchColumn() : 30);
$tq = $db->prepare("SELECT session_timeout_min FROM empresas WHERE id = ?");
$tq->execute([$eid]);
$timeout_actual = (int)($tq->fetchColumn() ?: 30);

// ── Lista de usuarios ─────────────────────────────────────────
$uq = $db->prepare("SELECT id, nombre, usuario, rol, activo, debe_cambiar_clave
                    FROM usuarios WHERE empresa_id = ? ORDER BY nombre");
$uq->execute([$eid]);
$usuarios = $uq->fetchAll();

$nav_modulo = 'config';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usuarios — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container py-4" style="max-width:860px">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0"><i class="bi bi-people me-2 text-primary"></i>Usuarios</h5>
        <a href="<?= url('modules/configuracion/usuarios_form.php') ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-person-plus me-1"></i>Nuevo usuario
        </a>
    </div>

    <?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success alert-dismissible py-2">
        <i class="bi bi-check-circle me-2"></i>Guardado correctamente.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ── Timeout de sesión ──────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <form method="POST" class="d-flex align-items-center gap-3 flex-wrap">
                <i class="bi bi-clock text-secondary"></i>
                <span class="fw-semibold small">Cerrar sesión por inactividad después de</span>
                <div class="input-group" style="width:130px">
                    <input type="number" name="session_timeout_min" class="form-control form-control-sm"
                           min="1" max="480" value="<?= $timeout_actual ?>">
                    <span class="input-group-text">min</span>
                </div>
                <button type="submit" class="btn btn-outline-secondary btn-sm">Guardar</button>
                <span class="text-muted small">(aplica a todos los usuarios)</span>
            </form>
        </div>
    </div>

    <!-- ── Tabla de usuarios ──────────────────────────────── -->
    <div class="card">
        <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Usuario</th>
                    <th class="text-center">Rol</th>
                    <th class="text-center">Estado</th>
                    <th class="no-print"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
            <tr>
                <td>
                    <?= h($u['nombre']) ?>
                    <?php if ($u['debe_cambiar_clave']): ?>
                    <span class="badge bg-warning text-dark ms-1" title="Debe cambiar la clave al próximo login">
                        <i class="bi bi-key me-1"></i>Clave temporal
                    </span>
                    <?php endif; ?>
                </td>
                <td class="font-monospace text-muted small"><?= h($u['usuario']) ?></td>
                <td class="text-center">
                    <?php if ($u['rol'] === 'admin'): ?>
                    <span class="badge bg-primary">Admin</span>
                    <?php else: ?>
                    <span class="badge bg-secondary">Operador</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ($u['activo']): ?>
                    <span class="badge bg-success">Activo</span>
                    <?php else: ?>
                    <span class="badge bg-danger">Inactivo</span>
                    <?php endif; ?>
                </td>
                <td class="text-end pe-3">
                    <a href="<?= url('modules/configuracion/usuarios_form.php') ?>?id=<?= $u['id'] ?>"
                       class="btn btn-sm btn-outline-secondary me-1">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <?php if ((int)$u['id'] !== (int)$_SESSION['usuario_id']): ?>
                    <form method="POST" action="<?= url('modules/configuracion/usuarios_eliminar.php') ?>"
                          class="d-inline"
                          onsubmit="return confirm('¿Eliminar a <?= h(addslashes($u['nombre'])) ?>?')">
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($usuarios)): ?>
            <tr><td colspan="5" class="text-center text-muted py-3">No hay usuarios registrados.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
