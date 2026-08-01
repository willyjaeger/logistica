<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método inválido']);
    exit;
}

if (!es_admin()) {
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

$db  = db();
$eid = empresa_id();

$id    = (int)($_POST['id'] ?? 0);
$costo = trim($_POST['costo'] ?? '');
$costo = str_replace(',', '.', $costo);

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'Falta id']);
    exit;
}

if ($costo === '') {
    $valor = null;
} elseif (is_numeric($costo) && $costo >= 0) {
    $valor = round((float)$costo, 2);
} else {
    echo json_encode(['ok' => false, 'error' => 'Costo inválido']);
    exit;
}

$stmt = $db->prepare("UPDATE entregas SET costo_transporte = ? WHERE id = ? AND empresa_id = ?");
$stmt->execute([$valor, $id, $eid]);

echo json_encode(['ok' => true, 'id' => $id, 'costo' => $valor]);
exit;
