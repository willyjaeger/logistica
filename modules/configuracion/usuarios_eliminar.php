<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!es_admin()) { header('Location: ' . url('index.php')); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/configuracion/usuarios.php')); exit;
}

$db  = db();
$eid = empresa_id();
$id  = (int)($_POST['id'] ?? 0);

// No puede eliminarse a sí mismo
if ($id > 0 && $id !== (int)$_SESSION['usuario_id']) {
    $db->prepare("DELETE FROM usuarios WHERE id = ? AND empresa_id = ?")
       ->execute([$id, $eid]);
}

header('Location: ' . url('modules/configuracion/usuarios.php') . '?ok=1');
exit;
