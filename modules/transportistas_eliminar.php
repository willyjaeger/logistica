<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/transportistas_lista.php'));
    exit;
}

$id  = (int)($_POST['id'] ?? 0);
$db  = db();
$eid = empresa_id();

if ($id > 0) {
    $st = $db->prepare("SELECT id FROM transportistas WHERE id = ? AND empresa_id = ?");
    $st->execute([$id, $eid]);
    if ($st->fetch()) {
        // CASCADE elimina camiones y choferes
        $db->prepare("DELETE FROM transportistas WHERE id = ? AND empresa_id = ?")->execute([$id, $eid]);
    }
}

header('Location: ' . url('modules/transportistas_lista.php') . '?borrado=1');
exit;
