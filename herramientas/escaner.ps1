# ============================================================
# ESCÁNER LOGAX
# Servicio local que permite al sistema web escanear con un botón.
# Escucha en http://localhost:8765 y usa WIA (el sistema de escaneo de Windows),
# así que funciona con cualquier escáner que tenga driver de Windows instalado.
#
#   GET  /estado    -> {"ok":true,"escaner":"Nombre del escáner"}
#   POST /escanear  -> imagen JPEG de la hoja escaneada
#
# Se instala con el instalador que se descarga desde Comprobantes (queda en el inicio de Windows).
# Uso manual:
#   powershell -ExecutionPolicy Bypass -File escaner.ps1 -Origen https://midominio.com.ar
# ============================================================
param(
    [Parameter(Mandatory = $true)]
    [string]$Origen,                  # sitio autorizado a usar el escáner (ej. https://midominio.com.ar)
    [int]$Puerto = 8765,
    [int]$Dpi = 200,
    [ValidateSet('color', 'gris')]
    [string]$Color = 'color',
    [switch]$Alimentador              # usar la bandeja alimentadora (ADF) en vez del vidrio
)

$ErrorActionPreference = 'Stop'
$Origen = $Origen.TrimEnd('/')
$FORMATO_JPEG = '{B96B3CAE-0728-11D3-9D7B-0000F81EF32E}'

$ERRORES_WIA = @{
    '80210002' = 'Papel atascado en el escáner'
    '80210003' = 'No hay papel en el alimentador'
    '80210005' = 'El escáner está apagado o desconectado'
    '80210006' = 'El escáner está ocupado, probá de nuevo'
    '80210007' = 'El escáner se está calentando, probá en unos segundos'
    '8021000A' = 'No se pudo comunicar con el escáner (revisá el cable)'
    '80210015' = 'No se encontró el escáner'
    '80210016' = 'La tapa del escáner está abierta'
    '80210064' = 'Escaneo cancelado'
    '80004005' = 'El escáner no respondió: revisá que esté encendido y conectado a la red'
}

function Get-InfoEscaner {
    $dm = New-Object -ComObject WIA.DeviceManager
    foreach ($info in $dm.DeviceInfos) {
        if ($info.Type -eq 1) { return $info }   # 1 = ScannerDeviceType
    }
    return $null
}

function Get-Prop($props, [int]$id) {
    foreach ($p in $props) { if ($p.PropertyID -eq $id) { return $p } }
    return $null
}

function Set-Prop($props, [int]$id, $valor) {
    $p = Get-Prop $props $id
    if ($p) { try { $p.Value = $valor } catch { } }
}

function Invoke-Escaneo {
    $info = Get-InfoEscaner
    if (-not $info) { throw 'No se encontró ningún escáner conectado a esta PC' }
    $dev = $info.Connect()

    # 3088 = WIA_DPS_DOCUMENT_HANDLING_SELECT (1 = alimentador, 2 = vidrio)
    if ($Alimentador) { Set-Prop $dev.Properties 3088 1 }

    $item = $dev.Items.Item(1)
    Set-Prop $item.Properties 6146 $(if ($Color -eq 'gris') { 2 } else { 1 })   # intención: 1 color, 2 grises
    Set-Prop $item.Properties 6147 $Dpi                                          # resolución horizontal
    Set-Prop $item.Properties 6148 $Dpi                                          # resolución vertical
    Set-Prop $item.Properties 6149 0                                             # inicio X
    Set-Prop $item.Properties 6150 0                                             # inicio Y
    # Al cambiar la resolución el área no se ajusta sola: usar toda la superficie del escáner
    foreach ($id in 6151, 6152) {
        $p = Get-Prop $item.Properties $id
        if ($p) { try { $p.Value = $p.SubTypeMax } catch { } }
    }

    # Muchos escáneres solo entregan BMP: pedir JPEG solo si lo soporta, y si no convertir después
    $soportaJpeg = @($item.Formats | Where-Object { $_ -eq $FORMATO_JPEG }).Count -gt 0
    $img = if ($soportaJpeg) { $item.Transfer($FORMATO_JPEG) } else { $item.Transfer() }
    if ($img.FormatID -ne $FORMATO_JPEG) {
        $proc = New-Object -ComObject WIA.ImageProcess
        $proc.Filters.Add($proc.FilterInfos.Item('Convert').FilterID)
        $proc.Filters.Item(1).Properties.Item('FormatID').Value = $FORMATO_JPEG
        $proc.Filters.Item(1).Properties.Item('Quality').Value = 85
        $img = $proc.Apply($img)
    }

    $tmp = Join-Path $env:TEMP ('escaner_' + [guid]::NewGuid().ToString('N') + '.jpg')
    $img.SaveFile($tmp)
    try { return [IO.File]::ReadAllBytes($tmp) }
    finally { Remove-Item $tmp -ErrorAction SilentlyContinue }
}

function Get-MensajeError($err) {
    $ex = $err.Exception
    while ($ex) {
        $hr = '{0:X8}' -f $ex.HResult
        if ($ERRORES_WIA.ContainsKey($hr)) { return $ERRORES_WIA[$hr] }
        $ex = $ex.InnerException
    }
    return $err.Exception.Message
}

function Send-Bytes($res, [int]$codigo, [string]$tipo, [byte[]]$bytes) {
    $res.StatusCode = $codigo
    $res.ContentType = $tipo
    $res.ContentLength64 = $bytes.Length
    $res.OutputStream.Write($bytes, 0, $bytes.Length)
    $res.Close()
}

function Send-Json($res, [int]$codigo, $obj) {
    $json = $obj | ConvertTo-Json -Compress
    Send-Bytes $res $codigo 'application/json; charset=utf-8' ([Text.Encoding]::UTF8.GetBytes($json))
}

$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://localhost:$Puerto/")
try {
    $listener.Start()
} catch {
    Write-Host "El Escáner Logax ya está abierto (o el puerto $Puerto está ocupado)."
    exit 1
}
Write-Host "Escáner Logax escuchando en http://localhost:$Puerto  (sitio autorizado: $Origen)"
Write-Host 'Dejá esta ventana abierta. Ctrl+C para cerrar.'

while ($listener.IsListening) {
    $ctx = $listener.GetContext()
    $req = $ctx.Request
    $res = $ctx.Response
    try {
        $origin = $req.Headers['Origin']
        if ($origin -and $origin.TrimEnd('/') -ne $Origen) {
            Send-Json $res 403 @{ ok = $false; error = 'Sitio no autorizado' }
            continue
        }
        if ($origin) {
            $res.AddHeader('Access-Control-Allow-Origin', $origin)
            $res.AddHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            $res.AddHeader('Access-Control-Allow-Private-Network', 'true')
            $res.AddHeader('Vary', 'Origin')
        }

        $ruta = $req.Url.AbsolutePath.TrimEnd('/')
        if ($req.HttpMethod -eq 'OPTIONS') {
            $res.StatusCode = 204
            $res.Close()
        } elseif ($ruta -eq '/estado' -and $req.HttpMethod -eq 'GET') {
            $info = Get-InfoEscaner
            if ($info) {
                Send-Json $res 200 @{ ok = $true; escaner = [string]$info.Properties.Item('Name').Value }
            } else {
                Send-Json $res 200 @{ ok = $false; error = 'Programa abierto, pero no hay escáner conectado' }
            }
        } elseif ($ruta -eq '/escanear' -and $req.HttpMethod -eq 'POST') {
            Write-Host ((Get-Date -Format 'HH:mm:ss') + ' Escaneando...')
            $bytes = Invoke-Escaneo
            Send-Bytes $res 200 'image/jpeg' $bytes
            Write-Host ((Get-Date -Format 'HH:mm:ss') + " OK ($([math]::Round($bytes.Length / 1KB)) KB)")
        } else {
            Send-Json $res 404 @{ ok = $false; error = 'Ruta inexistente' }
        }
    } catch {
        $msg = Get-MensajeError $_
        Write-Host ((Get-Date -Format 'HH:mm:ss') + " ERROR: $msg")
        try { Send-Json $res 500 @{ ok = $false; error = $msg } } catch { }
    }
}
