<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$cuit = preg_replace('/[^0-9]/', '', $_GET['cuit'] ?? '');

if (strlen($cuit) !== 11) {
    echo json_encode(['ok' => false, 'msg' => 'CUIT debe tener 11 dígitos.']);
    exit;
}

// Función de fetch con curl (preferido en hosting compartido)
function fetchUrl(string $url, int $timeout = 8): string|false {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; logistica/1.0)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) {
            error_log("ARCA curl error [{$url}]: {$err}");
            return false;
        }
        return $resp;
    }
    // fallback file_get_contents
    $ctx = stream_context_create([
        'http' => [
            'timeout'        => $timeout,
            'user_agent'     => 'Mozilla/5.0',
            'ignore_errors'  => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    return @file_get_contents($url, false, $ctx);
}

// ── Intento 1: TangoFactura (wrapper ARCA sin auth, el más confiable) ────
$url1 = "https://afip.tangofactura.com/Rest/GetContribuyenteFull?cuit={$cuit}";
$resp = fetchUrl($url1);
if ($resp !== false && $resp !== '') {
    $d = json_decode($resp, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        // CUIT no encontrado
        if (!empty($d['errorGetContribuyenteFull'])) {
            echo json_encode(['ok' => false, 'msg' => 'CUIT no encontrado en ARCA.']);
            exit;
        }
        $razon = $d['Contribuyente']['razonSocial'] ?? null;
        if ($razon && trim($razon) !== '') {
            echo json_encode(['ok' => true, 'razon_social' => trim($razon)]);
            exit;
        }
    }
}

// ── Intento 2: API pública ARCA vía servicio alternativo ─────
$url2 = "https://api.cuitonline.com/data/v2/?format=json&q={$cuit}";
$resp2 = fetchUrl($url2);
if ($resp2 !== false && $resp2 !== '') {
    $d2 = json_decode($resp2, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $razon = $d2['results'][0]['label'] ?? $d2['label'] ?? null;
        if ($razon && trim($razon) !== '') {
            echo json_encode(['ok' => true, 'razon_social' => trim($razon)]);
            exit;
        }
    }
}

// ── Sin resultado ─────────────────────────────────────────────
error_log("ARCA lookup fallido para CUIT {$cuit}");
echo json_encode([
    'ok'  => false,
    'msg' => 'No se encontró el CUIT en ARCA. Ingresá el nombre manualmente.',
]);
