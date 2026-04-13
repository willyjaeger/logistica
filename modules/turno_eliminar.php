<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/agenda.php'));
    exit;
}

$db  = db();
$eid = empresa_id();
$id  = (int)($_POST['id'] ?? 0);
$fecha_fallback = $_POST['fecha'] ?? date('Y-m-d');

if ($id <= 0) {
    header('Location: ' . url('modules/agenda.php') . '?fecha=' . urlencode($fecha_fallback));
    exit;
}

$st = $db->prepare("SELECT * FROM turnos WHERE id=? AND empresa_id=?");
$st->execute([$id, $eid]);
$turno = $st->fetch();

if (!$turno) {
    header('Location: ' . url('modules/agenda.php') . '?fecha=' . urlencode($fecha_fallback));
    exit;
}

// No se puede eliminar un turno ya en camino o entregado
if (in_array($turno['estado'], ['en_camino', 'entregado'])) {
    header('Location: ' . url('modules/agenda.php') . '?fecha=' . urlencode($turno['fecha']));
    exit;
}

$db->beginTransaction();
try {
    // Revertir remito vinculado a pendiente
    if ($turno['remito_id']) {
        $db->prepare("UPDATE remitos SET estado='pendiente', fecha_entrega=NULL WHERE id=? AND empresa_id=?")
           ->execute([$turno['remito_id'], $eid]);
    }

    $db->prepare("DELETE FROM turnos WHERE id=? AND empresa_id=?")->execute([$id, $eid]);
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('turno_eliminar: ' . $e->getMessage());
}

header('Location: ' . url('modules/agenda.php') . '?fecha=' . urlencode($turno['fecha']));
exit;
