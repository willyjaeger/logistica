<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

$stmt = db()->prepare("
    SELECT id, nombre, IFNULL(cuit,'') AS cuit
    FROM clientes
    WHERE empresa_id = ? AND activo = 1
      AND (nombre LIKE ? OR cuit LIKE ?)
    ORDER BY nombre LIMIT 12
");
$stmt->execute([empresa_id(), "%$q%", "%$q%"]);
echo json_encode($stmt->fetchAll());
