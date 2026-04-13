<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/agenda.php'));
    exit;
}

$db         = db();
$eid        = empresa_id();
$entrega_id = (int)($_POST['entrega_id'] ?? 0);
$fecha      = $_POST['fecha'] ?? date('Y-m-d');

if (!$entrega_id) {
    header('Location: ' . url('modules/agenda.php') . '?fecha=' . urlencode($fecha));
    exit;
}

$st = $db->prepare("SELECT * FROM entregas WHERE id=? AND empresa_id=? AND estado NOT IN ('completada','entregado','con_incidencias')");
$st->execute([$entrega_id, $eid]);
$entrega = $st->fetch();
if (!$entrega) {
    header('Location: ' . url('modules/agenda.php') . '?fecha=' . urlencode($fecha));
    exit;
}

$db->beginTransaction();
try {
    // Poner la entrega en camino
    $db->prepare("UPDATE entregas SET estado='en_camino', fecha_salida=NOW() WHERE id=? AND empresa_id=?")
       ->execute([$entrega_id, $eid]);

    // Todos los remitos de la entrega → en_camino
    $sr = $db->prepare("SELECT remito_id FROM entrega_remitos WHERE entrega_id=?");
    $sr->execute([$entrega_id]);
    foreach ($sr->fetchAll() as $r) {
        $db->prepare("UPDATE remitos SET estado='en_camino', fecha_entrega=? WHERE id=? AND empresa_id=?")
           ->execute([$entrega['fecha'], $r['remito_id'], $eid]);

        // Turno vinculado → en_camino
        $db->prepare("UPDATE turnos SET estado='en_camino' WHERE remito_id=? AND empresa_id=?")
           ->execute([$r['remito_id'], $eid]);
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('entrega_confirmar: ' . $e->getMessage());
}

header('Location: ' . url('modules/agenda.php') . '?fecha=' . urlencode($entrega['fecha']));
exit;
