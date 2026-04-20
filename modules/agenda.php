<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();
$hoy = date('Y-m-d');

// ── Reparar remitos con turno activo pero estado='pendiente' ────
$db->prepare("
    UPDATE remitos r
    INNER JOIN turnos t ON t.remito_id = r.id AND t.empresa_id = r.empresa_id
    SET r.estado = IF(t.tipo = 'turno', 'turnado', 'programado')
    WHERE r.empresa_id = ? AND r.estado = 'pendiente'
      AND t.estado = 'pendiente'
")->execute([$eid]);

// ── Parámetros ──────────────────────────────────────────────────
$vista    = $_GET['vista']  ?? 'semana';
$base     = $_GET['fecha']  ?? $hoy;
$ver_todo = isset($_GET['ver_todo']);
try { $baseDate = new DateTime($base); } catch (Exception $e) { $baseDate = new DateTime(); }

if ($vista === 'mes') {
    $baseDate->modify('first day of this month');
    $desde     = $baseDate->format('Y-m-d');
    $hasta     = (clone $baseDate)->modify('last day of this month')->format('Y-m-d');
    $prevFecha = (clone $baseDate)->modify('-1 month')->format('Y-m-d');
    $nextFecha = (clone $baseDate)->modify('+1 month')->format('Y-m-d');
    $mnames    = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                  'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $titulo    = $mnames[(int)$baseDate->format('n')] . ' ' . $baseDate->format('Y');
} else {
    $desde     = $baseDate->format('Y-m-d');
    $hasta     = (clone $baseDate)->modify('+6 days')->format('Y-m-d');
    $prevFecha = (clone $baseDate)->modify('-7 days')->format('Y-m-d');
    $nextFecha = (clone $baseDate)->modify('+7 days')->format('Y-m-d');
    $titulo    = fmtFecha($desde) . ' — ' . fmtFecha($hasta);
}

function fmtFecha(string $ymd): string {
    [$y,$m,$d] = explode('-', $ymd);
    $M = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    return (int)$d . ' ' . $M[(int)$m];
}
$dsem = ['','Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];

// ════════════════════════════════════════════════════════════════
// QUERY 1: Remitos del período que tienen turno o fecha_entrega
// Incluye remitos con estado pendiente/turnado/programado/en_camino
// siempre que tengan una fecha de agenda dentro del rango
// ════════════════════════════════════════════════════════════════
$stmt = $db->prepare("
    SELECT
        'remito'                              AS tipo_card,
        r.id                                  AS remito_id,
        r.nro_remito_propio,
        r.estado                              AS remito_estado,
        r.total_pallets,
        c.nombre                              AS cliente,
        t.id                                  AS turno_id,
        t.tipo                                AS turno_tipo,
        t.hora_turno,
        COALESCE(t.fecha, DATE(r.fecha_entrega)) AS fecha_agenda,
        er.entrega_id,
        e.estado                              AS entrega_estado,
        tr.nombre                             AS transportista,
        cam.patente
    FROM remitos r
    JOIN  clientes c ON c.id = r.cliente_id
    LEFT JOIN turnos t
           ON t.remito_id = r.id AND t.empresa_id = r.empresa_id
    LEFT JOIN entrega_remitos er ON er.remito_id = r.id
    LEFT JOIN entregas e
           ON e.id = er.entrega_id AND e.empresa_id = r.empresa_id
          AND e.estado IN ('pendiente','en_camino')
    LEFT JOIN transportistas tr  ON tr.id = e.transportista_id
    LEFT JOIN camiones       cam ON cam.id = e.camion_id
    WHERE r.empresa_id = ?
      AND COALESCE(t.fecha, DATE(r.fecha_entrega)) BETWEEN ? AND ?
      AND r.estado NOT IN ('entregado','en_stock','parcialmente_entregado')
    ORDER BY fecha_agenda, t.hora_turno, r.id
");
$stmt->execute([$eid, $desde, $hasta]);
$remitos_periodo = $stmt->fetchAll();

// ════════════════════════════════════════════════════════════════
// QUERY 2: Turnos SIN remito del período
// ════════════════════════════════════════════════════════════════
$stmt_t = $db->prepare("
    SELECT
        'turno_solo'    AS tipo_card,
        NULL            AS remito_id,
        NULL            AS nro_remito_propio,
        t.estado        AS remito_estado,
        NULL            AS total_pallets,
        c.nombre        AS cliente,
        t.id            AS turno_id,
        t.tipo          AS turno_tipo,
        t.hora_turno,
        t.fecha         AS fecha_agenda,
        NULL            AS entrega_id,
        NULL            AS entrega_estado,
        NULL            AS transportista,
        NULL            AS patente
    FROM turnos t
    JOIN clientes c ON c.id = t.cliente_id
    WHERE t.empresa_id = ?
      AND t.remito_id IS NULL
      AND t.fecha BETWEEN ? AND ?
      AND t.estado NOT IN ('entregado')
    ORDER BY t.fecha, t.hora_turno, t.id
");
$stmt_t->execute([$eid, $desde, $hasta]);
$turnos_solos = $stmt_t->fetchAll();

// ── Remitos vencidos (solo con ver_todo) ─────────────────────────
$vencidos = [];
if ($ver_todo) {
    $sv = $db->prepare("
        SELECT r.id AS remito_id, r.nro_remito_propio, r.estado AS remito_estado,
               r.total_pallets, c.nombre AS cliente,
               t.id AS turno_id, t.tipo AS turno_tipo, t.hora_turno,
               COALESCE(t.fecha, DATE(r.fecha_entrega)) AS fecha_agenda,
               er.entrega_id, tr.nombre AS transportista
        FROM remitos r
        JOIN  clientes c ON c.id = r.cliente_id
        LEFT JOIN turnos t ON t.remito_id = r.id AND t.empresa_id = r.empresa_id
        LEFT JOIN entrega_remitos er ON er.remito_id = r.id
        LEFT JOIN entregas e ON e.id = er.entrega_id AND e.empresa_id = r.empresa_id
                            AND e.estado IN ('pendiente','en_camino')
        LEFT JOIN transportistas tr ON tr.id = e.transportista_id
        WHERE r.empresa_id = ?
          AND COALESCE(t.fecha, DATE(r.fecha_entrega)) < ?
          AND COALESCE(t.fecha, DATE(r.fecha_entrega)) >= DATE_SUB(?, INTERVAL 60 DAY)
          AND r.estado NOT IN ('entregado','en_stock')
        ORDER BY fecha_agenda DESC, r.id
    ");
    $sv->execute([$eid, $desde, $desde]);
    $vencidos = $sv->fetchAll();
}

// ── Entregas pendientes del período (para select asignar + sección entregas) ──
$ents_raw = $db->prepare("
    SELECT e.id, e.fecha, tr.nombre AS transportista, cam.patente,
           e.camion_id, e.chofer_id
    FROM entregas e
    LEFT JOIN transportistas tr  ON tr.id = e.transportista_id
    LEFT JOIN camiones       cam ON cam.id = e.camion_id
    WHERE e.empresa_id=? AND e.fecha BETWEEN ? AND ? AND e.estado='pendiente'
    ORDER BY e.fecha, e.id
");
$ents_raw->execute([$eid, $desde, $hasta]);
$ents_x_dia = [];
foreach ($ents_raw->fetchAll() as $e) {
    $ents_x_dia[$e['fecha']][] = $e;
}

// ── Organizar por día ────────────────────────────────────────────
$dias = [];
$cur = new DateTime($desde); $fin = new DateTime($hasta);
while ($cur <= $fin) { $dias[$cur->format('Y-m-d')] = []; $cur->modify('+1 day'); }

// Unir remitos + turnos solos, ordenados por hora dentro de cada día
$cards_dia = array_fill_keys(array_keys($dias), []);
foreach ($remitos_periodo as $r) {
    if (array_key_exists($r['fecha_agenda'], $cards_dia))
        $cards_dia[$r['fecha_agenda']][] = $r;
}
foreach ($turnos_solos as $t) {
    if (array_key_exists($t['fecha_agenda'], $cards_dia))
        $cards_dia[$t['fecha_agenda']][] = $t;
}
// Ordenar cada día por hora_turno
foreach ($cards_dia as &$arr) {
    usort($arr, function($a, $b) {
        return strcmp($a['hora_turno'] ?? '99:99', $b['hora_turno'] ?? '99:99');
    });
}
unset($arr);

$nav_modulo = 'agenda';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agenda — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        body { background:#eef1f6; }

        /* Columnas */
        .semana-wrap { display:flex; gap:.5rem; overflow-x:auto; padding-bottom:.5rem; }
        .dia-col     { min-width:210px; flex:1; background:#fff; border-radius:.5rem;
                       box-shadow:0 1px 5px rgba(0,0,0,.09); overflow:hidden; }
        .dia-col.hoy    .dia-hdr { background:#1d4ed8; }
        .dia-col.pasado .dia-hdr { background:#64748b; }
        .dia-col.finde  .dia-hdr { background:#94a3b8; }
        .dia-col.finde  { background:#f1f5f9; }
        .dia-hdr { background:#2c3e50; color:#fff; padding:.4rem .75rem;
                   font-size:.82rem; font-weight:700;
                   display:flex; justify-content:space-between; align-items:center; }
        .dia-body { padding:.45rem; min-height:70px; }

        /* Tarjeta base */
        .rem-card {
            border-radius:.4rem; padding:.5rem .65rem; margin-bottom:.45rem;
            font-size:.83rem; border-left:4px solid #94a3b8;
            background:#f8fafc;
            border-top:1px solid #e2e8f0;
            border-right:1px solid #e2e8f0;
            border-bottom:1px solid #e2e8f0;
        }
        /* Estados por color de borde izquierdo */
        .rem-card.est-no_asignado { border-left-color:#3b82f6; background:#f0f7ff; }
        .rem-card.est-asignado    { border-left-color:#f97316; background:#fff7ed; }
        .rem-card.est-en_camino   { border-left-color:#06b6d4; background:#ecfeff; }
        .rem-card.est-sin_remito  { border-left-color:#a78bfa; background:#faf5ff; }
        .rem-card.est-vencido     { border-left-color:#ef4444; background:#fff1f2; }

        /* Contenido de la tarjeta */
        .rc-top      { display:flex; justify-content:space-between; align-items:flex-start;
                       gap:.3rem; flex-wrap:wrap; margin-bottom:.2rem; }
        .rc-badges   { display:flex; gap:.2rem; flex-wrap:wrap; align-items:center; }
        .rc-hora     { font-size:.72rem; color:#64748b; font-weight:600; white-space:nowrap; }
        .rc-cliente  { font-weight:700; color:#1e293b; font-size:.86rem; }
        .rc-nro      { font-size:.76rem; color:#0369a1; margin-top:.1rem; }
        .rc-pal      { color:#7c3aed; font-weight:700; margin-left:.3rem; }
        .rc-trans    { font-size:.75rem; color:#92400e; margin-top:.2rem; }
        .rc-warn     { font-size:.74rem; color:#b45309; margin-top:.1rem; }

        /* Botones de turno */
        .rc-turno-btns { display:flex; gap:.2rem; margin-top:.35rem; }
        .rc-turno-btns .btn { font-size:.72rem; padding:.15rem .4rem; }

        /* Sección asignar entrega */
        .rc-entrega { margin-top:.4rem; padding-top:.4rem;
                      border-top:1px dashed #cbd5e1; }
        .rc-entrega .lbl { font-size:.68rem; font-weight:700; text-transform:uppercase;
                           color:#94a3b8; letter-spacing:.04em; margin-bottom:.25rem; }
        .rc-entrega select { font-size:.8rem; }
        .rc-entrega .btn   { font-size:.8rem; }

        /* Sección entregas del día */
        .ent-dia-sec { border-top:2px dashed #e2e8f0; margin-top:.5rem; padding-top:.4rem; }
        .ent-dia-hdr { font-size:.67rem; font-weight:700; text-transform:uppercase;
                       color:#94a3b8; letter-spacing:.05em; margin-bottom:.3rem; }
        .ent-mini    { background:#fff7ed; border:1px solid #fed7aa; border-radius:.35rem;
                       padding:.35rem .5rem; margin-bottom:.3rem; font-size:.78rem; }
        .ent-mini .trans { font-weight:700; color:#92400e; }
        .ent-mini .veh   { color:#64748b; font-size:.72rem; }

        /* Botones al pie del día */
        .btn-dia { width:100%; border-radius:.3rem; background:transparent; font-size:.75rem;
                   padding:.25rem; cursor:pointer; margin-top:.3rem;
                   display:block; text-align:center; text-decoration:none; transition:all .15s; }
        .btn-dia-ent { border:1px dashed #f97316; color:#f97316; }
        .btn-dia-ent:hover { background:#fff7ed; color:#c2410c; }
        .btn-dia-tur { border:1px dashed #94a3b8; color:#94a3b8; }
        .btn-dia-tur:hover { border-color:#3b82f6; color:#3b82f6; background:#eff6ff; }

        /* Vista mes */
        .mes-grid  { display:grid; grid-template-columns:repeat(7,1fr); gap:3px; }
        .mes-hdr-d { text-align:center; font-size:.73rem; font-weight:700;
                     color:#64748b; text-transform:uppercase; padding:.3rem 0; }
        .mes-hdr-d.finde { color:#b0b8c8; }
        .mes-celda { background:#fff; border:1px solid #e2e8f0; border-radius:.3rem;
                     min-height:65px; padding:.3rem; cursor:pointer; }
        .mes-celda:hover  { background:#f0f4ff; }
        .mes-celda.hoy    { border-color:#3b82f6; background:#eff6ff; }
        .mes-celda.finde  { background:#f1f5f9; }
        .mes-dnum { font-weight:700; font-size:.83rem; color:#1e293b; }
        .mes-celda.hoy .mes-dnum { color:#1d4ed8; }
        .mes-pip { display:inline-block; width:8px; height:8px; border-radius:50%; margin:1px; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-3 px-lg-4">

    <!-- Encabezado -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h5 class="fw-bold mb-0"><i class="bi bi-calendar3 me-2 text-primary"></i>Agenda</h5>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <a href="?vista=<?= $vista ?>&fecha=<?= $prevFecha ?><?= $ver_todo?'&ver_todo':'' ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
            <a href="?vista=<?= $vista ?>&fecha=<?= $hoy ?><?= $ver_todo?'&ver_todo':'' ?>"       class="btn btn-sm btn-outline-secondary">Hoy</a>
            <a href="?vista=<?= $vista ?>&fecha=<?= $nextFecha ?><?= $ver_todo?'&ver_todo':'' ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
            <div class="btn-group btn-group-sm ms-1">
                <a href="?vista=semana&fecha=<?= $desde ?><?= $ver_todo?'&ver_todo':'' ?>" class="btn btn-outline-primary <?= $vista==='semana'?'active':'' ?>"><i class="bi bi-calendar-week me-1"></i>Semana</a>
                <a href="?vista=mes&fecha=<?= $desde ?><?= $ver_todo?'&ver_todo':'' ?>"   class="btn btn-outline-primary <?= $vista==='mes'?'active':'' ?>"><i class="bi bi-calendar-month me-1"></i>Mes</a>
            </div>
            <a href="?vista=<?= $vista ?>&fecha=<?= $base ?><?= $ver_todo?'':'&ver_todo' ?>"
               class="btn btn-sm <?= $ver_todo?'btn-secondary':'btn-outline-secondary' ?>">
                <i class="bi bi-<?= $ver_todo?'eye-slash':'eye' ?> me-1"></i><?= $ver_todo?'Ocultar vencidos':'Ver no entregados' ?>
            </a>
        </div>
    </div>
    <p class="text-muted small mb-3"><?= h($titulo) ?></p>

    <?php if ($ver_todo && $vencidos): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-danger text-white py-2">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>No entregados</strong> — <?= count($vencidos) ?> remito<?= count($vencidos)!==1?'s':'' ?> de fechas anteriores
        </div>
        <div class="card-body p-2">
            <div class="row g-2">
            <?php foreach ($vencidos as $r): ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <div class="rem-card est-vencido">
                    <div class="rc-top">
                        <span class="badge bg-danger" style="font-size:.7rem">No entregado</span>
                        <span class="text-muted" style="font-size:.7rem"><?= fmtFecha($r['fecha_agenda']) ?></span>
                    </div>
                    <div class="rc-cliente"><?= h($r['cliente']) ?></div>
                    <div class="rc-nro"><i class="bi bi-file-earmark-text me-1"></i><?= h($r['nro_remito_propio']) ?>
                        <?php if ($r['total_pallets'] > 0): ?>
                        <span class="rc-pal"><?= number_format((float)$r['total_pallets'],1) ?>p</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($r['turno_id']): ?>
                    <div class="rc-turno-btns mt-2">
                        <a href="<?= url('modules/turno_form.php') ?>?id=<?= $r['turno_id'] ?>"
                           class="btn btn-outline-secondary" title="Cambiar turno">
                            <i class="bi bi-pencil me-1"></i>Cambiar turno
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($vista === 'semana'): ?>
    <!-- ══════════ VISTA SEMANA ══════════ -->
    <div class="semana-wrap">
    <?php foreach ($dias as $fecha => $__):
        $dt      = new DateTime($fecha);
        $dow     = (int)$dt->format('N');
        $esHoy   = $fecha === $hoy;
        $esPas   = $fecha < $hoy;
        $esFinde = $dow >= 6;
        $clsDia  = ($esHoy?'hoy':($esPas?'pasado':'')) . ($esFinde?' finde':'');

        $cards    = $cards_dia[$fecha] ?? [];
        $ents_hoy = $ents_x_dia[$fecha] ?? [];
    ?>
    <div class="dia-col <?= trim($clsDia) ?>">
        <div class="dia-hdr">
            <span><?= $dsem[$dow] ?> <?= $dt->format('j') ?></span>
            <span style="font-size:.7rem;opacity:.8"><?= $dt->format('M') ?></span>
        </div>
        <div class="dia-body">

        <?php foreach ($cards as $r):
            $es_solo    = $r['tipo_card'] === 'turno_solo';  // turno sin remito
            $asignado   = !empty($r['entrega_id']);
            $en_camino  = $r['remito_estado'] === 'en_camino';

            if ($en_camino)        { $est_cls='est-en_camino'; $est_lbl='En camino'; $est_bg='badge-estado-en_camino'; }
            elseif ($es_solo)      { $est_cls='est-sin_remito'; $est_lbl='Sin remito'; $est_bg='badge-estado-sin_remito'; }
            elseif ($asignado)     { $est_cls='est-asignado'; $est_lbl='Asignado'; $est_bg='badge-estado-asignado'; }
            else                   { $est_cls='est-no_asignado'; $est_lbl='No asignado'; $est_bg='badge-estado-no_asignado'; }

            // Badge tipo turno
            $tipo_lbl = $r['turno_tipo'] === 'turno' ? 'Turno' : ($r['turno_tipo'] === 'programado' ? 'Prog' : '');
            $tipo_bg  = $r['turno_tipo'] === 'turno' ? 'badge-estado-turnado' : 'badge-estado-programado';
        ?>
        <div class="rem-card <?= $est_cls ?>">
            <div class="rc-top">
                <div class="rc-badges">
                    <span class="badge <?= $est_bg ?>" style="font-size:.68rem"><?= $est_lbl ?></span>
                    <?php if ($tipo_lbl): ?>
                    <span class="badge <?= $tipo_bg ?> bg-opacity-75" style="font-size:.65rem"><?= $tipo_lbl ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($r['hora_turno']): ?>
                <span class="rc-hora"><i class="bi bi-clock me-1"></i><?= substr($r['hora_turno'],0,5) ?></span>
                <?php endif; ?>
            </div>

            <div class="rc-cliente"><?= h($r['cliente']) ?></div>

            <?php if (!$es_solo): ?>
            <div class="rc-nro">
                <i class="bi bi-file-earmark-text me-1"></i><?= h($r['nro_remito_propio']) ?>
                <?php if ($r['total_pallets'] > 0): ?>
                <span class="rc-pal"><?= number_format((float)$r['total_pallets'],1) ?>p</span>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="rc-warn"><i class="bi bi-exclamation-circle me-1"></i>Sin remito asignado</div>
            <?php endif; ?>

            <?php if ($asignado && ($r['transportista'] || $r['patente'])): ?>
            <div class="rc-trans">
                <i class="bi bi-truck me-1"></i><?= h($r['transportista'] ?? '') ?>
                <?php if ($r['patente']): ?> · <?= h($r['patente']) ?><?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Botones de turno -->
            <?php if ($r['turno_id'] && !$en_camino): ?>
            <div class="rc-turno-btns">
                <a href="<?= url('modules/turno_form.php') ?>?id=<?= $r['turno_id'] ?>"
                   class="btn btn-outline-secondary" title="Cambiar turno">
                    <i class="bi bi-pencil me-1"></i>Cambiar turno
                </a>
                <form method="POST" action="<?= url('modules/turno_eliminar.php') ?>" class="d-inline"
                      onsubmit="return confirm('¿Eliminar turno?')">
                    <input type="hidden" name="id"    value="<?= $r['turno_id'] ?>">
                    <input type="hidden" name="fecha" value="<?= $fecha ?>">
                    <button class="btn btn-outline-danger" title="Eliminar turno">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Botón entrega -->
            <?php if (!$es_solo && !$en_camino): ?>
            <div class="rc-entrega">
                <?php if ($asignado): ?>
                <a href="<?= url('modules/entrega_dia_form.php') ?>?id=<?= $r['entrega_id'] ?>"
                   class="btn btn-outline-warning btn-sm w-100">
                    <i class="bi bi-truck me-1"></i>Entrega
                </a>
                <?php else: ?>
                <a href="<?= url('modules/entrega_dia_form.php') ?>?remito_id=<?= $r['remito_id'] ?>&fecha=<?= $fecha ?>"
                   class="btn btn-warning btn-sm w-100">
                    <i class="bi bi-truck-front me-1"></i>Entrega
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; // cards ?>

        <?php if (!$cards): ?>
        <div class="text-center text-muted py-2" style="font-size:.78rem">Sin remitos</div>
        <?php endif; ?>

        <!-- Entregas pendientes del día -->
        <?php if ($ents_hoy): ?>
        <div class="ent-dia-sec">
            <div class="ent-dia-hdr"><i class="bi bi-truck me-1"></i>Entregas del día</div>
            <?php foreach ($ents_hoy as $ent):
                $tieneVeh = !empty($ent['camion_id']) && !empty($ent['chofer_id']);
                $lbl_ent  = $ent['transportista'] ?? ('Entrega #'.$ent['id']);
                if ($ent['patente'] && $ent['transportista']) $lbl_ent = $ent['transportista'].' · '.$ent['patente'];
            ?>
            <div class="ent-mini">
                <div class="trans"><?= h($lbl_ent) ?></div>
                <?php if (!$tieneVeh): ?>
                <div class="veh"><i class="bi bi-exclamation-circle text-warning me-1"></i>Sin vehículo/chofer</div>
                <?php else: ?>
                <div class="veh"><i class="bi bi-check-circle text-success me-1"></i>Listo para salir</div>
                <?php endif; ?>
                <div class="d-flex gap-1 mt-1">
                    <a href="<?= url('modules/entrega_dia_form.php') ?>?id=<?= $ent['id'] ?>&fecha=<?= $fecha ?>"
                       class="btn btn-xs btn-outline-secondary flex-fill" style="font-size:.7rem">
                        <i class="bi bi-pencil me-1"></i>Editar
                    </a>
                    <?php if ($tieneVeh): ?>
                    <form method="POST" action="<?= url('modules/entrega_confirmar.php') ?>" class="flex-fill">
                        <input type="hidden" name="entrega_id" value="<?= $ent['id'] ?>">
                        <input type="hidden" name="fecha"      value="<?= $fecha ?>">
                        <button type="submit" class="btn btn-xs btn-success w-100" style="font-size:.7rem"
                                onclick="return confirm('¿Confirmar salida del camión?')">
                            <i class="bi bi-check-lg me-1"></i>Salida
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($fecha >= $hoy): ?>
        <a href="<?= url('modules/turno_form.php') ?>?fecha=<?= $fecha ?>" class="btn-dia btn-dia-tur">
            <i class="bi bi-plus-lg me-1"></i>Nuevo turno
        </a>
        <?php endif; ?>

        </div><!-- /dia-body -->
    </div><!-- /dia-col -->
    <?php endforeach; // dias ?>
    </div><!-- /semana-wrap -->

    <?php else: ?>
    <!-- ══════════ VISTA MES ══════════ -->
    <?php $dow1 = (int)(new DateTime($desde))->format('N') - 1; ?>
    <div class="mes-grid">
        <?php for ($i=1;$i<=7;$i++): ?>
        <div class="mes-hdr-d <?= $i>=6?'finde':'' ?>"><?= $dsem[$i] ?></div>
        <?php endfor; ?>
        <?php for ($i=0;$i<$dow1;$i++): ?><div></div><?php endfor; ?>
        <?php foreach ($dias as $fecha => $__):
            $dt    = new DateTime($fecha);
            $dow   = (int)$dt->format('N');
            $cards = $cards_dia[$fecha] ?? [];
            $ents  = $ents_x_dia[$fecha] ?? [];
        ?>
        <div class="mes-celda <?= $fecha===$hoy?'hoy':'' ?> <?= $dow>=6?'finde':'' ?>"
             onclick="location.href='?vista=semana&fecha=<?= $fecha ?><?= $ver_todo?'&ver_todo':'' ?>'">
            <div class="mes-dnum"><?= $dt->format('j') ?></div>
            <?php foreach ($cards as $r):
                $col = $r['remito_estado']==='en_camino' ? '#06b6d4'
                     : (!empty($r['entrega_id']) ? '#f97316' : '#3b82f6');
            ?>
            <span class="mes-pip" style="background:<?= $col ?>"></span>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
