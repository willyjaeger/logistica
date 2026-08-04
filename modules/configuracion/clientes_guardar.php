<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!es_admin()) { header('Location: ' . url('index.php')); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/configuracion/clientes.php')); exit;
}

$db  = db();
$eid = empresa_id();

$id        = (int)($_POST['id'] ?? 0);
$nombre    = trim($_POST['nombre']    ?? '');
$cuit      = preg_replace('/[^0-9]/', '', $_POST['cuit'] ?? '');
$direccion = trim($_POST['direccion'] ?? '');
$localidad = trim($_POST['localidad'] ?? '');
$telefono  = trim($_POST['telefono']  ?? '');
$email     = trim($_POST['email']     ?? '');
$activo    = isset($_POST['activo']) ? 1 : 0;
$es_nuevo  = $id === 0;

function redir_error(string $msg, int $id, array $post): never {
    $_SESSION['form_error'] = $msg;
    $_SESSION['form_post']  = $post;
    $url = url('modules/configuracion/clientes_form.php') . ($id ? "?id=$id" : '');
    header('Location: ' . $url); exit;
}

if ($nombre === '') redir_error('El nombre es obligatorio.', $id, $_POST);

$cuit_guardado = $cuit !== '' ? $cuit : null;

// Verificar duplicado por nombre (dentro de la empresa)
$dup = $db->prepare("SELECT id FROM clientes WHERE empresa_id = ? AND nombre = ? AND id != ?");
$dup->execute([$eid, $nombre, $id]);
if ($dup->fetch()) redir_error('Ya existe un cliente con ese nombre.', $id, $_POST);

if ($es_nuevo) {
    $db->prepare("
        INSERT INTO clientes (empresa_id, nombre, cuit, direccion, localidad, telefono, email, activo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$eid, $nombre, $cuit_guardado, $direccion ?: null, $localidad ?: null, $telefono ?: null, $email ?: null, $activo]);
} else {
    $chk = $db->prepare("SELECT id FROM clientes WHERE id = ? AND empresa_id = ?");
    $chk->execute([$id, $eid]);
    if (!$chk->fetch()) { header('Location: ' . url('modules/configuracion/clientes.php')); exit; }

    $db->prepare("
        UPDATE clientes SET nombre=?, cuit=?, direccion=?, localidad=?, telefono=?, email=?, activo=? WHERE id=?
    ")->execute([$nombre, $cuit_guardado, $direccion ?: null, $localidad ?: null, $telefono ?: null, $email ?: null, $activo, $id]);
}

header('Location: ' . url('modules/configuracion/clientes.php') . '?ok=1');
exit;
