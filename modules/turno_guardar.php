<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/agenda.php'));
    exit;
}

$db     = db();
$eid    = empresa_id();
$accion = $_POST['accion'] ?? 'guardar';
$id     = (int)($_POST['id'] ?? 0);

// Helper: redirigir a la agenda en la fecha del turno
function redir(string $fecha): void {
    header('Location: ' . url('modules/agenda.php') . '?fecha=' . urlencode($fecha));
    exit;
}

// ── ASIGNAR CAMIÓN (desde modal en agenda) ────────────────────
if ($accion === 'asignar' && $id > 0) {
    $st = $db->prepare("SELECT fecha FROM turnos WHERE id=? AND empresa_id=?");
    $st->execute([$id, $eid]);
    $t = $st->fetch();
    if ($t) {
        $tid = (int)($_POST['transportista_id'] ?? 0) ?: null;
        $cid = (int)($_POST['camion_id']        ?? 0) ?: null;
        $chd = (int)($_POST['chofer_id']        ?? 0) ?: null;
        $db->prepare("UPDATE turnos SET transportista_id=?, camion_id=?, chofer_id=? WHERE id=? AND empresa_id=?")
           ->execute([$tid, $cid, $chd, $id, $eid]);
        redir($t['fecha']);
    }
    redir($_POST['redirect_fecha'] ?? date('Y-m-d'));
}

// ── CONFIRMAR SALIDA ──────────────────────────────────────────
if ($accion === 'confirmar' && $id > 0) {
    $st = $db->prepare("SELECT * FROM turnos WHERE id=? AND empresa_id=?");
    $st->execute([$id, $eid]);
    $t = $st->fetch();
    if (!$t) { header('Location: ' . url('modules/agenda.php')); exit; }

    $db->beginTransaction();
    try {
        $entrega_id = null;

        if ($t['remito_id']) {
            // ¿El remito ya está en una entrega pendiente?
            $se = $db->prepare("
                SELECT er.entrega_id FROM entrega_remitos er
                JOIN entregas e ON e.id = er.entrega_id
                WHERE er.remito_id=? AND e.empresa_id=? AND e.estado='pendiente'
                LIMIT 1
            ");
            $se->execute([$t['remito_id'], $eid]);
            $row_e = $se->fetch();

            if ($row_e) {
                // Confirmar la entrega existente
                $entrega_id = (int)$row_e['entrega_id'];
                $db->prepare("UPDATE entregas SET estado='en_camino', fecha_salida=NOW() WHERE id=? AND empresa_id=?")
                   ->execute([$entrega_id, $eid]);
            } else {
                // Crear nueva entrega
                $db->prepare("
                    INSERT INTO entregas (empresa_id, fecha, transportista_id, camion_id, chofer_id, fecha_salida, estado)
                    VALUES (?,?,?,?,?,NOW(),'en_camino')
                ")->execute([$eid, $t['fecha'], $t['transportista_id'], $t['camion_id'], $t['chofer_id']]);
                $entrega_id = (int)$db->lastInsertId();
                $db->prepare("INSERT IGNORE INTO entrega_remitos (entrega_id, remito_id) VALUES (?,?)")
                   ->execute([$entrega_id, $t['remito_id']]);
            }

            // Poner todos los remitos de esa entrega en_camino
            $sr = $db->prepare("SELECT remito_id FROM entrega_remitos WHERE entrega_id=?");
            $sr->execute([$entrega_id]);
            foreach ($sr->fetchAll() as $rr) {
                $db->prepare("UPDATE remitos SET estado='en_camino', fecha_entrega=? WHERE id=? AND empresa_id=?")
                   ->execute([$t['fecha'], $rr['remito_id'], $eid]);
            }

            // Poner todos los turnos ligados a esa entrega en_camino
            $db->prepare("
                UPDATE turnos SET estado='en_camino'
                WHERE empresa_id=? AND remito_id IN (
                    SELECT remito_id FROM entrega_remitos WHERE entrega_id=?
                )
            ")->execute([$eid, $entrega_id]);
        }

        // Siempre poner el turno actual en_camino
        $db->prepare("UPDATE turnos SET estado='en_camino' WHERE id=?")->execute([$id]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log('turno_confirmar: ' . $e->getMessage());
    }
    redir($t['fecha']);
}

// ── CANCELAR ─────────────────────────────────────────────────
if ($accion === 'cancelar' && $id > 0) {
    $st = $db->prepare("SELECT * FROM turnos WHERE id=? AND empresa_id=?");
    $st->execute([$id, $eid]);
    $t = $st->fetch();
    if ($t) {
        if ($t['remito_id']) {
            $db->prepare("UPDATE remitos SET estado='pendiente', fecha_entrega=NULL WHERE id=? AND empresa_id=?")
               ->execute([$t['remito_id'], $eid]);
        }
        $db->prepare("UPDATE turnos SET estado='cancelado' WHERE id=?")->execute([$id]);
    }
    redir($t['fecha'] ?? date('Y-m-d'));
}

// ── GUARDAR / ACTUALIZAR ──────────────────────────────────────
$fecha            = $_POST['fecha']            ?? date('Y-m-d');
$tipo             = in_array($_POST['tipo']??'', ['turno','programado']) ? $_POST['tipo'] : 'programado';
$hora             = trim($_POST['hora_turno']      ?? '') ?: null;
$cliente_id       = (int)($_POST['cliente_id']     ?? 0) ?: null;
$remito_id        = (int)($_POST['remito_id']       ?? 0) ?: null;
$pallets_est      = (float)str_replace(',','.',($_POST['pallets_est'] ?? '0')) ?: null;
$transportista_id = (int)($_POST['transportista_id']?? 0) ?: null;
$camion_id        = (int)($_POST['camion_id']       ?? 0) ?: null;
$chofer_id        = (int)($_POST['chofer_id']       ?? 0) ?: null;
$observaciones    = trim($_POST['observaciones']    ?? '') ?: null;

$db->beginTransaction();
try {
    // Estado del remito según tipo de turno
    $estado_remito = $tipo === 'turno' ? 'turnado' : 'programado';

    // Si cambió el remito vinculado, revertir el anterior
    if ($id > 0) {
        $old = $db->prepare("SELECT remito_id FROM turnos WHERE id=? AND empresa_id=?");
        $old->execute([$id, $eid]);
        $old_row = $old->fetch();
        $old_remito = $old_row ? (int)$old_row['remito_id'] : 0;

        if ($old_remito && $old_remito !== (int)($remito_id ?? 0)) {
            $db->prepare("UPDATE remitos SET estado='pendiente', fecha_entrega=NULL WHERE id=? AND empresa_id=?")
               ->execute([$old_remito, $eid]);
        }

        $db->prepare("
            UPDATE turnos SET fecha=?,tipo=?,hora_turno=?,cliente_id=?,remito_id=?,
                pallets_est=?,transportista_id=?,camion_id=?,chofer_id=?,observaciones=?
            WHERE id=? AND empresa_id=?
        ")->execute([$fecha,$tipo,$hora,$cliente_id,$remito_id,
                     $pallets_est,$transportista_id,$camion_id,$chofer_id,$observaciones,
                     $id,$eid]);
    } else {
        $db->prepare("
            INSERT INTO turnos (empresa_id,fecha,tipo,hora_turno,cliente_id,remito_id,
                pallets_est,transportista_id,camion_id,chofer_id,observaciones)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([$eid,$fecha,$tipo,$hora,$cliente_id,$remito_id,
                     $pallets_est,$transportista_id,$camion_id,$chofer_id,$observaciones]);
    }

    // Actualizar estado del remito si se vinculó uno
    if ($remito_id) {
        $db->prepare("UPDATE remitos SET estado=?, fecha_entrega=? WHERE id=? AND empresa_id=?")
           ->execute([$estado_remito, $fecha, $remito_id, $eid]);
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('turno_guardar: ' . $e->getMessage());
    $_SESSION['form_error'] = 'Error al guardar: ' . $e->getMessage();
    $back = $id > 0 ? url("modules/turno_form.php?id=$id") : url("modules/turno_form.php?fecha=".urlencode($fecha));
    header('Location: ' . $back);
    exit;
}

redir($fecha);
