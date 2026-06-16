<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();
$pid  = (int)($_GET['proveedor_id'] ?? 0);
$mes  = max(1, min(12, (int)($_GET['mes']  ?? date('n'))));
$anio = max(2020, (int)($_GET['anio'] ?? date('Y')));

$result = ['precio_pos' => 0, 'precio_viaje' => 0, 'precio_modo' => 'camion', 'found' => false];

if ($pid > 0) {
    try {
        // Primero: mes/año exacto
        $st = $db->prepare("SELECT precio_pos, precio_viaje, precio_modo FROM cc_precios
                            WHERE empresa_id=? AND proveedor_id=? AND anio=? AND mes=?");
        $st->execute([$eid, $pid, $anio, $mes]);
        $row = $st->fetch();
        if (!$row) {
            // Fallback: último mes guardado
            $st2 = $db->prepare("SELECT precio_pos, precio_viaje, precio_modo FROM cc_precios
                                 WHERE empresa_id=? AND proveedor_id=?
                                 ORDER BY anio DESC, mes DESC LIMIT 1");
            $st2->execute([$eid, $pid]);
            $row = $st2->fetch();
        }
        if ($row) {
            $result = [
                'precio_pos'   => (float)$row['precio_pos'],
                'precio_viaje' => (float)$row['precio_viaje'],
                'precio_modo'  => $row['precio_modo'],
                'found'        => true,
            ];
        }
    } catch (Exception $e) {}
}

header('Content-Type: application/json');
echo json_encode($result);
