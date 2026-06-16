<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

// Mismos filtros que remitos_lista.php
$q      = trim($_GET['q']      ?? '');
$estado = $_GET['estado']      ?? '';
$desde  = $_GET['desde']       ?? '';
$hasta  = $_GET['hasta']       ?? '';

$where  = ['r.empresa_id = ?'];
$params = [$eid];

if ($q !== '') {
    $where[]  = '(r.nro_remito_propio LIKE ? OR c.nombre LIKE ? OR r.nro_oc LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
if ($estado !== '') {
    $where[]  = 'r.estado = ?';
    $params[] = $estado;
}
if ($desde !== '') {
    $where[]  = 'DATE(i.fecha_ingreso) >= ?';
    $params[] = $desde;
}
if ($hasta !== '') {
    $where[]  = 'DATE(i.fecha_ingreso) <= ?';
    $params[] = $hasta;
}

$sql = "
    SELECT r.id, r.nro_remito_propio, r.fecha_remito, r.estado,
           r.total_pallets, r.nro_oc, r.observaciones, r.fecha_entrega,
           r.fecha_devolucion, r.pallets_devueltos,
           c.nombre     AS cliente,
           p.nombre     AS proveedor,
           i.fecha_ingreso, i.transportista, i.patente_camion_ext,
           t.fecha      AS turno_fecha,
           ef.fecha_salida_real
    FROM remitos r
    JOIN clientes c ON r.cliente_id = c.id
    LEFT JOIN proveedores p ON r.proveedor_id = p.id
    JOIN ingresos i ON r.ingreso_id = i.id
    LEFT JOIN turnos t ON t.remito_id = r.id AND t.empresa_id = r.empresa_id
    LEFT JOIN (
        SELECT er.remito_id, DATE(MAX(en.fecha_salida)) AS fecha_salida_real
        FROM entrega_remitos er
        JOIN entregas en ON en.id = er.entrega_id
        WHERE en.estado IN ('completada','entregado','con_incidencias')
        GROUP BY er.remito_id
    ) ef ON ef.remito_id = r.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.fecha_ingreso DESC, r.id DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$remitos = $stmt->fetchAll();

// Ítems de los remitos encontrados
$items_map = [];
if ($remitos) {
    $ids = implode(',', array_column($remitos, 'id'));
    $ri  = $db->query("
        SELECT ri.remito_id, ri.descripcion, ri.cantidad, ri.pallets, a.codigo
        FROM remito_items ri
        LEFT JOIN articulos a ON ri.articulo_id = a.id
        WHERE ri.remito_id IN ($ids)
        ORDER BY ri.id
    ");
    foreach ($ri->fetchAll() as $row) {
        $items_map[$row['remito_id']][] = $row;
    }
}

$estado_label = [
    'pendiente'              => 'Pendiente',
    'turnado'                => 'Turnado',
    'programado'             => 'Programado',
    'en_camino'              => 'En camino',
    'entregado'              => 'Entregado',
    'parcialmente_entregado' => 'Parcial',
    'en_stock'               => 'En stock',
    'cancelado'              => 'Cancelado',
];

// Nombre de archivo con fecha y filtros aplicados
$nombre = 'remitos';
if ($desde || $hasta) $nombre .= '_' . ($desde ?: 'inicio') . '_' . ($hasta ?: 'fin');
if ($estado) $nombre .= '_' . $estado;
$nombre .= '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'w');

// BOM UTF-8 para que Excel lo abra correctamente
fwrite($out, "\xEF\xBB\xBF");

// Encabezados
fputcsv($out, [
    'Fecha ingreso',
    'Nro remito',
    'Cliente',
    'Proveedor',
    'Transportista',
    'Pallets',
    'Estado',
    'Nro OC',
    'F. programada',
    'F. efectiva',
    'Observaciones',
    'Artículos',
], ';');

foreach ($remitos as $r) {
    $items     = $items_map[$r['id']] ?? [];
    $items_str = implode(' | ', array_map(function($it) {
        $cod = $it['codigo'] ? '[' . $it['codigo'] . '] ' : '';
        $pal = $it['pallets'] > 0 ? ' (' . number_format($it['pallets'], 2) . ' pal.)' : '';
        return $cod . $it['descripcion'] . ' x' . (int)$it['cantidad'] . $pal;
    }, $items));

    fputcsv($out, [
        $r['fecha_ingreso'] ? date('d/m/Y', strtotime($r['fecha_ingreso'])) : '',
        $r['nro_remito_propio'],
        $r['cliente'],
        $r['proveedor'] ?? '',
        $r['transportista'] ?? '',
        $r['total_pallets'] > 0 ? number_format($r['total_pallets'], 2, ',', '') : '',
        $estado_label[$r['estado']] ?? $r['estado'],
        $r['nro_oc'] ?? '',
        $r['fecha_entrega']    ? date('d/m/Y', strtotime($r['fecha_entrega']))    : '',
        $r['fecha_salida_real'] ? date('d/m/Y', strtotime($r['fecha_salida_real'])) : '',
        $r['observaciones'] ?? '',
        $items_str,
    ], ';');
}

fclose($out);
exit;
