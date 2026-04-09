<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/remitos/lista.php'));
    exit;
}

$id  = (int)($_POST['id'] ?? 0);
$db  = db();
$eid = empresa_id();

if ($id <= 0) {
    header('Location: ' . url('modules/remitos/lista.php'));
    exit;
}

// Verificar que pertenece a esta empresa
$stmt = $db->prepare("SELECT ingreso_id FROM remitos WHERE id = ? AND empresa_id = ?");
$stmt->execute([$id, $eid]);
$rem = $stmt->fetch();
if (!$rem) {
    header('Location: ' . url('modules/remitos/lista.php'));
    exit;
}

// Eliminar ítems del remito
$db->prepare("DELETE FROM remito_items WHERE remito_id = ?")->execute([$id]);

// Eliminar remito
$db->prepare("DELETE FROM remitos WHERE id = ? AND empresa_id = ?")->execute([$id, $eid]);

// Si el ingreso quedó sin remitos, eliminarlo también
$cnt = $db->prepare("SELECT COUNT(*) FROM remitos WHERE ingreso_id = ?");
$cnt->execute([$rem['ingreso_id']]);
if ((int)$cnt->fetchColumn() === 0) {
    $db->prepare("DELETE FROM ingresos WHERE id = ? AND empresa_id = ?")->execute([$rem['ingreso_id'], $eid]);
}

header('Location: ' . url('modules/remitos/lista.php') . '?borrado=1');
exit;
