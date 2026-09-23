<?php
// Sirve el archivo de un comprobante. Lo usan tanto los usuarios internos como los de proveedor
// (estos últimos solo pueden ver comprobantes de remitos de su propio proveedor).
require_once __DIR__ . '/../config/auth.php';
require_login();
require_once __DIR__ . '/../modules/_comprobantes_helpers.php';

$db  = db();
$eid = empresa_id();
$id  = (int)($_GET['id'] ?? 0);

$where  = ['rc.id = ?', 'rc.empresa_id = ?'];
$params = [$id, $eid];
if (usuario_rol() === 'proveedor') {
    $where[]  = 'r.proveedor_id = ?';
    $params[] = proveedor_id();
}
$st = $db->prepare("
    SELECT rc.archivo, rc.mime, r.nro_remito_propio
    FROM remito_comprobantes rc
    JOIN remitos r ON r.id = rc.remito_id
    WHERE " . implode(' AND ', $where)
);
$st->execute($params);
$c = $st->fetch();

$abs = $c ? comprobantes_dir() . '/' . $c['archivo'] : '';
if (!$c || !is_file($abs)) {
    http_response_code(404);
    exit('Comprobante no encontrado');
}

$ext      = pathinfo($c['archivo'], PATHINFO_EXTENSION);
$descarga = 'Comprobante_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $c['nro_remito_propio']) . '_' . $id . '.' . $ext;

header('Content-Type: ' . $c['mime']);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $descarga . '"');
header('Cache-Control: private, max-age=86400');
readfile($abs);
