<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/stock/lista.php')); exit;
}

$db  = db();
$eid = empresa_id();
$uid = $_SESSION['usuario_id'];

$fecha         = $_POST['fecha']         ?? '';
$tipo          = $_POST['tipo']          ?? '';
$observaciones = trim($_POST['observaciones'] ?? '');
$items         = $_POST['items']         ?? [];

$tipos_validos = [
    'carga_inicial','ingreso_remito','ingreso_devolucion',
    'ingreso_expreso','ingreso_stock_seg',
    'salida_entrega','salida_consumo',
    'ajuste_positivo','ajuste_negativo',
];

function redir_error(string $msg, array $post): void {
    $_SESSION['form_error'] = $msg;
    $_SESSION['form_post']  = $post;
    header('Location: ' . url('modules/stock/movimiento_form.php'));
    exit;
}

if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha))
    redir_error('Fecha inválida.', $_POST);

if (!in_array($tipo, $tipos_validos))
    redir_error('Tipo de movimiento inválido.', $_POST);

$filas = [];
foreach ($items as $it) {
    $articulo_id = (int)($it['articulo_id'] ?? 0) ?: null;
    $descripcion = trim($it['descripcion'] ?? '');
    $cantidad    = (float)($it['cantidad'] ?? 0);
    if ($cantidad <= 0) continue;
    $filas[] = ['articulo_id' => $articulo_id, 'descripcion' => $descripcion, 'cantidad' => $cantidad];
}

if (!$filas)
    redir_error('Ingresá al menos un artículo con cantidad mayor a cero.', $_POST);

// Insertar primera fila → su ID es el lote_id para todo el grupo
$stmt = $db->prepare("
    INSERT INTO stock_movimientos
        (empresa_id, lote_id, fecha, tipo, articulo_id, descripcion, cantidad, observaciones, usuario_id)
    VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([$eid, $fecha, $tipo,
    $filas[0]['articulo_id'], $filas[0]['descripcion'],
    $filas[0]['cantidad'], $observaciones ?: null, $uid]);

$lote_id = (int)$db->lastInsertId();

// Actualizar lote_id de la primera fila
$db->prepare("UPDATE stock_movimientos SET lote_id = ? WHERE id = ?")
   ->execute([$lote_id, $lote_id]);

// Insertar filas restantes con el mismo lote_id
if (count($filas) > 1) {
    $stmt2 = $db->prepare("
        INSERT INTO stock_movimientos
            (empresa_id, lote_id, fecha, tipo, articulo_id, descripcion, cantidad, observaciones, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    for ($i = 1; $i < count($filas); $i++) {
        $stmt2->execute([$eid, $lote_id, $fecha, $tipo,
            $filas[$i]['articulo_id'], $filas[$i]['descripcion'],
            $filas[$i]['cantidad'], $observaciones ?: null, $uid]);
    }
}

header('Location: ' . url('modules/stock/comprobante.php?lote=' . $lote_id)); exit;
