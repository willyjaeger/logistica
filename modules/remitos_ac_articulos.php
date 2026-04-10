<?php
require_once __DIR__ . '/../config/auth.php';
require_login();
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

$q  = trim($_GET['q'] ?? '');
$db = db();

if (strlen($q) < 1) { echo json_encode([]); exit; }

try {
    $stmt = $db->prepare("
        SELECT id, codigo, descripcion, presentacion, bultos_por_pallet
        FROM articulos
        WHERE activo = 1
          AND (codigo LIKE ? OR descripcion LIKE ?)
        ORDER BY codigo LIMIT 15
    ");
    $stmt->execute(["$q%", "%$q%"]);
    echo json_encode($stmt->fetchAll());
} catch (PDOException $e) {
    error_log('ac_articulos error: ' . $e->getMessage());
    echo json_encode([]);
}
