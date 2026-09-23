<?php
// AJAX: elimina un comprobante (registro + archivo)
require_once __DIR__ . '/../config/auth.php';
require_login();
require_once __DIR__ . '/_comprobantes_helpers.php';
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false]); exit; }

$db  = db();
$eid = empresa_id();
$id  = (int)($_POST['id'] ?? 0);

$st = $db->prepare("SELECT archivo FROM remito_comprobantes WHERE id = ? AND empresa_id = ?");
$st->execute([$id, $eid]);
$archivo = $st->fetchColumn();
if ($archivo === false) { echo json_encode(['ok' => false, 'error' => 'No encontrado']); exit; }

$db->prepare("DELETE FROM remito_comprobantes WHERE id = ? AND empresa_id = ?")->execute([$id, $eid]);
$abs = comprobantes_dir() . '/' . $archivo;
if (is_file($abs)) unlink($abs);

echo json_encode(['ok' => true]);
