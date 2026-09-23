<?php
// Genera el instalador (.bat) del Escáner Logax para la PC que tiene el escáner.
// El .bat descarga herramientas/escaner.ps1, lo deja en el inicio de Windows autorizado
// solo para este sitio, y lo arranca.
require_once __DIR__ . '/../config/auth.php';
require_login();

$host = $_SERVER['HTTP_HOST'] ?? '';
if (!preg_match('/^[A-Za-z0-9.:-]+$/', $host)) { http_response_code(400); exit('Host inválido'); }
$https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$origen  = ($https ? 'https' : 'http') . '://' . $host;
$url_ps1 = $origen . url('herramientas/escaner.ps1');

$bat = <<<BAT
@echo off
title Instalar Escaner Logax
set "DEST=%LOCALAPPDATA%\\EscanerLogax"
if not exist "%DEST%" mkdir "%DEST%"

echo Descargando el programa del escaner...
powershell -NoProfile -ExecutionPolicy Bypass -Command "[Net.ServicePointManager]::SecurityProtocol='Tls12'; Invoke-WebRequest -UseBasicParsing '{$url_ps1}' -OutFile '%DEST%\\escaner.ps1'"
if errorlevel 1 (
    echo.
    echo No se pudo descargar el programa. Revisa la conexion a internet.
    pause
    exit /b 1
)

echo Configurando inicio automatico con Windows...
powershell -NoProfile -ExecutionPolicy Bypass -Command "\$s=(New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath('Startup')+'\\Escaner Logax.lnk'); \$s.TargetPath='powershell.exe'; \$s.Arguments='-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File \\"%DEST%\\escaner.ps1\\" -Origen {$origen}'; \$s.WindowStyle=7; \$s.Save()"

echo Iniciando...
start "" powershell -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "%DEST%\\escaner.ps1" -Origen {$origen}

echo.
echo Listo. Volve a la pantalla de Comprobantes: arriba a la derecha tiene que aparecer el escaner en verde.
echo Si el navegador pregunta si permite acceder a dispositivos de la red local, responder Permitir.
echo.
pause
BAT;

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="instalar_escaner_logax.bat"');
echo str_replace("\n", "\r\n", $bat);
