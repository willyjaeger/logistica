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
$entrega_fisica = isset($_POST['entrega_fisica']) ? 1 : 0; // checkbox: 1=física, 0=virtual

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
            fecha_remito = ?, nro_oc = ?, observaciones = ?, total_pallets = ?, entrega_fisica = ?
        WHERE id = ? AND empresa_id = ?
    ")->execute([
        $nro_remito, $proveedor_id, $cliente_id,
        $fecha_remito, $nro_oc ?: null, $observaciones ?: null, $total_pallets, $entrega_fisica,
        $remito_id, $eid
    ]);

    // Eliminar ítems existentes (solo pendientes para evitar FK issues)
    $db->prepare("DELETE FROM remito_items WHERE remito_id = ? AND estado = 'pendiente'")
       ->execute([$remito_id]);

} else {
    $db->prepare("
        INSERT INTO remitos
            (ingreso_id, empresa_id, nro_remito_propio, proveedor_id, cliente_id,
             fecha_remito, estado, nro_oc, observaciones, total_pallets, entrega_fisica)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $ingreso_id, $eid, $nro_remito, $proveedor_id, $cliente_id,
        $fecha_remito, 'pendiente', $nro_oc ?: null, $observaciones ?: null, $total_pallets, $entrega_fisica
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

// ── Turno de entrega ──────────────────────────────────────────
$turno_id_post      = $_POST['turno_id'] ?? '0';
$nuevo_turno_fecha  = trim($_POST['nuevo_turno_fecha'] ?? '');
$nuevo_turno_hora   = trim($_POST['nuevo_turno_hora']  ?? '') ?: null;
$nuevo_turno_tipo   = in_array($_POST['nuevo_turno_tipo'] ?? '', ['turno','programado'])
                      ? $_POST['nuevo_turno_tipo'] : 'turno';

// Desvinculat turno previo si el remito tenía uno y ahora cambia
$st_prev = $db->prepare("SELECT id FROM turnos WHERE remito_id=? AND empresa_id=?");
$st_prev->execute([$remito_id, $eid]);
$turno_previo = $st_prev->fetch();
$turno_previo_id = $turno_previo ? (int)$turno_previo['id'] : 0;

if ($turno_id_post === 'nuevo' && $nuevo_turno_fecha !== '') {
    // Desvincular turno previo si existe y es diferente al que vamos a crear
    if ($turno_previo_id) {
        $db->prepare("UPDATE turnos SET remito_id=NULL WHERE id=? AND empresa_id=?")
           ->execute([$turno_previo_id, $eid]);
        $db->prepare("UPDATE remitos SET estado='pendiente' WHERE id=? AND empresa_id=?")
           ->execute([$remito_id, $eid]);
    }
    // Crear nuevo turno
    $estado_rem = $nuevo_turno_tipo === 'turno' ? 'turnado' : 'programado';
    $db->prepare("
        INSERT INTO turnos (empresa_id, fecha, tipo, hora_turno, cliente_id, remito_id)
        VALUES (?,?,?,?,?,?)
    ")->execute([$eid, $nuevo_turno_fecha, $nuevo_turno_tipo, $nuevo_turno_hora, $cliente_id ?: null, $remito_id]);
    $db->prepare("UPDATE remitos SET estado=?, fecha_entrega=? WHERE id=? AND empresa_id=?")
       ->execute([$estado_rem, $nuevo_turno_fecha, $remito_id, $eid]);

} elseif ((int)$turno_id_post > 0) {
    $nuevo_tid = (int)$turno_id_post;
    if ($nuevo_tid !== $turno_previo_id) {
        // Desvincular turno previo
        if ($turno_previo_id) {
            $db->prepare("UPDATE turnos SET remito_id=NULL WHERE id=? AND empresa_id=?")
               ->execute([$turno_previo_id, $eid]);
        }
        // Verificar que el turno seleccionado pertenece a la empresa y está libre
        $st_t = $db->prepare("SELECT tipo FROM turnos WHERE id=? AND empresa_id=? AND (remito_id IS NULL OR remito_id=?)");
        $st_t->execute([$nuevo_tid, $eid, $remito_id]);
        $t_row = $st_t->fetch();
        if ($t_row) {
            $estado_rem = $t_row['tipo'] === 'turno' ? 'turnado' : 'programado';
            $db->prepare("UPDATE turnos SET remito_id=? WHERE id=? AND empresa_id=?")
               ->execute([$remito_id, $nuevo_tid, $eid]);
            // Obtener fecha del turno para fecha_entrega
            $st_tf = $db->prepare("SELECT fecha FROM turnos WHERE id=?");
            $st_tf->execute([$nuevo_tid]);
            $t_fecha = $st_tf->fetchColumn();
            $db->prepare("UPDATE remitos SET estado=?, fecha_entrega=? WHERE id=? AND empresa_id=?")
               ->execute([$estado_rem, $t_fecha, $remito_id, $eid]);
        }
    }
} elseif ((int)$turno_id_post === 0 && $turno_previo_id) {
    // El usuario eligió "sin turno": desvincular turno previo
    $db->prepare("UPDATE turnos SET remito_id=NULL WHERE id=? AND empresa_id=?")
       ->execute([$turno_previo_id, $eid]);
    $db->prepare("UPDATE remitos SET estado='pendiente', fecha_entrega=NULL WHERE id=? AND empresa_id=?")
       ->execute([$remito_id, $eid]);
}

// ── Stock: generar movimiento de ingreso (solo si entrega física) ─
// Eliminar ingreso previo para este remito (re-guardar limpia y recrea)
$db->prepare("DELETE FROM stock_movimientos WHERE remito_id = ? AND empresa_id = ? AND tipo = 'ingreso_remito'")
   ->execute([$remito_id, $eid]);

if ($entrega_fisica) {
    $si = $db->prepare("
        SELECT ri.articulo_id, COALESCE(a.descripcion, ri.descripcion) AS descripcion, ri.cantidad
        FROM remito_items ri
        LEFT JOIN articulos a ON a.id = ri.articulo_id
        WHERE ri.remito_id = ? AND ri.articulo_id IS NOT NULL AND ri.cantidad > 0
    ");
    $si->execute([$remito_id]);
    $stock_items = $si->fetchAll();

    if ($stock_items) {
        $uid_stock = $_SESSION['usuario_id'];
        $obs_stock = 'Remito ' . $nro_remito;
        $ins = $db->prepare("
            INSERT INTO stock_movimientos
                (empresa_id, lote_id, fecha, tipo, articulo_id, descripcion, cantidad, remito_id, observaciones, usuario_id)
            VALUES (?, NULL, ?, 'ingreso_remito', ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$eid, $fecha_remito, $stock_items[0]['articulo_id'],
            $stock_items[0]['descripcion'], $stock_items[0]['cantidad'],
            $remito_id, $obs_stock, $uid_stock]);
        $lote_stock = (int)$db->lastInsertId();
        $db->prepare("UPDATE stock_movimientos SET lote_id = ? WHERE id = ?")->execute([$lote_stock, $lote_stock]);

        for ($i = 1; $i < count($stock_items); $i++) {
            $ins->execute([$eid, $fecha_remito, $stock_items[$i]['articulo_id'],
                $stock_items[$i]['descripcion'], $stock_items[$i]['cantidad'],
                $remito_id, $obs_stock, $uid_stock]);
            $new_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE stock_movimientos SET lote_id = ? WHERE id = ?")->execute([$lote_stock, $new_id]);
        }
    }
}

// ── Redirección ───────────────────────────────────────────────
$accion = $_POST['accion'] ?? 'guardar';
if ($accion === 'guardar_y_otro') {
    header('Location: ' . url('modules/remitos_form.php') . '?ok=1');
} else {
    header('Location: ' . url('modules/remitos_lista.php') . '?ok=1');
}
exit;
