<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/entregas_lista.php'));
    exit;
}

$db  = db();
$eid = empresa_id();

$fecha            = $_POST['fecha']            ?? date('Y-m-d');
$transportista_id = (int)($_POST['transportista_id'] ?? 0) ?: null;
$camion_id        = (int)($_POST['camion_id']        ?? 0) ?: null;
$chofer_id        = (int)($_POST['chofer_id']        ?? 0) ?: null;
$observaciones    = trim($_POST['observaciones']     ?? '');
$remitos_ids      = array_filter(array_map('intval', $_POST['remitos'] ?? []));

if (empty($remitos_ids)) {
    $_SESSION['form_error'] = 'Seleccioná al menos un remito.';
    header('Location: ' . url('modules/entregas_form.php'));
    exit;
}

// Si la fecha del viaje es hoy → en_camino; si es anterior → ya fue, marcar completada/entregado
$es_hoy         = ($fecha === date('Y-m-d'));
$estado_entrega = $es_hoy ? 'en_camino'  : 'completada';
$estado_remito  = $es_hoy ? 'en_camino'  : 'entregado';

$db->beginTransaction();
try {
    // Verificar que transportista/camion/chofer pertenecen a esta empresa
    if ($transportista_id) {
        $st = $db->prepare("SELECT id FROM transportistas WHERE id = ? AND empresa_id = ?");
        $st->execute([$transportista_id, $eid]);
        if (!$st->fetch()) $transportista_id = null;
    }
    if ($camion_id && $transportista_id) {
        $st = $db->prepare("SELECT id FROM camiones WHERE id = ? AND transportista_id = ?");
        $st->execute([$camion_id, $transportista_id]);
        if (!$st->fetch()) $camion_id = null;
    }
    if ($chofer_id && $transportista_id) {
        $st = $db->prepare("SELECT id FROM choferes WHERE id = ? AND transportista_id = ?");
        $st->execute([$chofer_id, $transportista_id]);
        if (!$st->fetch()) $chofer_id = null;
    }
    $db->prepare("
        INSERT INTO entregas (empresa_id, fecha, transportista_id, camion_id, chofer_id, observaciones, fecha_salida, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$eid, $fecha, $transportista_id, $camion_id, $chofer_id, $observaciones ?: null, $fecha, $estado_entrega]);
    $entrega_id = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO entrega_remitos (entrega_id, remito_id) VALUES (?, ?)");
    $upd = $db->prepare("UPDATE remitos SET estado=?, fecha_entrega=? WHERE id=? AND empresa_id=?");

    foreach ($remitos_ids as $rid) {
        $ins->execute([$entrega_id, $rid]);
        $upd->execute([$estado_remito, $fecha, $rid, $eid]);
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('entregas_guardar error: ' . $e->getMessage());
    $_SESSION['form_error'] = 'Error al guardar: ' . $e->getMessage();
    header('Location: ' . url('modules/entregas_form.php'));
    exit;
}

header('Location: ' . url('modules/entregas_lista.php') . '?ok=1');
exit;
