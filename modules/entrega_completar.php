<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/agenda.php'));
    exit;
}

$db          = db();
$eid         = empresa_id();
$entrega_id  = (int)($_POST['entrega_id'] ?? 0);
$estados_ok  = ['completada', 'con_incidencias'];
$nuevo_estado = in_array($_POST['nuevo_estado'] ?? '', $estados_ok) ? $_POST['nuevo_estado'] : 'completada';
$fecha        = $_POST['fecha'] ?? date('Y-m-d');
$back         = $_POST['back'] ?? 'agenda';

if (!$entrega_id) {
    header('Location: ' . url('modules/agenda.php'));
    exit;
}

$st = $db->prepare("SELECT id FROM entregas WHERE id=? AND empresa_id=? AND estado NOT IN ('completada','entregado','con_incidencias')");
$st->execute([$entrega_id, $eid]);
if (!$st->fetch()) {
    $url_back = $back === 'lista' ? url('modules/entregas_lista.php') : url('modules/agenda.php') . '?fecha=' . urlencode($fecha);
    header('Location: ' . $url_back);
    exit;
}

$db->beginTransaction();
try {
    $db->prepare("UPDATE entregas SET estado=? WHERE id=? AND empresa_id=?")
       ->execute([$nuevo_estado, $entrega_id, $eid]);

    $sr = $db->prepare("SELECT remito_id FROM entrega_remitos WHERE entrega_id=?");
    $sr->execute([$entrega_id]);
    foreach ($sr->fetchAll() as $r) {
        $db->prepare("UPDATE remitos SET estado='entregado' WHERE id=? AND empresa_id=? AND estado NOT IN ('entregado','en_stock')")
           ->execute([$r['remito_id'], $eid]);
        $db->prepare("UPDATE turnos SET estado='entregado' WHERE remito_id=? AND empresa_id=? AND estado NOT IN ('entregado','cancelado')")
           ->execute([$r['remito_id'], $eid]);
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('entrega_completar: ' . $e->getMessage());
}

$url_back = $back === 'lista'
    ? url('modules/entregas_lista.php')
    : url('modules/agenda.php') . '?fecha=' . urlencode($fecha);
header('Location: ' . $url_back);
exit;
