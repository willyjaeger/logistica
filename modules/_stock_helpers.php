<?php
// Helpers compartidos para pasar remitos 'pendiente' a 'en_stock'.
// Usado por remito_marcar_stock.php (uno a la vez) y remitos_a_stock_guardar.php (varios).

function pasar_remito_a_stock(PDO $db, int $eid, int $remito_id, int $usuario_id): bool
{
    $st = $db->prepare("SELECT id, nro_remito_propio FROM remitos WHERE id = ? AND empresa_id = ? AND estado = 'pendiente'");
    $st->execute([$remito_id, $eid]);
    $remito = $st->fetch();
    if (!$remito) return false;

    $db->beginTransaction();
    try {
        $upd = $db->prepare("UPDATE remitos SET estado = 'en_stock' WHERE id = ? AND empresa_id = ? AND estado = 'pendiente'");
        $upd->execute([$remito_id, $eid]);

        if ($upd->rowCount() === 0) {
            $db->rollBack();
            return false;
        }

        $db->prepare("UPDATE remito_items SET estado = 'en_stock', cantidad_stock = cantidad WHERE remito_id = ?")
           ->execute([$remito_id]);

        // Si el remito no generó todavía un ingreso a stock (remito virtual), lo generamos ahora
        $chk = $db->prepare("SELECT COUNT(*) FROM stock_movimientos WHERE remito_id = ? AND empresa_id = ? AND tipo = 'ingreso_remito'");
        $chk->execute([$remito_id, $eid]);

        if ((int)$chk->fetchColumn() === 0) {
            $si = $db->prepare("
                SELECT ri.articulo_id, COALESCE(a.descripcion, ri.descripcion) AS descripcion, ri.cantidad
                FROM remito_items ri
                LEFT JOIN articulos a ON a.id = ri.articulo_id
                WHERE ri.remito_id = ? AND ri.articulo_id IS NOT NULL AND ri.cantidad > 0
            ");
            $si->execute([$remito_id]);
            $stock_items = $si->fetchAll();

            if ($stock_items) {
                $obs_stock = 'Pasa a stock ' . $remito['nro_remito_propio'];
                $ins = $db->prepare("
                    INSERT INTO stock_movimientos
                        (empresa_id, lote_id, fecha, tipo, articulo_id, descripcion, cantidad, remito_id, observaciones, usuario_id)
                    VALUES (?, NULL, CURDATE(), 'ingreso_remito', ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$eid, $stock_items[0]['articulo_id'],
                    $stock_items[0]['descripcion'], $stock_items[0]['cantidad'],
                    $remito_id, $obs_stock, $usuario_id]);
                $lote_stock = (int)$db->lastInsertId();
                $db->prepare("UPDATE stock_movimientos SET lote_id = ? WHERE id = ?")->execute([$lote_stock, $lote_stock]);

                for ($i = 1; $i < count($stock_items); $i++) {
                    $ins->execute([$eid, $stock_items[$i]['articulo_id'],
                        $stock_items[$i]['descripcion'], $stock_items[$i]['cantidad'],
                        $remito_id, $obs_stock, $usuario_id]);
                    $new_id = (int)$db->lastInsertId();
                    $db->prepare("UPDATE stock_movimientos SET lote_id = ? WHERE id = ?")->execute([$lote_stock, $new_id]);
                }
            }
        }

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log('pasar_remito_a_stock: ' . $e->getMessage());
        return false;
    }
}
