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


// ── Remitos disponibles para agregar ──────────────────────────
// Incluye:
//   1. Remitos ya en esta entrega (edición)
//   2. Remitos con turno en la fecha
//   3. Remitos con fecha_entrega = la fecha (programados para ese día)
//   4. Remitos en estado 'pendiente' (sin destino fijo aún)
// En todos los casos: no asignados a otra entrega activa
$eid_edit = $edit_id ?: 0;
$disponibles = $db->prepare("
    SELECT DISTINCT
        r.id    AS remito_id,
        r.nro_remito_propio,
        r.total_pallets,
        c.nombre AS cliente,
        t.id    AS turno_id
    FROM remitos r
    LEFT JOIN clientes c ON c.id = r.cliente_id
    LEFT JOIN turnos t   ON t.remito_id = r.id AND t.empresa_id = r.empresa_id AND t.fecha = ?
    WHERE r.empresa_id = ?
      AND r.estado NOT IN ('entregado','en_stock','parcialmente_entregado')
      AND NOT EXISTS (
          SELECT 1 FROM entrega_remitos er2
          JOIN entregas ex ON ex.id = er2.entrega_id
          WHERE er2.remito_id = r.id
            AND ex.empresa_id = ?
            AND ex.id != ?
            AND ex.estado NOT IN ('completada','entregado','con_incidencias')
      )
      AND (
          r.id IN (SELECT remito_id FROM entrega_remitos WHERE entrega_id = ?)
          OR t.id IS NOT NULL
          OR DATE(r.fecha_entrega) = ?
          OR r.estado = 'pendiente'
      )
    ORDER BY
        CASE WHEN t.id IS NOT NULL THEN 0
             WHEN DATE(r.fecha_entrega) = ? THEN 1
             ELSE 2 END,
        c.nombre, r.nro_remito_propio
");
$disponibles->execute([$def_fecha, $eid, $eid, $eid_edit, $eid_edit, $def_fecha, $def_fecha]);
$remitos_disponibles = $disponibles->fetchAll();

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

$titulo     = $edit_id ? 'Editar salida' : 'Nueva salida';
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
        .seccion-titulo { font-size:.78rem; font-weight:700; text-transform:uppercase;
                          letter-spacing:.08em; color:#f97316; margin-bottom:.75rem;
                          padding-bottom:.4rem; border-bottom:1px solid #e9ecef; }
        .form-label { color:#374151; font-weight:600; }

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

        <!-- ══ SECCIÓN: Transportista y vehículo ════════════════ -->
        <div class="seccion" id="sec-vehiculo">
            <div class="seccion-titulo"><i class="bi bi-building me-1"></i>Transportista y vehículo</div>
            <div class="row g-3">
                <div class="col-12 col-sm-6">
                    <label class="form-label form-label-sm mb-1">Empresa transportista</label>
                    <div class="input-group input-group-sm">
                        <select name="transportista_id" id="sel_trans" class="form-select form-select-sm"
                                onchange="filtrar(this.value)">
                            <option value="">— Sin asignar —</option>
                            <?php foreach ($trans_list as $tr): ?>
                            <option value="<?= $tr['id'] ?>" <?= $sel_trans == $tr['id'] ? 'selected' : '' ?>>
                                <?= h($tr['nombre']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-secondary" onclick="abrirModalTrans()"
                                style="padding:.2rem .45rem;font-size:.8rem"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </div>
                <div class="col-6 col-sm-3">
                    <label class="form-label form-label-sm mb-1">Camión</label>
                    <div class="input-group input-group-sm">
                        <select name="camion_id" id="sel_camion" class="form-select form-select-sm">
                            <option value="">— —</option>
                            <?php foreach ($cam_list as $c): ?>
                            <option value="<?= $c['id'] ?>" data-trans="<?= (int)$c['transportista_id'] ?>"
                                    <?= $sel_camion == $c['id'] ? 'selected' : '' ?>>
                                <?= h($c['patente']) ?><?= $c['marca'] ? ' · '.h($c['marca']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-secondary" onclick="abrirModalCamion()"
                                style="padding:.2rem .45rem;font-size:.8rem"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </div>
                <div class="col-6 col-sm-3">
                    <label class="form-label form-label-sm mb-1">Chofer</label>
                    <div class="input-group input-group-sm">
                        <select name="chofer_id" id="sel_chofer" class="form-select form-select-sm">
                            <option value="">— —</option>
                            <?php foreach ($cho_list as $c): ?>
                            <option value="<?= $c['id'] ?>" data-trans="<?= (int)$c['transportista_id'] ?>"
                                    <?= $sel_chofer == $c['id'] ? 'selected' : '' ?>>
                                <?= h($c['nombre']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-secondary" onclick="abrirModalChofer()"
                                style="padding:.2rem .45rem;font-size:.8rem"><i class="bi bi-plus-lg"></i></button>
                    </div>
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

    <?php if ($edit_id && $entrega):
        $est = $entrega['estado'];
        $badges = [
            'armando'   => ['secondary', 'Armando'],
            'en_camino' => ['primary',   'En camino'],
        ];
        [$badge_color, $badge_label] = $badges[$est] ?? ['secondary', $est];
    ?>
    <div class="seccion">
        <div class="seccion-titulo"><i class="bi bi-arrow-repeat me-1"></i>Cambiar estado</div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="badge bg-<?= $badge_color ?> fs-6 px-3 py-2"><?= $badge_label ?></span>
            <div class="d-flex gap-2 flex-wrap ms-auto">
                <?php if ($est === 'armando'): ?>
                <form method="POST" action="<?= url('modules/entrega_confirmar.php') ?>">
                    <input type="hidden" name="entrega_id" value="<?= $edit_id ?>">
                    <input type="hidden" name="fecha"      value="<?= h($entrega['fecha']) ?>">
                    <button type="submit" class="btn btn-primary"
                            onclick="return confirm('¿Confirmar salida? Los remitos pasarán a En camino.')">
                        <i class="bi bi-truck me-1"></i>Confirmar salida
                    </button>
                </form>
                <?php endif; ?>
                <form method="POST" action="<?= url('modules/entrega_completar.php') ?>">
                    <input type="hidden" name="entrega_id"   value="<?= $edit_id ?>">
                    <input type="hidden" name="nuevo_estado" value="completada">
                    <input type="hidden" name="fecha"        value="<?= h($entrega['fecha']) ?>">
                    <input type="hidden" name="back"         value="<?= h($back) ?>">
                    <button type="submit" class="btn btn-success"
                            onclick="return confirm('¿Marcar como completada? Los remitos quedarán como entregados.')">
                        <i class="bi bi-check-circle me-1"></i>Completada
                    </button>
                </form>
                <form method="POST" action="<?= url('modules/entrega_completar.php') ?>">
                    <input type="hidden" name="entrega_id"   value="<?= $edit_id ?>">
                    <input type="hidden" name="nuevo_estado" value="con_incidencias">
                    <input type="hidden" name="fecha"        value="<?= h($entrega['fecha']) ?>">
                    <input type="hidden" name="back"         value="<?= h($back) ?>">
                    <button type="submit" class="btn btn-outline-warning"
                            onclick="return confirm('¿Registrar con incidencias? Los remitos quedarán como entregados.')">
                        <i class="bi bi-exclamation-triangle me-1"></i>Con incidencias
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal nuevo transportista -->
<div class="modal fade" id="modalTrans" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h6 class="modal-title fw-bold">Nuevo transportista</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-2"><label class="form-label form-label-sm fw-semibold">Nombre *</label>
                <input type="text" id="mt_n" class="form-control form-control-sm"></div>
            <div class="row g-2">
                <div class="col"><label class="form-label form-label-sm fw-semibold">CUIT</label>
                    <input type="text" id="mt_c" class="form-control form-control-sm font-monospace"></div>
                <div class="col"><label class="form-label form-label-sm fw-semibold">Teléfono</label>
                    <input type="text" id="mt_t" class="form-control form-control-sm"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="guardarTrans()"><i class="bi bi-floppy me-1"></i>Crear</button>
        </div>
    </div></div>
</div>
<!-- Modal nuevo camión -->
<div class="modal fade" id="modalCam" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h6 class="modal-title fw-bold">Nuevo camión</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-2"><label class="form-label form-label-sm fw-semibold">Patente *</label>
                <input type="text" id="mc_p" class="form-control form-control-sm font-monospace text-uppercase"></div>
            <div class="row g-2">
                <div class="col"><label class="form-label form-label-sm fw-semibold">Marca</label>
                    <input type="text" id="mc_m" class="form-control form-control-sm"></div>
                <div class="col"><label class="form-label form-label-sm fw-semibold">Modelo</label>
                    <input type="text" id="mc_mo" class="form-control form-control-sm"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-success btn-sm" onclick="guardarCam()"><i class="bi bi-floppy me-1"></i>Crear</button>
        </div>
    </div></div>
</div>
<!-- Modal nuevo chofer -->
<div class="modal fade" id="modalCho" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h6 class="modal-title fw-bold">Nuevo chofer</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-2"><label class="form-label form-label-sm fw-semibold">Nombre *</label>
                <input type="text" id="mch_n" class="form-control form-control-sm"></div>
            <div class="mb-2"><label class="form-label form-label-sm fw-semibold">Teléfono</label>
                <input type="text" id="mch_t" class="form-control form-control-sm"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-warning btn-sm" onclick="guardarCho()"><i class="bi bi-floppy me-1"></i>Crear</button>
        </div>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';
const allCam = <?= json_encode($cam_list) ?>;
const allCho = <?= json_encode($cho_list) ?>;
const selTrans = document.getElementById('sel_trans');
const selCam   = document.getElementById('sel_camion');
const selCho   = document.getElementById('sel_chofer');

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

// ── Modales ────────────────────────────────────────────────────
async function post(url, data) {
    data.ajax = '1';
    return (await fetch(url, {method:'POST', body: new URLSearchParams(data)})).json();
}
const mTrans = new bootstrap.Modal(document.getElementById('modalTrans'));
const mCam   = new bootstrap.Modal(document.getElementById('modalCam'));
const mCho   = new bootstrap.Modal(document.getElementById('modalCho'));

function abrirModalTrans()  { document.getElementById('mt_n').value=''; mTrans.show(); setTimeout(()=>document.getElementById('mt_n').focus(),300); }
async function guardarTrans() {
    const nombre = document.getElementById('mt_n').value.trim();
    if (!nombre) return;
    const res = await post('<?= url('modules/transportistas_guardar_ajax.php') ?>', {nombre, cuit: document.getElementById('mt_c').value.trim(), telefono: document.getElementById('mt_t').value.trim()});
    if (!res.ok) { alert(res.error); return; }
    selTrans.appendChild(new Option(res.nombre, res.id));
    selTrans.value = res.id; mTrans.hide(); filtrar(res.id);
}
function abrirModalCamion() { document.getElementById('mc_p').value=''; mCam.show(); setTimeout(()=>document.getElementById('mc_p').focus(),300); }
async function guardarCam() {
    const patente = document.getElementById('mc_p').value.trim().toUpperCase();
    if (!patente) return;
    const tid = selTrans.value;
    const res = await post('<?= url('modules/camiones_guardar.php') ?>', {accion:'guardar', transportista_id:tid, patente, marca: document.getElementById('mc_m').value.trim(), modelo: document.getElementById('mc_mo').value.trim()});
    if (!res.ok) { alert(res.error); return; }
    allCam.push({id:res.id, transportista_id:tid, patente:res.patente, marca: document.getElementById('mc_m').value.trim()});
    mCam.hide(); filtrar(tid); selCam.value = res.id;
}
function abrirModalChofer() { document.getElementById('mch_n').value=''; mCho.show(); setTimeout(()=>document.getElementById('mch_n').focus(),300); }
async function guardarCho() {
    const nombre = document.getElementById('mch_n').value.trim();
    if (!nombre) return;
    const tid = selTrans.value;
    const res = await post('<?= url('modules/choferes_guardar.php') ?>', {accion:'guardar', transportista_id:tid, nombre, telefono: document.getElementById('mch_t').value.trim()});
    if (!res.ok) { alert(res.error); return; }
    allCho.push({id:res.id, transportista_id:tid, nombre:res.nombre});
    mCho.hide(); filtrar(tid); selCho.value = res.id;
}

// ── Inicialización ─────────────────────────────────────────────
(function() {
    const t = selTrans?.value;
    if (t) filtrar(t);
})();
</script>
</body>
</html>
