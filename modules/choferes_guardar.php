<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/transportistas_lista.php'));
    exit;
}

$db  = db();
$eid = empresa_id();

$accion           = $_POST['accion'] ?? '';
$id               = (int)($_POST['id'] ?? 0);
$transportista_id = (int)($_POST['transportista_id'] ?? 0);
$ajax             = !empty($_POST['ajax']);

// Verificar que el transportista pertenece a esta empresa
$st = $db->prepare("SELECT id FROM transportistas WHERE id = ? AND empresa_id = ?");
$st->execute([$transportista_id, $eid]);
if (!$st->fetch()) {
    if ($ajax) { echo json_encode(['ok' => false, 'error' => 'Sin permisos']); exit; }
    header('Location: ' . url('modules/transportistas_lista.php'));
    exit;
}

if ($accion === 'eliminar' && $id > 0) {
    $db->prepare("DELETE FROM choferes WHERE id = ? AND transportista_id = ?")->execute([$id, $transportista_id]);
    header('Location: ' . url("modules/transportistas_form.php?id=$transportista_id"));
    exit;
}

if ($accion === 'guardar') {
    $nombre   = trim($_POST['nombre']   ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $activo   = isset($_POST['activo']) ? (int)$_POST['activo'] : 1;

    if ($nombre === '') {
        if ($ajax) { echo json_encode(['ok' => false, 'error' => 'El nombre es obligatorio']); exit; }
        header('Location: ' . url("modules/transportistas_form.php?id=$transportista_id"));
        exit;
    }

    if ($id > 0) {
        $db->prepare("
            UPDATE choferes SET nombre=?, telefono=?, activo=?
            WHERE id = ? AND transportista_id = ?
        ")->execute([$nombre, $telefono ?: null, $activo, $id, $transportista_id]);
        $nuevo_id = $id;
    } else {
        $db->prepare("
            INSERT INTO choferes (empresa_id, transportista_id, nombre, telefono)
            VALUES (?, ?, ?, ?)
        ")->execute([$eid, $transportista_id, $nombre, $telefono ?: null]);
        $nuevo_id = (int)$db->lastInsertId();
    }

    if ($ajax) {
        echo json_encode(['ok' => true, 'id' => $nuevo_id, 'nombre' => $nombre]);
        exit;
    }

    header('Location: ' . url("modules/transportistas_form.php?id=$transportista_id&ok_chofer=1"));
    exit;
}

header('Location: ' . url("modules/transportistas_form.php?id=$transportista_id"));
exit;
