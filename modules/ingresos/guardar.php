<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/ingresos/nuevo.php'));
    exit;
}

$eid = empresa_id();
$db  = db();

// ── Datos del camión ──────────────────────────────────────────
$fecha_ingreso = trim($_POST['fecha_ingreso'] ?? '');
$proveedor_id  = !empty($_POST['proveedor_id']) ? (int) $_POST['proveedor_id'] : null;
$transportista = trim($_POST['transportista'] ?? '');
$patente       = strtoupper(trim($_POST['patente_camion_ext'] ?? ''));
$chofer        = trim($_POST['chofer_externo'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');

if ($fecha_ingreso) {
    $fecha_ingreso = str_replace('T', ' ', $fecha_ingreso) . ':00';
} else {
    $fecha_ingreso = date('Y-m-d H:i:s');
}

// Validar proveedor requerido
if (!$proveedor_id) {
    $_SESSION['error'] = 'Debe seleccionar un proveedor.';
    header('Location: ' . url('modules/ingresos/nuevo.php'));
    exit;
}

// ── Remitos ───────────────────────────────────────────────────
$remitos_post = $_POST['remitos'] ?? [];
$remitos = [];
foreach ($remitos_post as $r) {
    $nro_propio = trim($r['nro_remito_propio'] ?? '');
    $cliente_id = (int) ($r['cliente_id'] ?? 0);
    if ($nro_propio === '' || $cliente_id === 0) continue;
    $remitos[] = $r;
}

if (empty($remitos)) {
    $_SESSION['error'] = 'Debe haber al menos un remito con número y cliente.';
    header('Location: ' . url('modules/ingresos/nuevo.php'));
    exit;
}

// ── Guardar ───────────────────────────────────────────────────
try {
    $db->beginTransaction();

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

    $stmtR = $db->prepare("
        INSERT INTO remitos
            (ingreso_id, empresa_id, nro_remito_propio, nro_remito_proveedor,
             proveedor_id, cliente_id, fecha_remito, observaciones, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')
    ");

    foreach ($remitos as $r) {
        $stmtR->execute([
            $ingreso_id,
            $eid,
            trim($r['nro_remito_propio']),
            trim($r['nro_remito_proveedor'] ?? '') ?: null,
            $proveedor_id,   // mismo proveedor para todos los remitos del ingreso
            (int) $r['cliente_id'],
            !empty($r['fecha_remito']) ? $r['fecha_remito'] : null,
            trim($r['observaciones'] ?? '') ?: null,
        ]);
    }

    $db->commit();

    header('Location: ' . url('modules/ingresos/ver.php') . '?id=' . $ingreso_id . '&ok=1');
    exit;

} catch (Throwable $e) {
    $db->rollBack();
    error_log('guardar ingreso: ' . $e->getMessage());
    $_SESSION['error'] = 'Error al guardar el ingreso. Intentá de nuevo.';
    header('Location: ' . url('modules/ingresos/nuevo.php'));
    exit;
}
