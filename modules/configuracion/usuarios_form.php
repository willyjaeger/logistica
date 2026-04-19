<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!es_admin()) { header('Location: ' . url('index.php')); exit; }

$db  = db();
$eid = empresa_id();

$id = (int)($_GET['id'] ?? 0);
$es_nuevo = $id === 0;

$u = ['nombre' => '', 'usuario' => '', 'rol' => 'operador', 'activo' => 1, 'debe_cambiar_clave' => 0];

if (!$es_nuevo) {
    $q = $db->prepare("SELECT id, nombre, usuario, rol, activo, debe_cambiar_clave
                       FROM usuarios WHERE id = ? AND empresa_id = ?");
    $q->execute([$id, $eid]);
    $fila = $q->fetch();
    if (!$fila) { header('Location: ' . url('modules/configuracion/usuarios.php')); exit; }
    $u = $fila;
}

$error = $_SESSION['form_error'] ?? null;
$post  = $_SESSION['form_post']  ?? [];
unset($_SESSION['form_error'], $_SESSION['form_post']);
if ($post) $u = array_merge($u, $post);

$nav_modulo = 'config';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $es_nuevo ? 'Nuevo usuario' : 'Editar usuario' ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container py-4" style="max-width:560px">

    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= url('modules/configuracion/usuarios.php') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h5 class="fw-bold mb-0">
            <?= $es_nuevo ? '<i class="bi bi-person-plus me-2 text-primary"></i>Nuevo usuario'
                          : '<i class="bi bi-pencil me-2 text-primary"></i>Editar usuario' ?>
        </h5>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-2"></i><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
        <form method="POST" action="<?= url('modules/configuracion/usuarios_guardar.php') ?>" novalidate>
            <input type="hidden" name="id" value="<?= $id ?>">

            <div class="mb-3">
                <label class="form-label fw-semibold">Nombre completo</label>
                <input type="text" name="nombre" class="form-control"
                       value="<?= h($u['nombre']) ?>" required autofocus>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Usuario (login)</label>
                <input type="text" name="usuario" class="form-control" autocomplete="off"
                       value="<?= h($u['usuario']) ?>" required <?= !$es_nuevo ? 'readonly' : '' ?>>
                <?php if (!$es_nuevo): ?>
                <div class="form-text">El nombre de usuario no se puede modificar.</div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">
                    <?= $es_nuevo ? 'Contraseña temporal' : 'Nueva contraseña' ?>
                </label>
                <input type="password" name="password" class="form-control" autocomplete="new-password"
                       placeholder="<?= $es_nuevo ? 'Mínimo 6 caracteres' : 'Dejá en blanco para no cambiar' ?>"
                       <?= $es_nuevo ? 'required' : '' ?>>
                <?php if (!$es_nuevo): ?>
                <div class="form-text">Si cargás una nueva clave, el usuario deberá cambiarla al próximo ingreso.</div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Rol</label>
                <select name="rol" class="form-select">
                    <option value="operador" <?= $u['rol'] === 'operador' ? 'selected' : '' ?>>Operador</option>
                    <option value="admin"    <?= $u['rol'] === 'admin'    ? 'selected' : '' ?>>Administrador</option>
                </select>
            </div>

            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="activo" value="1" id="chk-activo"
                           <?= $u['activo'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="chk-activo">Usuario activo</label>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-floppy me-1"></i>Guardar
                </button>
                <a href="<?= url('modules/configuracion/usuarios.php') ?>" class="btn btn-outline-secondary">
                    Cancelar
                </a>
            </div>
        </form>
        </div>
    </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
