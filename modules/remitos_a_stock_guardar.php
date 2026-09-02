<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/_stock_helpers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/remitos_a_stock.php'));
    exit;
}

$db   = db();
$eid  = empresa_id();
$uid  = (int)$_SESSION['usuario_id'];
$q    = trim($_POST['q'] ?? '');
$ids  = array_unique(array_filter(array_map('intval', $_POST['remito_ids'] ?? [])));

$movidos = 0;
foreach ($ids as $remito_id) {
    if (pasar_remito_a_stock($db, $eid, $remito_id, $uid)) $movidos++;
}

$qs = $q !== '' ? '?q=' . urlencode($q) : '';
header('Location: ' . url('modules/remitos_lista.php') . '?stock=' . $movidos . ($qs ? '&' . ltrim($qs, '?') : ''));
exit;
