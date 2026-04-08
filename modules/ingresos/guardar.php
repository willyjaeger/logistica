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
$fecha_ingreso  = trim($_POST['fecha_ingreso'] ?? '');
$transportista  = trim($_POST['transportista'] ?? '');
$patente        = strtoupper(trim($_POST['patente_camion_ext'] ?? ''));
$chofer         = trim($_POST['chofer_externo'] ?? '');
$observaciones  = trim($_POST['observaciones'] ?? '');

// Convertir datetime-local (Y-m-dTH:i) a Y-m-d H:i:s para MySQL
if ($fecha_ingreso) {
    $fecha_ingreso = str_replace('T', ' ', $fecha_ingreso) . ':00';
} else {
    $fecha_ingreso = date('Y-m-d H:i:s');
}

// ── Remitos ───────────────────────────────────────────────────
$remitos_post = $_POST['remitos'] ?? [];

// Filtrar filas vacías (sin nro propio o sin cliente)
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

// ── Guardar en la base de datos ───────────────────────────────
try {
    $db->beginTransaction();

    // Insertar ingreso
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

    // Insertar remitos
    $stmtR = $db->prepare("
        INSERT INTO remitos
            (ingreso_id, empresa_id, nro_remito_propio, nro_remito_proveedor,
             proveedor_id, cliente_id, fecha_remito, observaciones, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')
    ");

    foreach ($remitos as $r) {
        $nro_propio     = trim($r['nro_remito_propio']);
        $nro_proveedor  = trim($r['nro_remito_proveedor'] ?? '');
        $proveedor_id   = !empty($r['proveedor_id']) ? (int) $r['proveedor_id'] : null;
        $cliente_id     = (int) $r['cliente_id'];
        $fecha_remito   = !empty($r['fecha_remito']) ? $r['fecha_remito'] : null;
        $obs_remito     = trim($r['observaciones'] ?? '');

        $stmtR->execute([
            $ingreso_id,
            $eid,
            $nro_propio,
            $nro_proveedor  ?: null,
            $proveedor_id,
            $cliente_id,
            $fecha_remito,
            $obs_remito     ?: null,
        ]);
    }

    $db->commit();

    header('Location: ' . url('modules/ingresos/ver.php') . '?id=' . $ingreso_id . '&ok=1');
    exit;

} catch (Throwable $e) {
    $db->rollBack();
    $_SESSION['error'] = 'Error al guardar el ingreso. Intentá de nuevo.';
    error_log('guardar ingreso: ' . $e->getMessage());
    header('Location: ' . url('modules/ingresos/nuevo.php'));
    exit;
}
