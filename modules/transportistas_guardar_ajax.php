<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método inválido']);
    exit;
}

$db  = db();
$eid = empresa_id();

$nombre   = trim($_POST['nombre']   ?? '');
$cuit     = trim($_POST['cuit']     ?? '');
$telefono = trim($_POST['telefono'] ?? '');

if ($nombre === '') {
    echo json_encode(['ok' => false, 'error' => 'El nombre es obligatorio']);
    exit;
}

$db->prepare("
    INSERT INTO transportistas (empresa_id, nombre, cuit, telefono)
    VALUES (?, ?, ?, ?)
")->execute([$eid, $nombre, $cuit ?: null, $telefono ?: null]);

$nuevo_id = (int)$db->lastInsertId();

echo json_encode(['ok' => true, 'id' => $nuevo_id, 'nombre' => $nombre]);
exit;
