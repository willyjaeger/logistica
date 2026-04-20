<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

$id         = (int)($_GET['id']        ?? 0);
$pre_remito = (int)($_GET['remito_id'] ?? 0);
$turno      = null;

if ($id > 0) {
    $st = $db->prepare("SELECT * FROM turnos WHERE id=? AND empresa_id=?");
    $st->execute([$id, $eid]);
    $turno = $st->fetch();
    if (!$turno) { header('Location: ' . url('modules/agenda.php')); exit; }
}

$fecha_default = $_GET['fecha'] ?? ($turno['fecha'] ?? date('Y-m-d'));

// Transportistas / camiones / choferes
$transportistas = $db->prepare("SELECT id, nombre FROM transportistas WHERE empresa_id=? AND activo=1 ORDER BY nombre");
$transportistas->execute([$eid]);
$transportistas = $transportistas->fetchAll();

$camiones_all = $db->prepare("SELECT id, transportista_id, patente, marca, modelo FROM camiones WHERE empresa_id=? AND activo=1 ORDER BY patente");
$camiones_all->execute([$eid]);
$camiones_all = $camiones_all->fetchAll();

$choferes_all = $db->prepare("SELECT id, transportista_id, nombre FROM choferes WHERE empresa_id=? AND activo=1 ORDER BY nombre");
$choferes_all->execute([$eid]);
$choferes_all = $choferes_all->fetchAll();

// Clientes
$clientes = $db->query("SELECT id, nombre FROM clientes WHERE activo=1 ORDER BY nombre")->fetchAll();

// Remitos pendientes (para vincular)
$remitos_pend = $db->prepare("
    SELECT r.id, r.nro_remito_propio, r.total_pallets, r.cliente_id, c.nombre AS cliente
    FROM remitos r
    JOIN clientes c ON c.id = r.cliente_id
    WHERE r.empresa_id=? AND r.estado IN ('pendiente','turnado','programado')
    ORDER BY c.nombre, r.id
");
$remitos_pend->execute([$eid]);
$remitos_pend = $remitos_pend->fetchAll();

// Si el turno ya tiene remito, incluirlo también (puede estar en otro estado)
// También aplica cuando viene pre-seleccionado por ?remito_id=
$remito_actual = null;
$remito_ref_id = $turno['remito_id'] ?? $pre_remito;
if ($remito_ref_id) {
    $rst = $db->prepare("SELECT id, nro_remito_propio, total_pallets, cliente_id FROM remitos WHERE id=? AND empresa_id=?");
    $rst->execute([$remito_ref_id, $eid]);
    $remito_actual = $rst->fetch();
    // Asegurarse de que esté en la lista de disponibles
    if ($remito_actual && !in_array($remito_actual['id'], array_column($remitos_pend, 'id'))) {
        $remito_actual['nombre'] = ''; // campo extra no necesario
        array_unshift($remitos_pend, [
            'id'              => $remito_actual['id'],
            'nro_remito_propio' => $remito_actual['nro_remito_propio'],
            'total_pallets'   => $remito_actual['total_pallets'],
            'cliente_id'      => $remito_actual['cliente_id'],
            'cliente'         => '', // se mostrará via el select de clientes
        ]);
    }
}

$error = $_SESSION['form_error'] ?? null;
unset($_SESSION['form_error']);

$nav_modulo = 'agenda';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $id ? 'Editar' : 'Nuevo' ?> turno — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #eef1f6; }
        .seccion { background:#fff; border-left:4px solid #6366f1; border-radius:.5rem; padding:1rem 1.25rem; margin-bottom:1rem; box-shadow:0 2px 8px rgba(0,0,0,.08); }
        .seccion.verde { border-left-color:#16a34a; }
        .seccion.naranja { border-left-color:#f59e0b; }
        .seccion-titulo { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#6366f1; margin-bottom:.75rem; padding-bottom:.4rem; border-bottom:1px solid #e9ecef; }
        .seccion.verde .seccion-titulo { color:#16a34a; }
        .seccion.naranja .seccion-titulo { color:#d97706; }
        .form-label { font-weight:600; color:#374151; }
        .btn-nuevo { padding:.2rem .45rem; font-size:.8rem; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-3 px-3" style="max-width:800px">
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= url('modules/agenda.php') ?>?fecha=<?= h($fecha_default) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h5 class="fw-bold mb-0"><?= $id ? 'Editar turno' : 'Nuevo turno / reserva' ?></h5>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible py-2">
        <i class="bi bi-exclamation-circle me-2"></i><?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('modules/turno_guardar.php') ?>" id="form-turno" novalidate>
        <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
        <input type="hidden" name="accion" value="guardar">

        <!-- DATOS BÁSICOS -->
        <div class="seccion">
            <div class="seccion-titulo"><i class="bi bi-calendar-event me-1"></i>Fecha y tipo</div>
            <div class="row g-3">
                <div class="col-sm-3">
                    <label class="form-label form-label-sm mb-1">Fecha <span class="text-danger">*</span></label>
                    <input type="date" name="fecha" class="form-control form-control-sm"
                           value="<?= h($turno['fecha'] ?? $fecha_default) ?>" required autofocus>
                </div>
                <div class="col-sm-3">
                    <label class="form-label form-label-sm mb-1">Tipo</label>
                    <select name="tipo" class="form-select form-select-sm">
                        <option value="programado" <?= ($turno['tipo']??'programado')==='programado'?'selected':'' ?>>
                            Programado (nuestra decisión)
                        </option>
                        <option value="turno" <?= ($turno['tipo']??'')==='turno'?'selected':'' ?>>
                            Turno (cliente nos da la fecha)
                        </option>
                    </select>
                </div>
                <div class="col-sm-2">
                    <label class="form-label form-label-sm mb-1">Hora <span class="text-muted">(opc)</span></label>
                    <input type="time" name="hora_turno" class="form-control form-control-sm"
                           value="<?= h(substr($turno['hora_turno']??'',0,5)) ?>">
                </div>
                <div class="col-sm-4">
                    <label class="form-label form-label-sm mb-1">Observaciones</label>
                    <input type="text" name="observaciones" class="form-control form-control-sm"
                           value="<?= h($turno['observaciones']??'') ?>"
                           placeholder="Ej: zona norte, prioridad...">
                </div>
            </div>
        </div>

        <!-- CLIENTE Y REMITO -->
        <div class="seccion verde">
            <div class="seccion-titulo"><i class="bi bi-person me-1"></i>Cliente y remito</div>
            <div class="row g-3">
                <div class="col-sm-5">
                    <label class="form-label form-label-sm mb-1">Cliente <span class="text-danger">*</span></label>
                    <select name="cliente_id" id="sel-cliente" class="form-select form-select-sm"
                            onchange="filtrarRemitos()" required>
                        <option value="">— Seleccionar cliente —</option>
                        <?php foreach ($clientes as $c): ?>
                        <?php $sel_cliente = $turno['cliente_id'] ?? $remito_actual['cliente_id'] ?? ''; ?>
                        <option value="<?= $c['id'] ?>" <?= $sel_cliente==$c['id']?'selected':'' ?>>
                            <?= h($c['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-5">
                    <label class="form-label form-label-sm mb-1">Vincular remito <span class="text-muted">(opc)</span></label>
                    <select name="remito_id" id="sel-remito" class="form-select form-select-sm"
                            onchange="autocompletarCliente(this.value)">
                        <option value="">— Sin remito todavía —</option>
                        <?php
                        // Mostrar remito actual (puede no estar en pendientes)
                        if ($remito_actual && !in_array($remito_actual['id'], array_column($remitos_pend,'id'))):
                        ?>
                        <option value="<?= $remito_actual['id'] ?>" selected>
                            <?= h($remito_actual['nro_remito_propio']) ?>
                        </option>
                        <?php endif; ?>
                        <?php foreach ($remitos_pend as $r): ?>
                        <?php $sel_remito = $turno['remito_id'] ?? $remito_ref_id ?? 0; ?>
                        <option value="<?= $r['id'] ?>"
                                data-cliente="<?= $r['cliente_id'] ?>"
                                <?= $sel_remito==$r['id']?'selected':'' ?>>
                            <?= h($r['nro_remito_propio']) ?> — <?= h($r['cliente']) ?>
                            <?= $r['total_pallets']>0 ? '('.number_format($r['total_pallets'],1).' pal)' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-2">
                    <label class="form-label form-label-sm mb-1">Pallets est.</label>
                    <input type="number" name="pallets_est" step="0.5" min="0"
                           class="form-control form-control-sm"
                           value="<?= h($turno['pallets_est'] ?? ($remito_actual ? number_format((float)$remito_actual['total_pallets'], 1, '.', '') : '')) ?>"
                           placeholder="0.0"
                           title="Estimación cuando no hay remito">
                </div>
            </div>
        </div>

        <!-- TRANSPORTE -->
        <div class="seccion naranja">
            <div class="seccion-titulo"><i class="bi bi-truck-front me-1"></i>Transporte <span class="text-muted fw-normal">(opcional — el encargado lo asigna desde la agenda)</span></div>
            <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <label class="form-label form-label-sm mb-1">Transportista</label>
                    <div class="input-group input-group-sm">
                        <select name="transportista_id" id="sel-transportista" class="form-select form-select-sm"
                                onchange="filtrarTransporte(this.value)">
                            <option value="">— Sin asignar —</option>
                            <?php foreach ($transportistas as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= ($turno['transportista_id']??'')==$t['id']?'selected':'' ?>>
                                <?= h($t['nombre']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-nuevo"
                                onclick="abrirModalTrans()"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label form-label-sm mb-1">Camión</label>
                    <div class="input-group input-group-sm">
                        <select name="camion_id" id="sel-camion" class="form-select form-select-sm">
                            <option value="">— Sin asignar —</option>
                            <?php foreach ($camiones_all as $c): ?>
                            <option value="<?= $c['id'] ?>" data-tid="<?= $c['transportista_id'] ?>"
                                    <?= ($turno['camion_id']??'')==$c['id']?'selected':'' ?>>
                                <?= h($c['patente']) ?><?= $c['marca']?' — '.h($c['marca']):'' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-nuevo"
                                onclick="abrirModalCamion()"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label form-label-sm mb-1">Chofer</label>
                    <div class="input-group input-group-sm">
                        <select name="chofer_id" id="sel-chofer" class="form-select form-select-sm">
                            <option value="">— Sin asignar —</option>
                            <?php foreach ($choferes_all as $c): ?>
                            <option value="<?= $c['id'] ?>" data-tid="<?= $c['transportista_id'] ?>"
                                    <?= ($turno['chofer_id']??'')==$c['id']?'selected':'' ?>>
                                <?= h($c['nombre']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-nuevo"
                                onclick="abrirModalChofer()"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="<?= url('modules/agenda.php') ?>?fecha=<?= h($fecha_default) ?>"
               class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-floppy me-1"></i><?= $id ? 'Guardar cambios' : 'Crear turno' ?>
            </button>
        </div>
    </form>
</div>

<!-- Modales nuevo transportista / camión / chofer (reusan los AJAX endpoints ya existentes) -->
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
const allCamiones = <?= json_encode($camiones_all) ?>;
const allChoferes = <?= json_encode($choferes_all) ?>;
const allRemitos  = <?= json_encode($remitos_pend) ?>;
const selTrans    = document.getElementById('sel-transportista');
const selCam      = document.getElementById('sel-camion');
const selCho      = document.getElementById('sel-chofer');

function filtrarTransporte(tid) {
    const curC = selCam.value, curCh = selCho.value;
    const cams = allCamiones.filter(c => !tid || String(c.transportista_id) === String(tid));
    const chos = allChoferes.filter(c => !tid || String(c.transportista_id) === String(tid));
    selCam.innerHTML = '<option value="">— Sin asignar —</option>' +
        cams.map(c => `<option value="${c.id}" ${curC==c.id?'selected':''}>${c.patente}${c.marca?' — '+c.marca:''}</option>`).join('');
    selCho.innerHTML = '<option value="">— Sin asignar —</option>' +
        chos.map(c => `<option value="${c.id}" ${curCh==c.id?'selected':''}>${c.nombre}</option>`).join('');
    if (tid && cams.length===1 && !curC) selCam.value = cams[0].id;
    if (tid && chos.length===1 && !curCh) selCho.value = chos[0].id;
}
// Init
filtrarTransporte(selTrans.value);

function filtrarRemitos() {
    const cid    = document.getElementById('sel-cliente').value;
    const selR   = document.getElementById('sel-remito');
    const curVal = selR.value;
    selR.innerHTML = '<option value="">— Sin remito todavía —</option>' +
        allRemitos.filter(r => !cid || String(r.cliente_id)===String(cid))
                  .map(r => `<option value="${r.id}" ${curVal==r.id?'selected':''}>${r.nro_remito_propio} — ${r.cliente}${r.total_pallets>0?' ('+parseFloat(r.total_pallets).toFixed(1)+' pal)':''}</option>`)
                  .join('');
}

function autocompletarCliente(remitoId) {
    if (!remitoId) return;
    const remito = allRemitos.find(r => String(r.id) === String(remitoId));
    if (!remito) return;
    const selC = document.getElementById('sel-cliente');
    if (!selC.value) {
        selC.value = remito.cliente_id;
        filtrarRemitos();
        document.getElementById('sel-remito').value = remitoId;
    }
    if (remito.total_pallets > 0) {
        document.querySelector('[name="pallets_est"]').value = parseFloat(remito.total_pallets).toFixed(1);
    }
}

document.getElementById('form-turno').addEventListener('submit', function(e) {
    const fecha   = this.elements['fecha'].value.trim();
    const cliente = this.elements['cliente_id'].value;
    const errores = [];
    if (!fecha)   errores.push('Fecha es obligatoria.');
    if (!cliente) errores.push('Cliente es obligatorio.');
    if (errores.length) {
        e.preventDefault();
        const existing = this.querySelector('.alert-validacion');
        if (existing) existing.remove();
        const div = document.createElement('div');
        div.className = 'alert alert-danger py-2 alert-validacion';
        div.innerHTML = '<i class="bi bi-exclamation-circle me-2"></i>' + errores.join(' ');
        this.insertBefore(div, this.firstChild);
        if (!fecha)        this.elements['fecha'].focus();
        else if (!cliente) document.getElementById('sel-cliente').focus();
    }
});

async function post(url, data) {
    data.ajax='1';
    return (await fetch(url,{method:'POST',body:new URLSearchParams(data)})).json();
}

const mTrans = new bootstrap.Modal(document.getElementById('modalTrans'));
const mCam   = new bootstrap.Modal(document.getElementById('modalCam'));
const mCho   = new bootstrap.Modal(document.getElementById('modalCho'));

function abrirModalTrans() { document.getElementById('mt_n').value=''; mTrans.show(); setTimeout(()=>document.getElementById('mt_n').focus(),300); }
async function guardarTrans() {
    const nombre = document.getElementById('mt_n').value.trim();
    if (!nombre) return;
    const res = await post('<?= url('modules/transportistas_guardar_ajax.php') ?>',{nombre,cuit:document.getElementById('mt_c').value.trim(),telefono:document.getElementById('mt_t').value.trim()});
    if (!res.ok){alert(res.error);return;}
    selTrans.appendChild(new Option(res.nombre,res.id));
    selTrans.value=res.id; mTrans.hide(); filtrarTransporte(res.id);
}
function abrirModalCamion() { document.getElementById('mc_p').value=''; mCam.show(); setTimeout(()=>document.getElementById('mc_p').focus(),300); }
async function guardarCam() {
    const patente=document.getElementById('mc_p').value.trim().toUpperCase();
    if(!patente)return;
    const tid=selTrans.value;
    const res=await post('<?= url('modules/camiones_guardar.php') ?>',{accion:'guardar',transportista_id:tid,patente,marca:document.getElementById('mc_m').value.trim(),modelo:document.getElementById('mc_mo').value.trim()});
    if(!res.ok){alert(res.error);return;}
    allCamiones.push({id:res.id,transportista_id:tid,patente:res.patente,marca:document.getElementById('mc_m').value.trim()});
    mCam.hide(); filtrarTransporte(tid); selCam.value=res.id;
}
function abrirModalChofer() { document.getElementById('mch_n').value=''; mCho.show(); setTimeout(()=>document.getElementById('mch_n').focus(),300); }
async function guardarCho() {
    const nombre=document.getElementById('mch_n').value.trim();
    if(!nombre)return;
    const tid=selTrans.value;
    const res=await post('<?= url('modules/choferes_guardar.php') ?>',{accion:'guardar',transportista_id:tid,nombre,telefono:document.getElementById('mch_t').value.trim()});
    if(!res.ok){alert(res.error);return;}
    allChoferes.push({id:res.id,transportista_id:tid,nombre:res.nombre});
    mCho.hide(); filtrarTransporte(tid); selCho.value=res.id;
}
</script>
</body>
</html>
