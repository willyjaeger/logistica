<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/remitos_lista.php'));
    exit;
}

$db  = db();
$eid = empresa_id();

// ── Datos de transporte ───────────────────────────────────────
$proveedor_id  = (int)($_POST['proveedor_id'] ?? 0) ?: null;
$transportista = trim($_POST['transportista'] ?? '');
$patente       = trim($_POST['patente'] ?? '');
$chofer        = trim($_POST['chofer'] ?? '');
$fecha_entrada = $_POST['fecha_entrada'] ?? date('Y-m-d');

// ── Datos del remito ──────────────────────────────────────────
$remito_id     = (int)($_POST['remito_id'] ?? 0);
$punto_venta   = str_pad(preg_replace('/\D/', '', $_POST['punto_venta'] ?? '1'), 5, '0', STR_PAD_LEFT);
$nro_num       = str_pad(preg_replace('/\D/', '', $_POST['nro_num'] ?? ''), 8, '0', STR_PAD_LEFT);
$nro_remito    = 'R-' . $punto_venta . '-' . $nro_num;
$cliente_id    = (int)($_POST['cliente_id'] ?? 0);
$fecha_remito  = $_POST['fecha_remito'] ?? date('Y-m-d');
$nro_oc        = trim($_POST['nro_oc'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');
$total_pallets = (float)str_replace(',', '.', $_POST['total_pallets'] ?? '0');

// ── Validación básica ─────────────────────────────────────────
$errores = [];
if ($cliente_id <= 0)  $errores[] = 'Seleccioná un cliente.';
if ($nro_num === '00000000') $errores[] = 'Ingresá el número de remito.';

if ($errores) {
    $_SESSION['form_error'] = implode(' ', $errores);
    $_SESSION['form_post']  = $_POST;
    $back = $remito_id > 0
        ? url("modules/remitos_form.php?id=$remito_id")
        : url('modules/remitos_form.php');
    header('Location: ' . $back);
    exit;
}

// ── Ingreso (transporte) ──────────────────────────────────────
if ($remito_id > 0) {
    // Edición: recuperar ingreso_id existente
    $stmt = $db->prepare("SELECT ingreso_id FROM remitos WHERE id = ? AND empresa_id = ?");
    $stmt->execute([$remito_id, $eid]);
    $row = $stmt->fetch();
    if (!$row) {
        header('Location: ' . url('modules/remitos_lista.php'));
        exit;
    }
    $ingreso_id = $row['ingreso_id'];

    // Actualizar datos de transporte
    $db->prepare("
        UPDATE ingresos
        SET transportista = ?, patente_camion_ext = ?, chofer_externo = ?, fecha_ingreso = ?
        WHERE id = ? AND empresa_id = ?
    ")->execute([$transportista, $patente, $chofer, $fecha_entrada . ' 00:00:00', $ingreso_id, $eid]);

} else {
    // Nuevo: reusar ingreso de sesión si coincide, sino crear uno nuevo
    $sess = $_SESSION['ingreso_actual'] ?? null;
    $mismo = $sess &&
             (int)($sess['proveedor_id'] ?? 0) === (int)($proveedor_id ?? 0) &&
             ($sess['transportista'] ?? '') === $transportista &&
             ($sess['patente']       ?? '') === $patente &&
             ($sess['chofer']        ?? '') === $chofer &&
             ($sess['fecha_entrada'] ?? '') === $fecha_entrada;

    if ($mismo && !empty($sess['id'])) {
        $ingreso_id = (int)$sess['id'];
    } else {
        $db->prepare("
            INSERT INTO ingresos (empresa_id, fecha_ingreso, transportista, patente_camion_ext, chofer_externo)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$eid, $fecha_entrada . ' 00:00:00', $transportista, $patente, $chofer]);
        $ingreso_id = (int)$db->lastInsertId();
    }

    // Guardar en sesión para el próximo remito
    $_SESSION['ingreso_actual'] = [
        'id'           => $ingreso_id,
        'proveedor_id' => $proveedor_id,
        'transportista'=> $transportista,
        'patente'      => $patente,
        'chofer'       => $chofer,
        'fecha_entrada'=> $fecha_entrada,
    ];
}

// ── Guardar remito ────────────────────────────────────────────
if ($remito_id > 0) {
    $db->prepare("
        UPDATE remitos
        SET nro_remito_propio = ?, proveedor_id = ?, cliente_id = ?,
            fecha_remito = ?, nro_oc = ?, observaciones = ?, total_pallets = ?
        WHERE id = ? AND empresa_id = ?
    ")->execute([
        $nro_remito, $proveedor_id, $cliente_id,
        $fecha_remito, $nro_oc ?: null, $observaciones ?: null, $total_pallets,
        $remito_id, $eid
    ]);

    // Eliminar ítems existentes (solo pendientes para evitar FK issues)
    $db->prepare("DELETE FROM remito_items WHERE remito_id = ? AND estado = 'pendiente'")
       ->execute([$remito_id]);

} else {
    $db->prepare("
        INSERT INTO remitos
            (ingreso_id, empresa_id, nro_remito_propio, proveedor_id, cliente_id,
             fecha_remito, estado, nro_oc, observaciones, total_pallets)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $ingreso_id, $eid, $nro_remito, $proveedor_id, $cliente_id,
        $fecha_remito, 'pendiente', $nro_oc ?: null, $observaciones ?: null, $total_pallets
    ]);
    $remito_id = (int)$db->lastInsertId();
}

// ── Guardar ítems ─────────────────────────────────────────────
$items = $_POST['items'] ?? [];
foreach ($items as $it) {
    $desc  = trim($it['descripcion'] ?? '');
    $cant  = (float)str_replace(',', '.', $it['cantidad'] ?? '0');
    $bpp   = (float)str_replace(',', '.', $it['bultos_por_pallet'] ?? '0');
    $art_id = (int)($it['articulo_id'] ?? 0) ?: null;

    if ($desc === '' && !$art_id) continue;
    if ($cant <= 0) continue;

    $pallets_item = ($bpp > 0) ? ($cant / $bpp) : 0;

    $db->prepare("
        INSERT INTO remito_items (remito_id, articulo_id, descripcion, cantidad, pallets, estado)
        VALUES (?, ?, ?, ?, ?, 'pendiente')
    ")->execute([$remito_id, $art_id, $desc, $cant, round($pallets_item, 4)]);
}

// ── Redirección ───────────────────────────────────────────────
$accion = $_POST['accion'] ?? 'guardar';
if ($accion === 'guardar_y_otro') {
    header('Location: ' . url('modules/remitos_form.php') . '?ok=1');
} else {
    header('Location: ' . url('modules/remitos_lista.php') . '?ok=1');
}
exit;
