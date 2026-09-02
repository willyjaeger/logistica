<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/agenda.php'));
    exit;
}

$db  = db();
$eid = empresa_id();

// entrega_id     = 0 al crear, >0 al editar una entrega existente
// entrega_destino= 0 = crear nueva, >0 = agregar a esa entrega existente
$entrega_id_edit = (int)($_POST['entrega_id']      ?? 0);
$entrega_destino = (int)($_POST['entrega_destino'] ?? 0);
$fecha           = $_POST['fecha_entrega'] ?? ($_POST['fecha'] ?? date('Y-m-d'));
$trans_id        = (int)($_POST['transportista_id'] ?? 0) ?: null;
$camion_id       = (int)($_POST['camion_id']        ?? 0) ?: null;
$chofer_id       = (int)($_POST['chofer_id']        ?? 0) ?: null;
$remito_ids      = array_filter(array_map('intval', $_POST['remito_ids'] ?? []));

function redir(string $fecha = ''): void {
    $back = $_POST['back'] ?? 'agenda';
    $url  = $back === 'lista'
        ? url('modules/entregas_lista.php')
        : url('modules/agenda.php');
    header('Location: ' . $url);
    exit;
}

$db->beginTransaction();
try {

    // ──────────────────────────────────────────────────────────────
    // MODO A: Edición de entrega existente
    // ──────────────────────────────────────────────────────────────
    if ($entrega_id_edit > 0) {
        $st = $db->prepare("SELECT id, estado FROM entregas WHERE id=? AND empresa_id=?");
        $st->execute([$entrega_id_edit, $eid]);
        $ent_row = $st->fetch();
        if (!$ent_row) { $db->rollBack(); redir($fecha); }

        $completada = in_array($ent_row['estado'], ['completada', 'entregado', 'con_incidencias']);

        // Actualizar siempre: fecha, fecha_salida (usada por CC) y transporte
        $db->prepare("UPDATE entregas SET fecha=?, fecha_salida=?, transportista_id=?, camion_id=?, chofer_id=?
                       WHERE id=? AND empresa_id=?")
           ->execute([$fecha, $fecha . ' 00:00:00', $trans_id, $camion_id, $chofer_id, $entrega_id_edit, $eid]);

        // Remitos actuales vinculados
        $st2 = $db->prepare("SELECT remito_id FROM entrega_remitos WHERE entrega_id=?");
        $st2->execute([$entrega_id_edit]);
        $actuales = array_column($st2->fetchAll(), 'remito_id');

        if ($completada) {
            // Entrega ya cerrada: solo propagar la nueva fecha a los remitos vinculados
            foreach ($actuales as $rid) {
                $db->prepare("UPDATE remitos SET fecha_entrega=? WHERE id=? AND empresa_id=?")
                   ->execute([$fecha, $rid, $eid]);
            }
        } else {
            // Entrega activa: gestionar altas/bajas de remitos normalmente
            foreach ($actuales as $rid) {
                if (!in_array($rid, $remito_ids)) {
                    $db->prepare("DELETE FROM entrega_remitos WHERE entrega_id=? AND remito_id=?")
                       ->execute([$entrega_id_edit, $rid]);
                    $st3 = $db->prepare("SELECT tipo FROM turnos WHERE remito_id=? AND empresa_id=? AND estado='pendiente'");
                    $st3->execute([$rid, $eid]);
                    $tur = $st3->fetch();
                    $est = ($tur && $tur['tipo'] === 'turno') ? 'turnado' : 'pendiente';
                    $db->prepare("UPDATE remitos SET estado=?, fecha_entrega=NULL WHERE id=? AND empresa_id=?")
                       ->execute([$est, $rid, $eid]);
                }
            }
            // Si la entrega ya salió, los remitos nuevos también van en_camino (así la auto-transición los captura)
            $nuevo_estado_remito = $ent_row['estado'] === 'en_camino' ? 'en_camino' : 'programado';
            foreach ($remito_ids as $rid) {
                if (!in_array($rid, $actuales)) {
                    $db->prepare("INSERT IGNORE INTO entrega_remitos (entrega_id, remito_id) VALUES (?,?)")
                       ->execute([$entrega_id_edit, $rid]);
                    $db->prepare("UPDATE remitos SET estado=?, fecha_entrega=? WHERE id=? AND empresa_id=?")
                       ->execute([$nuevo_estado_remito, $fecha, $rid, $eid]);
                } else {
                    $db->prepare("UPDATE remitos SET fecha_entrega=? WHERE id=? AND empresa_id=?")
                       ->execute([$fecha, $rid, $eid]);
                }
            }
        }

        $db->commit();
        redir($fecha);
    }

    // ──────────────────────────────────────────────────────────────
    // MODO B: Agregar remitos a entrega existente (entrega_destino > 0)
    // ──────────────────────────────────────────────────────────────
    if ($entrega_destino > 0) {
        $st = $db->prepare("SELECT id, fecha, estado FROM entregas WHERE id=? AND empresa_id=? AND estado NOT IN ('completada','entregado','con_incidencias')");
        $st->execute([$entrega_destino, $eid]);
        $ent_row = $st->fetch();
        if (!$ent_row) { $db->rollBack(); redir($fecha); }

        // Si la entrega ya salió, los remitos también van en_camino (así la auto-transición los captura)
        $nuevo_estado_remito = $ent_row['estado'] === 'en_camino' ? 'en_camino' : 'programado';

        // Solo agregar los nuevos remitos (INSERT IGNORE)
        foreach ($remito_ids as $rid) {
            $db->prepare("INSERT IGNORE INTO entrega_remitos (entrega_id, remito_id) VALUES (?,?)")
               ->execute([$entrega_destino, $rid]);
            $db->prepare("UPDATE remitos SET estado=?, fecha_entrega=? WHERE id=? AND empresa_id=?")
               ->execute([$nuevo_estado_remito, $ent_row['fecha'], $rid, $eid]);
        }

        $db->commit();
        redir($ent_row['fecha']);
    }

    // ──────────────────────────────────────────────────────────────
    // MODO C: Crear nueva entrega
    // ──────────────────────────────────────────────────────────────
    $db->prepare("
        INSERT INTO entregas (empresa_id, fecha, transportista_id, camion_id, chofer_id, fecha_salida, estado)
        VALUES (?,?,?,?,?,?,'armando')
    ")->execute([$eid, $fecha, $trans_id, $camion_id, $chofer_id, $fecha . ' 00:00:00']);
    $nueva_id = (int)$db->lastInsertId();

    foreach ($remito_ids as $rid) {
        $db->prepare("INSERT IGNORE INTO entrega_remitos (entrega_id, remito_id) VALUES (?,?)")
           ->execute([$nueva_id, $rid]);
        $db->prepare("UPDATE remitos SET estado='programado', fecha_entrega=? WHERE id=? AND empresa_id=?")
           ->execute([$fecha, $rid, $eid]);
    }

    $db->commit();

} catch (Exception $e) {
    $db->rollBack();
    error_log('entrega_dia_guardar: ' . $e->getMessage());
}

redir($fecha);
