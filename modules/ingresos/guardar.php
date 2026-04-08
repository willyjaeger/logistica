<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/ingresos/nuevo.php'));
    exit;
}

$eid = empresa_id();
$db  = db();

$fecha_ingreso = trim($_POST['fecha_ingreso'] ?? '');
$proveedor_id  = !empty($_POST['proveedor_id']) ? (int) $_POST['proveedor_id'] : null;
$transportista = trim($_POST['transportista'] ?? '');
$patente       = strtoupper(trim($_POST['patente_camion_ext'] ?? ''));
$chofer        = trim($_POST['chofer_externo'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');

if (!$proveedor_id) {
    header('Location: ' . url('modules/ingresos/nuevo.php'));
    exit;
}

if ($fecha_ingreso) {
    $fecha_ingreso = str_replace('T', ' ', $fecha_ingreso) . ':00';
} else {
    $fecha_ingreso = date('Y-m-d H:i:s');
}

try {
    $stmt = $db->prepare("
        INSERT INTO ingresos
            (empresa_id, fecha_ingreso, transportista, patente_camion_ext, chofer_externo, observaciones)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $eid,
        $fecha_ingreso,
        $transportista ?: null,
        $patente       ?: null,
        $chofer        ?: null,
        $observaciones ?: null,
    ]);
    $ingreso_id = (int) $db->lastInsertId();

    // Guardar proveedor_id en sesión para pre-llenar en ver.php
    $_SESSION['ultimo_proveedor_id'] = $proveedor_id;

    header('Location: ' . url('modules/ingresos/ver.php') . '?id=' . $ingreso_id . '&nuevo=1');
    exit;

} catch (Throwable $e) {
    error_log('guardar ingreso: ' . $e->getMessage());
    header('Location: ' . url('modules/ingresos/nuevo.php'));
    exit;
}
