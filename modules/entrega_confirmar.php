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
$back       = in_array($_POST['back'] ?? '', ['lista','agenda']) ? $_POST['back'] : 'agenda';

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
    $remito_ids = array_column($sr->fetchAll(), 'remito_id');

    foreach ($remito_ids as $rid) {
        $db->prepare("UPDATE remitos SET estado='en_camino', fecha_entrega=? WHERE id=? AND empresa_id=?")
           ->execute([$entrega['fecha'], $rid, $eid]);
        $db->prepare("UPDATE turnos SET estado='en_camino' WHERE remito_id=? AND empresa_id=?")
           ->execute([$rid, $eid]);
    }

    // ── Stock: generar salidas por cada ítem con artículo ────────
    if ($remito_ids) {
        $uid_s = $_SESSION['usuario_id'];
        $in    = implode(',', array_fill(0, count($remito_ids), '?'));
        $sir   = $db->prepare("
            SELECT ri.remito_id, ri.articulo_id,
                   COALESCE(a.descripcion, ri.descripcion) AS descripcion,
                   ri.cantidad,
                   COALESCE(r.entrega_fisica, 1) AS entrega_fisica,
                   r.nro_remito_propio
            FROM remito_items ri
            JOIN remitos r ON r.id = ri.remito_id
            LEFT JOIN articulos a ON a.id = ri.articulo_id
            WHERE ri.remito_id IN ($in) AND ri.articulo_id IS NOT NULL AND ri.cantidad > 0
        ");
        $sir->execute($remito_ids);
        $sitems = $sir->fetchAll();

        if ($sitems) {
            // Un lote_id para toda la entrega
            $ins_s = $db->prepare("
                INSERT INTO stock_movimientos
                    (empresa_id, lote_id, fecha, tipo, articulo_id, descripcion,
                     cantidad, remito_id, observaciones, usuario_id)
                VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $obs_e = 'Salida entrega #' . $entrega_id;
            $ins_s->execute([
                $eid, $entrega['fecha'],
                $sitems[0]['entrega_fisica'] ? 'salida_entrega' : 'salida_consumo',
                $sitems[0]['articulo_id'], $sitems[0]['descripcion'],
                $sitems[0]['cantidad'], $sitems[0]['remito_id'],
                $obs_e . ' · ' . $sitems[0]['nro_remito_propio'], $uid_s
            ]);
            $lote_s = (int)$db->lastInsertId();
            $db->prepare("UPDATE stock_movimientos SET lote_id=? WHERE id=?")->execute([$lote_s, $lote_s]);

            for ($i = 1; $i < count($sitems); $i++) {
                $it = $sitems[$i];
                $ins_s->execute([
                    $eid, $entrega['fecha'],
                    $it['entrega_fisica'] ? 'salida_entrega' : 'salida_consumo',
                    $it['articulo_id'], $it['descripcion'],
                    $it['cantidad'], $it['remito_id'],
                    $obs_e . ' · ' . $it['nro_remito_propio'], $uid_s
                ]);
                $new_s = (int)$db->lastInsertId();
                $db->prepare("UPDATE stock_movimientos SET lote_id=? WHERE id=?")->execute([$lote_s, $new_s]);
            }
        }
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('entrega_confirmar: ' . $e->getMessage());
}

header('Location: ' . url('modules/entrega_hoja_ruta.php') . '?id=' . $entrega_id . '&back=' . urlencode($back) . '&print=1');
exit;
