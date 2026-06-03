<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();
if (!es_admin()) { header('Location: ' . url('index.php')); exit; }

$db  = db();
$eid = empresa_id();

// ── Guardar camiones + saldo inicial (PRG) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid_post = (int)($_POST['proveedor_id'] ?? 0);
    if ($pid_post > 0) {
        $mes_post  = max(1, min(12, (int)($_POST['mes']  ?? date('n'))));
        $anio_post = max(2020, (int)($_POST['anio'] ?? date('Y')));
        $db->beginTransaction();
        try {
            // Guardar camiones
            foreach (($_POST['cam'] ?? []) as $fecha_str => $cnt) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_str)) continue;
                $cnt = max(0, (int)$cnt);
                if ($cnt > 0) {
                    $db->prepare("
                        INSERT INTO cc_viajes (empresa_id, proveedor_id, fecha, camiones)
                        VALUES (?,?,?,?)
                        ON DUPLICATE KEY UPDATE camiones = VALUES(camiones)
                    ")->execute([$eid, $pid_post, $fecha_str, $cnt]);
                } else {
                    $db->prepare("DELETE FROM cc_viajes WHERE empresa_id=? AND proveedor_id=? AND fecha=?")
                       ->execute([$eid, $pid_post, $fecha_str]);
                }
            }
            // Guardar saldo inicial (si viene en el POST)
            if (isset($_POST['saldo_ini'])) {
                $saldo_ini_post = max(0.0, (float)str_replace(',', '.', $_POST['saldo_ini']));
                $db->prepare("
                    INSERT INTO cc_saldo_inicial (empresa_id, proveedor_id, anio, mes, saldo)
                    VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE saldo = VALUES(saldo)
                ")->execute([$eid, $pid_post, $anio_post, $mes_post, $saldo_ini_post]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
        }
    }
    header('Location: ' . url('modules/reportes/cuenta_corriente.php') . '?' . http_build_query([
        'proveedor_id' => $pid_post,
        'mes'          => (int)($_POST['mes']   ?? date('n')),
        'anio'         => (int)($_POST['anio']  ?? date('Y')),
        'precio_pos'   => $_POST['precio_pos']   ?? '',
        'precio_viaje' => $_POST['precio_viaje'] ?? '',
        'precio_modo'  => $_POST['precio_modo']  ?? 'camion',
    ]));
    exit;
}

// ── Filtros GET ───────────────────────────────────────────────
$proveedor_id = (int)($_GET['proveedor_id'] ?? 0);
$mes          = max(1, min(12, (int)($_GET['mes']   ?? date('n'))));
$anio         = max(2020, (int)($_GET['anio']       ?? date('Y')));
$precio_pos   = (float)str_replace(',', '.', $_GET['precio_pos']   ?? '0');
$precio_viaje = (float)str_replace(',', '.', $_GET['precio_viaje'] ?? '0');
$precio_modo  = in_array($_GET['precio_modo'] ?? '', ['camion','pallet']) ? $_GET['precio_modo'] : 'camion';
$saldo_ini_get = array_key_exists('saldo_ini', $_GET)
    ? max(0.0, (float)str_replace(',', '.', $_GET['saldo_ini']))
    : null;

// ── Proveedores ───────────────────────────────────────────────
$pq = $db->prepare("SELECT id, nombre FROM proveedores WHERE empresa_id=? AND activo=1 ORDER BY nombre");
$pq->execute([$eid]);
$proveedores = $pq->fetchAll();

$prov_nombre = '';
foreach ($proveedores as $p) {
    if ((int)$p['id'] === $proveedor_id) { $prov_nombre = $p['nombre']; break; }
}

// ── Cálculo principal ─────────────────────────────────────────
$saldo_ini      = 0.0;
$saldo_ini_auto = 0.0;
$datos = null;
if ($proveedor_id > 0) {
    $inicio = sprintf('%04d-%02d-01', $anio, $mes);
    $fin    = date('Y-m-t', strtotime($inicio));

    $stmt = $db->prepare("
        SELECT r.id, r.nro_remito_propio, r.total_pallets, r.entrega_fisica,
               r.fecha_devolucion, r.pallets_devueltos,
               c.nombre              AS cliente,
               DATE(i.fecha_ingreso) AS fecha_ingreso,
               ef.fecha_salida_real
        FROM remitos r
        JOIN ingresos i ON i.id = r.ingreso_id
        JOIN clientes c ON c.id = r.cliente_id
        LEFT JOIN (
            SELECT er.remito_id, DATE(MAX(en.fecha_salida)) AS fecha_salida_real
            FROM entrega_remitos er
            JOIN entregas en ON en.id = er.entrega_id
            WHERE en.estado IN ('completada','entregado','con_incidencias')
            GROUP BY er.remito_id
        ) ef ON ef.remito_id = r.id
        WHERE r.empresa_id   = ?
          AND r.proveedor_id = ?
          AND DATE(i.fecha_ingreso) <= ?
          AND (ef.fecha_salida_real IS NULL OR ef.fecha_salida_real >= ?
               OR (r.fecha_devolucion IS NOT NULL AND r.fecha_devolucion <= ?))
        ORDER BY DATE(i.fecha_ingreso), r.id
    ");
    $stmt->execute([$eid, $proveedor_id, $fin, $inicio, $fin]);
    $remitos_periodo = $stmt->fetchAll();

    $tc = $db->prepare("SELECT fecha, camiones FROM cc_viajes
                        WHERE empresa_id=? AND proveedor_id=? AND fecha BETWEEN ? AND ?");
    $tc->execute([$eid, $proveedor_id, $inicio, $fin]);
    $truck_counts = array_column($tc->fetchAll(), 'camiones', 'fecha');

    // ── Saldo inicial ─────────────────────────────────────────
    // Auto-calculado: pallets que ingresaron antes del mes y no salieron (solo físicos)
    $ayer_ini = (new DateTime($inicio))->modify('-1 day')->format('Y-m-d');
    foreach ($remitos_periodo as $r) {
        $pal     = (float)$r['total_pallets'];
        $pal_dev = $r['fecha_devolucion'] !== null ? (float)($r['pallets_devueltos'] ?? $pal) : 0.0;
        if ($r['entrega_fisica'] &&
            $r['fecha_ingreso'] <= $ayer_ini &&
            ($r['fecha_salida_real'] === null || $r['fecha_salida_real'] > $ayer_ini)) {
            $saldo_ini_auto += $pal;
        }
        // Devolucion anterior al período: mercadería está de vuelta en depósito
        if ($r['fecha_devolucion'] !== null && $r['fecha_devolucion'] <= $ayer_ini) {
            $saldo_ini_auto += $pal_dev;
        }
    }
    // Si hay valor guardado en DB, lo usa; si no, usa el auto-calculado
    $si_q = $db->prepare("SELECT saldo FROM cc_saldo_inicial
                          WHERE empresa_id=? AND proveedor_id=? AND anio=? AND mes=?");
    $si_q->execute([$eid, $proveedor_id, $anio, $mes]);
    $si_row = $si_q->fetch();
    $saldo_ini  = $saldo_ini_get !== null ? $saldo_ini_get
                : (($si_row !== false) ? (float)$si_row['saldo'] : $saldo_ini_auto);
    // Pallets manuales no registrados en remitos (persiste durante todo el mes)
    $delta_ini  = $saldo_ini - $saldo_ini_auto;

    // ── Cálculo de posiciones ─────────────────────────────────
    //
    // Lógica exacta del Excel:
    //   Solo hay cobro cuando hay un EVENTO (día con movimiento).
    //   Al llegar ese evento, cobrás:
    //     saldo_del_evento_anterior × días_transcurridos × (precio_mensual / 30)
    //
    // Ejemplo del Excel de abril 2026:
    //   01/04 → ingresan 18 pal. Saldo cierra en 18. (0 días, no se cobra)
    //   10/04 → salen 5 pal. Han pasado 9 días.
    //            Cobro = 18 × 9 × 316 = $51.192
    //   13/04 → Pasaron 3 días (vie+sáb+dom). Saldo anterior era 13.
    //            Cobro = 13 × 3 × 316 = $12.324
    //

    // 1. Armar lista de eventos (días con movimiento) dentro del período
    // Los ingresos virtuales no generan evento (no cambian el stock)
    $eventos_fechas = [];
    foreach ($remitos_periodo as $r) {
        if ($r['entrega_fisica'] && $r['fecha_ingreso'] >= $inicio && $r['fecha_ingreso'] <= $fin)
            $eventos_fechas[$r['fecha_ingreso']] = true;
        if ($r['fecha_salida_real'] !== null
            && $r['fecha_salida_real'] >= $inicio
            && $r['fecha_salida_real'] <= $fin)
            $eventos_fechas[$r['fecha_salida_real']] = true;
        if ($r['fecha_devolucion'] !== null
            && $r['fecha_devolucion'] >= $inicio
            && $r['fecha_devolucion'] <= $fin)
            $eventos_fechas[$r['fecha_devolucion']] = true;
    }
    ksort($eventos_fechas);
    $eventos_fechas = array_keys($eventos_fechas);

    $dias                   = [];
    $total_posiciones       = 0.0;
    $total_pal_viajes       = 0.0;
    $total_camiones         = 0;
    $total_costo_viajes_sum = 0.0;
    $saldo_pos_acum         = 0.0;
    $saldo_viaje_acum       = 0.0;
    $precio_pos_dia         = $precio_pos; // ya es precio por día

    // Punto de partida: saldo inicial (guardado en DB o auto-calculado)
    // Si hay saldo ini > 0 arrancamos desde el día anterior al mes,
    // así el primer evento del mes (aunque sea el día 1) tiene dias >= 1.
    $saldo_evento_anterior = $saldo_ini;
    $fecha_evento_anterior = $saldo_ini > 0
        ? (new DateTime($inicio))->modify('-1 day')->format('Y-m-d')
        : $inicio;

    foreach ($eventos_fechas as $d) {
        // Stock al CIERRE de este evento (después de todos los movimientos de hoy)
        $stock        = 0.0;
        $pal_sal      = 0.0;
        $entradas     = [];
        $salidas      = [];
        $devoluciones = [];

        foreach ($remitos_periodo as $r) {
            $pal     = (float)$r['total_pallets'];
            $pal_dev = $r['fecha_devolucion'] !== null ? (float)($r['pallets_devueltos'] ?? $pal) : 0.0;
            if ($r['entrega_fisica'] &&
                $r['fecha_ingreso'] <= $d &&
                ($r['fecha_salida_real'] === null || $r['fecha_salida_real'] > $d)) {
                $stock += $pal;   // ingreso físico que no salió aún
            } elseif (!$r['entrega_fisica'] &&
                      $r['fecha_salida_real'] !== null &&
                      $r['fecha_salida_real'] <= $d) {
                $stock -= $pal;   // egreso de remito virtual: resta del stock físico existente
            }
            // Devolucion: la mercadería volvió al depósito desde fecha_devolucion
            if ($r['fecha_devolucion'] !== null && $r['fecha_devolucion'] <= $d) {
                $stock += $pal_dev;
            }
            if ($r['fecha_ingreso'] === $d && $r['entrega_fisica'])  $entradas[] = $r;
            if ($r['fecha_salida_real'] === $d) { $salidas[] = $r; $pal_sal += $pal; }
            if ($r['fecha_devolucion'] === $d)    $devoluciones[] = $r;
        }

        // Días transcurridos desde el evento anterior
        $dias_entre = (int)(new DateTime($d))->diff(new DateTime($fecha_evento_anterior))->days;

        // Cobro = saldo_anterior × días_transcurridos × precio_dia
        $cobro_pos = ($precio_pos > 0 && $saldo_evento_anterior > 0 && $dias_entre > 0)
            ? $saldo_evento_anterior * $dias_entre * $precio_pos_dia
            : null;

        $camiones_dia = isset($truck_counts[$d]) ? (int)$truck_counts[$d] : 0;
        $costo_viaje  = null;
        if ($precio_viaje > 0 && $pal_sal > 0) {
            $costo_viaje = $precio_modo === 'camion'
                ? $camiones_dia * $precio_viaje
                : $pal_sal      * $precio_viaje;
        }

        $saldo_pos_acum   += $cobro_pos   ?? 0;
        $saldo_viaje_acum += $costo_viaje ?? 0;

        // Para el total mostramos saldo_anterior × dias (= "posiciones cobradas")
        $pos_cobradas = $saldo_evento_anterior * $dias_entre;
        $total_posiciones       += $pos_cobradas;
        $total_pal_viajes       += $pal_sal;
        $total_camiones         += $camiones_dia;
        $total_costo_viajes_sum += $costo_viaje ?? 0;

        $saldo_acum = ($precio_pos > 0 || $precio_viaje > 0)
            ? $saldo_pos_acum + $saldo_viaje_acum
            : null;

        // Stock efectivo: remitos + delta de pallets manuales (persiste en todos los eventos)
        $stock_eff = max(0.0, $stock + $delta_ini);

        $dias[$d] = [
            'stock'          => $stock_eff,             // cierre de hoy (incluye saldo ini manual)
            'saldo_anterior' => $saldo_evento_anterior, // base del cobro
            'dias_entre'     => $dias_entre,            // días transcurridos
            'pos_cobradas'   => $pos_cobradas,          // pal × días
            'entradas'       => $entradas,
            'salidas'        => $salidas,
            'devoluciones'   => $devoluciones,
            'pal_sal'        => $pal_sal,
            'camiones'       => $camiones_dia,
            'costo_pos'      => $cobro_pos,
            'costo_viaje'    => $costo_viaje,
            'saldo_acum'     => $saldo_acum,
        ];

        // Este evento pasa a ser el anterior para el próximo
        $saldo_evento_anterior = $stock_eff;
        $fecha_evento_anterior = $d;
    }

    // Resumen global
    $total_ingresado = 0.0;
    $total_salido    = 0.0;
    $stock_actual    = 0.0;
    foreach ($remitos_periodo as $r) {
        $pal = (float)$r['total_pallets'];
        if ($r['entrega_fisica'] && $r['fecha_ingreso'] >= $inicio && $r['fecha_ingreso'] <= $fin) $total_ingresado += $pal;
        if ($r['fecha_salida_real'] !== null
            && $r['fecha_salida_real'] >= $inicio
            && $r['fecha_salida_real'] <= $fin)                             $total_salido    += $pal;
        if ($r['entrega_fisica'] && $r['fecha_salida_real'] === null)  $stock_actual += $pal;
        if (!$r['entrega_fisica'] && $r['fecha_salida_real'] !== null) $stock_actual -= $pal;
        if ($r['fecha_devolucion'] !== null) $stock_actual += (float)($r['pallets_devueltos'] ?? $pal);
    }
    $stock_actual = max(0.0, $stock_actual + $delta_ini); // sumar pallets manuales del saldo ini

    // total_costo_pos viene acumulado en $saldo_pos_acum del loop de eventos
    $total_costo_pos    = $precio_pos   > 0 ? $saldo_pos_acum : null;
    $total_costo_viajes = $precio_viaje > 0 ? $total_costo_viajes_sum                  : null;
    $total_general      = ($total_costo_pos ?? 0) + ($total_costo_viajes ?? 0);

    $datos = compact(
        'dias', 'total_posiciones', 'total_ingresado', 'total_salido', 'stock_actual',
        'inicio', 'fin', 'remitos_periodo',
        'total_pal_viajes', 'total_camiones',
        'total_costo_pos', 'total_costo_viajes', 'total_general'
    );
}

// ── Helpers ───────────────────────────────────────────────────
function fmtPal(float $p): string   { return $p > 0 ? number_format($p, 1) : '—'; }
function fmtMoney(float $v): string { return '$&nbsp;' . number_format($v, 2, ',', '.'); }
function fmtDia(string $ymd): string {
    [$y,$m,$d] = explode('-', $ymd); return "$d/$m/$y";
}
function diaSemana(string $ymd): string {
    return ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'][(int)(new DateTime($ymd))->format('w')];
}

$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
          'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$anios = range(date('Y'), 2024, -1);

$con_pos     = $precio_pos   > 0;
$con_viaje   = $precio_viaje > 0;
$con_saldo   = $con_pos || $con_viaje;   // columna Saldo acumulado
$modo_camion = $precio_modo === 'camion';
// Cantidad de columnas para colspan en filas de totales
$ncols = 6 + ($modo_camion ? 1 : 0) + ($con_pos ? 1 : 0) + ($con_viaje ? 1 : 0) + ($con_saldo ? 1 : 0);

$nav_modulo = 'reportes';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cuenta corriente — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        body { background: #eef1f6; }
        @media print {
            body { background: #fff; font-size: 11pt; }
            .no-print, .row-detail { display: none !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
            /* Tabla profesional de impresión */
            .print-doc { display: block !important; }
            .pt-logo-mark { color: #1a56b0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .pt-thead-row th { background: #1a2a3a !important; color: #fff !important;
                               -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .pt-day-row td { background: #f0f0f0 !important; font-weight: 700;
                             -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .pt-total-row td { background: #1a2a3a !important; color: #fff !important; font-weight: 700;
                               -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        .card { border: none !important; box-shadow: 0 2px 8px rgba(0,0,0,.10) !important; }
        #tabla-cc thead th {
            background: #2c3e50; color: #fff;
            font-size: .75rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: .05em; border: none;
        }
        /* Fila resumen del día */
        .row-summary      { background: #f8f9fa; }
        .row-summary td   { border-top: 2px solid #dee2e6 !important; font-weight: 600; }
        /* Filas de detalle (desplegables) */
        .row-detail       { background: #fff; }
        .row-detail td    { font-size: .85rem; border-top: none !important; padding-top: .3rem; padding-bottom: .3rem; }
        .row-detail-ing td { border-left: 3px solid #198754; }
        .row-detail-sal td { border-left: 3px solid #f97316; }
        /* Colores de valores */
        .col-pos   { color: #5a29a3; font-weight: 700; }
        .col-viaje { color: #c45200; font-weight: 700; }
        .col-costo { color: #0a6640; font-weight: 700; }
        /* Botón expandir */
        .btn-expand { width:24px; height:24px; padding:0; line-height:1; }
        .btn-expand .bi { transition: transform .2s; display:inline-block; }
        .btn-expand.open .bi { transform: rotate(90deg); }
        /* Totales */
        .subtotal-row td { background: #f0f2f5; font-weight: 600; }
        .total-row td    { background: #2c3e50 !important; color: #fff !important; font-weight: 700; font-size: .9rem; }
        /* Input camiones */
        .input-cam { width:58px; text-align:center; font-weight:700;
                     border:2px solid #f97316; border-radius:6px; padding:2px 4px;
                     font-size:.9rem; background:#fff7ed; }
        .input-cam:focus { outline:none; border-color:#c45200; box-shadow:0 0 0 2px #fde8d0; }
        /* Alineación tabla */
        #tabla-cc th                { white-space: nowrap; text-align: center !important; }
        #tabla-cc th:nth-child(1)   { text-align: left !important; }  /* Fecha */
        #tabla-cc th:nth-child(3)   { text-align: left !important; }  /* Concepto */
        #tabla-cc td                { white-space: nowrap; text-align: center !important; }
        #tabla-cc td:nth-child(1)   { text-align: left !important; }  /* Fecha */
        #tabla-cc td:nth-child(3)   { text-align: left !important; white-space: normal; min-width: 160px; } /* Concepto */
        /* Saldo inicial */
        .saldo-ini-bar { background: #f0f4ff; border-bottom: 1px solid #d0d9f0; }
        .input-saldo-ini { width:80px; text-align:center; font-weight:700;
                           border:2px solid #5a29a3; border-radius:6px; padding:2px 6px;
                           font-size:.9rem; background:#f8f5ff; }
        .input-saldo-ini:focus { outline:none; border-color:#3d1a7a; box-shadow:0 0 0 2px #e5d9ff; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4" style="max-width:1400px">

    <div class="d-flex align-items-center justify-content-between mb-3 no-print">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-journal-text me-2 text-primary"></i>Cuenta corriente — Proveedores
        </h5>
        <?php if (!empty($datos['remitos_periodo'])): ?>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Imprimir
        </button>
        <?php endif; ?>
    </div>

    <!-- ── Filtros ─────────────────────────────────────────── -->
    <form method="GET" class="card mb-3 no-print">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label form-label-sm fw-semibold mb-1">Proveedor</label>
                    <select name="proveedor_id" class="form-select form-select-sm" required>
                        <option value="">— Seleccionar —</option>
                        <?php foreach ($proveedores as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= (int)$p['id'] === $proveedor_id ? 'selected' : '' ?>>
                            <?= h($p['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-2 col-lg-2">
                    <label class="form-label form-label-sm fw-semibold mb-1">Mes</label>
                    <select name="mes" class="form-select form-select-sm">
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?= $i ?>" <?= $i === $mes ? 'selected' : '' ?>><?= $meses[$i] ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-1 col-lg-1">
                    <label class="form-label form-label-sm fw-semibold mb-1">Año</label>
                    <select name="anio" class="form-select form-select-sm">
                        <?php foreach ($anios as $a): ?>
                        <option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3 col-lg-2">
                    <label class="form-label form-label-sm fw-semibold mb-1">
                        <span style="color:#5a29a3"><i class="bi bi-box-seam me-1"></i>$ posición / día</span>
                    </label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" name="precio_pos" class="form-control form-control-sm"
                               step="0.01" min="0" value="<?= $precio_pos > 0 ? $precio_pos : '' ?>" placeholder="0.00">
                    </div>
                </div>
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label form-label-sm fw-semibold mb-1">
                        <span style="color:#c45200"><i class="bi bi-truck me-1"></i>Distribución</span>
                    </label>
                    <div class="input-group input-group-sm">
                        <select name="precio_modo" class="form-select form-select-sm" style="max-width:130px">
                            <option value="camion" <?= $precio_modo === 'camion' ? 'selected' : '' ?>>Por camión</option>
                            <option value="pallet" <?= $precio_modo === 'pallet' ? 'selected' : '' ?>>Por pallet</option>
                        </select>
                        <span class="input-group-text">$</span>
                        <input type="number" name="precio_viaje" class="form-control form-control-sm"
                               step="0.01" min="0" value="<?= $precio_viaje > 0 ? $precio_viaje : '' ?>"
                               placeholder="<?= $modo_camion ? '$ / camión' : '$ / pallet' ?>">
                    </div>
                </div>
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1 invisible d-none d-lg-block">.</label>
                    <button type="submit" class="btn btn-primary btn-sm d-block">
                        <i class="bi bi-search me-1"></i>Ver
                    </button>
                </div>
            </div>
        </div>
        <?php if ($saldo_ini_get !== null): ?>
        <input type="hidden" name="saldo_ini" value="<?= h($saldo_ini_get) ?>">
        <?php endif; ?>
    </form>

    <?php if ($datos): ?>

    <!-- ── Encabezado impresión ────────────────────────────── -->
    <!-- ══════════════════════════════════════════════════════════
         IMPRESIÓN PROFESIONAL — solo visible al imprimir
         ══════════════════════════════════════════════════════════ -->
    <?php if ($datos && !empty($datos['remitos_periodo'])): ?>
    <div class="print-doc" style="display:none; font-family:'Segoe UI',Arial,sans-serif; font-size:9.5pt; color:#111;">

        <!-- Encabezado del documento -->
        <table style="width:100%; border-bottom:2.5pt solid #111; padding-bottom:8pt; margin-bottom:10pt;">
            <tr>
                <td style="vertical-align:middle; width:60%;">
                    <div style="display:flex; align-items:center; gap:10pt;">
                        <img src="<?= url('assets/img/icon-192.png') ?>" style="height:44pt; width:44pt; border-radius:6pt;">
                        <div>
                            <div class="pt-logo-mark" style="font-size:20pt; font-weight:900; letter-spacing:-0.5pt; line-height:1;">Logax</div>
                            <div style="font-size:7.5pt; color:#555; text-transform:uppercase; letter-spacing:1pt;">Sistema de logística</div>
                        </div>
                    </div>
                </td>
                <td style="vertical-align:top; text-align:right;">
                    <div style="font-size:11pt; font-weight:700;"><?= h($prov_nombre) ?></div>
                    <div style="font-size:9pt; color:#444;"><?= $meses[$mes] ?> <?= $anio ?></div>
                    <?php if ($con_pos): ?>
                    <div style="font-size:8pt; color:#555;">Almacenaje: $<?= number_format($precio_pos,2,',','.') ?>/pos·día</div>
                    <?php endif; ?>
                    <?php if ($con_viaje): ?>
                    <div style="font-size:8pt; color:#555;">Distribución: $<?= number_format($precio_viaje,2,',','.') ?>/<?= $modo_camion ? 'camión' : 'pallet' ?></div>
                    <?php endif; ?>
                    <div style="font-size:7.5pt; color:#888; margin-top:2pt;">Emitido <?= date('d/m/Y H:i') ?></div>
                </td>
            </tr>
        </table>

        <div style="font-size:11pt; font-weight:700; text-transform:uppercase; letter-spacing:.5pt; margin-bottom:6pt;">Detalle de movimientos</div>

        <!-- Tabla de movimientos -->
        <?php
        // Columnas dinámicas
        $pt_cols = 7; // fecha, tipo, remito, cliente, entrada, salida, stock
        if ($con_pos)   $pt_cols++;
        if ($con_viaje) $pt_cols++;
        if ($con_saldo) $pt_cols++;
        ?>
        <table style="width:100%; border-collapse:collapse; font-size:8.5pt;">
            <thead>
                <tr class="pt-thead-row">
                    <th style="padding:4pt 5pt; text-align:left; white-space:nowrap;">Fecha</th>
                    <th style="padding:4pt 5pt; text-align:left;">Tipo</th>
                    <th style="padding:4pt 5pt; text-align:left;">Remito</th>
                    <th style="padding:4pt 5pt; text-align:left;">Cliente</th>
                    <th style="padding:4pt 5pt; text-align:right;">Pal. entrada</th>
                    <th style="padding:4pt 5pt; text-align:right;">Pal. salida</th>
                    <th style="padding:4pt 5pt; text-align:right;">Stock</th>
                    <?php if ($con_pos):   ?><th style="padding:4pt 5pt; text-align:right;">$ Almacenaje</th><?php endif; ?>
                    <?php if ($con_viaje): ?><th style="padding:4pt 5pt; text-align:right;">$ Distribución</th><?php endif; ?>
                    <?php if ($con_saldo): ?><th style="padding:4pt 5pt; text-align:right;">Saldo acum.</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php
            $pt_row_alt = false;
            foreach ($datos['dias'] as $dia => $info):
                $tiene_entradas = !empty($info['entradas']);
                $tiene_salidas  = !empty($info['salidas']);
                $tiene_devols   = !empty($info['devoluciones'] ?? []);
                if (!$tiene_entradas && !$tiene_salidas && !$tiene_devols) continue;

                // Armar lista de movimientos del día: primero entradas, luego salidas, luego devoluciones
                $pt_movs = [];
                foreach ($info['entradas']          as $r) $pt_movs[] = ['tipo' => 'ingreso',    'r' => $r];
                foreach ($info['salidas']           as $r) $pt_movs[] = ['tipo' => 'salida',     'r' => $r];
                foreach ($info['devoluciones'] ?? [] as $r) $pt_movs[] = ['tipo' => 'devolucion', 'r' => $r];
                $pt_last = count($pt_movs) - 1;

                // Fila separadora de día
                $sem_pt = diaSemana($dia);
                [$y,$m_n,$d_n] = explode('-', $dia);
                $label_dia = "$sem_pt $d_n/$m_n/$y";
                $info_dia  = $info['saldo_anterior'] > 0
                    ? number_format($info['saldo_anterior'],1).' pal. × '.$info['dias_entre'].' días'
                    : '';
            ?>
                <tr class="pt-day-row">
                    <td colspan="<?= $pt_cols ?>" style="padding:3pt 5pt; font-size:8pt; border-top:1pt solid #ccc;">
                        <?= $label_dia ?>
                        <?php if ($info_dia): ?>
                        <span style="font-weight:400; color:#555; margin-left:8pt;"><?= $info_dia ?></span>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php foreach ($pt_movs as $pt_idx => $mov):
                    $r        = $mov['r'];
                    $es_ing   = $mov['tipo'] === 'ingreso';
                    $es_dev   = $mov['tipo'] === 'devolucion';
                    $pal      = (float)$r['total_pallets'];
                    $pal_show = $es_dev ? (float)($r['pallets_devueltos'] ?? $pal) : $pal;
                    $es_last  = ($pt_idx === $pt_last);
                    $bg_row   = $pt_row_alt ? '#f9f9f9' : '#fff';
                    $pt_row_alt = !$pt_row_alt;
                ?>
                <tr style="background:<?= $bg_row ?>; border-bottom:.5pt solid #e0e0e0;">
                    <td style="padding:3pt 5pt; color:#666; font-size:8pt; white-space:nowrap;"><?= "$d_n/$m_n/$y" ?></td>
                    <td style="padding:3pt 5pt; white-space:nowrap;">
                        <?php if ($es_ing): ?>
                        <span style="font-weight:600;">↑ Ingreso</span>
                        <?php elseif ($es_dev): ?>
                        <span style="font-weight:600; color:#155724;">↩ Devolución</span>
                        <?php else: ?>
                        <span>↓ Salida</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:3pt 5pt; font-family:monospace; font-size:8pt;"><?= h($r['nro_remito_propio']) ?></td>
                    <td style="padding:3pt 5pt;"><?= h($r['cliente']) ?></td>
                    <td style="padding:3pt 5pt; text-align:right; font-weight:<?= ($es_ing || $es_dev) ? '700' : '400' ?>;">
                        <?= ($es_ing || $es_dev) ? '+'.fmtPal($pal_show) : '—' ?>
                    </td>
                    <td style="padding:3pt 5pt; text-align:right; font-weight:<?= (!$es_ing && !$es_dev) ? '700' : '400' ?>;">
                        <?= (!$es_ing && !$es_dev) ? '−'.fmtPal($pal) : '—' ?>
                    </td>
                    <!-- Stock y billing solo en la última fila del día -->
                    <?php if ($es_last): ?>
                    <td style="padding:3pt 5pt; text-align:right; font-weight:700;"><?= fmtPal($info['stock']) ?></td>
                    <?php if ($con_pos): ?>
                    <td style="padding:3pt 5pt; text-align:right;">
                        <?= ($info['costo_pos'] !== null && $info['costo_pos'] > 0) ? fmtMoney($info['costo_pos']) : '—' ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($con_viaje): ?>
                    <td style="padding:3pt 5pt; text-align:right;">
                        <?= ($info['costo_viaje'] !== null && $info['costo_viaje'] > 0) ? fmtMoney($info['costo_viaje']) : '—' ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($con_saldo): ?>
                    <td style="padding:3pt 5pt; text-align:right; font-weight:700;">
                        <?= ($info['saldo_acum'] !== null && $info['saldo_acum'] > 0) ? fmtMoney($info['saldo_acum']) : '—' ?>
                    </td>
                    <?php endif; ?>
                    <?php else: ?>
                    <td style="padding:3pt 5pt;"></td>
                    <?php if ($con_pos):   ?><td style="padding:3pt 5pt;"></td><?php endif; ?>
                    <?php if ($con_viaje): ?><td style="padding:3pt 5pt;"></td><?php endif; ?>
                    <?php if ($con_saldo): ?><td style="padding:3pt 5pt;"></td><?php endif; ?>
                    <?php endif; ?>
                </tr>
                <?php endforeach; // movimientos del día ?>
            <?php endforeach; // días ?>
            </tbody>
            <tfoot>
                <tr class="pt-total-row">
                    <td colspan="4" style="padding:5pt 5pt; text-align:right; font-size:9pt;">
                        TOTAL <?= strtoupper($meses[$mes]) ?> <?= $anio ?>
                    </td>
                    <td style="padding:5pt 5pt; text-align:right;">+<?= fmtPal($datos['total_ingresado']) ?></td>
                    <td style="padding:5pt 5pt; text-align:right;">−<?= fmtPal($datos['total_salido']) ?></td>
                    <td style="padding:5pt 5pt; text-align:right;"><?= fmtPal($datos['stock_actual']) ?></td>
                    <?php if ($con_pos): ?>
                    <td style="padding:5pt 5pt; text-align:right;"><?= fmtMoney($datos['total_costo_pos']) ?></td>
                    <?php endif; ?>
                    <?php if ($con_viaje): ?>
                    <td style="padding:5pt 5pt; text-align:right;"><?= fmtMoney($datos['total_costo_viajes']) ?></td>
                    <?php endif; ?>
                    <?php if ($con_saldo): ?>
                    <td style="padding:5pt 5pt; text-align:right;"><?= fmtMoney($datos['total_general']) ?></td>
                    <?php endif; ?>
                </tr>
            </tfoot>
        </table>

        <?php if ($con_pos || $con_viaje): ?>
        <div style="margin-top:10pt; padding-top:6pt; border-top:1pt solid #ccc; font-size:8pt; color:#555;">
            <?php if ($con_pos): ?>
            Almacenaje: <?= number_format($datos['total_posiciones'],1) ?> posiciones × $<?= number_format($precio_pos,2,',','.') ?>/día = <strong><?= fmtMoney($datos['total_costo_pos']) ?></strong>
            <?php endif; ?>
            <?php if ($con_pos && $con_viaje): ?>&nbsp;&nbsp;+&nbsp;&nbsp;<?php endif; ?>
            <?php if ($con_viaje): ?>
            Distribución: <?= $modo_camion ? $datos['total_camiones'].' camiones' : fmtPal($datos['total_pal_viajes']).' pal.' ?> × $<?= number_format($precio_viaje,2,',','.') ?>/<?= $modo_camion ? 'camión' : 'pallet' ?> = <strong><?= fmtMoney($datos['total_costo_viajes']) ?></strong>
            <?php endif; ?>
            &nbsp;&nbsp;&nbsp;
            <strong style="font-size:9.5pt;">Total a cobrar: <?= fmtMoney($datos['total_general']) ?></strong>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <!-- ── Tarjetas resumen (solo pantalla) ──────────────── -->
    <div class="row g-3 mb-4 no-print">
        <div class="col-6 col-md-2">
            <div class="card h-100"><div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Ingresados</div>
                <div class="fs-4 fw-bold text-success">+<?= fmtPal($datos['total_ingresado']) ?></div>
                <div class="text-muted" style="font-size:.72rem">pallets</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card h-100"><div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Entregados</div>
                <div class="fs-4 fw-bold text-danger">−<?= fmtPal($datos['total_salido']) ?></div>
                <div class="text-muted" style="font-size:.72rem">pallets</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card h-100"><div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1">En stock</div>
                <div class="fs-4 fw-bold text-primary"><?= fmtPal($datos['stock_actual']) ?></div>
                <div class="text-muted" style="font-size:.72rem">pallets</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card h-100"><div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Posiciones</div>
                <div class="fs-4 fw-bold" style="color:#5a29a3"><?= number_format($datos['total_posiciones'],1) ?></div>
                <?php if ($con_pos): ?><div class="fw-semibold text-success small mt-1"><?= fmtMoney($datos['total_costo_pos']) ?></div><?php endif; ?>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card h-100"><div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1"><?= $modo_camion ? 'Camiones' : 'Pal. distrib.' ?></div>
                <div class="fs-4 fw-bold" style="color:#c45200">
                    <?= $modo_camion ? $datos['total_camiones'] : fmtPal($datos['total_pal_viajes']) ?>
                </div>
                <?php if ($con_viaje): ?><div class="fw-semibold text-success small mt-1"><?= fmtMoney($datos['total_costo_viajes']) ?></div><?php endif; ?>
            </div></div>
        </div>
        <?php if ($con_pos || $con_viaje): ?>
        <div class="col-6 col-md-2">
            <div class="card h-100" style="border:2px solid #198754 !important"><div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Total a cobrar</div>
                <div class="fs-4 fw-bold text-success"><?= fmtMoney($datos['total_general']) ?></div>
                <div class="text-muted" style="font-size:.72rem">
                    <?php if ($con_pos && $con_viaje): ?>alm. + distrib.
                    <?php elseif ($con_pos): ?>almacenaje
                    <?php else: ?>distribución<?php endif; ?>
                </div>
            </div></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($datos['remitos_periodo'])): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        No hay remitos para <strong><?= h($prov_nombre) ?></strong> en <?= $meses[$mes] ?> <?= $anio ?>.
    </div>
    <?php else: ?>

    <!-- ── Tabla + form guardar camiones ──────────────────── -->
    <form method="POST" action="<?= url('modules/reportes/cuenta_corriente.php') ?>">
        <input type="hidden" name="proveedor_id" value="<?= $proveedor_id ?>">
        <input type="hidden" name="mes"          value="<?= $mes ?>">
        <input type="hidden" name="anio"         value="<?= $anio ?>">
        <input type="hidden" name="precio_pos"   value="<?= h($precio_pos) ?>">
        <input type="hidden" name="precio_viaje" value="<?= h($precio_viaje) ?>">
        <input type="hidden" name="precio_modo"  value="<?= h($precio_modo) ?>">

    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
            <span class="fw-semibold">
                <i class="bi bi-calendar3 me-2 text-primary"></i>
                <?= h($prov_nombre) ?> — <?= $meses[$mes] ?> <?= $anio ?>
            </span>
            <div class="d-flex gap-2 align-items-center no-print">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleTodo()">
                    <i class="bi bi-arrows-expand me-1"></i>Expandir todo
                </button>
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="bi bi-floppy me-1"></i>Guardar
                </button>
            </div>
        </div>
        <!-- ── Saldo inicial ─────────────────────────────── -->
        <div class="saldo-ini-bar d-flex align-items-center gap-2 px-3 py-2 no-print">
            <i class="bi bi-box-seam col-pos"></i>
            <span class="small fw-semibold text-uppercase" style="color:#5a29a3">Saldo inicial</span>
            <input type="number" name="saldo_ini" id="input-saldo-ini" class="input-saldo-ini"
                   step="0.5" min="0"
                   value="<?= number_format($saldo_ini, 1, '.', '') ?>"
                   title="Pallets en depósito al inicio del período">
            <span class="text-muted small">pallets al <?= fmtDia($ayer_ini) ?></span>
            <?php if ($saldo_ini_get !== null): ?>
            <span class="badge bg-warning text-dark ms-1">
                <i class="bi bi-exclamation-triangle me-1"></i>Sin guardar
            </span>
            <?php elseif (abs($saldo_ini - $saldo_ini_auto) > 0.01): ?>
            <span class="badge bg-secondary ms-1" title="Valor que calcula el sistema desde los remitos">
                Sistema: <?= number_format($saldo_ini_auto, 1) ?>
            </span>
            <?php else: ?>
            <span class="text-muted small ms-1">(calculado automáticamente)</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" id="tabla-cc">
            <thead>
                <tr>
                    <th style="width:90px">Fecha</th>
                    <th style="width:28px" class="no-print"></th><!-- expand -->
                    <th>Concepto</th>
                    <th class="text-end">Pal. entrada</th>
                    <th class="text-end">Pal. salida</th>
                    <?php if ($modo_camion): ?><th class="text-center no-print">Camiones</th><?php endif; ?>
                    <th class="text-end">Stock</th>
                    <?php if ($con_pos):   ?><th class="text-end">$ Almacenaje</th><?php endif; ?>
                    <?php if ($con_viaje): ?><th class="text-end"><?= $modo_camion ? '$ / camión' : '$ / pallet' ?></th><?php endif; ?>
                    <?php if ($con_saldo): ?><th class="text-end" style="background:#1a3a2a">Saldo acumulado</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php
            $rowIdx = 0;
            foreach ($datos['dias'] as $dia => $info):
                $tiene_entradas  = !empty($info['entradas']);
                $tiene_salidas   = !empty($info['salidas']);
                $tiene_devols    = !empty($info['devoluciones'] ?? []);
                $tiene_movs      = $tiene_entradas || $tiene_salidas || $tiene_devols;
                if (!$tiene_movs) continue;

                $sem     = diaSemana($dia);
                $esFinde = in_array($sem, ['Sáb','Dom']);
                $rowIdx++;
                $grpId   = 'grp-' . $rowIdx;

                // Concepto para la fila resumen
                $badges = '';
                if ($tiene_entradas) {
                    $n = count($info['entradas']);
                    $pal_e = array_sum(array_column($info['entradas'], 'total_pallets'));
                    $badges .= '<span class="badge bg-success me-1">' . $n . ' ingreso' . ($n>1?'s':'') . ' +' . number_format((float)$pal_e,1) . ' pal.</span>';
                }
                if ($tiene_salidas) {
                    $n = count($info['salidas']);
                    $badges .= '<span class="badge me-1" style="background:#f97316">' . $n . ' entrega' . ($n>1?'s':'') . ' −' . fmtPal($info['pal_sal']) . ' pal.</span>';
                }
                if ($tiene_devols) {
                    $n = count($info['devoluciones']);
                    $pal_d = array_sum(array_map(fn($r) => (float)($r['pallets_devueltos'] ?? $r['total_pallets']), $info['devoluciones']));
                    $badges .= '<span class="badge bg-info text-dark me-1">' . $n . ' devolución' . ($n>1?'es':'') . ' +' . number_format($pal_d,1) . ' pal.</span>';
                }
                if (!$tiene_movs) $badges = '<span class="text-muted small">Stock en depósito</span>';

                $tiene_detalle = $tiene_movs;
            ?>

            <!-- ── Fila resumen del día ─────────────────── -->
            <tr class="row-summary <?= $esFinde ? 'table-secondary' : '' ?>">
                <td class="small <?= $esFinde ? 'text-muted fst-italic' : '' ?>">
                    <?= $sem ?> <?= fmtDia($dia) ?>
                </td>
                <td class="no-print ps-1">
                    <?php if ($tiene_detalle): ?>
                    <button type="button" class="btn btn-expand btn-sm btn-outline-secondary rounded-circle"
                            onclick="toggleGrp('<?= $grpId ?>', this)">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                    <?php endif; ?>
                </td>
                <td><?= $badges ?></td>
                <td class="text-end text-success">
                    <?php
                    $pal_ent = $tiene_entradas ? array_sum(array_column($info['entradas'], 'total_pallets')) : 0;
                    $pal_dev_dia = $tiene_devols ? array_sum(array_map(fn($r) => (float)($r['pallets_devueltos'] ?? $r['total_pallets']), $info['devoluciones'])) : 0;
                    echo ($pal_ent + $pal_dev_dia > 0) ? '+' . fmtPal($pal_ent + $pal_dev_dia) : '—';
                    ?>
                </td>
                <td class="text-end col-viaje">
                    <?= $tiene_salidas ? '−' . fmtPal($info['pal_sal']) : '—' ?>
                </td>
                <?php if ($modo_camion): ?>
                <td class="text-center no-print">
                    <?php if ($tiene_salidas): ?>
                    <input type="number" name="cam[<?= $dia ?>]" class="input-cam"
                           value="<?= $info['camiones'] ?: '' ?>" min="0" max="99" placeholder="0">
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td class="text-end text-muted"><?= fmtPal($info['stock']) ?></td>
                <?php if ($con_pos): ?>
                <td class="text-end col-costo">
                    <?= ($info['costo_pos'] !== null && $info['costo_pos'] > 0) ? fmtMoney($info['costo_pos']) : '—' ?>
                </td>
                <?php endif; ?>
                <?php if ($con_viaje): ?>
                <td class="text-end <?= $tiene_salidas ? 'col-viaje' : 'text-muted' ?>">
                    <?php if ($tiene_salidas && $info['costo_viaje'] !== null): ?>
                        <?= ($modo_camion && $info['camiones'] === 0)
                            ? '<span class="text-muted fst-italic small">—</span>'
                            : fmtMoney($info['costo_viaje']) ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <?php endif; ?>
                <?php if ($con_saldo): ?>
                <td class="text-end col-costo">
                    <?= $info['saldo_acum'] > 0 ? fmtMoney($info['saldo_acum']) : '—' ?>
                </td>
                <?php endif; ?>
            </tr>

            <?php if ($tiene_detalle): ?>
            <!-- ── Filas de detalle desplegables ───────── -->

            <?php foreach ($info['entradas'] as $r): ?>
            <tr class="row-detail row-detail-ing <?= $grpId ?> d-none">
                <td class="ps-3 text-muted" style="font-size:.8rem"><?= fmtDia($dia) ?></td>
                <td class="no-print"></td>
                <td>
                    <span class="badge bg-success me-1">Ingreso</span>
                    <span class="font-monospace small"><?= h($r['nro_remito_propio']) ?></span>
                    <span class="text-muted ms-1 small"><?= h($r['cliente']) ?></span>
                </td>
                <td class="text-end text-success fw-semibold">+<?= fmtPal((float)$r['total_pallets']) ?></td>
                <td></td>
                <?php if ($modo_camion): ?><td class="no-print"></td><?php endif; ?>
                <td colspan="<?= 1 + ($con_pos?1:0) + ($con_viaje?1:0) + ($con_saldo?1:0) ?>"></td>
            </tr>
            <?php endforeach; ?>

            <?php foreach ($info['salidas'] as $r): ?>
            <tr class="row-detail row-detail-sal <?= $grpId ?> d-none">
                <td class="ps-3 text-muted" style="font-size:.8rem"><?= fmtDia($dia) ?></td>
                <td class="no-print"></td>
                <td>
                    <span class="badge me-1" style="background:#f97316">Entrega</span>
                    <span class="font-monospace small"><?= h($r['nro_remito_propio']) ?></span>
                    <span class="text-muted ms-1 small"><?= h($r['cliente']) ?></span>
                </td>
                <td></td>
                <td class="text-end col-viaje fw-semibold">−<?= fmtPal((float)$r['total_pallets']) ?></td>
                <?php if ($modo_camion): ?><td class="no-print"></td><?php endif; ?>
                <td colspan="<?= 1 + ($con_pos?1:0) + ($con_viaje?1:0) + ($con_saldo?1:0) ?>"></td>
            </tr>
            <?php endforeach; ?>

            <?php foreach ($info['devoluciones'] ?? [] as $r): ?>
            <tr class="row-detail row-detail-ing <?= $grpId ?> d-none">
                <td class="ps-3 text-muted" style="font-size:.8rem"><?= fmtDia($dia) ?></td>
                <td class="no-print"></td>
                <td>
                    <span class="badge bg-info text-dark me-1">Devolución</span>
                    <span class="font-monospace small"><?= h($r['nro_remito_propio']) ?></span>
                    <span class="text-muted ms-1 small"><?= h($r['cliente']) ?></span>
                </td>
                <td class="text-end text-success fw-semibold">+<?= fmtPal((float)($r['pallets_devueltos'] ?? $r['total_pallets'])) ?></td>
                <td></td>
                <?php if ($modo_camion): ?><td class="no-print"></td><?php endif; ?>
                <td colspan="<?= 1 + ($con_pos?1:0) + ($con_viaje?1:0) + ($con_saldo?1:0) ?>"></td>
            </tr>
            <?php endforeach; ?>

            <?php endif; // tiene_detalle ?>
            <?php endforeach; // dias ?>

            <!-- ── Total general ───────────────────────── -->
            <tr class="total-row">
                <?php if (!$con_pos && !$con_viaje): ?>
                <td colspan="<?= $ncols ?>" class="text-end pe-3">
                    <?= strtoupper($meses[$mes]) ?> <?= $anio ?> — <?= number_format($datos['total_posiciones'],1) ?> posiciones
                </td>
                <?php elseif (!$con_saldo): ?>
                <td colspan="<?= $ncols-1 ?>" class="text-end pe-3">TOTAL <?= strtoupper($meses[$mes]) ?> <?= $anio ?></td>
                <td class="text-end"><?= fmtMoney($datos['total_general']) ?></td>
                <?php else: ?>
                <td colspan="<?= $ncols-3 ?>" class="text-end pe-3">TOTAL <?= strtoupper($meses[$mes]) ?> <?= $anio ?></td>
                <td class="text-end"><?= fmtMoney($datos['total_costo_pos']) ?></td>
                <td class="text-end"><?= fmtMoney($datos['total_costo_viajes']) ?></td>
                <td class="text-end"><?= fmtMoney($datos['total_general']) ?></td>
                <?php endif; ?>
            </tr>

            </tbody>
        </table>
        </div>
        </div><!-- card-body -->
    </div><!-- card -->

    <div class="d-flex justify-content-end mt-2 no-print">
        <button type="submit" class="btn btn-warning">
            <i class="bi bi-floppy me-1"></i>Guardar
        </button>
    </div>
    </form>

    <!-- ── Fórmula al pie ──────────────────────────────────── -->
    <?php if ($con_pos || $con_viaje): ?>
    <div class="card mt-3">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <?php if ($con_pos): ?>
                <span class="text-muted small">
                    Almacenaje: <strong><?= number_format($datos['total_posiciones'],1) ?> pal. cobrados</strong>
                    × <strong>$<?= number_format($precio_pos,2,',','.') ?>/día</strong>
                    = <strong class="col-costo"><?= fmtMoney($datos['total_costo_pos']) ?></strong>
                </span>
                <?php endif; ?>
                <?php if ($con_pos && $con_viaje): ?><span class="text-muted">+</span><?php endif; ?>
                <?php if ($con_viaje): ?>
                <span class="text-muted small">
                    Distribución: <strong>
                        <?= $modo_camion ? $datos['total_camiones'].' camiones' : fmtPal($datos['total_pal_viajes']).' pal.' ?>
                    </strong>
                    × <strong>$<?= number_format($precio_viaje,2,',','.') ?>/<?= $modo_camion ? 'camión' : 'pallet' ?></strong>
                    = <strong class="col-viaje"><?= fmtMoney($datos['total_costo_viajes']) ?></strong>
                </span>
                <?php endif; ?>
                <span class="ms-auto fs-5 fw-bold text-success">
                    Total: <?= fmtMoney($datos['total_general']) ?>
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // remitos_periodo ?>
    <?php endif; // datos ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleGrp(grpId, btn) {
    const rows = document.querySelectorAll('tr.' + grpId);
    const open = !rows[0]?.classList.contains('d-none');
    rows.forEach(r => r.classList.toggle('d-none', open));
    btn.classList.toggle('open', !open);
}
(function () {
    const inp = document.getElementById('input-saldo-ini');
    if (!inp) return;
    const orig = inp.value;
    inp.addEventListener('blur', function () {
        if (this.value === orig) return; // sin cambio, no recargar
        const url = new URL(window.location.href);
        url.searchParams.set('saldo_ini', this.value || '0');
        window.location.href = url.toString();
    });
})();
let todoAbierto = false;
function toggleTodo() {
    todoAbierto = !todoAbierto;
    document.querySelectorAll('tr.row-detail').forEach(r => r.classList.toggle('d-none', !todoAbierto));
    document.querySelectorAll('.btn-expand').forEach(b => b.classList.toggle('open', todoAbierto));
    event.currentTarget.innerHTML = todoAbierto
        ? '<i class="bi bi-arrows-collapse me-1"></i>Colapsar todo'
        : '<i class="bi bi-arrows-expand me-1"></i>Expandir todo';
}
</script>
</body>
</html>