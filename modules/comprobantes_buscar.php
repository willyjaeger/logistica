<?php
// AJAX: busca remitos para vincular comprobantes (?q=) o devuelve el detalle de uno (?id=)
require_once __DIR__ . '/../config/auth.php';
require_login();
require_once __DIR__ . '/_comprobantes_helpers.php';
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

$db  = db();
$eid = empresa_id();

$sel = "
    SELECT r.id, r.nro_remito_propio, r.nro_remito_proveedor, r.nro_oc, r.estado, r.fecha_entrega,
           c.nombre AS cliente, p.nombre AS proveedor,
           (SELECT COUNT(*) FROM remito_comprobantes rc WHERE rc.remito_id = r.id) AS n_comp
    FROM remitos r
    JOIN clientes c ON c.id = r.cliente_id
    LEFT JOIN proveedores p ON p.id = r.proveedor_id
";

try {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $st = $db->prepare("$sel WHERE r.empresa_id = ? AND r.id = ?");
        $st->execute([$eid, $id]);
        $rem = $st->fetch();
        if (!$rem) { echo json_encode(['ok' => false, 'error' => 'Remito no encontrado']); exit; }
        $rem['comprobantes'] = array_map(fn($c) => $c + [
            'url' => url('portal/ver.php?id=' . $c['id']),
        ], comprobantes_de_remito($db, $eid, $id));
        echo json_encode(['ok' => true, 'remito' => $rem]);
        exit;
    }

    $q = trim($_GET['q'] ?? '');
    if ($q === '') { echo json_encode(['ok' => true, 'remitos' => []]); exit; }

    // Si tipea solo el número (ej. "123"), priorizar el remito cuyo número final es exactamente ese
    $num = ctype_digit($q) ? (int)$q : -1;
    $st = $db->prepare("
        $sel
        WHERE r.empresa_id = ?
          AND (r.nro_remito_propio LIKE ? OR r.nro_remito_proveedor LIKE ? OR r.nro_oc LIKE ? OR c.nombre LIKE ?)
        ORDER BY (r.nro_remito_propio = ? OR r.nro_remito_proveedor = ?) DESC,
                 (CAST(SUBSTRING_INDEX(r.nro_remito_propio, '-', -1) AS UNSIGNED) = ?) DESC,
                 (r.nro_remito_propio LIKE ? OR r.nro_remito_proveedor LIKE ?) DESC,
                 r.id DESC
        LIMIT 15
    ");
    $like = "%$q%";
    $st->execute([$eid, $like, $like, $like, $like, $q, $q, $num, "%$q", "%$q"]);
    echo json_encode(['ok' => true, 'remitos' => $st->fetchAll()]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Error al buscar']);
}
