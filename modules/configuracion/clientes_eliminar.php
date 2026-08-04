<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!es_admin()) { header('Location: ' . url('index.php')); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/configuracion/clientes.php')); exit;
}

$db  = db();
$eid = empresa_id();
$id  = (int)($_POST['id'] ?? 0);

if ($id > 0) {
    $st = $db->prepare("SELECT id FROM clientes WHERE id = ? AND empresa_id = ?");
    $st->execute([$id, $eid]);
    if ($st->fetch()) {
        try {
            $db->prepare("DELETE FROM clientes WHERE id = ? AND empresa_id = ?")->execute([$id, $eid]);
        } catch (PDOException $e) {
            header('Location: ' . url('modules/configuracion/clientes.php') . '?error=en_uso');
            exit;
        }
    }
}

header('Location: ' . url('modules/configuracion/clientes.php') . '?borrado=1');
exit;
