<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$eid = empresa_id();
$db  = db();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: ' . url('modules/ingresos/lista.php')); exit; }

// ── Cargar ingreso ────────────────────────────────────────────
$stmt = $db->prepare("SELECT i.*, p.nombre AS proveedor_nombre
    FROM ingresos i
    LEFT JOIN proveedores p ON p.id = (
        SELECT proveedor_id FROM remitos WHERE ingreso_id = i.id LIMIT 1
    )
    WHERE i.id = ? AND i.empresa_id = ?");
$stmt->execute([$id, $eid]);
$ingreso = $stmt->fetch();
if (!$ingreso) { header('Location: ' . url('modules/ingresos/lista.php')); exit; }

// ── Proveedor del ingreso (para cargar artículos) ─────────────
$prov_stmt = $db->prepare("SELECT proveedor_id FROM remitos WHERE ingreso_id = ? LIMIT 1");
$prov_stmt->execute([$id]);
$prov_row = $prov_stmt->fetch();
$prov_id  = $prov_row['proveedor_id'] ?? ($_SESSION['ultimo_proveedor_id'] ?? null);

// ── Artículos del proveedor ───────────────────────────────────
$articulos_list = [];
$articulos_map  = []; // codigo => fila (para cálculo server-side)
if ($prov_id) {
    $art_stmt = $db->prepare("SELECT id, codigo, descripcion, presentacion, bultos_por_pallet
        FROM articulos WHERE proveedor_id = ? AND activo = 1 ORDER BY codigo+0, codigo");
    $art_stmt->execute([$prov_id]);
    $articulos_list = $art_stmt->fetchAll();
    foreach ($articulos_list as $a) {
        $articulos_map[$a['codigo']] = $a;
    }
}

// ── Agregar cliente rápido ────────────────────────────────────
$nuevo_cliente_id = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar_cliente') {
    $cli_nombre = trim($_POST['cli_nombre'] ?? '');
    $cli_dir    = trim($_POST['cli_direccion'] ?? '');
    $cli_tel    = trim($_POST['cli_telefono'] ?? '');
    if ($cli_nombre !== '') {
        $db->prepare("INSERT INTO clientes (empresa_id, nombre, direccion, telefono) VALUES (?,?,?,?)")
           ->execute([$eid, $cli_nombre, $cli_dir ?: null, $cli_tel ?: null]);
        $nuevo_cliente_id = (int) $db->lastInsertId();
    }
    header('Location: ver.php?id=' . $id . '&cli=' . $nuevo_cliente_id);
    exit;
}

// ── Guardar remito + ítems ────────────────────────────────────
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_remito') {

    $letra        = strtoupper(trim($_POST['letra']       ?? ''));
    $pv           = trim($_POST['punto_venta']  ?? '');
    $nro          = trim($_POST['nro_remito']   ?? '');
    $cliente_id   = (int) ($_POST['cliente_id'] ?? 0);
    $fecha_rem    = trim($_POST['fecha_remito'] ?? '') ?: null;
    $remito_prov  = !empty($_POST['proveedor_id']) ? (int)$_POST['proveedor_id'] : $prov_id;
    $total_pallets= str_replace(',', '.', trim($_POST['total_pallets'] ?? '')) ?: null;

    $nro_propio = '';
    if ($letra && $pv && $nro)  $nro_propio = "$letra-$pv-$nro";
    elseif ($nro)               $nro_propio = $nro;

    if (!$nro_propio)       $err = 'El número de remito es obligatorio.';
    elseif (!$cliente_id)   $err = 'Seleccioná el cliente.';
    else {
        // Filtrar ítems
        $items = [];
        foreach ($_POST['items'] ?? [] as $item) {
            $desc   = trim($item['descripcion'] ?? '');
            $cant   = (float) str_replace(',', '.', $item['cantidad'] ?? '0');
            $art_id = !empty($item['articulo_id']) ? (int)$item['articulo_id'] : null;
            $bxp    = (float) ($item['bultos_por_pallet'] ?? 0);
            if ($desc === '' || $cant <= 0) continue;
            $pallets_item = ($bxp > 0) ? round($cant / $bxp, 2) : 0;
            $items[] = ['desc'=>$desc, 'cant'=>$cant, 'art_id'=>$art_id, 'pallets'=>$pallets_item];
        }

        // Calcular total_pallets si no fue editado manualmente
        if ($total_pallets === null || $total_pallets === '') {
            $total_pallets = array_sum(array_column($items, 'pallets')) ?: null;
        }

        try {
            $db->beginTransaction();

            $db->prepare("INSERT INTO remitos
                (ingreso_id, empresa_id, nro_remito_propio, proveedor_id,
                 cliente_id, fecha_remito, total_pallets, estado)
                VALUES (?,?,?,?,?,?,?,'pendiente')")
               ->execute([$id, $eid, $nro_propio, $remito_prov,
                          $cliente_id, $fecha_rem, $total_pallets]);
            $remito_id = (int) $db->lastInsertId();

            if (!empty($items)) {
                $stmtI = $db->prepare("INSERT INTO remito_items
                    (remito_id, articulo_id, descripcion, cantidad, pallets, estado)
                    VALUES (?,?,?,?,?,'pendiente')");
                foreach ($items as $it) {
                    $stmtI->execute([$remito_id, $it['art_id'],
                                     $it['desc'], $it['cant'], $it['pallets']]);
                }
            }

            $db->commit();

            $_SESSION['remito_defaults'] = [
                'letra'        => $letra,
                'punto_venta'  => $pv,
                'proveedor_id' => $remito_prov,
            ];
            $_SESSION['ultimo_proveedor_id'] = $remito_prov;

            header('Location: ver.php?id=' . $id . '&ok=1');
            exit;
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('guardar remito: ' . $e->getMessage());
            $err = 'Error al guardar. Intentá de nuevo.';
        }
    }
}

// ── Remitos del ingreso ───────────────────────────────────────
$stmt = $db->prepare("
    SELECT r.id, r.nro_remito_propio, r.estado, r.total_pallets,
           c.nombre AS cliente_nombre,
           COUNT(ri.id) AS total_items,
           SUM(ri.cantidad) AS total_bultos
    FROM remitos r
    JOIN clientes c ON r.cliente_id = c.id
    LEFT JOIN remito_items ri ON ri.remito_id = r.id
    WHERE r.ingreso_id = ? AND r.empresa_id = ?
    GROUP BY r.id ORDER BY r.id");
$stmt->execute([$id, $eid]);
$remitos = $stmt->fetchAll();

$total_pallets_ingreso = array_sum(array_column($remitos, 'total_pallets'));

// ── Clientes ──────────────────────────────────────────────────
$cli_stmt = $db->prepare("SELECT id, nombre, direccion FROM clientes WHERE empresa_id = ? AND activo = 1 ORDER BY nombre");
$cli_stmt->execute([$eid]);
$clientes = $cli_stmt->fetchAll();

// ── Proveedores ───────────────────────────────────────────────
$pst = $db->prepare("SELECT id, nombre FROM proveedores WHERE empresa_id = ? AND activo = 1 ORDER BY nombre");
$pst->execute([$eid]);
$proveedores = $pst->fetchAll();

// ── Defaults para el formulario ───────────────────────────────
$def          = $_SESSION['remito_defaults'] ?? [];
$def_letra    = $def['letra']        ?? '';
$def_pv       = $def['punto_venta']  ?? '';
$def_prov_id  = $def['proveedor_id'] ?? $prov_id ?? '';
$def_cli_id   = (int) ($_GET['cli'] ?? 0);

if (!empty($remitos)) {
    $ultimo = end($remitos);
    $parts  = explode('-', $ultimo['nro_remito_propio'], 3);
    if (count($parts) === 3) {
        $def_letra = $def_letra ?: $parts[0];
        $def_pv    = $def_pv    ?: $parts[1];
    }
}

$nav_modulo  = 'ingresos';
$FILAS_ITEMS = 10;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingreso #<?= $id ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
    <style>
        .tabla-items td, .tabla-items th { vertical-align: middle; padding: .3rem .4rem; font-size: .85rem; }
        .col-cod  { width: 72px; }
        .col-cant { width: 76px; }
        .col-plt  { width: 64px; }
        /* Autocomplete cliente */
        .autocomplete-wrap { position: relative; }
        .autocomplete-drop {
            display: none; position: absolute; z-index: 1050;
            width: 100%; max-height: 220px; overflow-y: auto;
            background: #fff; border: 1px solid #ced4da;
            border-top: none; border-radius: 0 0 .375rem .375rem;
            box-shadow: 0 4px 12px rgba(0,0,0,.1);
        }
        .autocomplete-drop .ac-item {
            padding: .45rem .75rem; cursor: pointer; font-size: .9rem;
        }
        .autocomplete-drop .ac-item:hover { background: #e9f3ff; }
        .ac-item small { color: #6c757d; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-4">

    <!-- Encabezado -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex align-items-center gap-3">
            <a href="<?= url('modules/ingresos/lista.php') ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div>
                <h5 class="fw-bold mb-0">
                    Ingreso #<?= $id ?>
                    <?php if ($ingreso['proveedor_nombre']): ?>
                    <span class="fw-normal text-muted fs-6 ms-1">— <?= h($ingreso['proveedor_nombre']) ?></span>
                    <?php endif; ?>
                </h5>
                <small class="text-muted">
                    <?= fecha_legible($ingreso['fecha_ingreso']) ?>
                    <?php if ($ingreso['transportista']): ?> · <?= h($ingreso['transportista']) ?><?php endif; ?>
                    <?php if ($ingreso['patente_camion_ext']): ?>
                     · <span class="badge bg-secondary"><?= h($ingreso['patente_camion_ext']) ?></span>
                    <?php endif; ?>
                </small>
            </div>
        </div>
        <div class="text-end">
            <span class="badge bg-primary rounded-pill"><?= count($remitos) ?> remitos</span>
            <?php if ($total_pallets_ingreso > 0): ?>
            <span class="badge bg-success rounded-pill ms-1"><?= number_format($total_pallets_ingreso, 1) ?> pallets</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['ok'])): ?>
    <div class="alert alert-success alert-dismissible py-2 d-flex align-items-center mb-3">
        <i class="bi bi-check-circle-fill me-2"></i><div>Remito guardado.</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['nuevo']) && empty($remitos)): ?>
    <div class="alert alert-info alert-dismissible py-2 d-flex align-items-center mb-3">
        <i class="bi bi-info-circle-fill me-2"></i><div>Camión registrado. Cargá los remitos uno por uno.</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($err): ?>
    <div class="alert alert-danger py-2 d-flex align-items-center mb-3">
        <i class="bi bi-exclamation-circle-fill me-2"></i><div><?= h($err) ?></div>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ══ FORMULARIO REMITO ══════════════════════════════ -->
        <div class="col-xl-6">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold border-0 pb-0">
                <i class="bi bi-file-earmark-plus text-success me-2"></i>Agregar remito
            </div>
            <div class="card-body">
              <form method="POST" id="form-remito" autocomplete="off">
                <input type="hidden" name="accion" value="guardar_remito">
                <input type="hidden" name="proveedor_id" value="<?= (int)$def_prov_id ?>">

                <div class="row g-2 mb-3">
                    <!-- Nro de remito -->
                    <div class="col-12">
                        <label class="form-label fw-semibold mb-1">Nro de remito <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" name="letra" id="letra" class="form-control text-center fw-bold"
                                   style="max-width:52px" maxlength="2" placeholder="A"
                                   value="<?= h($def_letra) ?>" title="Letra">
                            <span class="input-group-text px-1">-</span>
                            <input type="text" name="punto_venta" id="punto_venta" class="form-control text-center"
                                   style="max-width:72px" maxlength="5" placeholder="0001"
                                   value="<?= h($def_pv) ?>" title="Punto de venta">
                            <span class="input-group-text px-1">-</span>
                            <input type="text" name="nro_remito" id="nro_remito" class="form-control fw-bold"
                                   placeholder="00000001" required title="Número">
                        </div>
                    </div>

                    <!-- Fecha y Cliente -->
                    <div class="col-4">
                        <label class="form-label mb-1">Fecha</label>
                        <input type="date" name="fecha_remito" class="form-control"
                               value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-8">
                        <label class="form-label mb-1">Cliente <span class="text-danger">*</span></label>
                        <div class="d-flex gap-1">
                            <div class="autocomplete-wrap flex-grow-1">
                                <input type="text" id="cliente_search" class="form-control"
                                       placeholder="Escribí para buscar…" required
                                       <?= $def_cli_id ? 'data-prefill="'.$def_cli_id.'"' : '' ?>>
                                <div class="autocomplete-drop" id="cliente_drop"></div>
                            </div>
                            <input type="hidden" name="cliente_id" id="cliente_id">
                            <button type="button" class="btn btn-outline-success px-2"
                                    data-bs-toggle="modal" data-bs-target="#modalCliente"
                                    title="Agregar cliente nuevo">
                                <i class="bi bi-person-plus-fill"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tabla de ítems -->
                <div class="fw-semibold mb-1 small text-muted text-uppercase">
                    <i class="bi bi-list-ul me-1"></i>Detalle de mercadería
                </div>
                <div class="table-responsive mb-2">
                    <table class="table tabla-items table-bordered mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="col-cod text-center">Cód</th>
                                <th>Descripción</th>
                                <th class="col-cant text-center">Bultos</th>
                                <th class="col-plt text-center">Pallets</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-items">
                        <?php for ($i = 0; $i < $FILAS_ITEMS; $i++): ?>
                        <tr>
                            <td class="col-cod p-1">
                                <input type="text" name="items[<?=$i?>][codigo_busq]"
                                       class="form-control form-control-sm text-center item-cod border-0"
                                       placeholder="—" inputmode="numeric">
                                <input type="hidden" name="items[<?=$i?>][articulo_id]" class="item-art-id">
                                <input type="hidden" name="items[<?=$i?>][bultos_por_pallet]" class="item-bxp" value="0">
                            </td>
                            <td class="p-1">
                                <input type="text" name="items[<?=$i?>][descripcion]"
                                       class="form-control form-control-sm item-desc border-0"
                                       placeholder="Descripción">
                            </td>
                            <td class="col-cant p-1">
                                <input type="text" name="items[<?=$i?>][cantidad]"
                                       class="form-control form-control-sm text-center item-cant border-0"
                                       placeholder="0" inputmode="decimal">
                            </td>
                            <td class="col-plt p-1 text-center">
                                <span class="item-pallets text-muted small">—</span>
                            </td>
                        </tr>
                        <?php endfor; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="2" class="text-end fw-semibold small pe-2">Total calculado:</td>
                                <td class="text-center fw-bold" id="total-bultos">0</td>
                                <td class="text-center fw-bold" id="total-pallets-calc">0</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Total pallets real -->
                <div class="d-flex align-items-center gap-2 mb-3">
                    <label class="form-label mb-0 text-nowrap fw-semibold">
                        <i class="bi bi-stack me-1 text-primary"></i>Total pallets real:
                    </label>
                    <input type="text" name="total_pallets" id="total-pallets-real"
                           class="form-control form-control-sm" style="max-width:90px"
                           placeholder="Auto" inputmode="decimal">
                    <small class="text-muted">Editable si difiere del cálculo</small>
                </div>

                <button type="submit" class="btn btn-success w-100">
                    <i class="bi bi-check2-circle me-2"></i>Guardar remito
                </button>
              </form>
            </div>
          </div>
        </div>

        <!-- ══ LISTA DE REMITOS ═══════════════════════════════ -->
        <div class="col-xl-6">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold border-0 pb-0 d-flex justify-content-between">
                <span><i class="bi bi-file-earmark-text text-primary me-2"></i>Remitos cargados</span>
                <span class="badge bg-primary rounded-pill"><?= count($remitos) ?></span>
            </div>
            <div class="card-body p-0">
              <?php if (empty($remitos)): ?>
              <div class="text-center text-muted py-5">
                  <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                  Todavía no hay remitos. Usá el formulario.
              </div>
              <?php else: ?>
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.9rem">
                    <thead class="table-light">
                        <tr>
                            <th>Nro remito</th>
                            <th>Cliente</th>
                            <th class="text-center">Bultos</th>
                            <th class="text-center">Pallets</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($remitos as $r): ?>
                    <tr>
                        <td class="fw-semibold"><?= h($r['nro_remito_propio']) ?></td>
                        <td><?= h($r['cliente_nombre']) ?></td>
                        <td class="text-center"><?= $r['total_bultos'] ? number_format($r['total_bultos'],0) : '—' ?></td>
                        <td class="text-center">
                            <?php if ($r['total_pallets']): ?>
                            <span class="badge bg-success"><?= number_format($r['total_pallets'],1) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-estado-<?= $r['estado'] ?>">
                                <?= ucfirst(str_replace('_',' ',$r['estado'])) ?>
                            </span>
                        </td>
                        <td>
                            <a href="remito_items.php?id=<?= $r['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="Ver ítems">
                                <i class="bi bi-list-check"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($total_pallets_ingreso > 0): ?>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="3" class="text-end fw-semibold">Total ingreso:</td>
                            <td class="text-center fw-bold">
                                <span class="badge bg-success fs-6"><?= number_format($total_pallets_ingreso,1) ?></span>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

    </div><!-- /row -->
</div><!-- /container -->

<!-- ── Modal: Agregar cliente rápido ── -->
<div class="modal fade" id="modalCliente" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="accion" value="agregar_cliente">
        <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Nuevo cliente</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Nombre <span class="text-danger">*</span></label>
                <input type="text" name="cli_nombre" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">Dirección</label>
                <input type="text" name="cli_direccion" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label">Teléfono</label>
                <input type="text" name="cli_telefono" class="form-control">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check2 me-1"></i>Guardar cliente
            </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
'use strict';

// ── Datos del servidor ────────────────────────────────────────
const ARTICULOS = <?= json_encode(array_values($articulos_list), JSON_UNESCAPED_UNICODE) ?>;
const CLIENTES  = <?= json_encode(array_values($clientes),       JSON_UNESCAPED_UNICODE) ?>;
const ART_MAP   = {};
ARTICULOS.forEach(a => { ART_MAP[String(a.codigo)] = a; });

// ── Autocomplete cliente ──────────────────────────────────────
const cliSearch = document.getElementById('cliente_search');
const cliHidden = document.getElementById('cliente_id');
const cliDrop   = document.getElementById('cliente_drop');

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderDrop(matches) {
    if (!matches.length) { cliDrop.style.display='none'; return; }
    cliDrop.innerHTML = matches.slice(0,12).map(c =>
        `<div class="ac-item" data-id="${c.id}" data-nombre="${esc(c.nombre)}">
            ${esc(c.nombre)}<br><small>${esc(c.direccion||'')}</small>
         </div>`
    ).join('');
    cliDrop.style.display = 'block';
}

cliSearch.addEventListener('input', function() {
    cliHidden.value = '';
    const q = this.value.trim().toLowerCase();
    if (!q) { cliDrop.style.display='none'; return; }
    renderDrop(CLIENTES.filter(c => c.nombre.toLowerCase().includes(q)));
});

cliDrop.addEventListener('click', function(e) {
    const item = e.target.closest('.ac-item');
    if (!item) return;
    cliSearch.value  = item.dataset.nombre;
    cliHidden.value  = item.dataset.id;
    cliDrop.style.display = 'none';
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('.autocomplete-wrap')) cliDrop.style.display = 'none';
});

// Pre-llenar cliente si viene de agregar cliente nuevo
const prefillId = cliSearch.dataset.prefill;
if (prefillId) {
    const c = CLIENTES.find(x => String(x.id) === String(prefillId));
    if (c) { cliSearch.value = c.nombre; cliHidden.value = c.id; }
}

// ── Lookup artículos + cálculo de pallets ─────────────────────
function calcularPallets(cant, bxp) {
    if (!bxp || bxp <= 0 || !cant || cant <= 0) return 0;
    return Math.round((cant / bxp) * 100) / 100;
}

function actualizarTotales() {
    let totalBultos = 0, totalPallets = 0;
    document.querySelectorAll('#tbody-items tr').forEach(tr => {
        const cant = parseFloat(tr.querySelector('.item-cant').value.replace(',','.')) || 0;
        const bxp  = parseFloat(tr.querySelector('.item-bxp').value) || 0;
        const plt  = calcularPallets(cant, bxp);
        const span = tr.querySelector('.item-pallets');
        span.textContent = plt > 0 ? plt.toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2}) : '—';
        totalBultos  += cant;
        totalPallets += plt;
    });
    document.getElementById('total-bultos').textContent =
        totalBultos > 0 ? totalBultos.toLocaleString('es-AR') : '0';
    document.getElementById('total-pallets-calc').textContent =
        totalPallets > 0 ? totalPallets.toLocaleString('es-AR', {minimumFractionDigits:2}) : '0';

    // Auto-llenar total_pallets_real si no fue editado
    const realInput = document.getElementById('total-pallets-real');
    if (!realInput.dataset.manual) {
        realInput.value = totalPallets > 0
            ? totalPallets.toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2})
            : '';
    }
}

document.getElementById('total-pallets-real').addEventListener('input', function() {
    this.dataset.manual = '1';
});

function lookupArticulo(codInput) {
    const row  = codInput.closest('tr');
    const cod  = codInput.value.trim();
    const art  = ART_MAP[cod];
    const desc = row.querySelector('.item-desc');
    const bxp  = row.querySelector('.item-bxp');
    const aid  = row.querySelector('.item-art-id');
    if (art) {
        desc.value = art.descripcion + (art.presentacion ? '  ' + art.presentacion : '');
        bxp.value  = art.bultos_por_pallet;
        aid.value  = art.id;
    } else {
        bxp.value = '0';
        aid.value = '';
    }
    actualizarTotales();
}

document.getElementById('tbody-items').addEventListener('change', function(e) {
    if (e.target.classList.contains('item-cod')) lookupArticulo(e.target);
    if (e.target.classList.contains('item-cant')) actualizarTotales();
});

document.getElementById('tbody-items').addEventListener('input', function(e) {
    if (e.target.classList.contains('item-cant')) actualizarTotales();
});

// Letra en mayúsculas
document.getElementById('letra').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});

// Foco en nro_remito al cargar
document.getElementById('nro_remito').focus();

})();
</script>
</body>
</html>
