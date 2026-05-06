<?php
/**
 * Generador de íconos PWA — ejecutar una sola vez.
 * Usa assets/img/logo-source.png tal cual (sin modificar colores ni fondo).
 * Genera: icon-192.png, icon-512.png, favicon.ico
 */
require_once __DIR__ . '/config/auth.php';
require_login();
if (!es_admin()) { http_response_code(403); exit('Solo administradores.'); }

$src_path = __DIR__ . '/assets/img/logo-source.png';

if (!file_exists($src_path)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>No existe <code>assets/img/logo-source.png</code>.</p>';
    exit;
}

function make_icon(string $src, string $dest, int $size): string {
    $orig = imagecreatefrompng($src);
    if (!$orig) return "Error leyendo la imagen fuente.";

    $sw = imagesx($orig);
    $sh = imagesy($orig);

    // Lienzo cuadrado con fondo blanco
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);

    // Escalar el logo manteniendo proporción, con un margen del 5%
    $margin = (int)($size * 0.05);
    $area   = $size - $margin * 2;
    $scale  = min($area / $sw, $area / $sh);
    $dw     = (int)($sw * $scale);
    $dh     = (int)($sh * $scale);
    $dx     = (int)(($size - $dw) / 2);
    $dy     = (int)(($size - $dh) / 2);

    imagecopyresampled($out, $orig, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
    imagepng($out, $dest, 9);
    imagedestroy($orig);
    imagedestroy($out);
    return "OK — {$size}×{$size} → " . basename($dest);
}

function make_favicon(string $src, string $dest): string {
    $orig = imagecreatefrompng($src);
    if (!$orig) return "Error leyendo imagen para favicon.";
    $size = 48;
    $out  = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);
    $sw = imagesx($orig); $sh = imagesy($orig);
    $margin = (int)($size * 0.05);
    $area   = $size - $margin * 2;
    $scale  = min($area / $sw, $area / $sh);
    $dw = (int)($sw * $scale); $dh = (int)($sh * $scale);
    $dx = (int)(($size - $dw) / 2); $dy = (int)(($size - $dh) / 2);
    imagecopyresampled($out, $orig, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
    ob_start(); imagepng($out); $png = ob_get_clean();
    imagedestroy($orig); imagedestroy($out);
    $plen = strlen($png);
    $ico  = pack('vvv', 0, 1, 1);
    $ico .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, $plen, 22);
    $ico .= $png;
    file_put_contents($dest, $ico);
    return "OK — favicon.ico (48×48)";
}

$results = [];
$results[] = make_icon($src_path, __DIR__ . '/assets/img/icon-192.png', 192);
$results[] = make_icon($src_path, __DIR__ . '/assets/img/icon-512.png', 512);
$results[] = make_favicon($src_path, __DIR__ . '/favicon.ico');

header('Content-Type: text/html; charset=utf-8');
echo '<style>body{font-family:sans-serif;padding:40px;max-width:500px}
li{margin:8px 0;font-size:15px}.ok{color:#166534}.er{color:#991b1b}</style>';
echo '<h2>Íconos generados</h2><ul>';
foreach ($results as $r) {
    echo '<li class="' . (str_starts_with($r, 'OK') ? 'ok' : 'er') . '">'
       . htmlspecialchars($r) . '</li>';
}
echo '</ul>';
echo '<p style="margin-top:16px;color:#555">Podés borrar este archivo del servidor.</p>';
echo '<p><a href="' . (defined('BASE_URL') ? BASE_URL : '/ops') . '/index.php">Ir al panel</a></p>';
