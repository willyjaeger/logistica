<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/_stock_helpers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('modules/remitos_lista.php'));
    exit;
}

$db        = db();
$eid       = empresa_id();
$remito_id = (int)($_POST['id'] ?? 0);
$back      = trim($_POST['back'] ?? '');

function redir_lista_stock(string $back, bool $ok = false): void {
    $qs = $ok ? 'stock=1' : '';
    if ($back) $qs .= ($qs ? '&' : '') . $back;
    header('Location: ' . url('modules/remitos_lista.php') . ($qs ? '?' . $qs : ''));
    exit;
}

if (!$remito_id) {
    redir_lista_stock($back);
}

$ok = pasar_remito_a_stock($db, $eid, $remito_id, (int)$_SESSION['usuario_id']);
redir_lista_stock($back, $ok);
