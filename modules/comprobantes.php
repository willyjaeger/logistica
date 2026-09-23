<?php
// Carga de comprobantes de entrega: remito firmado escaneado o fotografiado
require_once __DIR__ . '/../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

$remito_ini = (int)($_GET['remito_id'] ?? 0);

// Remitos entregados en los últimos 30 días que todavía no tienen comprobante
$pendientes = [];
$falta_migracion = false;
try {
    $st = $db->prepare("
        SELECT r.id, r.nro_remito_propio, c.nombre AS cliente, r.fecha_entrega
        FROM remitos r
        JOIN clientes c ON c.id = r.cliente_id
        WHERE r.empresa_id = ?
          AND r.estado IN ('entregado','parcialmente_entregado')
          AND COALESCE(r.fecha_entrega, DATE(r.creado_en)) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND NOT EXISTS (SELECT 1 FROM remito_comprobantes rc WHERE rc.remito_id = r.id)
        ORDER BY r.fecha_entrega DESC, r.nro_remito_propio
        LIMIT 100
    ");
    $st->execute([$eid]);
    $pendientes = $st->fetchAll();
} catch (PDOException $e) {
    $falta_migracion = true;
}

$nav_modulo = 'comprobantes';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobantes de entrega — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        body { background: #eef1f6; }
        #q-remito { font-size: 1.4rem; font-family: var(--bs-font-monospace); }
        .res-item { cursor: pointer; }
        .res-item.activo { background: #eef5ff; border-left: 4px solid #0d6efd; }
        .btn-accion { font-size: 1.15rem; padding: .9rem 1rem; }
        .btn-accion i { font-size: 1.6rem; display: block; margin-bottom: .2rem; }
        #zona-remito.drag { outline: 3px dashed #0d6efd; outline-offset: 4px; }
        .thumb { width: 110px; height: 140px; border: 1px solid #dee2e6; border-radius: .4rem; background: #fff;
                 position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center; }
        .thumb img { width: 100%; height: 100%; object-fit: cover; }
        .thumb .btn-borrar { position: absolute; top: 3px; right: 3px; padding: 0 .35rem; line-height: 1.3; }
        .pend-item { cursor: pointer; }
        .pend-item:hover { background: #f8f9fa; }
        #aviso { position: fixed; top: 70px; left: 50%; transform: translateX(-50%); z-index: 2000; min-width: 280px; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div id="aviso"></div>

<div class="container-fluid py-3 px-4">

    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0"><i class="bi bi-file-earmark-check me-2 text-primary"></i>Comprobantes de entrega</h5>
        <span id="estado-escaner" class="badge bg-secondary"><i class="bi bi-hourglass me-1"></i>Buscando escáner…</span>
    </div>

    <?php if ($falta_migracion): ?>
    <div class="alert alert-danger">Falta aplicar <code>migracion_comprobantes.sql</code> en la base de datos.</div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-8">

            <!-- 1. Buscar remito -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <label class="form-label fw-semibold mb-1" for="q-remito">1. Nro de remito</label>
                    <input type="text" id="q-remito" class="form-control" autocomplete="off" autofocus
                           placeholder="Tipeá o escaneá el número y Enter">
                    <div id="resultados" class="list-group mt-2"></div>
                </div>
            </div>

            <!-- 2. Remito seleccionado + acciones -->
            <div id="zona-remito" class="card border-0 shadow-sm d-none">
                <div class="card-body">
                    <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                        <div>
                            <div class="fs-4 fw-bold font-monospace" id="r-nro"></div>
                            <div id="r-cliente" class="fw-semibold"></div>
                            <div class="small text-muted" id="r-extra"></div>
                        </div>
                        <div id="r-estado"></div>
                    </div>

                    <label class="form-label fw-semibold mb-2">2. Cargar el remito firmado</label>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <button type="button" id="btn-escanear" class="btn btn-primary btn-accion w-100" disabled>
                                <i class="bi bi-printer"></i>Escanear
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button type="button" id="btn-foto" class="btn btn-outline-primary btn-accion w-100">
                                <i class="bi bi-camera"></i>Sacar foto
                            </button>
                            <input type="file" id="in-foto" accept="image/*" capture="environment" class="d-none">
                        </div>
                        <div class="col-md-4">
                            <button type="button" id="btn-archivo" class="btn btn-outline-secondary btn-accion w-100">
                                <i class="bi bi-folder2-open"></i>Subir archivo
                            </button>
                            <input type="file" id="in-archivo" accept="image/*,application/pdf" multiple class="d-none">
                        </div>
                    </div>
                    <div class="small text-muted mb-3">
                        También podés arrastrar el archivo acá o pegarlo con Ctrl+V.
                        Si el remito tiene varias hojas, cargalas una por una: quedan todas vinculadas.
                    </div>

                    <div id="subiendo" class="alert alert-info py-2 d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span><span id="subiendo-txt">Subiendo…</span>
                    </div>

                    <div class="fw-semibold small text-muted mb-2">Comprobantes cargados</div>
                    <div id="thumbs" class="d-flex flex-wrap gap-2"></div>
                </div>
            </div>
        </div>

        <!-- Pendientes -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold small">
                    <i class="bi bi-exclamation-circle text-warning me-1"></i>Entregados sin comprobante
                    <span class="text-muted fw-normal">(últimos 30 días)</span>
                    <span class="badge bg-warning text-dark ms-1" id="pend-cant"><?= count($pendientes) ?></span>
                </div>
                <div class="list-group list-group-flush" id="pendientes" style="max-height:70vh;overflow:auto">
                    <?php foreach ($pendientes as $p): ?>
                    <div class="list-group-item pend-item py-2" data-id="<?= $p['id'] ?>">
                        <div class="d-flex justify-content-between">
                            <span class="font-monospace small fw-semibold"><?= h($p['nro_remito_propio']) ?></span>
                            <span class="small text-muted"><?= $p['fecha_entrega'] ? h(date('d/m', strtotime($p['fecha_entrega']))) : '' ?></span>
                        </div>
                        <div class="small text-truncate"><?= h($p['cliente']) ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$pendientes): ?>
                    <div class="list-group-item text-muted small text-center py-3">Nada pendiente 👍</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body small">
                    <div class="fw-semibold mb-1"><i class="bi bi-printer me-1"></i>Escáner</div>
                    <p class="text-muted mb-2">
                        Para que el botón <b>Escanear</b> funcione, la PC que tiene el escáner debe tener abierto el
                        programa <i>Escáner Logax</i> (se instala una sola vez).
                    </p>
                    <a href="<?= url('modules/escaner_descargar.php?f=instalar') ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-download me-1"></i>Descargar instalador
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const URL_BUSCAR   = <?= json_encode(url('modules/comprobantes_buscar.php')) ?>;
const URL_SUBIR    = <?= json_encode(url('modules/comprobantes_subir.php')) ?>;
const URL_ELIMINAR = <?= json_encode(url('modules/comprobantes_eliminar.php')) ?>;
const ESCANER      = 'http://localhost:8765';
const ESTADOS = {
    pendiente: 'Pendiente', turnado: 'Turnado', programado: 'Programado', en_camino: 'En camino',
    entregado: 'Entregado', parcialmente_entregado: 'Parcial', en_stock: 'En stock', cancelado: 'Cancelado'
};

const $ = id => document.getElementById(id);
let remito = null;        // remito seleccionado
let resultados = [];
let escanerOk = false;
let ocupado = false;

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function aviso(texto, tipo = 'success') {
    const div = document.createElement('div');
    div.className = `alert alert-${tipo} shadow py-2 text-center fw-semibold`;
    div.textContent = texto;
    $('aviso').replaceChildren(div);
    setTimeout(() => div.remove(), tipo === 'success' ? 2500 : 6000);
}

// ── Búsqueda de remito ───────────────────────────────────────────
let tBuscar;
$('q-remito').addEventListener('input', () => {
    clearTimeout(tBuscar);
    tBuscar = setTimeout(buscar, 250);
});
$('q-remito').addEventListener('keydown', async e => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    e.stopPropagation();   // que no lo tome el "Enter avanza campo" de la navbar
    clearTimeout(tBuscar);
    await buscar();
    if (resultados.length) seleccionar(resultados[0].id);
});

async function buscar() {
    const q = $('q-remito').value.trim();
    if (!q) { resultados = []; $('resultados').innerHTML = ''; return; }
    const r = await fetch(URL_BUSCAR + '?q=' + encodeURIComponent(q)).then(r => r.json()).catch(() => null);
    resultados = r?.ok ? r.remitos : [];
    $('resultados').innerHTML = resultados.length
        ? resultados.map(x => `
            <a class="list-group-item list-group-item-action res-item ${remito?.id == x.id ? 'activo' : ''}" data-id="${x.id}">
                <div class="d-flex justify-content-between">
                    <span class="font-monospace fw-semibold">${esc(x.nro_remito_propio)}</span>
                    <span>${x.n_comp > 0 ? `<span class="badge bg-success"><i class="bi bi-paperclip"></i> ${x.n_comp}</span>` : ''}
                          <span class="badge badge-estado-${esc(x.estado)}">${esc(ESTADOS[x.estado] ?? x.estado)}</span></span>
                </div>
                <div class="small">${esc(x.cliente)} <span class="text-muted">${x.proveedor ? '· ' + esc(x.proveedor) : ''}${x.nro_remito_proveedor ? ' · Rto prov. ' + esc(x.nro_remito_proveedor) : ''}</span></div>
            </a>`).join('')
        : '<div class="list-group-item text-muted small">Sin resultados</div>';
}

$('resultados').addEventListener('click', e => {
    const it = e.target.closest('[data-id]');
    if (it) seleccionar(it.dataset.id);
});
$('pendientes').addEventListener('click', e => {
    const it = e.target.closest('[data-id]');
    if (!it) return;
    $('q-remito').value = it.querySelector('.font-monospace').textContent;
    $('resultados').innerHTML = '';
    seleccionar(it.dataset.id);
});

async function seleccionar(id) {
    const r = await fetch(URL_BUSCAR + '?id=' + id).then(r => r.json()).catch(() => null);
    if (!r?.ok) { aviso(r?.error || 'No se pudo cargar el remito', 'danger'); return; }
    remito = r.remito;
    document.querySelectorAll('.res-item').forEach(el => el.classList.toggle('activo', el.dataset.id == id));
    $('zona-remito').classList.remove('d-none');
    $('r-nro').textContent = remito.nro_remito_propio;
    $('r-cliente').textContent = remito.cliente;
    const extra = [];
    if (remito.proveedor) extra.push(remito.proveedor);
    if (remito.nro_remito_proveedor) extra.push('Rto prov. ' + remito.nro_remito_proveedor);
    if (remito.nro_oc) extra.push('OC ' + remito.nro_oc);
    if (remito.fecha_entrega) extra.push('Entrega ' + remito.fecha_entrega.split('-').reverse().join('/'));
    $('r-extra').textContent = extra.join(' · ');
    $('r-estado').innerHTML = `<span class="badge fs-6 badge-estado-${esc(remito.estado)}">${esc(ESTADOS[remito.estado] ?? remito.estado)}</span>`;
    pintarThumbs();
    ($('btn-escanear').disabled ? $('btn-foto') : $('btn-escanear')).focus();
}

function pintarThumbs() {
    const cs = remito.comprobantes;
    $('thumbs').innerHTML = cs.length ? cs.map(c => `
        <div class="thumb">
            <a href="${esc(c.url)}" target="_blank" class="w-100 h-100 d-flex align-items-center justify-content-center text-decoration-none">
                ${c.mime === 'application/pdf'
                    ? '<i class="bi bi-file-earmark-pdf text-danger" style="font-size:3rem"></i>'
                    : `<img src="${esc(c.url)}" loading="lazy" alt="">`}
            </a>
            <button type="button" class="btn btn-sm btn-danger btn-borrar" data-borrar="${c.id}" title="Eliminar">&times;</button>
        </div>`).join('')
        : '<div class="text-muted small">Todavía no tiene comprobantes.</div>';

    // Si ya tiene comprobante, sacarlo de la lista de pendientes
    if (cs.length) {
        const p = document.querySelector(`#pendientes [data-id="${remito.id}"]`);
        if (p) { p.remove(); $('pend-cant').textContent = document.querySelectorAll('#pendientes [data-id]').length; }
    }
}

$('thumbs').addEventListener('click', async e => {
    const b = e.target.closest('[data-borrar]');
    if (!b || !confirm('¿Eliminar este comprobante?')) return;
    const fd = new FormData();
    fd.append('id', b.dataset.borrar);
    const r = await fetch(URL_ELIMINAR, { method: 'POST', body: fd }).then(r => r.json()).catch(() => null);
    if (r?.ok) seleccionar(remito.id); else aviso('No se pudo eliminar', 'danger');
});

// ── Subida ───────────────────────────────────────────────────────
// Las imágenes se achican a ~2200px en JPEG para no llenar el hosting; los PDF van tal cual.
function comprimir(blob) {
    if (!blob.type.startsWith('image/')) return Promise.resolve(blob);
    return new Promise(resolve => {
        const img = new Image();
        const u = URL.createObjectURL(blob);
        img.onload = () => {
            const max = 2200;
            const k = Math.min(1, max / Math.max(img.width, img.height));
            const cv = document.createElement('canvas');
            cv.width = Math.round(img.width * k);
            cv.height = Math.round(img.height * k);
            cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
            URL.revokeObjectURL(u);
            cv.toBlob(b => resolve(b && b.size < blob.size ? b : blob), 'image/jpeg', 0.82);
        };
        img.onerror = () => { URL.revokeObjectURL(u); resolve(blob); };
        img.src = u;
    });
}

function setOcupado(on, txt = 'Subiendo…') {
    ocupado = on;
    $('subiendo').classList.toggle('d-none', !on);
    $('subiendo-txt').textContent = txt;
    ['btn-foto', 'btn-archivo'].forEach(id => $(id).disabled = on);
    $('btn-escanear').disabled = on || !escanerOk;
}

async function subir(blob, origen, nombre) {
    if (!remito) { aviso('Primero elegí el remito', 'warning'); return false; }
    const archivo = await comprimir(blob);
    const fd = new FormData();
    fd.append('remito_id', remito.id);
    fd.append('origen', origen);
    fd.append('archivo', archivo, nombre || (archivo.type === 'application/pdf' ? 'comprobante.pdf' : 'comprobante.jpg'));
    const r = await fetch(URL_SUBIR, { method: 'POST', body: fd }).then(r => r.json()).catch(() => null);
    if (!r?.ok) { aviso(r?.error || 'Error al subir el comprobante', 'danger'); return false; }
    return true;
}

async function subirVarios(files, origen) {
    if (!remito || !files.length || ocupado) return;
    setOcupado(true);
    let ok = 0;
    for (const f of files) if (await subir(f, origen, f.name)) ok++;
    setOcupado(false);
    if (ok) terminado(ok);
}

async function terminado(n) {
    aviso(`✓ ${n > 1 ? n + ' comprobantes guardados' : 'Comprobante guardado'} en ${remito.nro_remito_propio}`);
    await seleccionar(remito.id);
    // Listo para el próximo remito: el nro queda seleccionado, se tipea encima
    $('q-remito').focus();
    $('q-remito').select();
}

$('btn-foto').addEventListener('click', () => $('in-foto').click());
$('btn-archivo').addEventListener('click', () => $('in-archivo').click());
$('in-foto').addEventListener('change', e => { subirVarios([...e.target.files], 'foto'); e.target.value = ''; });
$('in-archivo').addEventListener('change', e => { subirVarios([...e.target.files], 'archivo'); e.target.value = ''; });

// Arrastrar y soltar / pegar
const zona = $('zona-remito');
['dragenter', 'dragover'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.add('drag'); }));
['dragleave', 'drop'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.remove('drag'); }));
zona.addEventListener('drop', e => subirVarios([...e.dataTransfer.files], 'archivo'));
document.addEventListener('paste', e => {
    const files = [...(e.clipboardData?.files || [])];
    if (files.length && remito) { e.preventDefault(); subirVarios(files, 'archivo'); }
});

// ── Escáner (programa local en la PC del escáner) ────────────────
async function fetchTimeout(url, opts = {}, ms = 2000) {
    const ctl = new AbortController();
    const t = setTimeout(() => ctl.abort(), ms);
    try { return await fetch(url, { ...opts, signal: ctl.signal }); }
    finally { clearTimeout(t); }
}

async function verificarEscaner() {
    let info = null;
    try { info = await (await fetchTimeout(ESCANER + '/estado')).json(); } catch (e) {}
    escanerOk = !!info?.ok;
    const b = $('estado-escaner');
    if (escanerOk) {
        b.className = 'badge bg-success';
        b.innerHTML = '<i class="bi bi-printer me-1"></i>' + esc(info.escaner || 'Escáner listo');
    } else {
        b.className = 'badge bg-secondary';
        b.innerHTML = '<i class="bi bi-printer me-1"></i>' + esc(info?.error || 'Escáner no disponible en esta PC');
    }
    if (!ocupado) $('btn-escanear').disabled = !escanerOk;
}

$('btn-escanear').addEventListener('click', async () => {
    if (!remito || ocupado) return;
    setOcupado(true, 'Escaneando… no saques la hoja');
    try {
        const resp = await fetchTimeout(ESCANER + '/escanear', { method: 'POST' }, 120000);
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            throw new Error(err.error || 'El escáner devolvió un error');
        }
        const img = await resp.blob();
        setOcupado(true, 'Guardando…');
        const ok = await subir(img, 'escaner');
        setOcupado(false);
        if (ok) terminado(1);
    } catch (e) {
        setOcupado(false);
        aviso(e.name === 'AbortError' ? 'El escáner no respondió' : e.message, 'danger');
        verificarEscaner();
    }
});

verificarEscaner();
setInterval(() => { if (!ocupado) verificarEscaner(); }, 15000);

<?php if ($remito_ini): ?>
seleccionar(<?= $remito_ini ?>);
<?php endif; ?>
</script>
</body>
</html>
