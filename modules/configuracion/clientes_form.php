<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!es_admin()) { header('Location: ' . url('index.php')); exit; }

$db  = db();
$eid = empresa_id();

$id = (int)($_GET['id'] ?? 0);
$es_nuevo = $id === 0;

$c = ['nombre' => '', 'cuit' => '', 'direccion' => '', 'localidad' => '', 'telefono' => '', 'email' => '', 'activo' => 1];

if (!$es_nuevo) {
    $q = $db->prepare("SELECT id, nombre, cuit, direccion, localidad, telefono, email, activo
                       FROM clientes WHERE id = ? AND empresa_id = ?");
    $q->execute([$id, $eid]);
    $fila = $q->fetch();
    if (!$fila) { header('Location: ' . url('modules/configuracion/clientes.php')); exit; }
    $c = $fila;
}

$error = $_SESSION['form_error'] ?? null;
$post  = $_SESSION['form_post']  ?? [];
unset($_SESSION['form_error'], $_SESSION['form_post']);
if ($post) $c = array_merge($c, $post);

$nav_modulo = 'config';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $es_nuevo ? 'Nuevo cliente' : 'Editar cliente' ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container py-4" style="max-width:560px">

    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= url('modules/configuracion/clientes.php') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h5 class="fw-bold mb-0">
            <?= $es_nuevo ? '<i class="bi bi-person-plus me-2 text-primary"></i>Nuevo cliente'
                          : '<i class="bi bi-pencil me-2 text-primary"></i>Editar cliente' ?>
        </h5>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-2"></i><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
        <form method="POST" action="<?= url('modules/configuracion/clientes_guardar.php') ?>" novalidate>
            <input type="hidden" name="id" value="<?= $id ?>">

            <div class="mb-3">
                <label class="form-label fw-semibold">Nombre</label>
                <input type="text" name="nombre" class="form-control"
                       value="<?= h($c['nombre']) ?>" required autofocus>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">CUIT</label>
                <input type="text" name="cuit" class="form-control" placeholder="Sin puntos ni guiones"
                       value="<?= h($c['cuit']) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Dirección</label>
                <input type="text" name="direccion" class="form-control"
                       value="<?= h($c['direccion']) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Localidad</label>
                <input type="text" name="localidad" class="form-control"
                       value="<?= h($c['localidad']) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Teléfono</label>
                <input type="text" name="telefono" class="form-control"
                       value="<?= h($c['telefono']) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Email</label>
                <input type="email" name="email" class="form-control"
                       value="<?= h($c['email']) ?>">
            </div>

            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="activo" value="1" id="chk-activo"
                           <?= $c['activo'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="chk-activo">Cliente activo</label>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-floppy me-1"></i>Guardar
                </button>
                <a href="<?= url('modules/configuracion/clientes.php') ?>" class="btn btn-outline-secondary">
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
