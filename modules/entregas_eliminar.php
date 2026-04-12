<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/entregas_lista.php'));
    exit;
}

$id  = (int)($_POST['id'] ?? 0);
$db  = db();
$eid = empresa_id();

if ($id <= 0) {
    header('Location: ' . url('modules/entregas_lista.php'));
    exit;
}

// Verificar que la entrega pertenece a esta empresa
$stmt = $db->prepare("SELECT id FROM entregas WHERE id = ? AND empresa_id = ?");
$stmt->execute([$id, $eid]);
if (!$stmt->fetch()) {
    header('Location: ' . url('modules/entregas_lista.php'));
    exit;
}

$db->beginTransaction();
try {
    // Obtener remitos de esta entrega antes de borrar
    $rems = $db->prepare("SELECT remito_id FROM entrega_remitos WHERE entrega_id = ?");
    $rems->execute([$id]);
    $remito_ids = array_column($rems->fetchAll(), 'remito_id');

    // Borrar entrega (cascade borra entrega_remitos)
    $db->prepare("DELETE FROM entregas WHERE id = ? AND empresa_id = ?")->execute([$id, $eid]);

    // Volver remitos a pendiente
    if ($remito_ids) {
        $placeholders = implode(',', array_fill(0, count($remito_ids), '?'));
        $db->prepare("
            UPDATE remitos SET estado='pendiente', fecha_entrega=NULL
            WHERE id IN ($placeholders)
        ")->execute($remito_ids);
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('entregas_eliminar error: ' . $e->getMessage());
}

header('Location: ' . url('modules/entregas_lista.php') . '?borrado=1');
exit;
