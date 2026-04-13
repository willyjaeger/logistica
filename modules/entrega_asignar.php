<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/agenda.php'));
    exit;
}

$db     = db();
$eid    = empresa_id();
$accion = $_POST['accion'] ?? '';
$fecha  = $_POST['fecha']  ?? date('Y-m-d');

function redir(string $fecha): void {
    header('Location: ' . url('modules/agenda.php') . '?fecha=' . urlencode($fecha));
    exit;
}

// ── QUITAR: desvincula remito de su entrega ────────────────────
if ($accion === 'quitar') {
    $remito_id  = (int)($_POST['remito_id']  ?? 0);
    $entrega_id = (int)($_POST['entrega_id'] ?? 0);

    if (!$remito_id || !$entrega_id) redir($fecha);

    // Verificar que la entrega pertenece a la empresa y está pendiente
    $st = $db->prepare("SELECT id FROM entregas WHERE id=? AND empresa_id=? AND estado NOT IN ('completada','entregado','con_incidencias')");
    $st->execute([$entrega_id, $eid]);
    if (!$st->fetch()) redir($fecha);

    // Verificar que el remito pertenece a la empresa
    $st2 = $db->prepare("SELECT id FROM remitos WHERE id=? AND empresa_id=?");
    $st2->execute([$remito_id, $eid]);
    if (!$st2->fetch()) redir($fecha);

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM entrega_remitos WHERE entrega_id=? AND remito_id=?")
           ->execute([$entrega_id, $remito_id]);

        // Si tiene turno → estado turnado; si no → pendiente
        $st3 = $db->prepare("SELECT id FROM turnos WHERE remito_id=? AND empresa_id=? LIMIT 1");
        $st3->execute([$remito_id, $eid]);
        $nuevo_estado = $st3->fetch() ? 'turnado' : 'pendiente';

        $db->prepare("UPDATE remitos SET estado=?, fecha_entrega=NULL WHERE id=? AND empresa_id=?")
           ->execute([$nuevo_estado, $remito_id, $eid]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log('entrega_asignar quitar: ' . $e->getMessage());
    }
    redir($fecha);
}

// ── ASIGNAR: agrega remito a entrega existente o crea una nueva ─
if ($accion === 'asignar') {
    $remito_id  = (int)($_POST['remito_id']  ?? 0);
    $entrega_id = (int)($_POST['entrega_id'] ?? 0); // 0 = crear nueva

    if (!$remito_id) redir($fecha);

    // Verificar que el remito pertenece a la empresa
    $st = $db->prepare("SELECT id FROM remitos WHERE id=? AND empresa_id=?");
    $st->execute([$remito_id, $eid]);
    if (!$st->fetch()) redir($fecha);

    $db->beginTransaction();
    try {
        if ($entrega_id > 0) {
            // Verificar que la entrega existe y está pendiente
            $st2 = $db->prepare("SELECT id FROM entregas WHERE id=? AND empresa_id=? AND estado NOT IN ('completada','entregado','con_incidencias')");
            $st2->execute([$entrega_id, $eid]);
            if (!$st2->fetch()) { $db->rollBack(); redir($fecha); }
        } else {
            // Crear nueva entrega vacía para la fecha indicada
            $db->prepare("
                INSERT INTO entregas (empresa_id, fecha, fecha_salida, estado)
                VALUES (?, ?, ?, 'armando')
            ")->execute([$eid, $fecha, $fecha . ' 00:00:00']);
            $entrega_id = (int)$db->lastInsertId();
        }

        // Vincular remito a la entrega
        $db->prepare("INSERT IGNORE INTO entrega_remitos (entrega_id, remito_id) VALUES (?,?)")
           ->execute([$entrega_id, $remito_id]);

        // Actualizar estado del remito
        $db->prepare("UPDATE remitos SET estado='programado', fecha_entrega=? WHERE id=? AND empresa_id=?")
           ->execute([$fecha, $remito_id, $eid]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log('entrega_asignar asignar: ' . $e->getMessage());
    }
    redir($fecha);
}

redir($fecha);
