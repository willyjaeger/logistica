<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

// Remitos pendientes de entrega
$stmt = $db->prepare("
    SELECT r.id, r.nro_remito_propio, r.total_pallets, r.proveedor_id,
           c.nombre AS cliente,
           p.nombre AS proveedor,
           i.fecha_ingreso
    FROM remitos r
    JOIN clientes c   ON r.cliente_id  = c.id
    LEFT JOIN proveedores p ON r.proveedor_id = p.id
    JOIN ingresos i   ON r.ingreso_id  = i.id
    WHERE r.empresa_id = ? AND r.estado NOT IN ('entregado')
    ORDER BY i.fecha_ingreso ASC, r.id ASC
");
$stmt->execute([$eid]);
$pendientes = $stmt->fetchAll();

// Transportistas activos con sus camiones y choferes
$trans = $db->prepare("SELECT id, nombre, cuit, telefono FROM transportistas WHERE empresa_id = ? AND activo = 1 ORDER BY nombre");
$trans->execute([$eid]);
$transportistas = $trans->fetchAll();

$cam = $db->prepare("SELECT id, transportista_id, patente, marca, modelo FROM camiones WHERE activo = 1 AND empresa_id = ? ORDER BY patente");
$cam->execute([$eid]);
$camiones = $cam->fetchAll();

$cho = $db->prepare("SELECT id, transportista_id, nombre FROM choferes WHERE activo = 1 AND empresa_id = ? ORDER BY nombre");
$cho->execute([$eid]);
$choferes_all = $cho->fetchAll();

// Proveedores para filtro
$provs = $db->query("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();

$form_error = $_SESSION['form_error'] ?? null;
unset($_SESSION['form_error']);

$nav_modulo = 'entregas';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nueva entrega — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        body { background: #eef1f6; }
        .barra-top {
            position: sticky; top: 56px; z-index: 99;
            background: #1e293b; border-bottom: 3px solid #16a34a;
            padding: .75rem 1.5rem;
            display: flex; align-items: center; gap: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,.25);
        }
        .barra-top .stat-label { font-size: .8rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; }
        .barra-top .stat-val   { font-size: 1.8rem; font-weight: 700; color: #4ade80; line-height: 1; }
        .seccion {
            background: #fff; border: none; border-left: 4px solid #16a34a;
            border-radius: .5rem; padding: 1rem 1.25rem; margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        .seccion-titulo {
            font-size: .78rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .08em; color: #16a34a; margin-bottom: .75rem;
            padding-bottom: .4rem; border-bottom: 1px solid #e9ecef;
        }
        .form-label { color: #374151; font-weight: 600; }
        #tabla-remitos thead th {
            background: #1e293b; color: #94a3b8;
            font-size: .75rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .05em; padding: .5rem .6rem; border: none;
        }
        #tabla-remitos tbody tr { cursor: pointer; }
        #tabla-remitos tbody tr:hover { background: #dbeafe; }
        #tabla-remitos tbody tr.seleccionado { background: #dcfce7; }
        #tabla-remitos td { vertical-align: middle; padding: .4rem .6rem; font-size: .9rem; }
        .pallets-badge { font-size: .85rem; font-weight: 700; color: #0d6efd; }
        #filtro-buscar { max-width: 260px; }
        .btn-nuevo { padding: .2rem .45rem; font-size: .8rem; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- BARRA STICKY -->
<div class="barra-top">
    <div>
        <div class="stat-label">Remitos</div>
        <div class="stat-val" id="cnt-remitos">0</div>
    </div>
    <div>
        <div class="stat-label">Pallets</div>
        <div class="stat-val" id="cnt-pallets">0.0</div>
    </div>
    <div class="ms-auto">
        <button type="submit" form="form-entrega" class="btn btn-success px-4">
            <i class="bi bi-check-lg me-1"></i>Confirmar entrega
        </button>
    </div>
</div>

<div class="container-fluid py-3 px-3 px-lg-4">
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= url('modules/entregas_lista.php') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h5 class="fw-bold mb-0">Nueva entrega</h5>
    </div>

    <?php if ($form_error): ?>
    <div class="alert alert-danger alert-dismissible py-2 mb-3">
        <i class="bi bi-exclamation-circle me-2"></i><?= h($form_error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (empty($pendientes)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>No hay remitos pendientes de entrega.
    </div>
    <?php else: ?>

    <form id="form-entrega" method="POST" action="<?= url('modules/entregas_guardar.php') ?>">

        <!-- DATOS DEL VIAJE -->
        <div class="seccion">
            <div class="seccion-titulo"><i class="bi bi-truck me-1"></i>Datos del viaje</div>
            <div class="row g-2 align-items-end">

                <div class="col-sm-2">
                    <label class="form-label form-label-sm mb-1">Fecha <span class="text-danger">*</span></label>
                    <input type="date" name="fecha" class="form-control form-control-sm"
                           value="<?= date('Y-m-d') ?>" required>
                </div>

                <!-- TRANSPORTISTA -->
                <div class="col-sm-3">
                    <label class="form-label form-label-sm mb-1">Transportista</label>
                    <div class="input-group input-group-sm">
                        <select name="transportista_id" id="sel-transportista" class="form-select form-select-sm">
                            <option value="">— Seleccionar —</option>
                            <?php foreach ($transportistas as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= h($t['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-nuevo"
                                title="Nuevo transportista" onclick="abrirModalTransportista()">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </div>
                </div>

                <!-- CAMIÓN -->
                <div class="col-sm-3">
                    <label class="form-label form-label-sm mb-1">Camión (patente)</label>
                    <div class="input-group input-group-sm">
                        <select name="camion_id" id="sel-camion" class="form-select form-select-sm" disabled>
                            <option value="">— Primero elegí transportista —</option>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-nuevo"
                                id="btn-nuevo-camion" title="Nuevo camión" disabled
                                onclick="abrirModalCamion()">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </div>
                </div>

                <!-- CHOFER -->
                <div class="col-sm-3">
                    <label class="form-label form-label-sm mb-1">Chofer</label>
                    <div class="input-group input-group-sm">
                        <select name="chofer_id" id="sel-chofer" class="form-select form-select-sm" disabled>
                            <option value="">— Primero elegí transportista —</option>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-nuevo"
                                id="btn-nuevo-chofer" title="Nuevo chofer" disabled
                                onclick="abrirModalChofer()">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </div>
                </div>

                <div class="col-sm-12 col-lg">
                    <label class="form-label form-label-sm mb-1">Observaciones</label>
                    <input type="text" name="observaciones" class="form-control form-control-sm"
                           placeholder="Notas del viaje">
                </div>

            </div>
        </div>

        <!-- REMITOS -->
        <div class="seccion">
            <div class="seccion-titulo"><i class="bi bi-file-earmark-text me-1"></i>Remitos a entregar</div>

            <div class="d-flex gap-2 mb-2 flex-wrap">
                <input type="text" id="filtro-buscar" class="form-control form-control-sm"
                       placeholder="Buscar cliente o nro remito...">
                <select id="filtro-prov" class="form-select form-select-sm" style="max-width:200px">
                    <option value="">Todos los proveedores</option>
                    <?php foreach ($provs as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= h($p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-sel-todos">
                    <i class="bi bi-check-all me-1"></i>Todos
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-sel-ninguno">
                    <i class="bi bi-x me-1"></i>Ninguno
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" id="tabla-remitos">
                    <thead>
                        <tr>
                            <th style="width:36px">
                                <input type="checkbox" id="chk-todos" class="form-check-input">
                            </th>
                            <th>Nro remito</th>
                            <th>Fecha ingreso</th>
                            <th>Cliente</th>
                            <th>Proveedor</th>
                            <th class="text-end">Pallets</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendientes as $r):
                        $fi = substr($r['fecha_ingreso'], 0, 10);
                        [$y,$m,$d] = explode('-', $fi);
                    ?>
                    <tr class="remito-row"
                        data-id="<?= $r['id'] ?>"
                        data-pallets="<?= (float)($r['total_pallets'] ?? 0) ?>"
                        data-prov="<?= $r['proveedor_id'] ?? '' ?>"
                        data-texto="<?= strtolower(h($r['nro_remito_propio']) . ' ' . h($r['cliente'])) ?>">
                        <td><input type="checkbox" name="remitos[]" value="<?= $r['id'] ?>"
                                   class="form-check-input chk-remito"></td>
                        <td class="fw-semibold font-monospace"><?= h($r['nro_remito_propio']) ?></td>
                        <td class="text-muted"><?= "$d/$m/$y" ?></td>
                        <td><?= h($r['cliente']) ?></td>
                        <td class="text-muted small"><?= h($r['proveedor'] ?? '—') ?></td>
                        <td class="text-end pallets-badge">
                            <?= $r['total_pallets'] > 0 ? number_format($r['total_pallets'], 1) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="<?= url('modules/entregas_lista.php') ?>" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-success px-4">
                <i class="bi bi-check-lg me-1"></i>Confirmar entrega
            </button>
        </div>

    </form>
    <?php endif; ?>
</div>

<!-- ==================== MODAL NUEVO TRANSPORTISTA ==================== -->
<div class="modal fade" id="modalTransportista" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-person-vcard me-2"></i>Nuevo transportista</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label form-label-sm fw-semibold">Nombre / Razón social <span class="text-danger">*</span></label>
                    <input type="text" id="mt_nombre" class="form-control form-control-sm"
                           placeholder="Ej: Transportes García">
                </div>
                <div class="row g-2">
                    <div class="col">
                        <label class="form-label form-label-sm fw-semibold">CUIT <span class="text-muted">(opcional)</span></label>
                        <input type="text" id="mt_cuit" class="form-control form-control-sm font-monospace"
                               placeholder="20-12345678-9">
                    </div>
                    <div class="col">
                        <label class="form-label form-label-sm fw-semibold">Teléfono <span class="text-muted">(opcional)</span></label>
                        <input type="text" id="mt_telefono" class="form-control form-control-sm"
                               placeholder="011 1234-5678">
                    </div>
                </div>
                <div class="mt-3 p-2 bg-light rounded small text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Podés agregar camiones y choferes después desde el módulo de Transportistas.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="guardarTransportista()">
                    <i class="bi bi-floppy me-1"></i>Crear y seleccionar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ==================== MODAL NUEVO CAMIÓN ==================== -->
<div class="modal fade" id="modalCamion" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-truck-front me-2"></i>Nuevo camión</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label form-label-sm fw-semibold">Patente <span class="text-danger">*</span></label>
                    <input type="text" id="mc_patente" class="form-control form-control-sm font-monospace text-uppercase"
                           placeholder="Ej: AB123CD">
                </div>
                <div class="row g-2">
                    <div class="col">
                        <label class="form-label form-label-sm fw-semibold">Marca <span class="text-muted">(opcional)</span></label>
                        <input type="text" id="mc_marca" class="form-control form-control-sm"
                               placeholder="Ej: Mercedes">
                    </div>
                    <div class="col">
                        <label class="form-label form-label-sm fw-semibold">Modelo <span class="text-muted">(opcional)</span></label>
                        <input type="text" id="mc_modelo" class="form-control form-control-sm"
                               placeholder="Ej: Actros 1845">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success btn-sm" onclick="guardarCamion()">
                    <i class="bi bi-floppy me-1"></i>Crear y seleccionar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ==================== MODAL NUEVO CHOFER ==================== -->
<div class="modal fade" id="modalChofer" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-person-badge me-2"></i>Nuevo chofer</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label form-label-sm fw-semibold">Nombre completo <span class="text-danger">*</span></label>
                    <input type="text" id="mch_nombre" class="form-control form-control-sm"
                           placeholder="Nombre y apellido">
                </div>
                <div class="mb-3">
                    <label class="form-label form-label-sm fw-semibold">Teléfono <span class="text-muted">(opcional)</span></label>
                    <input type="text" id="mch_telefono" class="form-control form-control-sm"
                           placeholder="Ej: 011 1234-5678">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning btn-sm" onclick="guardarChofer()">
                    <i class="bi bi-floppy me-1"></i>Crear y seleccionar
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

// Datos embebidos desde PHP
const todosLosCamiones = <?= json_encode($camiones) ?>;
const todosLosChoferes = <?= json_encode($choferes_all) ?>;

const selTrans  = document.getElementById('sel-transportista');
const selCamion = document.getElementById('sel-camion');
const selChofer = document.getElementById('sel-chofer');
const btnNuevoCamion = document.getElementById('btn-nuevo-camion');
const btnNuevoChofer = document.getElementById('btn-nuevo-chofer');

// ──── Dropdowns encadenados ────────────────────────────────────────────────
function poblarDropdowns(tid) {
    const cams = todosLosCamiones.filter(c => String(c.transportista_id) === String(tid));
    const chos = todosLosChoferes.filter(c => String(c.transportista_id) === String(tid));

    // Camiones
    selCamion.innerHTML = '<option value="">— Sin especificar —</option>';
    cams.forEach(c => {
        const label = c.patente + (c.marca ? ' — ' + c.marca : '') + (c.modelo ? ' ' + c.modelo : '');
        selCamion.innerHTML += `<option value="${c.id}">${label}</option>`;
    });
    selCamion.disabled = false;
    btnNuevoCamion.disabled = false;
    if (cams.length === 1) selCamion.value = cams[0].id;

    // Choferes
    selChofer.innerHTML = '<option value="">— Sin especificar —</option>';
    chos.forEach(c => {
        selChofer.innerHTML += `<option value="${c.id}">${c.nombre}</option>`;
    });
    selChofer.disabled = false;
    btnNuevoChofer.disabled = false;
    if (chos.length === 1) selChofer.value = chos[0].id;
}

function limpiarDropdowns() {
    selCamion.innerHTML = '<option value="">— Primero elegí transportista —</option>';
    selCamion.disabled = true;
    btnNuevoCamion.disabled = true;
    selChofer.innerHTML = '<option value="">— Primero elegí transportista —</option>';
    selChofer.disabled = true;
    btnNuevoChofer.disabled = true;
}

selTrans.addEventListener('change', function () {
    if (this.value) poblarDropdowns(this.value);
    else limpiarDropdowns();
});

// ──── AJAX helper ─────────────────────────────────────────────────────────
async function postAjax(url, data) {
    data.ajax = '1';
    const body = new URLSearchParams(data);
    const r = await fetch(url, { method: 'POST', body });
    return r.json();
}

// ──── Modal Transportista ──────────────────────────────────────────────────
const mTrans = new bootstrap.Modal(document.getElementById('modalTransportista'));

function abrirModalTransportista() {
    document.getElementById('mt_nombre').value = '';
    document.getElementById('mt_cuit').value = '';
    document.getElementById('mt_telefono').value = '';
    mTrans.show();
    setTimeout(() => document.getElementById('mt_nombre').focus(), 300);
}

async function guardarTransportista() {
    const nombre = document.getElementById('mt_nombre').value.trim();
    if (!nombre) { document.getElementById('mt_nombre').focus(); return; }

    const res = await postAjax('<?= url('modules/transportistas_guardar_ajax.php') ?>', {
        nombre,
        cuit:     document.getElementById('mt_cuit').value.trim(),
        telefono: document.getElementById('mt_telefono').value.trim(),
    });

    if (!res.ok) { alert(res.error || 'Error al guardar'); return; }

    // Agregar al select y seleccionar
    const opt = new Option(res.nombre, res.id);
    selTrans.appendChild(opt);
    selTrans.value = res.id;
    // Agregar a los arrays locales (sin camiones ni choferes aún)
    mTrans.hide();
    poblarDropdowns(res.id);
}

// ──── Modal Camión ─────────────────────────────────────────────────────────
const mCamion = new bootstrap.Modal(document.getElementById('modalCamion'));

function abrirModalCamion() {
    document.getElementById('mc_patente').value = '';
    document.getElementById('mc_marca').value = '';
    document.getElementById('mc_modelo').value = '';
    mCamion.show();
    setTimeout(() => document.getElementById('mc_patente').focus(), 300);
}

async function guardarCamion() {
    const patente = document.getElementById('mc_patente').value.trim().toUpperCase();
    if (!patente) { document.getElementById('mc_patente').focus(); return; }

    const tid = selTrans.value;
    const res = await postAjax('<?= url('modules/camiones_guardar.php') ?>', {
        accion:           'guardar',
        transportista_id: tid,
        patente,
        marca:  document.getElementById('mc_marca').value.trim(),
        modelo: document.getElementById('mc_modelo').value.trim(),
    });

    if (!res.ok) { alert(res.error || 'Error al guardar'); return; }

    // Agregar al array local
    todosLosCamiones.push({ id: res.id, transportista_id: tid, patente: res.patente,
                             marca: document.getElementById('mc_marca').value.trim(),
                             modelo: document.getElementById('mc_modelo').value.trim() });
    mCamion.hide();
    poblarDropdowns(tid);
    selCamion.value = res.id;
}

// ──── Modal Chofer ─────────────────────────────────────────────────────────
const mChofer = new bootstrap.Modal(document.getElementById('modalChofer'));

function abrirModalChofer() {
    document.getElementById('mch_nombre').value = '';
    document.getElementById('mch_telefono').value = '';
    mChofer.show();
    setTimeout(() => document.getElementById('mch_nombre').focus(), 300);
}

async function guardarChofer() {
    const nombre = document.getElementById('mch_nombre').value.trim();
    if (!nombre) { document.getElementById('mch_nombre').focus(); return; }

    const tid = selTrans.value;
    const res = await postAjax('<?= url('modules/choferes_guardar.php') ?>', {
        accion:           'guardar',
        transportista_id: tid,
        nombre,
        telefono: document.getElementById('mch_telefono').value.trim(),
    });

    if (!res.ok) { alert(res.error || 'Error al guardar'); return; }

    todosLosChoferes.push({ id: res.id, transportista_id: tid, nombre: res.nombre });
    mChofer.hide();
    poblarDropdowns(tid);
    selChofer.value = res.id;
}

// ──── Tabla de remitos ─────────────────────────────────────────────────────
const rows     = Array.from(document.querySelectorAll('.remito-row'));
const cntRem   = document.getElementById('cnt-remitos');
const cntPal   = document.getElementById('cnt-pallets');
const chkTodos = document.getElementById('chk-todos');

function actualizarContadores() {
    const sel = rows.filter(r => r.querySelector('.chk-remito').checked && !r.classList.contains('d-none'));
    const pallets = sel.reduce((s, r) => s + parseFloat(r.dataset.pallets || 0), 0);
    cntRem.textContent = sel.length;
    cntPal.textContent = pallets.toFixed(1);
    rows.forEach(r => r.classList.toggle('seleccionado', r.querySelector('.chk-remito').checked));
}

rows.forEach(r => {
    r.addEventListener('click', function (e) {
        if (e.target.type === 'checkbox') return;
        const chk = r.querySelector('.chk-remito');
        chk.checked = !chk.checked;
        actualizarContadores();
    });
    r.querySelector('.chk-remito').addEventListener('change', actualizarContadores);
});

if (chkTodos) {
    chkTodos.addEventListener('change', function () {
        rows.filter(r => !r.classList.contains('d-none'))
            .forEach(r => r.querySelector('.chk-remito').checked = this.checked);
        actualizarContadores();
    });
}

document.getElementById('btn-sel-todos').addEventListener('click', function () {
    rows.filter(r => !r.classList.contains('d-none'))
        .forEach(r => r.querySelector('.chk-remito').checked = true);
    actualizarContadores();
});
document.getElementById('btn-sel-ninguno').addEventListener('click', function () {
    rows.forEach(r => r.querySelector('.chk-remito').checked = false);
    actualizarContadores();
});

function filtrar() {
    const txt  = document.getElementById('filtro-buscar').value.toLowerCase().trim();
    const prov = document.getElementById('filtro-prov').value;
    rows.forEach(r => {
        const matchTxt  = !txt  || r.dataset.texto.includes(txt);
        const matchProv = !prov || r.dataset.prov === prov;
        r.classList.toggle('d-none', !matchTxt || !matchProv);
    });
    actualizarContadores();
}

document.getElementById('filtro-buscar').addEventListener('input', filtrar);
document.getElementById('filtro-prov').addEventListener('change', filtrar);

// ──── Prevenir doble envío ─────────────────────────────────────────────────
document.getElementById('form-entrega').addEventListener('submit', function() {
    document.querySelectorAll('[form="form-entrega"][type="submit"], #form-entrega [type="submit"]')
        .forEach(btn => {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Guardando…';
        });
});
</script>
</body>
</html>
