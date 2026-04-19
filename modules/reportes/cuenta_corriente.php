<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

// ── Guardar camiones (PRG) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid_post = (int)($_POST['proveedor_id'] ?? 0);
    if ($pid_post > 0) {
        $db->beginTransaction();
        try {
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

// ── Proveedores ───────────────────────────────────────────────
$pq = $db->prepare("SELECT id, nombre FROM proveedores WHERE empresa_id=? AND activo=1 ORDER BY nombre");
$pq->execute([$eid]);
$proveedores = $pq->fetchAll();

$prov_nombre = '';
foreach ($proveedores as $p) {
    if ((int)$p['id'] === $proveedor_id) { $prov_nombre = $p['nombre']; break; }
}

// ── Cálculo principal ─────────────────────────────────────────
$datos = null;
if ($proveedor_id > 0) {
    $inicio = sprintf('%04d-%02d-01', $anio, $mes);
    $fin    = date('Y-m-t', strtotime($inicio));

    $stmt = $db->prepare("
        SELECT r.id, r.nro_remito_propio, r.total_pallets,
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
          AND (ef.fecha_salida_real IS NULL OR ef.fecha_salida_real >= ?)
        ORDER BY DATE(i.fecha_ingreso), r.id
    ");
    $stmt->execute([$eid, $proveedor_id, $fin, $inicio]);
    $remitos_periodo = $stmt->fetchAll();

    $tc = $db->prepare("SELECT fecha, camiones FROM cc_viajes
                        WHERE empresa_id=? AND proveedor_id=? AND fecha BETWEEN ? AND ?");
    $tc->execute([$eid, $proveedor_id, $inicio, $fin]);
    $truck_counts = array_column($tc->fetchAll(), 'camiones', 'fecha');

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
    $eventos_fechas = [];
    foreach ($remitos_periodo as $r) {
        if ($r['fecha_ingreso'] >= $inicio && $r['fecha_ingreso'] <= $fin)
            $eventos_fechas[$r['fecha_ingreso']] = true;
        if ($r['fecha_salida_real'] !== null
            && $r['fecha_salida_real'] >= $inicio
            && $r['fecha_salida_real'] <= $fin)
            $eventos_fechas[$r['fecha_salida_real']] = true;
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
    $precio_pos_dia         = $precio_pos > 0 ? $precio_pos / 30.0 : 0.0;

    // Saldo al cierre del evento anterior.
    // Si había pallets de meses anteriores, los contamos.
    $saldo_evento_anterior = 0.0;
    $fecha_evento_anterior = $inicio;  // arrancamos desde el inicio del mes
    $ayer_inicio = (new DateTime($inicio))->modify('-1 day')->format('Y-m-d');
    foreach ($remitos_periodo as $r) {
        $pal = (float)$r['total_pallets'];
        if ($r['fecha_ingreso'] <= $ayer_inicio &&
            ($r['fecha_salida_real'] === null || $r['fecha_salida_real'] > $ayer_inicio)) {
            $saldo_evento_anterior += $pal;
        }
    }

    foreach ($eventos_fechas as $d) {
        // Stock al CIERRE de este evento (después de todos los movimientos de hoy)
        $stock    = 0.0;
        $pal_sal  = 0.0;
        $entradas = [];
        $salidas  = [];

        foreach ($remitos_periodo as $r) {
            $pal = (float)$r['total_pallets'];
            if ($r['fecha_ingreso'] <= $d &&
                ($r['fecha_salida_real'] === null || $r['fecha_salida_real'] > $d)) {
                $stock += $pal;
            }
            if ($r['fecha_ingreso'] === $d)     $entradas[] = $r;
            if ($r['fecha_salida_real'] === $d) { $salidas[] = $r; $pal_sal += $pal; }
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

        $dias[$d] = [
            'stock'          => $stock,                 // cierre de hoy
            'saldo_anterior' => $saldo_evento_anterior, // base del cobro
            'dias_entre'     => $dias_entre,            // días transcurridos
            'pos_cobradas'   => $pos_cobradas,          // pal × días
            'entradas'       => $entradas,
            'salidas'        => $salidas,
            'pal_sal'        => $pal_sal,
            'camiones'       => $camiones_dia,
            'costo_pos'      => $cobro_pos,
            'costo_viaje'    => $costo_viaje,
            'saldo_acum'     => $saldo_acum,
        ];

        // Este evento pasa a ser el anterior para el próximo
        $saldo_evento_anterior = $stock;
        $fecha_evento_anterior = $d;
    }

    // Resumen global
    $total_ingresado = 0.0;
    $total_salido    = 0.0;
    $stock_actual    = 0.0;
    foreach ($remitos_periodo as $r) {
        $pal = (float)$r['total_pallets'];
        if ($r['fecha_ingreso'] >= $inicio && $r['fecha_ingreso'] <= $fin) $total_ingresado += $pal;
        if ($r['fecha_salida_real'] !== null
            && $r['fecha_salida_real'] >= $inicio
            && $r['fecha_salida_real'] <= $fin)                             $total_salido    += $pal;
        if ($r['fecha_salida_real'] === null)                               $stock_actual    += $pal;
    }

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
$ncols = 8 + ($modo_camion ? 1 : 0) + ($con_pos ? 1 : 0) + ($con_viaje ? 1 : 0) + ($con_saldo ? 1 : 0);

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
            body { background: #fff; }
            .no-print, .row-detail { display: none !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
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
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4" style="max-width:1200px">

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
    </form>

    <?php if ($datos): ?>

    <!-- ── Encabezado impresión ────────────────────────────── -->
    <div class="d-none d-print-block mb-3">
        <h4 class="fw-bold mb-0"><?= APP_NAME ?> — Cuenta corriente</h4>
        <div class="text-muted fs-6">
            <?= h($prov_nombre) ?> &nbsp;·&nbsp; <?= $meses[$mes] ?> <?= $anio ?>
            <?php if ($con_pos):   ?> &nbsp;·&nbsp; Almacenaje $<?= number_format($precio_pos,2,',','.') ?>/pos.<?php endif; ?>
            <?php if ($con_viaje): ?> &nbsp;·&nbsp; Distrib. $<?= number_format($precio_viaje,2,',','.') ?>/<?= $modo_camion ? 'cam.' : 'pal.' ?><?php endif; ?>
        </div>
        <hr>
    </div>

    <!-- ── Tarjetas resumen ────────────────────────────────── -->
    <div class="row g-3 mb-4">
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
                <?php if ($modo_camion): ?>
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="bi bi-floppy me-1"></i>Guardar camiones
                </button>
                <?php endif; ?>
            </div>
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
                    <th class="text-end">Pal. ayer<br><small class="fw-normal opacity-75">base cobro</small></th>
                    <th class="text-end">Stock hoy<br><small class="fw-normal opacity-75">cierre</small></th>
                    <?php if ($con_pos):   ?><th class="text-end">$ Almacenaje</th><?php endif; ?>
                    <?php if ($con_viaje): ?><th class="text-end"><?= $modo_camion ? '$ / camión' : '$ / pallet' ?></th><?php endif; ?>
                    <?php if ($con_saldo): ?><th class="text-end" style="background:#1a3a2a">Saldo acumulado</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php
            $rowIdx = 0;
            foreach ($datos['dias'] as $dia => $info):
                $tiene_entradas = !empty($info['entradas']);
                $tiene_salidas  = !empty($info['salidas']);
                $tiene_movs     = $tiene_entradas || $tiene_salidas;
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
                    <?php if ($tiene_entradas): ?>
                    +<?= fmtPal(array_sum(array_column($info['entradas'], 'total_pallets'))) ?>
                    <?php else: ?>—<?php endif; ?>
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
                <td class="text-end col-pos" title="<?= $info['saldo_anterior'] ?> pal × <?= $info['dias_entre'] ?> días"><?= fmtPal($info['saldo_anterior']) ?> × <?= $info['dias_entre'] ?>d</td>
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

            <?php endif; // tiene_detalle ?>
            <?php endforeach; // dias ?>

            <!-- ── Subtotales ──────────────────────────── -->
            <?php if ($con_pos || $con_viaje): ?>
            <tr class="subtotal-row">
                <td colspan="<?= 7 + ($modo_camion?1:0) + 1 ?>" class="text-end pe-2 text-muted small text-uppercase">Subtotal almacenaje</td>
                <td class="text-end col-pos"><?= number_format($datos['total_posiciones'],1) ?> pal.</td>
                <?php if ($con_pos):   ?><td class="text-end col-costo"><?= fmtMoney($datos['total_costo_pos']) ?></td><?php endif; ?>
                <?php if ($con_viaje): ?><td></td><?php endif; ?>
                <?php if ($con_saldo): ?><td></td><?php endif; ?>
            </tr>
            <tr class="subtotal-row">
                <td colspan="<?= 3 + ($modo_camion?1:0) + 1 ?>" class="text-end pe-2 text-muted small text-uppercase">Subtotal distribución</td>
                <td class="text-end col-viaje">
                    <?= $modo_camion ? $datos['total_camiones'].' cam.' : fmtPal($datos['total_pal_viajes']).' pal.' ?>
                </td>
                <td colspan="3"></td>
                <?php if ($con_pos):   ?><td></td><?php endif; ?>
                <?php if ($con_viaje): ?><td class="text-end col-viaje"><?= fmtMoney($datos['total_costo_viajes']) ?></td><?php endif; ?>
                <?php if ($con_saldo): ?><td></td><?php endif; ?>
            </tr>
            <?php endif; ?>

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

    <?php if ($modo_camion): ?>
    <div class="d-flex justify-content-end mt-2 no-print">
        <button type="submit" class="btn btn-warning">
            <i class="bi bi-floppy me-1"></i>Guardar camiones
        </button>
    </div>
    <?php endif; ?>
    </form>

    <!-- ── Fórmula al pie ──────────────────────────────────── -->
    <?php if ($con_pos || $con_viaje): ?>
    <div class="card mt-3">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <?php if ($con_pos): ?>
                <span class="text-muted small">
                    Almacenaje: <strong><?= number_format($datos['total_posiciones'],1) ?> pal. cobrados</strong>
                    × <strong>$<?= number_format($precio_pos,2,',','.') ?>/30</strong>
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