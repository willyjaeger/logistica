<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!es_admin()) { header('Location: ' . url('index.php')); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/configuracion/usuarios.php')); exit;
}

$db  = db();
$eid = empresa_id();

$id       = (int)($_POST['id'] ?? 0);
$nombre   = trim($_POST['nombre']   ?? '');
$usuario  = trim($_POST['usuario']  ?? '');
$password = $_POST['password'] ?? '';
$rol      = in_array($_POST['rol'] ?? '', ['admin','operador']) ? $_POST['rol'] : 'operador';
$activo   = isset($_POST['activo']) ? 1 : 0;
$es_nuevo = $id === 0;

function redir_error(string $msg, int $id, array $post): never {
    $_SESSION['form_error'] = $msg;
    $_SESSION['form_post']  = $post;
    $url = url('modules/configuracion/usuarios_form.php') . ($id ? "?id=$id" : '');
    header('Location: ' . $url); exit;
}

if ($nombre === '') redir_error('El nombre es obligatorio.', $id, $_POST);

if ($es_nuevo) {
    if ($usuario === '') redir_error('El nombre de usuario es obligatorio.', 0, $_POST);
    if (strlen($password) < 6) redir_error('La contraseña debe tener al menos 6 caracteres.', 0, $_POST);

    // Verificar unicidad
    $chk = $db->prepare("SELECT id FROM usuarios WHERE usuario = ?");
    $chk->execute([$usuario]);
    if ($chk->fetch()) redir_error('Ese nombre de usuario ya existe.', 0, $_POST);

    $db->prepare("
        INSERT INTO usuarios (empresa_id, nombre, usuario, password, rol, activo, debe_cambiar_clave)
        VALUES (?, ?, ?, ?, ?, ?, 1)
    ")->execute([$eid, $nombre, $usuario, password_hash($password, PASSWORD_BCRYPT), $rol, $activo]);
} else {
    // Verificar que el usuario pertenece a la empresa
    $chk = $db->prepare("SELECT id FROM usuarios WHERE id = ? AND empresa_id = ?");
    $chk->execute([$id, $eid]);
    if (!$chk->fetch()) { header('Location: ' . url('modules/configuracion/usuarios.php')); exit; }

    if ($password !== '') {
        if (strlen($password) < 6) redir_error('La contraseña debe tener al menos 6 caracteres.', $id, $_POST);
        $db->prepare("
            UPDATE usuarios SET nombre=?, rol=?, activo=?, password=?, debe_cambiar_clave=1 WHERE id=?
        ")->execute([$nombre, $rol, $activo, password_hash($password, PASSWORD_BCRYPT), $id]);
    } else {
        $db->prepare("
            UPDATE usuarios SET nombre=?, rol=?, activo=? WHERE id=?
        ")->execute([$nombre, $rol, $activo, $id]);
    }
}

header('Location: ' . url('modules/configuracion/usuarios.php') . '?ok=1');
exit;
