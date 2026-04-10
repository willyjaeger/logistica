<?php
require_once __DIR__ . '/../config/auth.php';
require_login();
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

$q  = trim($_GET['q'] ?? '');
$db = db();

try {
    if ($q === '') {
        $stmt = $db->prepare("
            SELECT id, nombre, IFNULL(cuit,'') AS cuit
            FROM clientes
            WHERE activo = 1
            ORDER BY nombre LIMIT 20
        ");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("
            SELECT id, nombre, IFNULL(cuit,'') AS cuit
            FROM clientes
            WHERE activo = 1
              AND (nombre LIKE ? OR cuit LIKE ?)
            ORDER BY nombre LIMIT 12
        ");
        $stmt->execute(["%$q%", "%$q%"]);
    }
    echo json_encode($stmt->fetchAll());
} catch (PDOException $e) {
    // Sin columna cuit
    try {
        if ($q === '') {
            $stmt = $db->prepare("
                SELECT id, nombre, '' AS cuit
                FROM clientes
                WHERE activo = 1
                ORDER BY nombre LIMIT 20
            ");
            $stmt->execute();
        } else {
            $stmt = $db->prepare("
                SELECT id, nombre, '' AS cuit
                FROM clientes
                WHERE activo = 1 AND nombre LIKE ?
                ORDER BY nombre LIMIT 12
            ");
            $stmt->execute(["%$q%"]);
        }
        echo json_encode($stmt->fetchAll());
    } catch (PDOException $e2) {
        error_log('ac_clientes error: ' . $e2->getMessage());
        echo json_encode([]);
    }
}
