<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/remitos_lista.php'));
    exit;
}

$db        = db();
$eid       = empresa_id();
$remito_id = (int)($_POST['remito_id'] ?? 0);
$fecha_dev = $_POST['fecha_devolucion'] ?? '';
$pal_dev   = (float)str_replace(',', '.', $_POST['pallets_devueltos'] ?? '0');
$back      = trim($_POST['back'] ?? '');

function redir_dev(string $back): void {
    header('Location: ' . url('modules/remitos_lista.php') . '?dev=1' . ($back ? '&' . $back : ''));
    exit;
}
function redir_err(string $back): void {
    header('Location: ' . url('modules/remitos_lista.php') . ($back ? '?' . $back : ''));
    exit;
}

if (!$remito_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_dev) || $pal_dev <= 0) {
    redir_err($back);
}

$st = $db->prepare("
    SELECT id, total_pallets, nro_remito_propio
    FROM remitos
    WHERE id = ? AND empresa_id = ? AND estado = 'entregado' AND fecha_devolucion IS NULL
");
$st->execute([$remito_id, $eid]);
$remito = $st->fetch();
if (!$remito) {
    redir_err($back);
}

$pal_dev = min($pal_dev, (float)$remito['total_pallets']);

$db->beginTransaction();
try {
    $db->prepare("
        UPDATE remitos SET fecha_devolucion = ?, pallets_devueltos = ?
        WHERE id = ? AND empresa_id = ?
    ")->execute([$fecha_dev, $pal_dev, $remito_id, $eid]);

    // Movimiento de stock: reingreso por devolución
    $uid = $_SESSION['usuario_id'];
    $obs = 'Devolución ' . $remito['nro_remito_propio'];
    $ins = $db->prepare("
        INSERT INTO stock_movimientos
            (empresa_id, lote_id, fecha, tipo, articulo_id, descripcion,
             cantidad, remito_id, observaciones, usuario_id)
        VALUES (?, NULL, ?, 'ingreso_devolucion', NULL, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$eid, $fecha_dev, $obs, $pal_dev, $remito_id, $obs, $uid]);
    $new_id = (int)$db->lastInsertId();
    $db->prepare("UPDATE stock_movimientos SET lote_id = ? WHERE id = ?")->execute([$new_id, $new_id]);

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('remito_devolucion_guardar: ' . $e->getMessage());
}

redir_dev($back);
