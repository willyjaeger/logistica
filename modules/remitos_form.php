<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

// ── Modo edición ──────────────────────────────────────────────
$edit_id = (int)($_GET['id'] ?? 0);
$remito  = null;
$ingreso = null;
$edit_items = [];

if ($edit_id > 0) {
    $stmt = $db->prepare("
        SELECT r.*, c.nombre AS cliente_nombre
        FROM remitos r
        JOIN clientes c ON r.cliente_id = c.id
        WHERE r.id = ? AND r.empresa_id = ?
    ");
    $stmt->execute([$edit_id, $eid]);
    $remito = $stmt->fetch();
    if (!$remito) {
        header('Location: ' . url('modules/remitos_lista.php'));
        exit;
    }

    $stmt2 = $db->prepare("SELECT * FROM ingresos WHERE id = ?");
    $stmt2->execute([$remito['ingreso_id']]);
    $ingreso = $stmt2->fetch();

    $stmt3 = $db->prepare("
        SELECT ri.*, a.codigo, a.presentacion, a.bultos_por_pallet AS bpp
        FROM remito_items ri
        LEFT JOIN articulos a ON ri.articulo_id = a.id
        WHERE ri.remito_id = ?
        ORDER BY ri.id
    ");
    $stmt3->execute([$edit_id]);
    $edit_items = $stmt3->fetchAll();
}

// ── Parsear nro_remito para los campos ────────────────────────
$val_pv  = '';
$val_nro = '';
if ($remito) {
    // Formato R-00001-00000001
    $parts   = explode('-', $remito['nro_remito_propio'] ?? '');
    $val_pv  = $parts[1] ?? '';
    $val_nro = $parts[2] ?? '';
}

// ── Pre-carga desde sesión (nuevo remito) ─────────────────────
$sess = $_SESSION['ingreso_actual'] ?? [];

// ── Datos de listas desplegables ──────────────────────────────
$proveedores = $db->prepare("SELECT id, nombre FROM proveedores WHERE empresa_id=? AND activo=1 ORDER BY nombre");
$proveedores->execute([$eid]);
$proveedores = $proveedores->fetchAll();

// ── Error / datos del POST previo ─────────────────────────────
$form_error = $_SESSION['form_error'] ?? null;
$form_post  = $_SESSION['form_post']  ?? null;
unset($_SESSION['form_error'], $_SESSION['form_post']);

// Valores de campos: en orden: form_post > remito/ingreso > session > defaults
function vp(string $key, $default = ''): string {
    global $form_post;
    return h($form_post[$key] ?? $default);
}

$titulo = $edit_id > 0 ? 'Editar remito' : 'Nuevo remito';
$nav_modulo = 'remitos';
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
        body { background: #eef1f6; }

        /* Sticky top bar */
        .barra-pallets {
            position: sticky;
            top: 56px;
            z-index: 99;
            background: #1e293b;
            border-bottom: 3px solid #0d6efd;
            padding: .75rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,.25);
        }
        .pallets-display {
            display: flex;
            align-items: baseline;
            gap: .4rem;
        }
        .pallets-label { font-size: .85rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
        #total_pallets {
            width: 100px;
            font-size: 2.8rem;
            font-weight: 700;
            color: #38bdf8;
            border: none;
            border-bottom: 2px solid #334155;
            text-align: center;
            padding: 0;
            background: transparent;
        }
        #total_pallets:focus { outline: none; border-bottom-color: #38bdf8; }

        /* Secciones del formulario */
        .seccion {
            background: #fff;
            border: none;
            border-left: 4px solid #0d6efd;
            border-radius: .5rem;
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        .seccion-titulo {
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #0d6efd;
            margin-bottom: .75rem;
            padding-bottom: .4rem;
            border-bottom: 1px solid #e9ecef;
        }
        .form-label { color: #374151; font-weight: 600; }
        .form-control, .form-select {
            border-color: #ced4da;
            color: #1a1a2e;
        }
        .form-control:focus, .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 .2rem rgba(13,110,253,.15);
        }

        /* Autocomplete */
        .ac-wrap { position: relative; }
        .ac-drop {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 200;
            background: #fff; border: 1px solid #0d6efd; border-radius: .375rem;
            max-height: 220px; overflow-y: auto; display: none;
            box-shadow: 0 4px 16px rgba(0,0,0,.15);
        }
        .ac-item { padding: .4rem .75rem; cursor: pointer; font-size: .9rem; color: #212529; }
        .ac-item:hover, .ac-hl { background: #dbeafe; }
        .ac-item small { color: #6c757d; }

        /* Tabla de artículos */
        #tabla-items thead { background: #1e293b; }
        #tabla-items th { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; white-space: nowrap; padding: .5rem .4rem; border: none; }
        #tabla-items tbody tr:nth-child(odd) { background: #f8faff; }
        #tabla-items tbody tr:hover { background: #dbeafe; }
        #tabla-items td { vertical-align: middle; padding: .3rem .4rem; border-color: #e2e8f0; }
        #tabla-items input { font-size: .9rem; color: #1a1a2e; border-color: #cbd5e1; }
        #tabla-items input:focus { border-color: #0d6efd; }
        .col-cod   { width: 90px; }
        .col-desc  { min-width: 280px; }
        .col-cant  { width: 80px; }
        .col-bpp   { width: 90px; }
        .col-pal   { width: 75px; }
        .col-del   { width: 36px; }
        .pallets-calc { font-size: .85rem; color: #0d6efd; font-weight: 600; text-align: right; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- ═══ BARRA STICKY: PALLETS + GUARDAR ══════════════════════ -->
<div class="barra-pallets">
    <div class="pallets-display">
        <span class="pallets-label">Total Pallets</span>
        <input type="text" id="total_pallets" name="total_pallets_display"
               inputmode="decimal" autocomplete="off"
               value="<?= $edit_id > 0 ? h($remito['total_pallets'] ?? '0') : ($form_post['total_pallets'] ?? '0') ?>">
    </div>
    <div class="d-flex gap-2 ms-auto">
        <?php if (!$edit_id): ?>
        <button type="submit" form="form-remito" name="accion" value="guardar_y_otro"
                class="btn btn-outline-primary">
            <i class="bi bi-plus-circle me-1"></i>Guardar y otro
        </button>
        <?php endif; ?>
        <button type="submit" form="form-remito" name="accion" value="guardar"
                class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i>Guardar
        </button>
    </div>
</div>

<div class="container-fluid py-3 px-3 px-lg-4">

    <!-- Título -->
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= url('modules/remitos_lista.php') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h5 class="fw-bold mb-0"><?= $titulo ?></h5>
    </div>

    <?php if ($form_error): ?>
    <div class="alert alert-danger alert-dismissible py-2 mb-3">
        <i class="bi bi-exclamation-circle me-2"></i><?= h($form_error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['ok'])): ?>
    <div class="alert alert-success alert-dismissible py-2 mb-3">
        <i class="bi bi-check-circle me-2"></i>Remito guardado. Podés cargar otro.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form id="form-remito" method="POST" action="<?= url('modules/remitos_guardar.php') ?>">
        <input type="hidden" name="remito_id" value="<?= $edit_id ?>">
        <!-- total_pallets se sincroniza desde el input de la barra -->
        <input type="hidden" name="total_pallets" id="total_pallets_hidden"
               value="<?= $edit_id > 0 ? h($remito['total_pallets'] ?? '0') : '0' ?>">

        <!-- ─── SECCIÓN: CAMIÓN / TRANSPORTE ─────────────────── -->
        <div class="seccion">
            <div class="seccion-titulo"><i class="bi bi-truck me-1"></i>Camión / Transporte</div>
            <div class="row g-2">
                <div class="col-sm-3 col-lg-2">
                    <label class="form-label form-label-sm mb-1">Proveedor</label>
                    <select name="proveedor_id" class="form-select form-select-sm">
                        <option value="">— ninguno —</option>
                        <?php
                        $sel_prov = $form_post['proveedor_id']
                            ?? ($edit_id > 0 ? $remito['proveedor_id'] : ($sess['proveedor_id'] ?? ''));
                        foreach ($proveedores as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $sel_prov == $p['id'] ? 'selected' : '' ?>>
                            <?= h($p['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3 col-lg-2">
                    <label class="form-label form-label-sm mb-1">Transportista</label>
                    <input type="text" name="transportista" class="form-control form-control-sm"
                           placeholder="Empresa de transporte"
                           value="<?= $form_post ? vp('transportista') : h($ingreso['transportista'] ?? ($sess['transportista'] ?? '')) ?>">
                </div>
                <div class="col-sm-2 col-lg-2">
                    <label class="form-label form-label-sm mb-1">Patente</label>
                    <input type="text" name="patente" class="form-control form-control-sm"
                           placeholder="ABC123"
                           value="<?= $form_post ? vp('patente') : h($ingreso['patente_camion_ext'] ?? ($sess['patente'] ?? '')) ?>">
                </div>
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label form-label-sm mb-1">Chofer</label>
                    <input type="text" name="chofer" class="form-control form-control-sm"
                           placeholder="Nombre del chofer"
                           value="<?= $form_post ? vp('chofer') : h($ingreso['chofer_externo'] ?? ($sess['chofer'] ?? '')) ?>">
                </div>
                <div class="col-sm-3 col-lg-2">
                    <label class="form-label form-label-sm mb-1">Fecha entrada</label>
                    <input type="date" name="fecha_entrada" class="form-control form-control-sm"
                           value="<?php
                            if ($form_post) echo vp('fecha_entrada', date('Y-m-d'));
                            elseif ($edit_id > 0) echo substr($ingreso['fecha_ingreso'] ?? date('Y-m-d'), 0, 10);
                            else echo $sess['fecha_entrada'] ?? date('Y-m-d');
                           ?>">
                </div>
            </div>
        </div>

        <!-- ─── SECCIÓN: DATOS DEL REMITO ────────────────────── -->
        <div class="seccion">
            <div class="seccion-titulo"><i class="bi bi-file-earmark-text me-1"></i>Datos del remito</div>
            <div class="row g-2 align-items-end">
                <div class="col-sm-2 col-lg-2">
                    <label class="form-label form-label-sm mb-1">Fecha</label>
                    <input type="date" name="fecha_remito" id="fecha_remito" class="form-control form-control-sm"
                           value="<?= $form_post ? vp('fecha_remito', date('Y-m-d')) : h($remito['fecha_remito'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label form-label-sm mb-1">Nro remito</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text fw-bold">R</span>
                        <input type="text" name="punto_venta" id="punto_venta"
                               class="form-control font-monospace text-center" style="max-width:75px"
                               placeholder="00001" maxlength="5" inputmode="numeric"
                               value="<?= $form_post ? vp('punto_venta') : h($val_pv) ?>">
                        <span class="input-group-text text-muted">-</span>
                        <input type="text" name="nro_num" id="nro_num"
                               class="form-control font-monospace" style="max-width:110px"
                               placeholder="00000001" maxlength="8" inputmode="numeric"
                               value="<?= $form_post ? vp('nro_num') : h($val_nro) ?>">
                    </div>
                </div>
                <div class="col-sm-4 col-lg-4">
                    <label class="form-label form-label-sm mb-1">
                        Cliente <span class="text-danger">*</span>
                    </label>
                    <div class="ac-wrap">
                        <input type="hidden" id="cliente_id" name="cliente_id"
                               value="<?= $form_post ? vp('cliente_id') : h($remito['cliente_id'] ?? '') ?>">
                        <div class="input-group input-group-sm">
                            <input type="text" id="cli_search" class="form-control"
                                   placeholder="Buscar cliente..." autocomplete="off"
                                   value="<?= $form_post ? vp('cli_nombre') : h($remito['cliente_nombre'] ?? '') ?>">
                            <button type="button" class="btn btn-outline-secondary" id="btn_nuevo_cliente"
                                    data-bs-toggle="modal" data-bs-target="#modalCliente" title="Nuevo cliente">
                                <i class="bi bi-person-plus"></i>
                            </button>
                        </div>
                        <div id="cli_drop" class="ac-drop"></div>
                    </div>
                </div>
                <div class="col-sm-2 col-lg-2">
                    <label class="form-label form-label-sm mb-1">Nro OC <span class="text-muted">(opcional)</span></label>
                    <input type="text" name="nro_oc" class="form-control form-control-sm"
                           placeholder="Orden de compra"
                           value="<?= $form_post ? vp('nro_oc') : h($remito['nro_oc'] ?? '') ?>">
                </div>
            </div>
            <div class="row g-2 mt-1">
                <div class="col-12">
                    <label class="form-label form-label-sm mb-1">Observaciones</label>
                    <textarea name="observaciones" class="form-control form-control-sm" rows="2"
                              placeholder="Notas adicionales sobre el remito..."><?= $form_post ? vp('observaciones') : h($remito['observaciones'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- ─── SECCIÓN: ARTÍCULOS ───────────────────────────── -->
        <div class="seccion">
            <div class="seccion-titulo"><i class="bi bi-boxes me-1"></i>Artículos</div>
            <div class="table-responsive">
                <table class="table table-sm mb-2" id="tabla-items">
                    <thead>
                        <tr>
                            <th class="col-cod">Código</th>
                            <th class="col-desc">Descripción</th>
                            <th class="col-cant text-end">Cantidad</th>
                            <th class="col-pal text-end">Pallets</th>
                            <th class="col-del"></th>
                        </tr>
                    </thead>
                    <tbody id="tbody-items">
                    <?php
                    // Filas en edición: items existentes + relleno hasta 3 vacías
                    $filas = $edit_items ?: [];
                    // Si hay form_post (volvió por error), reconstruir desde POST
                    if ($form_post && isset($form_post['items'])) {
                        $filas = [];
                        foreach ($form_post['items'] as $pi) {
                            $filas[] = [
                                'articulo_id'    => $pi['articulo_id'] ?? '',
                                'codigo'         => $pi['codigo'] ?? '',
                                'descripcion'    => $pi['descripcion'] ?? '',
                                'presentacion'   => $pi['presentacion'] ?? '',
                                'cantidad'       => $pi['cantidad'] ?? '',
                                'bpp'            => $pi['bultos_por_pallet'] ?? '',
                                'pallets'        => 0,
                                'estado'         => 'pendiente',
                            ];
                        }
                    }
                    $n_filas = max(count($filas), 1);
                    $vacias  = max(0, 10 - $n_filas);
                    $total   = $n_filas + $vacias;

                    $idx = 0;
                    foreach ($filas as $it):
                        $bpp_val  = $it['bpp'] ?? $it['bultos_por_pallet'] ?? '';
                        // pallets individuales para mostrar
                        $pal_show = ($bpp_val > 0 && $it['cantidad'] > 0)
                            ? round($it['cantidad'] / $bpp_val, 2) : '';
                        $estado_it = $it['estado'] ?? 'pendiente';
                        $readonly  = ($estado_it !== 'pendiente') ? ' readonly' : '';
                    ?>
                    <tr class="item-row" data-idx="<?= $idx ?>">
                        <td class="col-cod">
                            <input type="hidden" name="items[<?= $idx ?>][articulo_id]"
                                   class="item-art-id" value="<?= h($it['articulo_id'] ?? '') ?>">
                            <input type="hidden" name="items[<?= $idx ?>][bultos_por_pallet]"
                                   class="item-bpp" value="<?= h($bpp_val) ?>">
                            <div class="ac-wrap">
                                <input type="text" name="items[<?= $idx ?>][codigo]"
                                       class="form-control form-control-sm item-cod font-monospace"
                                       value="<?= h($it['codigo'] ?? '') ?>"
                                       autocomplete="off"<?= $readonly ?>>
                                <div class="ac-drop art-drop"></div>
                            </div>
                        </td>
                        <td class="col-desc">
                            <div class="ac-wrap">
                                <input type="text" name="items[<?= $idx ?>][descripcion]"
                                       class="form-control form-control-sm item-desc"
                                       value="<?= h($it['descripcion']) ?>"
                                       autocomplete="off" tabindex="-1"<?= $readonly ?>>
                                <div class="ac-drop desc-drop"></div>
                            </div>
                        </td>
                        <td class="col-cant">
                            <input type="text" name="items[<?= $idx ?>][cantidad]"
                                   class="form-control form-control-sm item-cant text-end"
                                   inputmode="decimal"
                                   value="<?= h($it['cantidad'] ?? '') ?>"<?= $readonly ?>>
                        </td>
                        <td class="col-pal pallets-calc" data-pallets="<?= $pal_show ?>">
                            <?= $pal_show !== '' ? number_format($pal_show, 2) : '' ?>
                        </td>
                        <td class="col-del">
                            <?php if ($estado_it === 'pendiente'): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-del-row" title="Quitar fila">
                                <i class="bi bi-x"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php $idx++; endforeach;

                    // Filas vacías
                    for ($v = 0; $v < max($vacias, $edit_id ? 0 : 9); $v++): ?>
                    <tr class="item-row" data-idx="<?= $idx ?>">
                        <td class="col-cod">
                            <input type="hidden" name="items[<?= $idx ?>][articulo_id]" class="item-art-id" value="">
                            <input type="hidden" name="items[<?= $idx ?>][bultos_por_pallet]" class="item-bpp" value="">
                            <div class="ac-wrap">
                                <input type="text" name="items[<?= $idx ?>][codigo]"
                                       class="form-control form-control-sm item-cod font-monospace"
                                       autocomplete="off">
                                <div class="ac-drop art-drop"></div>
                            </div>
                        </td>
                        <td class="col-desc">
                            <div class="ac-wrap">
                                <input type="text" name="items[<?= $idx ?>][descripcion]"
                                       class="form-control form-control-sm item-desc"
                                       autocomplete="off" tabindex="-1">
                                <div class="ac-drop desc-drop"></div>
                            </div>
                        </td>
                        <td class="col-cant">
                            <input type="text" name="items[<?= $idx ?>][cantidad]"
                                   class="form-control form-control-sm item-cant text-end"
                                   inputmode="decimal">
                        </td>
                        <td class="col-pal pallets-calc" data-pallets=""></td>
                        <td class="col-del">
                            <button type="button" class="btn btn-sm btn-outline-danger btn-del-row" title="Quitar fila">
                                <i class="bi bi-x"></i>
                            </button>
                        </td>
                    </tr>
                    <?php $idx++; endfor; ?>
                    </tbody>
                </table>
            </div>

            <button type="button" id="btn-agregar-fila" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-plus me-1"></i>Agregar fila
            </button>
        </div>

        <!-- ─── BOTÓN GUARDAR INFERIOR ────────────────────────── -->
        <div class="d-flex justify-content-end gap-2 mt-2 mb-4">
            <a href="<?= url('modules/remitos_lista.php') ?>" class="btn btn-outline-secondary">Cancelar</a>
            <?php if (!$edit_id): ?>
            <button type="submit" name="accion" value="guardar_y_otro" class="btn btn-outline-primary">
                <i class="bi bi-plus-circle me-1"></i>Guardar y otro
            </button>
            <?php endif; ?>
            <button type="submit" name="accion" value="guardar" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Guardar
            </button>
        </div>

    </form>
</div><!-- /container -->


<!-- ═══ MODAL: NUEVO CLIENTE ═════════════════════════════════ -->
<div class="modal fade" id="modalCliente" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-bold"><i class="bi bi-person-plus me-2"></i>Nuevo cliente</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="modal-cliente-error" class="alert alert-danger py-2 d-none"></div>
        <div class="mb-3">
          <label class="form-label form-label-sm">Nombre <span class="text-danger">*</span></label>
          <input type="text" id="nc_nombre" class="form-control" placeholder="Razón social / nombre">
        </div>
        <div class="mb-3">
          <label class="form-label form-label-sm">CUIT</label>
          <div class="input-group">
            <input type="text" id="nc_cuit" class="form-control" placeholder="20-12345678-9"
                   inputmode="numeric" maxlength="13">
            <button type="button" class="btn btn-outline-secondary" id="btn_arca">
              <i class="bi bi-search me-1"></i>ARCA
            </button>
          </div>
          <div id="arca_status" class="form-text"></div>
        </div>
        <div class="row g-2">
          <div class="col-8">
            <label class="form-label form-label-sm">Dirección</label>
            <input type="text" id="nc_dir" class="form-control" placeholder="Calle y número">
          </div>
          <div class="col-4">
            <label class="form-label form-label-sm">Localidad</label>
            <input type="text" id="nc_loc" class="form-control" placeholder="Ciudad">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="btn_guardar_cliente">
          <i class="bi bi-check-lg me-1"></i>Guardar cliente
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';
// ═══════════════════════════════════════════════════════════════
// AUTOCOMPLETE CLIENTES
// ═══════════════════════════════════════════════════════════════
const cliSearch = document.getElementById('cli_search');
const cliId     = document.getElementById('cliente_id');
const cliDrop   = document.getElementById('cli_drop');
let cliHl = -1;
let cliTimer;

function cliItems()         { return Array.from(cliDrop.querySelectorAll('.ac-item')); }
function cliSetHl(its, idx) {
    cliHl = idx;
    its.forEach((el, i) => el.classList.toggle('ac-hl', i === idx));
    if (idx >= 0) its[idx].scrollIntoView({ block: 'nearest' });
}
function cliSelect(el) {
    cliId.value    = el.dataset.id;
    cliSearch.value = el.dataset.nombre;
    cliDrop.style.display = 'none';
    cliHl = -1;
}

function cliBuscar(q) {
    fetch('<?= url('modules/remitos_ac_clientes.php') ?>?q=' + encodeURIComponent(q))
        .then(r => r.json()).then(data => {
            cliHl = -1;
            if (!data.length) { cliDrop.style.display = 'none'; return; }
            cliDrop.innerHTML = data.map(c => {
                const safe = c.nombre.replace(/&/g,'&amp;').replace(/"/g,'&quot;');
                return `<div class="ac-item" data-id="${c.id}" data-nombre="${safe}">
                    ${c.nombre}${c.cuit ? `<small class="ms-2">${c.cuit}</small>` : ''}
                </div>`;
            }).join('');
            cliDrop.querySelectorAll('.ac-item').forEach(el =>
                el.addEventListener('mousedown', e => { e.preventDefault(); cliSelect(el); })
            );
            cliDrop.style.display = 'block';
        });
}

cliSearch.addEventListener('focus', function() {
    if (!cliId.value) cliBuscar(this.value.trim());
});

cliSearch.addEventListener('input', function() {
    clearTimeout(cliTimer);
    cliId.value = '';
    const q = this.value.trim();
    if (q.length === 0) { cliBuscar(''); return; }
    cliTimer = setTimeout(() => cliBuscar(q), 200);
});

cliSearch.addEventListener('keydown', function(e) {
    const its  = cliItems();
    const open = cliDrop.style.display !== 'none' && its.length;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (open) cliSetHl(its, Math.min(cliHl + 1, its.length - 1));
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (open) cliSetHl(its, Math.max(cliHl - 1, 0));
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (open && cliHl >= 0) {
            cliSelect(its[cliHl]);
        } else if (cliId.value) {
            cliDrop.style.display = 'none';
            const firstCod = document.querySelector('#tbody-items .item-cod');
            if (firstCod) firstCod.focus();
        }
    } else if (e.key === 'Escape') {
        cliDrop.style.display = 'none';
    }
});

document.addEventListener('click', e => {
    if (!cliSearch.contains(e.target) && !cliDrop.contains(e.target))
        cliDrop.style.display = 'none';
});

// ═══════════════════════════════════════════════════════════════
// AUTOCOMPLETE ARTÍCULOS (delega a cada fila)
// Código y Descripción tienen su propio dropdown
// ═══════════════════════════════════════════════════════════════
const AC_URL = '<?= url('modules/remitos_ac_articulos.php') ?>';

function initRowAC(row) {
    const codInput  = row.querySelector('.item-cod');
    const descInput = row.querySelector('.item-desc');
    const cantInput = row.querySelector('.item-cant');
    const bppInput  = row.querySelector('.item-bpp');
    const artId     = row.querySelector('.item-art-id');
    const codDrop   = row.querySelector('.art-drop');
    const descDrop  = row.querySelector('.desc-drop');

    let codHl = -1, descHl = -1;
    let codTimer, descTimer;

    // Construir ítem HTML para el dropdown
    function artHTML(a) {
        return `<div class="ac-item"
                     data-id="${a.id}" data-cod="${a.codigo}"
                     data-desc="${a.descripcion.replace(/"/g,'&quot;')}"
                     data-pres="${(a.presentacion||'').replace(/"/g,'&quot;')}"
                     data-bpp="${a.bultos_por_pallet}">
                    <strong class="me-2">${a.codigo}</strong>
                    <span>${a.descripcion}</span>
                    ${a.presentacion ? `<small class="ms-1 text-muted">— ${a.presentacion}</small>` : ''}
                </div>`;
    }

    // Seleccionar artículo desde cualquier dropdown
    function selectArt(el) {
        artId.value     = el.dataset.id;
        codInput.value  = el.dataset.cod;
        // Unir descripción + presentación en un solo campo
        const pres = el.dataset.pres ? ' - ' + el.dataset.pres : '';
        descInput.value = el.dataset.desc + pres;
        bppInput.value  = el.dataset.bpp;
        codDrop.style.display  = 'none';
        descDrop.style.display = 'none';
        codHl = -1; descHl = -1;
        recalcRow(row);
        cantInput.focus();
        cantInput.select();
    }

    // Función genérica para manejar un dropdown
    function setupDrop(inputEl, drop, hlRef, searchParam) {
        let hl = 0, timer;
        const getItems = () => Array.from(drop.querySelectorAll('.ac-item'));
        const setHl = (its, idx) => {
            hl = idx;
            its.forEach((el, i) => el.classList.toggle('ac-hl', i === idx));
            if (idx >= 0) its[idx].scrollIntoView({ block: 'nearest' });
        };

        inputEl.addEventListener('input', function() {
            clearTimeout(timer);
            artId.value = '';
            const q = this.value.trim();
            if (!q) { drop.style.display = 'none'; return; }
            timer = setTimeout(() => {
                fetch(AC_URL + '?q=' + encodeURIComponent(q))
                    .then(r => r.json()).then(data => {
                        hl = -1;
                        if (!data.length) { drop.style.display = 'none'; return; }
                        drop.innerHTML = data.map(artHTML).join('');
                        drop.querySelectorAll('.ac-item').forEach(el =>
                            el.addEventListener('mousedown', ev => { ev.preventDefault(); selectArt(el); })
                        );
                        drop.style.display = 'block';
                    });
            }, 150);
        });

        inputEl.addEventListener('keydown', function(e) {
            const its  = getItems();
            const open = drop.style.display !== 'none' && its.length;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (open) setHl(its, Math.min(hl + 1, its.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (open) setHl(its, Math.max(hl - 1, 0));
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (open && hl >= 0) { selectArt(its[hl]); }
                else {
                    drop.style.display = 'none';
                    // Enter en cod → desc; Enter en desc → cant
                    if (inputEl === codInput) descInput.focus();
                    else { cantInput.focus(); cantInput.select(); }
                }
            } else if (e.key === 'Escape') {
                drop.style.display = 'none';
            } else if (e.key === 'Tab') {
                if (open && its.length) { e.preventDefault(); selectArt(hl >= 0 ? its[hl] : its[0]); }
                else drop.style.display = 'none';
            }
        });

        document.addEventListener('click', ev => {
            if (!inputEl.contains(ev.target) && !drop.contains(ev.target))
                drop.style.display = 'none';
        });
    }

    // Código: Enter va directo a cantidad (Tab también por tabindex="-1" en desc)
    codInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); cantInput.focus(); cantInput.select(); }
    });

    setupDrop(descInput, descDrop, 'descHl', 'desc');

    // Bug 2: si el usuario sale del campo código sin seleccionar del dropdown,
    // intentar completar descripción por coincidencia exacta de código
    codInput.addEventListener('blur', function() {
        const cod = this.value.trim();
        if (!cod || artId.value) return;
        fetch(AC_URL + '?q=' + encodeURIComponent(cod))
            .then(r => r.json())
            .then(data => {
                const match = data.find(a => a.codigo.toLowerCase() === cod.toLowerCase());
                if (match) {
                    const pres = match.presentacion ? ' - ' + match.presentacion : '';
                    artId.value     = match.id;
                    codInput.value  = match.codigo;
                    descInput.value = match.descripcion + pres;
                    bppInput.value  = match.bultos_por_pallet;
                    recalcRow(row);
                }
            });
    });

    // Bug 3: al hacer foco en descripción, mostrar dropdown con valor actual
    descInput.addEventListener('focus', function() {
        const q = this.value.trim();
        if (!q) return;
        fetch(AC_URL + '?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.length) { descDrop.style.display = 'none'; return; }
                descDrop.innerHTML = data.map(artHTML).join('');
                descDrop.querySelectorAll('.ac-item').forEach(el =>
                    el.addEventListener('mousedown', ev => { ev.preventDefault(); selectArt(el); })
                );
                descDrop.style.display = 'block';
            });
    });

    // Enter en cantidad → siguiente fila
    cantInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const rows = Array.from(document.querySelectorAll('#tbody-items .item-row'));
            const idx  = rows.indexOf(row);
            const next = rows[idx + 1];
            if (next) next.querySelector('.item-cod').focus();
        }
    });

    cantInput.addEventListener('input', () => recalcRow(row));
}

function navEnter(nextEl) {
    return function(e) {
        if (e.key === 'Enter') { e.preventDefault(); nextEl.focus(); }
    };
}

// ═══════════════════════════════════════════════════════════════
// CÁLCULO DE PALLETS
// ═══════════════════════════════════════════════════════════════
function recalcRow(row) {
    const cant = parseFloat(row.querySelector('.item-cant').value.replace(',', '.')) || 0;
    const bpp  = parseFloat(row.querySelector('.item-bpp').value.replace(',', '.')) || 0;
    const cell = row.querySelector('.pallets-calc');
    if (bpp > 0 && cant > 0) {
        const p = cant / bpp;
        cell.dataset.pallets = p;
        cell.textContent = p.toFixed(2);
    } else {
        cell.dataset.pallets = '';
        cell.textContent = '';
    }
    recalcTotal();
}

function recalcTotal() {
    let sum = 0;
    document.querySelectorAll('#tbody-items .pallets-calc').forEach(cell => {
        const v = parseFloat(cell.dataset.pallets) || 0;
        sum += v;
    });
    const total = sum > 0 ? Math.ceil(sum) : 0;
    document.getElementById('total_pallets').value = total || '';
    document.getElementById('total_pallets_hidden').value = total;
}

// Inicializar filas existentes
document.querySelectorAll('#tbody-items .item-row').forEach(row => initRowAC(row));

// Recalcular al cargar (para edición)
document.querySelectorAll('#tbody-items .pallets-calc').forEach(cell => {
    const row  = cell.closest('.item-row');
    const cant = parseFloat(row.querySelector('.item-cant').value.replace(',', '.')) || 0;
    const bpp  = parseFloat(row.querySelector('.item-bpp').value.replace(',', '.')) || 0;
    if (bpp > 0 && cant > 0) {
        const p = cant / bpp;
        cell.dataset.pallets = p;
        cell.textContent = p.toFixed(2);
    }
});
recalcTotal();

// ═══════════════════════════════════════════════════════════════
// AGREGAR FILA
// ═══════════════════════════════════════════════════════════════
document.getElementById('btn-agregar-fila').addEventListener('click', function() {
    const tbody = document.getElementById('tbody-items');
    const rows  = tbody.querySelectorAll('.item-row');
    const idx   = rows.length;

    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.dataset.idx = idx;
    tr.innerHTML = `
      <td class="col-cod">
        <input type="hidden" name="items[${idx}][articulo_id]" class="item-art-id" value="">
        <input type="hidden" name="items[${idx}][bultos_por_pallet]" class="item-bpp" value="">
        <div class="ac-wrap">
          <input type="text" name="items[${idx}][codigo]"
                 class="form-control form-control-sm item-cod font-monospace" autocomplete="off">
          <div class="ac-drop art-drop"></div>
        </div>
      </td>
      <td class="col-desc">
        <div class="ac-wrap">
          <input type="text" name="items[${idx}][descripcion]"
                 class="form-control form-control-sm item-desc" autocomplete="off" tabindex="-1">
          <div class="ac-drop desc-drop"></div>
        </div>
      </td>
      <td class="col-cant">
        <input type="text" name="items[${idx}][cantidad]"
               class="form-control form-control-sm item-cant text-end" inputmode="decimal">
      </td>
      <td class="col-pal pallets-calc" data-pallets=""></td>
      <td class="col-del">
        <button type="button" class="btn btn-sm btn-outline-danger btn-del-row" title="Quitar fila">
          <i class="bi bi-x"></i>
        </button>
      </td>
    `;
    tbody.appendChild(tr);
    initRowAC(tr);
    tr.querySelector('.item-cod').focus();
});

// ═══════════════════════════════════════════════════════════════
// ELIMINAR FILA
// ═══════════════════════════════════════════════════════════════
document.getElementById('tbody-items').addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-del-row');
    if (!btn) return;
    const row = btn.closest('.item-row');
    row.remove();
    recalcTotal();
});

// ═══════════════════════════════════════════════════════════════
// SINCRONIZAR TOTAL_PALLETS (barra sticky → hidden)
// ═══════════════════════════════════════════════════════════════
document.getElementById('total_pallets').addEventListener('input', function() {
    document.getElementById('total_pallets_hidden').value = this.value.replace(',', '.');
});
document.getElementById('total_pallets').addEventListener('focus', function() {
    this.select();
});

// ═══════════════════════════════════════════════════════════════
// AUTO-PAD PUNTO DE VENTA Y NRO REMITO
// ═══════════════════════════════════════════════════════════════
document.getElementById('punto_venta').addEventListener('blur', function() {
    if (this.value.trim()) this.value = this.value.trim().padStart(5, '0');
});
document.getElementById('nro_num').addEventListener('blur', function() {
    if (this.value.trim()) this.value = this.value.trim().padStart(8, '0');
});

// ═══════════════════════════════════════════════════════════════
// NAVEGACIÓN CON ENTER EN CAMPOS SUPERIORES
// Impide que Enter dispare el submit y avanza al siguiente campo
// ═══════════════════════════════════════════════════════════════
const navSecuencia = [
    'select[name=proveedor_id]',
    'input[name=transportista]',
    'input[name=patente]',
    'input[name=chofer]',
    'input[name=fecha_entrada]',
    'input[name=fecha_remito]',
    '#punto_venta',
    '#nro_num',
    '#cli_search',
    'input[name=nro_oc]',
].map(sel => document.querySelector(sel)).filter(Boolean);

navSecuencia.forEach((el, i) => {
    el.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const next = navSecuencia[i + 1];
        if (next) { next.focus(); if (next.select) next.select(); }
        else {
            // Después del último campo superior → primer ítem
            const firstCod = document.querySelector('#tbody-items .item-cod');
            if (firstCod) firstCod.focus();
        }
    });
});

// Bloquear submit por Enter en cualquier input fuera de tbody
document.getElementById('form-remito').addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    if (e.target.tagName === 'TEXTAREA') return;
    if (e.target.tagName === 'SELECT') return;
    if (e.target.closest('#tbody-items')) return;
    if (e.target === cliSearch) return; // lo maneja su propio listener
    e.preventDefault();
});

// ═══════════════════════════════════════════════════════════════
// MODAL NUEVO CLIENTE + ARCA LOOKUP
// ═══════════════════════════════════════════════════════════════
document.getElementById('modalCliente').addEventListener('shown.bs.modal', function() {
    document.getElementById('nc_nombre').focus();
});

document.getElementById('btn_arca').addEventListener('click', function() {
    const cuit = document.getElementById('nc_cuit').value.replace(/\D/g, '');
    const st   = document.getElementById('arca_status');
    const btn  = this;
    if (cuit.length !== 11) {
        st.textContent = 'Ingresá 11 dígitos sin guiones.';
        st.className = 'form-text text-danger';
        return;
    }
    btn.disabled = true;
    st.textContent = 'Consultando ARCA...';
    st.className = 'form-text text-muted';

    fetch('<?= url('modules/remitos_afip_lookup.php') ?>?cuit=' + cuit)
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if (d.ok) {
                document.getElementById('nc_nombre').value = d.razon_social;
                st.textContent = 'Nombre obtenido de ARCA.';
                st.className = 'form-text text-success';
            } else {
                st.textContent = d.msg || 'No encontrado.';
                st.className = 'form-text text-warning';
            }
        })
        .catch(() => {
            btn.disabled = false;
            st.textContent = 'Error de conexión con ARCA.';
            st.className = 'form-text text-danger';
        });
});

// Autofocus: si viene de "Guardar y otro" foco en fecha, si no en proveedor
(function() {
    const params = new URLSearchParams(window.location.search);
    if (params.has('ok')) {
        document.getElementById('fecha_remito').focus();
        document.getElementById('fecha_remito').select();
    } else {
        document.querySelector('select[name=proveedor_id]').focus();
    }
})();

document.getElementById('btn_guardar_cliente').addEventListener('click', function() {
    const nombre = document.getElementById('nc_nombre').value.trim();
    const cuit   = document.getElementById('nc_cuit').value.trim();
    const dir    = document.getElementById('nc_dir').value.trim();
    const loc    = document.getElementById('nc_loc').value.trim();
    const errDiv = document.getElementById('modal-cliente-error');

    if (!nombre) { errDiv.textContent = 'El nombre es obligatorio.'; errDiv.classList.remove('d-none'); return; }
    errDiv.classList.add('d-none');

    const fd = new FormData();
    fd.append('nombre', nombre);
    fd.append('cuit', cuit);
    fd.append('direccion', dir);
    fd.append('localidad', loc);

    this.disabled = true;
    fetch('<?= url('modules/remitos_guardar_cliente.php') ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            this.disabled = false;
            if (d.ok) {
                // Seleccionar el nuevo cliente en el formulario
                cliId.value    = d.id;
                cliSearch.value = d.nombre;
                bootstrap.Modal.getInstance(document.getElementById('modalCliente')).hide();
                // Limpiar modal
                ['nc_nombre','nc_cuit','nc_dir','nc_loc'].forEach(id => document.getElementById(id).value = '');
                document.getElementById('arca_status').textContent = '';
            } else {
                errDiv.textContent = d.msg;
                errDiv.classList.remove('d-none');
            }
        })
        .catch(() => {
            this.disabled = false;
            errDiv.textContent = 'Error al guardar.';
            errDiv.classList.remove('d-none');
        });
});
</script>
</body>
</html>
