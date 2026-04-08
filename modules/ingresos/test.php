<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo "<p style='color:red'>Error $errno: $errstr en $errfile línea $errline</p>";
    return true;
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<pre style='color:red'>FATAL: {$e['message']}\nen {$e['file']}:{$e['line']}</pre>";
    }
});

require_once __DIR__ . '/../../config/auth.php';
echo '<p>auth.php ✓</p>';

// Verificar archivos críticos
$archivos = [
    'navbar.php'  => __DIR__ . '/../../includes/navbar.php',
    'forms.js'    => __DIR__ . '/../../assets/js/forms.js',
    'app.css'     => __DIR__ . '/../../assets/css/app.css',
];
foreach ($archivos as $nombre => $ruta) {
    if (file_exists($ruta)) {
        echo "<p>$nombre ✓</p>";
    } else {
        echo "<p style='color:red'>$nombre FALTA en: $ruta</p>";
    }
}

// Simular lo que hace nuevo.php
$eid = empresa_id();
$db  = db();

$stmt = $db->prepare("SELECT id, nombre FROM proveedores WHERE empresa_id = ? AND activo = 1");
$stmt->execute([$eid]);
echo '<p>Query proveedores ✓ (' . $stmt->rowCount() . ' filas)</p>';

$stmt = $db->prepare("SELECT id, nombre FROM clientes WHERE empresa_id = ? AND activo = 1");
$stmt->execute([$eid]);
echo '<p>Query clientes ✓ (' . $stmt->rowCount() . ' filas)</p>';

echo '<h3>Todo OK - el problema no está en las dependencias</h3>';
