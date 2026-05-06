<?php
/**
 * Generador de íconos PWA — ejecutar una sola vez.
 *
 * 1. Guardá el logo original como: assets/img/logo-source.png
 * 2. Visitá esta URL:  /ops/gen_icons.php
 * 3. Se generarán:
 *      assets/img/icon-192.png   (192×192, fondo azul)
 *      assets/img/icon-512.png   (512×512, fondo azul, maskable)
 *      favicon.ico               (48×48 embebido)
 */
require_once __DIR__ . '/config/auth.php';
require_login();
if (!es_admin()) { http_response_code(403); exit('Solo administradores.'); }

$src_path = __DIR__ . '/assets/img/logo-source.png';

if (!file_exists($src_path)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<style>body{font-family:sans-serif;padding:40px;max-width:600px;margin:0 auto}</style>';
    echo '<h2>Paso previo requerido</h2>';
    echo '<p>Guardá el logo original como:</p>';
    echo '<code style="background:#eee;padding:8px 14px;border-radius:6px;display:block;font-size:15px">';
    echo htmlspecialchars(__DIR__ . '/assets/img/logo-source.png');
    echo '</code>';
    echo '<p style="margin-top:20px">Luego recargá esta página.</p>';
    exit;
}

function make_icon(string $src, string $dest, int $size, bool $maskable = false): string {
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    if ($ext === 'png')      $orig = imagecreatefrompng($src);
    elseif ($ext === 'jpg')  $orig = imagecreatefromjpeg($src);
    else return "Formato no soportado: $ext";
    if (!$orig) return "No se pudo leer la imagen fuente.";

    $sw = imagesx($orig);
    $sh = imagesy($orig);

    // Fondo azul oscuro
    $out = imagecreatetruecolor($size, $size);
    $bg  = imagecolorallocate($out, 15, 40, 80); // #0f2850
    imagefill($out, 0, 0, $bg);

    // Área del logo: 80% del ícono (maskable necesita 72% para zona segura)
    $pct  = $maskable ? 0.68 : 0.80;
    $area = (int)($size * $pct);

    // Escalar manteniendo proporción
    $scale = min($area / $sw, $area / $sh);
    $dw    = (int)($sw * $scale);
    $dh    = (int)($sh * $scale);
    $dx    = (int)(($size - $dw) / 2);
    $dy    = (int)(($size - $dh) / 2);

    imagecopyresampled($out, $orig, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
    imagepng($out, $dest, 9);
    imagedestroy($orig);
    imagedestroy($out);
    return "OK — $size×$size → " . basename($dest);
}

$results = [];
$results[] = make_icon($src_path, __DIR__ . '/assets/img/icon-192.png', 192, false);
$results[] = make_icon($src_path, __DIR__ . '/assets/img/icon-512.png', 512, true);

// favicon 48×48 (PNG inside ICO container — basic 1-image ICO)
function make_favicon(string $src, string $dest): string {
    $ext  = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $orig = $ext === 'png' ? imagecreatefrompng($src) : imagecreatefromjpeg($src);
    if (!$orig) return "No se pudo leer imagen para favicon.";
    $size = 48;
    $out  = imagecreatetruecolor($size, $size);
    $bg   = imagecolorallocate($out, 15, 40, 80);
    imagefill($out, 0, 0, $bg);
    $sw = imagesx($orig); $sh = imagesy($orig);
    $scale = min($size / $sw, $size / $sh) * 0.80;
    $dw = (int)($sw * $scale); $dh = (int)($sh * $scale);
    $dx = (int)(($size - $dw) / 2); $dy = (int)(($size - $dh) / 2);
    imagecopyresampled($out, $orig, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
    ob_start(); imagepng($out); $png = ob_get_clean();
    imagedestroy($orig); imagedestroy($out);
    $plen = strlen($png);
    // ICO header + ICONDIRENTRY + PNG data
    $ico  = pack('vvv', 0, 1, 1);                          // Reserved, Type=1, Count=1
    $ico .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, $plen, 22); // ICONDIRENTRY
    $ico .= $png;
    file_put_contents($dest, $ico);
    return "OK — favicon.ico (48×48)";
}
$results[] = make_favicon($src_path, __DIR__ . '/favicon.ico');

// Respuesta
header('Content-Type: text/html; charset=utf-8');
echo '<style>body{font-family:sans-serif;padding:40px;max-width:600px;margin:0 auto}
li{margin:8px 0;font-size:15px}.ok{color:#166534}.er{color:#991b1b}</style>';
echo '<h2>Íconos generados</h2><ul>';
foreach ($results as $r) {
    $ok = str_starts_with($r, 'OK');
    echo '<li class="' . ($ok ? 'ok' : 'er') . '">' . htmlspecialchars($r) . '</li>';
}
echo '</ul>';
echo '<p>Una vez generados, podés borrar este archivo del servidor.</p>';
echo '<p><a href="' . (defined('BASE_URL') ? BASE_URL : '/ops') . '/index.php">Ir al panel</a></p>';
