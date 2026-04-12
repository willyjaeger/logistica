<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/transportistas_lista.php'));
    exit;
}

$db  = db();
$eid = empresa_id();

$id       = (int)($_POST['id'] ?? 0);
$nombre   = trim($_POST['nombre']   ?? '');
$cuit     = trim($_POST['cuit']     ?? '');
$telefono = trim($_POST['telefono'] ?? '');
$activo   = isset($_POST['activo']) ? (int)$_POST['activo'] : 1;

if ($nombre === '') {
    $_SESSION['form_error'] = 'El nombre es obligatorio.';
    $back = $id ? url("modules/transportistas_form.php?id=$id") : url('modules/transportistas_form.php');
    header('Location: ' . $back);
    exit;
}

if ($id > 0) {
    // Verificar que pertenece a esta empresa
    $st = $db->prepare("SELECT id FROM transportistas WHERE id = ? AND empresa_id = ?");
    $st->execute([$id, $eid]);
    if (!$st->fetch()) {
        header('Location: ' . url('modules/transportistas_lista.php'));
        exit;
    }
    $db->prepare("
        UPDATE transportistas SET nombre=?, cuit=?, telefono=?, activo=?
        WHERE id = ? AND empresa_id = ?
    ")->execute([$nombre, $cuit ?: null, $telefono ?: null, $activo, $id, $eid]);

    header('Location: ' . url("modules/transportistas_form.php?id=$id&ok=1"));
} else {
    $db->prepare("
        INSERT INTO transportistas (empresa_id, nombre, cuit, telefono)
        VALUES (?, ?, ?, ?)
    ")->execute([$eid, $nombre, $cuit ?: null, $telefono ?: null]);
    $nuevo_id = (int)$db->lastInsertId();

    header('Location: ' . url("modules/transportistas_form.php?id=$nuevo_id&ok=1"));
}
exit;
