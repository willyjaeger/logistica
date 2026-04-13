<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

$edit_id      = (int)($_GET['id']        ?? 0);
$pre_remito   = (int)($_GET['remito_id'] ?? 0);
$def_fecha    = $_GET['fecha'] ?? date('Y-m-d');
$back         = $_GET['back'] ?? 'agenda'; // 'agenda' | 'lista'
$back_url     = $back === 'lista' ? url('modules/entregas_lista.php') : url('modules/agenda.php');

// Auto-detectar fecha del turno cuando viene desde remitos_lista con remito_id
if (!$edit_id && $pre_remito) {
    $st_tf = $db->prepare("SELECT fecha FROM turnos WHERE remito_id=? AND empresa_id=? AND estado='pendiente' ORDER BY fecha LIMIT 1");
    $st_tf->execute([$pre_remito, $eid]);
    $tf = $st_tf->fetchColumn();
    if ($tf) $def_fecha = $tf;
}

$entrega          = null;
$remitos_actuales = []; // remito_ids ya vinculados a esta entrega (en edición)

// ── Modo edición: cargar entrega existente ─────────────────────
if ($edit_id > 0) {
    $st = $db->prepare("
        SELECT e.*, tr.nombre AS trans_nombre, cam.patente, ch.nombre AS cho_nombre
        FROM entregas e
        LEFT JOIN transportistas tr  ON tr.id = e.transportista_id
        LEFT JOIN camiones       cam ON cam.id= e.camion_id
        LEFT JOIN choferes       ch  ON ch.id = e.chofer_id
        WHERE e.id=? AND e.empresa_id=? AND e.estado NOT IN ('completada','entregado','con_incidencias')
    ");
    $st->execute([$edit_id, $eid]);
    $entrega = $st->fetch();
    if (!$entrega) { header('Location: ' . url('modules/agenda.php')); exit; }

    $def_fecha = $entrega['fecha'];
    $st2 = $db->prepare("SELECT remito_id FROM entrega_remitos WHERE entrega_id=?");
    $st2->execute([$edit_id]);
    $remitos_actuales = array_column($st2->fetchAll(), 'remito_id');
}

// ── Entregas pendientes del día (para "agregar a existente") ───
// Solo cuando se está creando (no editando)
$ents_existentes = [];
if (!$edit_id) {
    $se = $db->prepare("
        SELECT e.id, e.fecha, tr.nombre AS transportista, cam.patente, ch.nombre AS chofer,
               e.transportista_id, e.camion_id, e.chofer_id
        FROM entregas e
        LEFT JOIN transportistas tr  ON tr.id = e.transportista_id
        LEFT JOIN camiones       cam ON cam.id= e.camion_id
        LEFT JOIN choferes       ch  ON ch.id = e.chofer_id
        WHERE e.empresa_id=? AND e.fecha=? AND e.estado NOT IN ('completada','entregado','con_incidencias')
        ORDER BY e.id
    ");
    $se->execute([$eid, $def_fecha]);
    $ents_existentes = $se->fetchAll();

    // Remitos ya en cada entrega existente (para mostrar en la UI)
    $ids_ex = array_column($ents_existentes, 'id');
    $rems_de_ent = [];
    if ($ids_ex) {
        $ph = implode(',', array_fill(0, count($ids_ex), '?'));
        $sr = $db->prepare("SELECT er.entrega_id, r.nro_remito_propio, c.nombre AS cliente
                             FROM entrega_remitos er
                             JOIN remitos r ON r.id=er.remito_id
                             LEFT JOIN clientes c ON c.id=r.cliente_id
                             WHERE er.entrega_id IN ($ph)");
        $sr->execute($ids_ex);
        foreach ($sr->fetchAll() as $row) $rems_de_ent[$row['entrega_id']][] = $row;
    }
    // Adjuntar remitos a cada entrega
    foreach ($ents_existentes as &$e_ex) {
        $e_ex['remitos'] = $rems_de_ent[$e_ex['id']] ?? [];
    }
    unset($e_ex);
}

// ── Remitos disponibles para agregar ──────────────────────────
// Incluye:
//   1. Remitos ya en esta entrega (siempre, para poder editarlos)
//   2. Remitos con turno en la fecha, no asignados a otra entrega activa
//   3. Remitos con fecha_entrega = la fecha, no asignados a otra entrega activa
$disponibles = $db->prepare("
    SELECT DISTINCT
        r.id    AS remito_id,
        r.nro_remito_propio,
        r.total_pallets,
        c.nombre AS cliente,
        t.id    AS turno_id
    FROM remitos r
    LEFT JOIN clientes c ON c.id = r.cliente_id
    LEFT JOIN turnos t   ON t.remito_id = r.id AND t.empresa_id = r.empresa_id
    WHERE r.empresa_id = ?
      AND r.estado NOT IN ('entregado','en_stock','parcialmente_entregado')
      AND (
          -- Ya está en esta entrega
          r.id IN (SELECT remito_id FROM entrega_remitos WHERE entrega_id = ?)
          OR (
              -- Tiene turno en la fecha y no está en otra entrega activa
              t.id IS NOT NULL AND t.fecha = ?
              AND NOT EXISTS (
                  SELECT 1 FROM entrega_remitos er2
                  JOIN entregas ex ON ex.id = er2.entrega_id
                  WHERE er2.remito_id = r.id
                    AND ex.empresa_id = ?
                    AND ex.id != ?
                    AND ex.estado NOT IN ('completada','entregado','con_incidencias')
              )
          )
          OR (
              -- Tiene fecha_entrega en la fecha y no está en otra entrega activa
              DATE(r.fecha_entrega) = ?
              AND NOT EXISTS (
                  SELECT 1 FROM entrega_remitos er2
                  JOIN entregas ex ON ex.id = er2.entrega_id
                  WHERE er2.remito_id = r.id
                    AND ex.empresa_id = ?
                    AND ex.id != ?
                    AND ex.estado NOT IN ('completada','entregado','con_incidencias')
              )
          )
      )
    ORDER BY c.nombre, r.nro_remito_propio
");
$eid_edit = $edit_id ?: 0;
$disponibles->execute([$eid, $eid_edit, $def_fecha, $eid, $eid_edit, $def_fecha, $eid, $eid_edit]);
$remitos_disponibles = $disponibles->fetchAll();

// También incluir remito pre-seleccionado aunque no tenga turno para esa fecha
// (puede venir de remitos_lista donde la fecha aún no está definida)
$remito_pre = null;
if ($pre_remito && !in_array($pre_remito, array_column($remitos_disponibles, 'remito_id'))) {
    $rp = $db->prepare("SELECT r.id AS remito_id, r.nro_remito_propio, r.total_pallets, c.nombre AS cliente
                         FROM remitos r LEFT JOIN clientes c ON c.id=r.cliente_id
                         WHERE r.id=? AND r.empresa_id=?");
    $rp->execute([$pre_remito, $eid]);
    $remito_pre = $rp->fetch();
    if ($remito_pre) {
        array_unshift($remitos_disponibles, $remito_pre);
    }
}

// ── Datos para dropdowns ───────────────────────────────────────
$trans_q = $db->prepare("SELECT id, nombre FROM transportistas WHERE empresa_id=? AND activo=1 ORDER BY nombre");
$trans_q->execute([$eid]);
$trans_list = $trans_q->fetchAll();

$cam_q = $db->prepare("SELECT id, transportista_id, patente, marca FROM camiones WHERE empresa_id=? AND activo=1 ORDER BY patente");
$cam_q->execute([$eid]);
$cam_list = $cam_q->fetchAll();

$cho_q = $db->prepare("SELECT id, transportista_id, nombre FROM choferes WHERE empresa_id=? AND activo=1 ORDER BY nombre");
$cho_q->execute([$eid]);
$cho_list = $cho_q->fetchAll();

$sel_trans  = $entrega['transportista_id'] ?? '';
$sel_camion = $entrega['camion_id']        ?? '';
$sel_chofer = $entrega['chofer_id']        ?? '';

// Pre-seleccionar remitos: los actuales (edición) + el pre-seleccionado (nuevo)
$pre_checked = $remitos_actuales;
if ($pre_remito && !in_array($pre_remito, $pre_checked)) $pre_checked[] = $pre_remito;

$titulo     = $edit_id ? 'Editar entrega' : 'Nueva entrega';
$nav_modulo = 'agenda';

function fmtDia(string $ymd): string {
    [$y,$m,$d] = explode('-', $ymd);
    $M = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    return (int)$d . ' ' . $M[(int)$m] . ' ' . $y;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $titulo ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background:#eef1f6; }
        .seccion { background:#fff; border-left:4px solid #f97316; border-radius:.5rem;
                   padding:1rem 1.25rem; margin-bottom:1rem; box-shadow:0 2px 8px rgba(0,0,0,.08); }
        .seccion.azul { border-left-color:#3b82f6; }
        .seccion-titulo { font-size:.78rem; font-weight:700; text-transform:uppercase;
                          letter-spacing:.08em; color:#f97316; margin-bottom:.75rem;
                          padding-bottom:.4rem; border-bottom:1px solid #e9ecef; }
        .seccion.azul .seccion-titulo { color:#3b82f6; }
        .form-label { color:#374151; font-weight:600; }

        /* Tarjeta entrega existente */
        .ent-opcion { border:2px solid #e2e8f0; border-radius:.45rem; padding:.7rem 1rem;
                      cursor:pointer; margin-bottom:.5rem; transition:border-color .15s,background .15s; }
        .ent-opcion:hover { border-color:#3b82f6; background:#eff6ff; }
        .ent-opcion.sel { border-color:#3b82f6; background:#eff6ff; }
        .ent-opcion input[type=radio] { display:none; }
        .ent-rem-chip { display:inline-block; background:#e0f2fe; color:#0369a1;
                        border-radius:3px; padding:1px 7px; font-size:.75rem; margin:1px; }

        /* Remito checkbox */
        .rem-item { border:2px solid #e2e8f0; border-radius:.4rem; padding:.55rem .9rem;
                    cursor:pointer; transition:border-color .15s,background .15s; margin-bottom:.4rem;
                    display:flex; align-items:center; gap:.75rem; }
        .rem-item:hover { border-color:#f97316; background:#fff7ed; }
        .rem-item.chk { border-color:#f97316; background:#fff7ed; }
        .rem-item input[type=checkbox] { display:none; }
        .rem-ico { font-size:1.1rem; }
        .rem-pallets { color:#7c3aed; font-weight:700; font-size:.82rem; margin-left:auto; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-3 px-3" style="max-width:680px">

    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= $back_url ?>"
           class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Volver</a>
        <h5 class="fw-bold mb-0">
            <i class="bi bi-truck-front me-2 text-warning"></i><?= $titulo ?>
            <span class="fw-normal text-muted fs-6 ms-2"><?= fmtDia($def_fecha) ?></span>
        </h5>
    </div>

    <form method="POST" action="<?= url('modules/entrega_dia_guardar.php') ?>" id="form-entrega">
        <input type="hidden" name="entrega_id" value="<?= $edit_id ?>">
        <input type="hidden" name="fecha"      value="<?= h($def_fecha) ?>">
        <input type="hidden" name="back"       value="<?= h($back) ?>">
        <!-- entrega_destino: 0 = nueva, N = agregar a entrega N -->
        <input type="hidden" name="entrega_destino" id="entrega_destino" value="<?= $edit_id ?: 0 ?>">

        <?php if (!$edit_id && $ents_existentes): ?>
        <!-- ══ SECCIÓN: ¿Nueva o agregar a existente? ══════════ -->
        <div class="seccion azul">
            <div class="seccion-titulo"><i class="bi bi-signpost-split me-1"></i>¿Nueva entrega o agregar a una existente?</div>

            <?php foreach ($ents_existentes as $ex):
                $info = array_filter([$ex['transportista'], $ex['patente'], $ex['chofer']]);
                $label = implode(' · ', $info) ?: 'Sin transportista aún';
            ?>
            <div class="ent-opcion" id="opcion_ent_<?= $ex['id'] ?>" onclick="elegirEntrega(<?= $ex['id'] ?>)">
                <input type="radio" name="_dest" value="<?= $ex['id'] ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <strong><i class="bi bi-truck me-1 text-warning"></i><?= h($label) ?></strong>
                        <div class="mt-1">
                            <?php foreach ($ex['remitos'] as $rr): ?>
                            <span class="ent-rem-chip"><?= h($rr['nro_remito_propio']) ?> — <?= h($rr['cliente']) ?></span>
                            <?php endforeach; ?>
                            <?php if (empty($ex['remitos'])): ?>
                            <span class="text-muted small">Sin remitos aún</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="badge bg-light border text-muted" style="font-size:.72rem">E-<?= $ex['id'] ?></span>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="ent-opcion sel" id="opcion_nueva" onclick="elegirEntrega(0)">
                <input type="radio" name="_dest" value="0" checked>
                <strong><i class="bi bi-plus-circle me-1 text-success"></i>Crear nueva entrega</strong>
                <span class="text-muted small ms-2">para este día</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- ══ SECCIÓN: Transportista y vehículo ════════════════ -->
        <div class="seccion" id="sec-vehiculo">
            <div class="seccion-titulo"><i class="bi bi-building me-1"></i>Transportista y vehículo</div>

            <?php if ($edit_id && $entrega): ?>
            <!-- En edición, mostrar info actual antes de los dropdowns -->
            <?php if ($entrega['trans_nombre']): ?>
            <div class="alert alert-light py-2 small mb-2">
                <i class="bi bi-truck-front me-1 text-warning"></i>
                <strong><?= h($entrega['trans_nombre']) ?></strong>
                <?php if ($entrega['patente']): ?> · <?= h($entrega['patente']) ?><?php endif; ?>
                <?php if ($entrega['cho_nombre']): ?> / <?= h($entrega['cho_nombre']) ?><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-12 col-sm-6">
                    <label class="form-label form-label-sm mb-1">Empresa transportista</label>
                    <select name="transportista_id" id="sel_trans" class="form-select form-select-sm"
                            onchange="filtrar(this.value)">
                        <option value="">— Sin asignar —</option>
                        <?php foreach ($trans_list as $tr): ?>
                        <option value="<?= $tr['id'] ?>" <?= $sel_trans == $tr['id'] ? 'selected' : '' ?>>
                            <?= h($tr['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-3">
                    <label class="form-label form-label-sm mb-1">Camión</label>
                    <select name="camion_id" id="sel_camion" class="form-select form-select-sm">
                        <option value="">— —</option>
                        <?php foreach ($cam_list as $c): ?>
                        <option value="<?= $c['id'] ?>" data-trans="<?= (int)$c['transportista_id'] ?>"
                                <?= $sel_camion == $c['id'] ? 'selected' : '' ?>>
                            <?= h($c['patente']) ?><?= $c['marca'] ? ' · '.h($c['marca']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-3">
                    <label class="form-label form-label-sm mb-1">Chofer</label>
                    <select name="chofer_id" id="sel_chofer" class="form-select form-select-sm">
                        <option value="">— —</option>
                        <?php foreach ($cho_list as $c): ?>
                        <option value="<?= $c['id'] ?>" data-trans="<?= (int)$c['transportista_id'] ?>"
                                <?= $sel_chofer == $c['id'] ? 'selected' : '' ?>>
                            <?= h($c['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label form-label-sm mb-1">Fecha</label>
                    <input type="date" name="fecha_entrega" class="form-control form-control-sm"
                           value="<?= h($entrega['fecha'] ?? $def_fecha) ?>" required>
                </div>
            </div>
        </div>

        <!-- ══ SECCIÓN: Remitos a incluir ══════════════════════ -->
        <div class="seccion">
            <div class="seccion-titulo"><i class="bi bi-file-earmark-check me-1"></i>Remitos a incluir</div>
            <?php if (empty($remitos_disponibles)): ?>
            <p class="text-muted small mb-0">
                No hay remitos disponibles para este día.
                <a href="<?= url('modules/turno_form.php') ?>?fecha=<?= $def_fecha ?>">Crear un turno</a> o
                <a href="<?= url('modules/remitos_form.php') ?>">cargar un remito</a>.
            </p>
            <?php else: ?>
            <?php foreach ($remitos_disponibles as $r):
                $checked = in_array($r['remito_id'], $pre_checked);
            ?>
            <label class="rem-item <?= $checked ? 'chk' : '' ?>" onclick="toggleRem(this)">
                <input type="checkbox" name="remito_ids[]" value="<?= $r['remito_id'] ?>"
                       <?= $checked ? 'checked' : '' ?>>
                <i class="bi <?= $checked ? 'bi-check-square-fill text-warning' : 'bi-square text-muted' ?> rem-ico"></i>
                <div class="flex-grow-1">
                    <div class="fw-semibold small"><?= h($r['cliente'] ?? '—') ?></div>
                    <div class="text-info" style="font-size:.77rem"><?= h($r['nro_remito_propio']) ?></div>
                </div>
                <?php if ($r['total_pallets']): ?>
                <span class="rem-pallets"><?= number_format((float)$r['total_pallets'],1) ?> pal</span>
                <?php endif; ?>
            </label>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="<?= $back_url ?>"
               class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-warning">
                <i class="bi bi-floppy me-1"></i><?= $edit_id ? 'Guardar cambios' : 'Guardar entrega' ?>
            </button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const allCam = <?= json_encode($cam_list) ?>;
const allCho = <?= json_encode($cho_list) ?>;

// ── Elegir entrega destino (nueva o existente) ─────────────────
function elegirEntrega(id) {
    document.getElementById('entrega_destino').value = id;
    document.querySelectorAll('.ent-opcion').forEach(el => el.classList.remove('sel'));
    const target = id === 0
        ? document.getElementById('opcion_nueva')
        : document.getElementById('opcion_ent_' + id);
    if (target) target.classList.add('sel');

    // Mostrar/ocultar sección vehículo (solo para nueva entrega)
    document.getElementById('sec-vehiculo').style.display = id === 0 ? '' : 'none';
}

// ── Filtrar camiones/choferes por transportista ────────────────
function filtrar(tid) {
    [['sel_camion', allCam], ['sel_chofer', allCho]].forEach(([selId, arr]) => {
        const sel = document.getElementById(selId);
        Array.from(sel.options).forEach(o => {
            if (!o.value) return;
            o.hidden = !!(tid && o.dataset.trans && o.dataset.trans !== String(tid));
        });
        if (sel.selectedOptions[0]?.hidden) sel.value = '';
        const vis = arr.filter(c => !tid || String(c.transportista_id) === String(tid));
        if (vis.length === 1) sel.value = vis[0].id;
    });
}

// ── Toggle remito checkbox ─────────────────────────────────────
function toggleRem(label) {
    const chk = label.querySelector('input[type=checkbox]');
    // El click del label ya cambia el checked; esperar un tick
    setTimeout(() => {
        const ico = label.querySelector('.rem-ico');
        if (chk.checked) {
            label.classList.add('chk');
            ico.className = 'bi bi-check-square-fill text-warning rem-ico';
        } else {
            label.classList.remove('chk');
            ico.className = 'bi bi-square text-muted rem-ico';
        }
    }, 0);
}

// ── Inicialización ─────────────────────────────────────────────
(function() {
    const t = document.getElementById('sel_trans')?.value;
    if (t) filtrar(t);
})();
</script>
</body>
</html>
