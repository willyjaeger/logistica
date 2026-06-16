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
$fecha       = $_POST['fecha'] ?? date('Y-m-d');
$back        = $_POST['back']  ?? 'agenda';

if (!$entrega_id) {
    header('Location: ' . url('modules/agenda.php'));
    exit;
}

$st = $db->prepare("SELECT id FROM entregas WHERE id=? AND empresa_id=?");
$st->execute([$entrega_id, $eid]);
if (!$st->fetch()) {
    $url_back = $back === 'lista' ? url('modules/entregas_lista.php') : url('modules/agenda.php') . '?fecha=' . urlencode($fecha);
    header('Location: ' . $url_back);
    exit;
}

// Detectar si viene del form intermedio (tiene resultados por remito)
$resultados   = $_POST['resultados']   ?? null;  // array remito_id => 'entregado'|'no_entregado'
$observaciones = $_POST['observaciones'] ?? [];   // array remito_id => texto

$db->beginTransaction();
try {
    $sr = $db->prepare("SELECT remito_id FROM entrega_remitos WHERE entrega_id=?");
    $sr->execute([$entrega_id]);
    $remitos = $sr->fetchAll(PDO::FETCH_COLUMN);

    if ($resultados !== null) {
        // ── Flujo nuevo: resultado por remito ─────────────────────
        $hay_no_entregado = false;

        foreach ($remitos as $remito_id) {
            $resultado  = in_array($resultados[$remito_id] ?? '', ['entregado','no_entregado'])
                          ? $resultados[$remito_id]
                          : 'entregado';
            $observacion = trim($observaciones[$remito_id] ?? '');

            $db->prepare("UPDATE entrega_remitos SET resultado=?, observacion=? WHERE entrega_id=? AND remito_id=?")
               ->execute([$resultado, $observacion ?: null, $entrega_id, $remito_id]);

            if ($resultado === 'entregado') {
                $db->prepare("UPDATE remitos SET estado='entregado' WHERE id=? AND empresa_id=? AND estado NOT IN ('entregado','en_stock')")
                   ->execute([$remito_id, $eid]);
                $db->prepare("UPDATE turnos SET estado='entregado' WHERE remito_id=? AND empresa_id=? AND estado NOT IN ('entregado','cancelado')")
                   ->execute([$remito_id, $eid]);
            } else {
                $hay_no_entregado = true;
                // El remito vuelve a disponible para un nuevo viaje
                $db->prepare("UPDATE remitos SET estado='pendiente' WHERE id=? AND empresa_id=? AND estado NOT IN ('en_stock')")
                   ->execute([$remito_id, $eid]);
                // El turno asociado se cancela (la fecha ya pasó)
                $db->prepare("UPDATE turnos SET estado='cancelado' WHERE remito_id=? AND empresa_id=? AND estado NOT IN ('entregado','cancelado')")
                   ->execute([$remito_id, $eid]);
            }
        }

        $nuevo_estado = $hay_no_entregado ? 'con_incidencias' : 'completada';

    } else {
        // ── Flujo legacy: marcar todo como entregado ──────────────
        $estados_ok   = ['completada', 'con_incidencias'];
        $nuevo_estado = in_array($_POST['nuevo_estado'] ?? '', $estados_ok) ? $_POST['nuevo_estado'] : 'completada';

        foreach ($remitos as $remito_id) {
            $db->prepare("UPDATE remitos SET estado='entregado' WHERE id=? AND empresa_id=? AND estado NOT IN ('entregado','en_stock')")
               ->execute([$remito_id, $eid]);
            $db->prepare("UPDATE turnos SET estado='entregado' WHERE remito_id=? AND empresa_id=? AND estado NOT IN ('entregado','cancelado')")
               ->execute([$remito_id, $eid]);
        }
    }

    $db->prepare("UPDATE entregas SET estado=? WHERE id=? AND empresa_id=?")
       ->execute([$nuevo_estado, $entrega_id, $eid]);

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
