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
$back      = trim($_POST['back'] ?? '');
$items_raw = $_POST['items'] ?? [];

function redir_lista(string $back, bool $ok = false): void {
    $qs = $ok ? 'dev=1' : '';
    if ($back) $qs .= ($qs ? '&' : '') . $back;
    header('Location: ' . url('modules/remitos_lista.php') . ($qs ? '?' . $qs : ''));
    exit;
}

if (!$remito_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_dev)) {
    redir_lista($back);
}

// Filtrar sólo ítems con cantidad > 0
$items = [];
foreach ($items_raw as $row) {
    $cant = (float)str_replace(',', '.', $row['cantidad'] ?? '0');
    $pal  = (float)str_replace(',', '.', $row['pallets']  ?? '0');
    if ($cant <= 0 && $pal <= 0) continue;
    $items[] = [
        'articulo_id' => (int)($row['articulo_id'] ?? 0) ?: null,
        'descripcion' => trim($row['descripcion'] ?? ''),
        'cantidad'    => $cant,
        'pallets'     => $pal > 0 ? $pal : 0,
    ];
}

if (empty($items)) {
    redir_lista($back);
}

// Verificar que el remito existe, pertenece a la empresa, está entregado y sin devolución
$st = $db->prepare("
    SELECT id, nro_remito_propio
    FROM remitos
    WHERE id = ? AND empresa_id = ? AND estado = 'entregado' AND fecha_devolucion IS NULL
");
$st->execute([$remito_id, $eid]);
$remito = $st->fetch();
if (!$remito) {
    redir_lista($back);
}

$total_pallets_dev = array_sum(array_column($items, 'pallets'));

$db->beginTransaction();
try {
    // 1. Insertar devolucion_items
    $ins_item = $db->prepare("
        INSERT INTO devolucion_items
            (empresa_id, remito_id, articulo_id, descripcion, cantidad, pallets)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($items as $it) {
        $ins_item->execute([
            $eid, $remito_id,
            $it['articulo_id'],
            $it['descripcion'],
            $it['cantidad'],
            $it['pallets'],
        ]);
    }

    // 2. Actualizar remito: fecha_devolucion + total pallets devueltos
    $db->prepare("
        UPDATE remitos
        SET fecha_devolucion  = ?,
            pallets_devueltos = ?
        WHERE id = ? AND empresa_id = ?
    ")->execute([$fecha_dev, $total_pallets_dev, $remito_id, $eid]);

    // 3. Movimientos de stock: un ingreso_devolucion por artículo
    $uid     = $_SESSION['usuario_id'];
    $obs_pre = 'Dev. ' . $remito['nro_remito_propio'];
    $ins_sm  = $db->prepare("
        INSERT INTO stock_movimientos
            (empresa_id, lote_id, fecha, tipo, articulo_id, descripcion,
             cantidad, remito_id, observaciones, usuario_id)
        VALUES (?, NULL, ?, 'ingreso_devolucion', ?, ?, ?, ?, ?)
    ");

    $lote_id = null;
    foreach ($items as $it) {
        $ins_sm->execute([
            $eid, $fecha_dev,
            $it['articulo_id'],
            $it['descripcion'],
            $it['cantidad'],
            $remito_id,
            $obs_pre,
            $uid,
        ]);
        $new_id = (int)$db->lastInsertId();
        if ($lote_id === null) $lote_id = $new_id;
        $db->prepare("UPDATE stock_movimientos SET lote_id = ? WHERE id = ?")
           ->execute([$lote_id, $new_id]);
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('remito_devolucion_guardar: ' . $e->getMessage());
}

redir_lista($back, true);
