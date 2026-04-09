<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) { echo json_encode([]); exit; }

$stmt = db()->prepare("
    SELECT id, codigo, descripcion, presentacion, bultos_por_pallet
    FROM articulos
    WHERE empresa_id = ? AND activo = 1
      AND (codigo LIKE ? OR descripcion LIKE ?)
    ORDER BY codigo LIMIT 15
");
$stmt->execute([empresa_id(), "$q%", "%$q%"]);
echo json_encode($stmt->fetchAll());
