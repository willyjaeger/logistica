<?php
// AJAX: sube un comprobante (escaneo / foto / archivo) y lo vincula a un remito
require_once __DIR__ . '/../config/auth.php';
require_login();
require_once __DIR__ . '/_comprobantes_helpers.php';
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

function responder(bool $ok, array $extra = []): never
{
    echo json_encode(['ok' => $ok] + $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(false, ['error' => 'Método inválido']);

$db        = db();
$eid       = empresa_id();
$remito_id = (int)($_POST['remito_id'] ?? 0);
$origen    = in_array($_POST['origen'] ?? '', ['escaner', 'foto', 'archivo']) ? $_POST['origen'] : 'archivo';

$st = $db->prepare("SELECT id FROM remitos WHERE id = ? AND empresa_id = ?");
$st->execute([$remito_id, $eid]);
if (!$st->fetch()) responder(false, ['error' => 'Remito no encontrado']);

$f = $_FILES['archivo'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE]) ? 'El archivo es demasiado grande' : 'No se recibió el archivo';
    responder(false, ['error' => $msg]);
}
if ($f['size'] > COMPROBANTE_MAX_BYTES) responder(false, ['error' => 'El archivo supera los 15 MB']);

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
if (!isset(COMPROBANTE_MIMES[$mime])) responder(false, ['error' => 'Formato no admitido (usar JPG, PNG o PDF)']);

$rel_dir = $eid . '/' . date('Y') . '/' . date('m');
$abs_dir = comprobantes_dir() . '/' . $rel_dir;
if (!is_dir($abs_dir) && !mkdir($abs_dir, 0755, true)) responder(false, ['error' => 'No se pudo crear la carpeta de destino']);

$nombre = $remito_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . COMPROBANTE_MIMES[$mime];
if (!move_uploaded_file($f['tmp_name'], "$abs_dir/$nombre")) responder(false, ['error' => 'No se pudo guardar el archivo']);

$db->prepare("
    INSERT INTO remito_comprobantes (empresa_id, remito_id, archivo, nombre_original, mime, tamano, origen, subido_por)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
")->execute([$eid, $remito_id, "$rel_dir/$nombre", mb_substr($f['name'] ?? '', 0, 255), $mime,
             (int)$f['size'], $origen, $_SESSION['usuario_id']]);

responder(true, ['id' => (int)$db->lastInsertId()]);
