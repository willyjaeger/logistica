<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$nombre = trim($_POST['nombre'] ?? '');
$cuit   = preg_replace('/[^0-9]/', '', $_POST['cuit'] ?? '');
$dir    = trim($_POST['direccion'] ?? '');
$loc    = trim($_POST['localidad'] ?? '');

if ($nombre === '') {
    echo json_encode(['ok' => false, 'msg' => 'El nombre es obligatorio.']);
    exit;
}

$cuit_guardado = $cuit !== '' ? $cuit : null;

$db = db();
$eid = empresa_id();

// Verificar duplicado por nombre
$dup = $db->prepare("SELECT id FROM clientes WHERE empresa_id=? AND nombre=? LIMIT 1");
$dup->execute([$eid, $nombre]);
if ($dup->fetch()) {
    echo json_encode(['ok' => false, 'msg' => 'Ya existe un cliente con ese nombre.']);
    exit;
}

$db->prepare("INSERT INTO clientes (empresa_id, nombre, cuit, direccion, localidad) VALUES (?,?,?,?,?)")
   ->execute([$eid, $nombre, $cuit_guardado, $dir ?: null, $loc ?: null]);

echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId(), 'nombre' => $nombre]);
