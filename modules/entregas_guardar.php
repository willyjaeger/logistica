<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/entregas_lista.php'));
    exit;
}

$db  = db();
$eid = empresa_id();

$fecha         = $_POST['fecha']         ?? date('Y-m-d');
$chofer        = trim($_POST['chofer']        ?? '');
$patente       = trim($_POST['patente']       ?? '');
$transportista = trim($_POST['transportista'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');
$remitos_ids   = array_filter(array_map('intval', $_POST['remitos'] ?? []));

// Validación
if (empty($remitos_ids)) {
    $_SESSION['form_error'] = 'Seleccioná al menos un remito.';
    header('Location: ' . url('modules/entregas_form.php'));
    exit;
}

$db->beginTransaction();
try {
    // Insertar entrega
    $db->prepare("
        INSERT INTO entregas (empresa_id, fecha, chofer, patente, transportista, observaciones)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$eid, $fecha, $chofer ?: null, $patente ?: null, $transportista ?: null, $observaciones ?: null]);
    $entrega_id = (int)$db->lastInsertId();

    // Vincular remitos y marcarlos como entregados
    $ins = $db->prepare("INSERT INTO entrega_remitos (entrega_id, remito_id) VALUES (?, ?)");
    $upd = $db->prepare("UPDATE remitos SET estado='entregado', fecha_entrega=? WHERE id=? AND empresa_id=?");

    foreach ($remitos_ids as $rid) {
        $ins->execute([$entrega_id, $rid]);
        $upd->execute([$fecha, $rid, $eid]);
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('entregas_guardar error: ' . $e->getMessage());
    $_SESSION['form_error'] = 'Error al guardar la entrega. Intente nuevamente.';
    header('Location: ' . url('modules/entregas_form.php'));
    exit;
}

header('Location: ' . url('modules/entregas_lista.php') . '?ok=1');
exit;
