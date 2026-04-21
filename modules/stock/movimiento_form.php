<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

$tipo_pre     = $_GET['tipo']        ?? '';
$articulo_pre = (int)($_GET['articulo_id'] ?? 0);

$art_pre = null;
if ($articulo_pre) {
    $s = $db->prepare("SELECT id, codigo, descripcion, presentacion, bultos_por_pallet FROM articulos WHERE id = ?");
    $s->execute([$articulo_pre]);
    $art_pre = $s->fetch();
}

$error = $_SESSION['form_error'] ?? null; unset($_SESSION['form_error']);
$post  = $_SESSION['form_post']  ?? [];   unset($_SESSION['form_post']);

$tipos = [
    'entradas' => [
        'carga_inicial'     => 'Carga inicial de stock',
        'ingreso_remito'    => 'Ingreso con remito (Sanesa trae mercadería)',
        'ingreso_stock_seg' => 'Stock de seguridad (Sanesa, sin remito específico)',
        'ingreso_devolucion'=> 'Devolución de cliente (no recibió)',
        'ingreso_expreso'   => 'Ingreso desde expreso / cliente / tercero',
        'ajuste_positivo'   => 'Ajuste positivo (corrección)',
    ],
    'salidas' => [
        'salida_entrega'    => 'Salida por entrega a cliente',
        'salida_consumo'    => 'Consumo de stock (remito virtual Sanesa)',
        'ajuste_negativo'   => 'Ajuste negativo (corrección)',
    ],
];

$es_entrada_tipos = ['carga_inicial','ingreso_remito','ingreso_stock_seg',
                     'ingreso_devolucion','ingreso_expreso','ajuste_positivo'];

// Placeholder de observaciones según tipo
$obs_hints = [
    'carga_inicial'     => ['Detalle de la carga inicial',
                            "Ej: Stock existente al 31/03/2026. Relevamiento físico de depósito."],
    'ingreso_remito'    => ['Referencia del remito',
                            "Ej: Remito Sanesa R-00001-00000123 · Camión ABC 123 · Chofer Juan Pérez"],
    'ingreso_stock_seg' => ['Origen del stock de seguridad',
                            "Ej: Sanesa envió mercadería extra sin remito propio. Tel. contacto: ..."],
    'ingreso_devolucion'=> ['Motivo de la devolución',
                            "Ej: Cliente Jumbo Córdoba no recibió el lote por falta de espacio. Entrega #45."],
    'ingreso_expreso'   => ['Procedencia y referencia del expreso',
                            "Ej: Retirado de Expreso DHL, guía 7890123. Remite: cliente La Anónima Mendoza."],
    'ajuste_positivo'   => ['Motivo del ajuste',
                            "Ej: Diferencia de inventario físico. Conteo realizado el dd/mm/aaaa."],
    'salida_entrega'    => ['Cliente y referencia de entrega',
                            "Ej: Entrega #45 — Jumbo Córdoba. Chofer: Juan Pérez."],
    'salida_consumo'    => ['Remito virtual consumido',
                            "Ej: Remito virtual Sanesa R-00001-00000456. No trajo mercadería física."],
    'ajuste_negativo'   => ['Motivo del ajuste',
                            "Ej: Diferencia de inventario físico. Mercadería vencida retirada."],
];

$nav_modulo = 'stock';
$titulo = $tipo_pre === 'carga_inicial' ? 'Carga inicial de stock' : 'Registrar movimiento de stock';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $titulo ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        .ac-dropdown { position:absolute; z-index:1055; background:#fff; border:1px solid #dee2e6;
                       border-radius:.375rem; max-height:240px; overflow-y:auto;
                       box-shadow:0 4px 12px rgba(0,0,0,.12); min-width:320px; }
        .ac-item { padding:.35rem .7rem; cursor:pointer; font-size:.85rem; }
        .ac-item:hover,.ac-item.active { background:#e7f0ff; }
        .ac-code { font-family:monospace; color:#6b7280; font-size:.75rem; }
        .items-table th { font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; }
        .pallets-calc { font-size:.82rem; color:#7c3aed; font-weight:600; }
        .dir-badge { font-size:.72rem; font-weight:700; letter-spacing:.05em; padding:.2em .6em; border-radius:.25rem; }
        .dir-entrada { background:#dcfce7; color:#166534; }
        .dir-salida  { background:#fee2e2; color:#991b1b; }
        .obs-hint { font-size:.75rem; color:#6b7280; margin-top:.25rem; }
    </style>
</head>
<body class="bg-light">
<?php include __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container py-3" style="max-width:860px">

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= url('modules/stock/lista.php') ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Volver
    </a>
    <h5 class="mb-0 fw-bold ms-1">
        <i class="bi bi-arrow-left-right me-2 text-primary"></i><?= $titulo ?>
    </h5>
</div>

<?php if ($error): ?>
<div class="alert alert-danger py-2"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm p-4">
<form method="POST" action="<?= url('modules/stock/movimiento_guardar.php') ?>" id="frmMov">

<!-- Cabecera -->
<div class="row g-3 mb-3">
    <div class="col-sm-4">
        <label class="form-label fw-semibold">Fecha <span class="text-danger">*</span></label>
        <input type="date" name="fecha" class="form-control"
               value="<?= h($post['fecha'] ?? date('Y-m-d')) ?>" required>
    </div>
    <div class="col-sm-8">
        <label class="form-label fw-semibold">
            Tipo de movimiento <span class="text-danger">*</span>
            <span id="dir-badge" class="dir-badge ms-2" style="display:none"></span>
        </label>
        <select name="tipo" id="sel-tipo" class="form-select" required>
            <option value="">— Seleccionar —</option>
            <?php foreach ($tipos as $grupo => $opts): ?>
            <optgroup label="<?= strtoupper($grupo) ?>">
                <?php foreach ($opts as $val => $lbl): ?>
                <option value="<?= $val ?>"
                    <?= ($post['tipo'] ?? $tipo_pre) === $val ? 'selected' : '' ?>>
                    <?= $lbl ?>
                </option>
                <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<!-- Observaciones / Origen -->
<div class="mb-3">
    <label class="form-label fw-semibold" id="obs-label">
        Observaciones / Origen
    </label>
    <textarea name="observaciones" id="obs-textarea" class="form-control" rows="3"
              placeholder="Descripción del movimiento, referencia, procedencia…"><?= h($post['observaciones'] ?? '') ?></textarea>
    <div class="obs-hint" id="obs-hint"></div>
</div>

<hr class="my-3">

<!-- Items -->
<div class="d-flex justify-content-between align-items-center mb-2">
    <span class="fw-semibold">Artículos</span>
    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add">
        <i class="bi bi-plus-lg me-1"></i>Agregar artículo
    </button>
</div>

<div class="table-responsive">
<table class="table table-sm items-table align-middle" id="tbl-items">
    <thead class="table-light">
        <tr>
            <th>Artículo</th>
            <th style="width:130px">Cantidad bultos</th>
            <th style="width:110px" class="text-end">Pallets equiv.</th>
            <th style="width:36px"></th>
        </tr>
    </thead>
    <tbody id="tbody"></tbody>
</table>
</div>

<div class="d-flex justify-content-end gap-2 mt-3">
    <a href="<?= url('modules/stock/lista.php') ?>" class="btn btn-outline-secondary">Cancelar</a>
    <button type="submit" class="btn btn-primary px-4">
        <i class="bi bi-check-lg me-1"></i>Guardar y ver comprobante
    </button>
</div>

</form>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const AC_URL     = <?= json_encode(url('modules/remitos_ac_articulos.php')) ?>;
const ES_ENTRADA = <?= json_encode($es_entrada_tipos) ?>;
const TIPO_PRE   = <?= json_encode($tipo_pre) ?>;
const ART_PRE    = <?= json_encode($art_pre) ?>;
const POST_ITEMS = <?= json_encode(isset($post['items']) ? $post['items'] : []) ?>;
const OBS_HINTS  = <?= json_encode($obs_hints) ?>;

let rowIdx = 0;

// ── Tipo → badge + obs label/hint ────────────────────────────
function updateTipo() {
    const t     = document.getElementById('sel-tipo').value;
    const badge = document.getElementById('dir-badge');
    const label = document.getElementById('obs-label');
    const hint  = document.getElementById('obs-hint');
    const ta    = document.getElementById('obs-textarea');

    if (!t) { badge.style.display = 'none'; hint.textContent = ''; return; }

    const entrada = ES_ENTRADA.includes(t);
    badge.textContent = entrada ? '▲ ENTRADA' : '▼ SALIDA';
    badge.className   = 'dir-badge ms-2 ' + (entrada ? 'dir-entrada' : 'dir-salida');
    badge.style.display = '';

    if (OBS_HINTS[t]) {
        label.textContent = OBS_HINTS[t][0];
        ta.placeholder    = OBS_HINTS[t][1];
        hint.textContent  = '';
    } else {
        label.textContent = 'Observaciones';
        ta.placeholder    = 'Referencia, motivo, procedencia…';
        hint.textContent  = '';
    }
}
document.getElementById('sel-tipo').addEventListener('change', updateTipo);
updateTipo();

// ── Fila de artículo ─────────────────────────────────────────
function crearFila(art, cantidad) {
    const i = rowIdx++;
    const tr = document.createElement('tr');
    tr.dataset.bpp = art ? art.bultos_por_pallet : 1;
    tr.innerHTML = `
        <td style="position:relative">
            <input type="hidden" name="items[${i}][articulo_id]" class="hid-id" value="${art ? art.id : ''}">
            <input type="hidden" name="items[${i}][descripcion]" class="hid-desc"
                   value="${art ? (art.descripcion+' '+art.presentacion) : ''}">
            <input type="hidden" name="items[${i}][bpp]" class="hid-bpp"
                   value="${art ? art.bultos_por_pallet : 1}">
            <input type="text" class="form-control form-control-sm ac-input"
                   placeholder="Buscar artículo…"
                   value="${art ? (art.codigo+' — '+art.descripcion+' '+art.presentacion) : ''}"
                   autocomplete="off" spellcheck="false">
            <div class="ac-dropdown" style="display:none"></div>
        </td>
        <td>
            <input type="number" name="items[${i}][cantidad]" class="form-control form-control-sm qty"
                   min="0.01" step="1" value="${cantidad||''}" required placeholder="0">
        </td>
        <td class="text-end pallets-calc" data-pal>—</td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger btn-del py-0 px-2">
                <i class="bi bi-x"></i>
            </button>
        </td>`;
    document.getElementById('tbody').appendChild(tr);
    calcPallets(tr);
    bindFila(tr);
    return tr;
}

function calcPallets(tr) {
    const bpp = parseFloat(tr.dataset.bpp) || 1;
    const qty = parseFloat(tr.querySelector('.qty').value) || 0;
    tr.querySelector('[data-pal]').textContent = qty > 0
        ? (qty/bpp).toLocaleString('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2})
        : '—';
}

function bindFila(tr) {
    const inp   = tr.querySelector('.ac-input');
    const drop  = tr.querySelector('.ac-dropdown');
    const hidId = tr.querySelector('.hid-id');
    const hidDs = tr.querySelector('.hid-desc');
    const hidBp = tr.querySelector('.hid-bpp');
    const qty   = tr.querySelector('.qty');
    let timer;

    inp.addEventListener('input', () => { clearTimeout(timer); hidId.value=''; timer=setTimeout(()=>buscar(inp.value.trim()),200); });
    inp.addEventListener('focus', () => { if(!hidId.value) buscar(''); });
    inp.addEventListener('keydown', e => {
        const items = drop.querySelectorAll('.ac-item');
        const act   = drop.querySelector('.ac-item.active');
        if      (e.key==='ArrowDown') { e.preventDefault(); const n=act?act.nextElementSibling:items[0]; if(n){act&&act.classList.remove('active');n.classList.add('active');} }
        else if (e.key==='ArrowUp')   { e.preventDefault(); const p=act?act.previousElementSibling:items[items.length-1]; if(p){act&&act.classList.remove('active');p.classList.add('active');} }
        else if (e.key==='Enter')     { e.preventDefault(); const s=drop.querySelector('.ac-item.active')||drop.querySelector('.ac-item'); if(s)s.click(); }
        else if (e.key==='Escape')    { drop.style.display='none'; }
    });
    document.addEventListener('click', e => { if(!tr.contains(e.target)) drop.style.display='none'; });
    qty.addEventListener('input', () => calcPallets(tr));
    tr.querySelector('.btn-del').addEventListener('click', () => tr.remove());

    function buscar(q) {
        fetch(AC_URL+'?q='+encodeURIComponent(q))
            .then(r=>r.json()).then(data => {
                drop.innerHTML='';
                if(!data.length){drop.style.display='none';return;}
                data.forEach(a => {
                    const d = document.createElement('div');
                    d.className='ac-item';
                    d.innerHTML=`<span class="ac-code">${a.codigo}</span> ${a.descripcion} <small class="text-muted">${a.presentacion}</small>`;
                    d.addEventListener('mousedown', e=>e.preventDefault());
                    d.addEventListener('click', () => {
                        hidId.value=a.id; hidDs.value=a.descripcion+' '+a.presentacion;
                        hidBp.value=a.bultos_por_pallet; tr.dataset.bpp=a.bultos_por_pallet;
                        inp.value=a.codigo+' — '+a.descripcion+' '+a.presentacion;
                        drop.style.display='none'; qty.focus(); calcPallets(tr);
                    });
                    drop.appendChild(d);
                });
                drop.style.display='block';
            });
    }
}

document.getElementById('btn-add').addEventListener('click', () => {
    crearFila(null,null);
    document.querySelector('#tbody tr:last-child .ac-input').focus();
});

// Init: restaurar post o pre-selección
if (POST_ITEMS.length) {
    POST_ITEMS.forEach(it => {
        const art = it.articulo_id
            ? {id:it.articulo_id,codigo:'',descripcion:it.descripcion||'',presentacion:'',bultos_por_pallet:parseFloat(it.bpp)||1}
            : null;
        crearFila(art, it.cantidad);
    });
} else if (ART_PRE) {
    crearFila(ART_PRE, null);
} else {
    crearFila(null, null);
}
</script>
</body>
</html>
