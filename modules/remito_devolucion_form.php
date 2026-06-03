<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db        = db();
$eid       = empresa_id();
$remito_id = (int)($_GET['remito_id'] ?? 0);
$back      = $_GET['back'] ?? '';

if (!$remito_id) {
    header('Location: ' . url('modules/remitos_lista.php'));
    exit;
}

$st = $db->prepare("
    SELECT r.id, r.nro_remito_propio, r.total_pallets, r.fecha_devolucion,
           c.nombre AS cliente,
           p.nombre AS proveedor,
           DATE(i.fecha_ingreso) AS fecha_ingreso
    FROM remitos r
    JOIN clientes  c ON c.id = r.cliente_id
    LEFT JOIN proveedores p ON p.id = r.proveedor_id
    JOIN ingresos  i ON i.id = r.ingreso_id
    WHERE r.id = ? AND r.empresa_id = ? AND r.estado = 'entregado'
");
$st->execute([$remito_id, $eid]);
$remito = $st->fetch();
if (!$remito) {
    header('Location: ' . url('modules/remitos_lista.php'));
    exit;
}

// Ya tiene devolución registrada → no permitir duplicar
if ($remito['fecha_devolucion']) {
    header('Location: ' . url('modules/remitos_lista.php') . ($back ? '?' . $back : ''));
    exit;
}

// Ítems originales del remito
$si = $db->prepare("
    SELECT ri.id, ri.articulo_id, ri.descripcion, ri.cantidad, ri.pallets,
           a.codigo,
           a.bultos_por_pallet AS bpp
    FROM remito_items ri
    LEFT JOIN articulos a ON a.id = ri.articulo_id
    WHERE ri.remito_id = ?
    ORDER BY ri.id
");
$si->execute([$remito_id]);
$items_orig = $si->fetchAll();

$nav_modulo = 'remitos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registrar devolución — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        body { background: #eef1f6; }
        .card { border: none !important; box-shadow: 0 2px 8px rgba(0,0,0,.10) !important; }
        .seccion { background:#fff; border-radius:.5rem; padding:1rem 1.25rem; margin-bottom:1rem;
                   box-shadow:0 1px 4px rgba(0,0,0,.07); }
        .seccion-titulo { font-size:.78rem; font-weight:700; text-transform:uppercase;
                          letter-spacing:.06em; color:#5a6477; margin-bottom:.75rem; }
        #tabla-dev thead th { background:#2c3e50; color:#fff; font-size:.75rem;
                               font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
        .col-ref   { color:#888; font-size:.82rem; }
        .row-cero  { opacity:.4; }
        #barra-total { position:sticky; bottom:0; background:#1a2a3a; color:#fff;
                       padding:.6rem 1.5rem; border-radius:.5rem .5rem 0 0;
                       display:flex; align-items:center; justify-content:space-between; }
        .total-val { font-size:1.3rem; font-weight:900; color:#4ade80; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-3 px-lg-4" style="max-width:960px">

    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= url('modules/remitos_lista.php') . ($back ? '?' . $back : '') ?>"
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h5 class="fw-bold mb-0">
            <i class="bi bi-arrow-return-left me-2 text-warning"></i>Registrar devolución
        </h5>
    </div>

    <!-- Info del remito -->
    <div class="alert alert-warning py-2 mb-3">
        <strong><?= h($remito['nro_remito_propio']) ?></strong>
        &nbsp;·&nbsp; <?= h($remito['cliente']) ?>
        <?php if ($remito['proveedor']): ?>
        &nbsp;·&nbsp; <span class="text-muted"><?= h($remito['proveedor']) ?></span>
        <?php endif; ?>
        &nbsp;·&nbsp; Ingresó: <?= $remito['fecha_ingreso'] ? date('d/m/Y', strtotime($remito['fecha_ingreso'])) : '—' ?>
    </div>

    <form method="POST" action="<?= url('modules/remito_devolucion_guardar.php') ?>" id="form-dev">
        <input type="hidden" name="remito_id" value="<?= $remito_id ?>">
        <input type="hidden" name="back"      value="<?= h($back) ?>">

        <!-- Fecha de reingreso -->
        <div class="seccion">
            <div class="seccion-titulo"><i class="bi bi-calendar-event me-1"></i>Fecha de reingreso al stock</div>
            <div class="row g-2">
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label form-label-sm fw-semibold">Fecha de devolución <span class="text-danger">*</span></label>
                    <input type="date" name="fecha_devolucion" class="form-control"
                           value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
        </div>

        <!-- Artículos devueltos -->
        <div class="seccion">
            <div class="seccion-titulo"><i class="bi bi-boxes me-1"></i>Artículos devueltos</div>
            <p class="text-muted small mb-2">
                Se pre-cargaron los artículos originales del remito.
                Ingresá la cantidad devuelta por artículo — poner <strong>0</strong> en los que no volvieron.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-2" id="tabla-dev">
                    <thead>
                        <tr>
                            <th style="width:90px">Código</th>
                            <th>Descripción</th>
                            <th class="text-end" style="width:100px">Qty original</th>
                            <th class="text-end" style="width:120px">Qty devuelta</th>
                            <th class="text-end" style="width:110px">Pallets dev.</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items_orig as $idx => $it):
                        $bpp    = (float)($it['bpp'] ?? 0);
                        $pal_orig = (float)$it['pallets'];
                    ?>
                    <tr class="item-dev-row" data-bpp="<?= $bpp ?>">
                        <td class="font-monospace small"><?= h($it['codigo'] ?? '—') ?></td>
                        <td><?= h($it['descripcion']) ?></td>
                        <td class="text-end col-ref"><?= number_format((float)$it['cantidad'], 0) ?></td>
                        <td class="text-end">
                            <input type="hidden" name="items[<?= $idx ?>][articulo_id]"
                                   value="<?= (int)($it['articulo_id'] ?? 0) ?>">
                            <input type="hidden" name="items[<?= $idx ?>][descripcion]"
                                   value="<?= h($it['descripcion']) ?>">
                            <input type="number" name="items[<?= $idx ?>][cantidad]"
                                   class="form-control form-control-sm text-end item-cant"
                                   min="0" step="1"
                                   value="0">
                        </td>
                        <td class="text-end">
                            <input type="number" name="items[<?= $idx ?>][pallets]"
                                   class="form-control form-control-sm text-end item-pal"
                                   min="0" step="0.001"
                                   value="0">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-5">
            <a href="<?= url('modules/remitos_lista.php') . ($back ? '?' . $back : '') ?>"
               class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-warning fw-semibold">
                <i class="bi bi-check-lg me-1"></i>Registrar devolución
            </button>
        </div>
    </form>
</div>

<!-- Barra sticky de totales -->
<div id="barra-total">
    <span><i class="bi bi-boxes me-2"></i>Total pallets a reingresar</span>
    <span class="total-val" id="val-total">0.000</span>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const rows = document.querySelectorAll('.item-dev-row');

    function recalcRow(row) {
        const bpp  = parseFloat(row.dataset.bpp) || 0;
        const cant = parseFloat(row.querySelector('.item-cant').value) || 0;
        const palInput = row.querySelector('.item-pal');

        // Si bpp > 0 y el usuario no modificó pallets manualmente, auto-calcula
        if (bpp > 0) {
            palInput.value = cant > 0 ? (cant / bpp).toFixed(3) : '0';
        }

        row.classList.toggle('row-cero', cant === 0);
        recalcTotal();
    }

    function recalcTotal() {
        let total = 0;
        document.querySelectorAll('.item-pal').forEach(inp => {
            total += parseFloat(inp.value) || 0;
        });
        document.getElementById('val-total').textContent = total.toFixed(3);
    }

    rows.forEach(row => {
        const cantInp = row.querySelector('.item-cant');
        const palInp  = row.querySelector('.item-pal');

        cantInp.addEventListener('input', () => recalcRow(row));
        palInp.addEventListener('input',  recalcTotal);

        // Estado inicial: todo empieza en 0
        row.classList.add('row-cero');
    });

    // Validar antes de enviar: al menos un ítem con cantidad > 0
    document.getElementById('form-dev').addEventListener('submit', function(e) {
        const alguno = Array.from(document.querySelectorAll('.item-cant'))
                            .some(inp => parseFloat(inp.value) > 0);
        if (!alguno) {
            e.preventDefault();
            alert('Ingresá la cantidad devuelta de al menos un artículo.');
        }
    });
})();
</script>
</body>
</html>
