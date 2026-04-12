<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/transportistas_lista.php'));
    exit;
}

$db  = db();
$eid = empresa_id();

$accion          = $_POST['accion'] ?? '';
$id              = (int)($_POST['id'] ?? 0);
$transportista_id = (int)($_POST['transportista_id'] ?? 0);
$ajax            = !empty($_POST['ajax']);

// Verificar que el transportista pertenece a esta empresa
$st = $db->prepare("SELECT id FROM transportistas WHERE id = ? AND empresa_id = ?");
$st->execute([$transportista_id, $eid]);
if (!$st->fetch()) {
    if ($ajax) { echo json_encode(['ok' => false, 'error' => 'Sin permisos']); exit; }
    header('Location: ' . url('modules/transportistas_lista.php'));
    exit;
}

if ($accion === 'eliminar' && $id > 0) {
    $db->prepare("DELETE FROM camiones WHERE id = ? AND transportista_id = ?")->execute([$id, $transportista_id]);
    header('Location: ' . url("modules/transportistas_form.php?id=$transportista_id"));
    exit;
}

if ($accion === 'guardar') {
    $patente = strtoupper(trim($_POST['patente'] ?? ''));
    $marca   = trim($_POST['marca']   ?? '');
    $modelo  = trim($_POST['modelo']  ?? '');
    $activo  = isset($_POST['activo']) ? (int)$_POST['activo'] : 1;

    if ($patente === '') {
        if ($ajax) { echo json_encode(['ok' => false, 'error' => 'La patente es obligatoria']); exit; }
        header('Location: ' . url("modules/transportistas_form.php?id=$transportista_id"));
        exit;
    }

    if ($id > 0) {
        $db->prepare("
            UPDATE camiones SET patente=?, marca=?, modelo=?, activo=?
            WHERE id = ? AND transportista_id = ?
        ")->execute([$patente, $marca ?: null, $modelo ?: null, $activo, $id, $transportista_id]);
        $nuevo_id = $id;
    } else {
        $db->prepare("
            INSERT INTO camiones (empresa_id, transportista_id, patente, marca, modelo)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$eid, $transportista_id, $patente, $marca ?: null, $modelo ?: null]);
        $nuevo_id = (int)$db->lastInsertId();
    }

    if ($ajax) {
        echo json_encode(['ok' => true, 'id' => $nuevo_id, 'patente' => $patente,
                          'label' => $patente . ($marca ? " — $marca" : '') . ($modelo ? " $modelo" : '')]);
        exit;
    }

    header('Location: ' . url("modules/transportistas_form.php?id=$transportista_id&ok_camion=1"));
    exit;
}

header('Location: ' . url("modules/transportistas_form.php?id=$transportista_id"));
exit;
