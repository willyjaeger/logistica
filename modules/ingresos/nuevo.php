<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../../config/auth.php';
require_login();

$eid = empresa_id();
$db  = db();

// Cargar proveedores y clientes para los selects
$stmt = $db->prepare("SELECT id, nombre FROM proveedores WHERE empresa_id = ? AND activo = 1 ORDER BY nombre");
$stmt->execute([$eid]);
$proveedores = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, nombre FROM clientes WHERE empresa_id = ? AND activo = 1 ORDER BY nombre");
$stmt->execute([$eid]);
$clientes = $stmt->fetchAll();

$fecha_default = date('Y-m-d\TH:i');

$nav_modulo = 'ingresos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nuevo Ingreso — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
    <style>
        .tabla-remitos td { vertical-align: middle; }
        .tabla-remitos .form-control-sm,
        .tabla-remitos .form-select-sm { font-size: .85rem; }
        .col-num { width: 38px; text-align: center; color: #6c757d; font-weight: 600; }
        .col-acc { width: 46px; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-4 px-4">

    <!-- Encabezado -->
    <div class="d-flex align-items-center mb-4">
        <a href="<?= url('modules/ingresos/lista.php') ?>" class="btn btn-sm btn-outline-secondary me-3"
           title="Volver a la lista">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div>
            <h5 class="fw-bold mb-0">
                <i class="bi bi-box-arrow-in-down me-2 text-primary"></i>Registrar ingreso de camión
            </h5>
            <small class="text-muted">Completá los datos del camión y los remitos que trajo</small>
        </div>
    </div>

    <form method="POST" action="guardar.php" id="form-ingreso">

        <!-- ── Datos del camión ── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold border-0 pb-0">
                <i class="bi bi-truck-front-fill text-primary me-2"></i>Datos del camión
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="fecha_ingreso">Fecha y hora</label>
                        <input type="datetime-local" id="fecha_ingreso" name="fecha_ingreso"
                               class="form-control" value="<?= $fecha_default ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="transportista">Transportista</label>
                        <input type="text" id="transportista" name="transportista"
                               class="form-control" placeholder="Empresa de transporte" autocomplete="off">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="patente_camion_ext">Patente</label>
                        <input type="text" id="patente_camion_ext" name="patente_camion_ext"
                               class="form-control text-uppercase" placeholder="AB 123 CD"
                               maxlength="15" autocomplete="off"
                               oninput="this.value=this.value.toUpperCase()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="chofer_externo">Chofer</label>
                        <input type="text" id="chofer_externo" name="chofer_externo"
                               class="form-control" placeholder="Nombre del chofer" autocomplete="off">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="observaciones">Observaciones generales</label>
                        <textarea id="observaciones" name="observaciones" class="form-control" rows="2"
                                  placeholder="Ej: mercadería con signos de humedad, bultos abiertos… (opcional)"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Remitos ── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 pb-0 d-flex justify-content-between align-items-center">
                <span class="fw-semibold">
                    <i class="bi bi-file-earmark-text text-primary me-2"></i>Remitos
                </span>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btn-agregar">
                    <i class="bi bi-plus-circle me-1"></i>Agregar remito
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table tabla-remitos mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="col-num">#</th>
                                <th>Nro remito (propio) <span class="text-danger">*</span></th>
                                <th>Nro remito (proveedor)</th>
                                <th>Proveedor</th>
                                <th>Cliente <span class="text-danger">*</span></th>
                                <th>Fecha remito</th>
                                <th>Observaciones</th>
                                <th class="col-acc"></th>
                            </tr>
                        </thead>
                        <tbody id="tbody-remitos"></tbody>
                    </table>
                </div>
                <div id="msg-vacio" class="text-center text-muted py-5" style="display:none">
                    <i class="bi bi-file-earmark-plus fs-2 d-block mb-2"></i>
                    No hay remitos. Hacé clic en <strong>Agregar remito</strong> para empezar.
                </div>
            </div>
        </div>

        <!-- ── Acciones ── -->
        <div class="d-flex justify-content-between align-items-center">
            <a href="<?= url('modules/ingresos/lista.php') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle me-2"></i>Cancelar
            </a>
            <button type="submit" class="btn btn-primary btn-lg px-5">
                <i class="bi bi-check2-circle me-2"></i>Guardar ingreso
            </button>
        </div>

    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/forms.js"></script>
<script>
(function () {
  'use strict';

  // Datos del servidor (JSON-encoded, seguros contra XSS)
  const PROVEEDORES = <?= json_encode($proveedores, JSON_UNESCAPED_UNICODE) ?>;
  const CLIENTES    = <?= json_encode($clientes,    JSON_UNESCAPED_UNICODE) ?>;

  let contadorRemitos = 0;

  /* Escapado seguro para usar en atributos HTML dentro de template literals */
  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function buildOptions(items, textoVacio) {
    let html = `<option value="">${textoVacio}</option>`;
    items.forEach(function (item) {
      html += `<option value="${esc(item.id)}">${esc(item.nombre)}</option>`;
    });
    return html;
  }

  function renumerarFilas() {
    document.querySelectorAll('#tbody-remitos tr').forEach(function (tr, i) {
      tr.querySelector('.num-fila').textContent = i + 1;
    });
  }

  function actualizarVacio() {
    const filas = document.querySelectorAll('#tbody-remitos tr');
    document.getElementById('msg-vacio').style.display = filas.length ? 'none' : '';
  }

  function agregarRemito() {
    const idx = contadorRemitos++;
    const tbody = document.getElementById('tbody-remitos');

    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="col-num"><span class="num-fila"></span></td>
      <td>
        <input type="text" name="remitos[${idx}][nro_remito_propio]"
               class="form-control form-control-sm" required placeholder="Ej: 00001">
      </td>
      <td>
        <input type="text" name="remitos[${idx}][nro_remito_proveedor]"
               class="form-control form-control-sm" placeholder="Nro del proveedor">
      </td>
      <td>
        <select name="remitos[${idx}][proveedor_id]" class="form-select form-select-sm">
          ${buildOptions(PROVEEDORES, '— Sin proveedor —')}
        </select>
      </td>
      <td>
        <select name="remitos[${idx}][cliente_id]" class="form-select form-select-sm" required>
          ${buildOptions(CLIENTES, '— Seleccioná —')}
        </select>
      </td>
      <td>
        <input type="date" name="remitos[${idx}][fecha_remito]"
               class="form-control form-control-sm">
      </td>
      <td>
        <input type="text" name="remitos[${idx}][observaciones]"
               class="form-control form-control-sm" placeholder="Opcional">
      </td>
      <td class="col-acc">
        <button type="button" class="btn btn-sm btn-outline-danger btn-eliminar" tabindex="-1"
                title="Eliminar remito">
          <i class="bi bi-trash"></i>
        </button>
      </td>
    `;

    tbody.appendChild(tr);
    renumerarFilas();
    actualizarVacio();

    // Foco en el primer campo de la nueva fila
    const primerCampo = tr.querySelector('input:not([type=hidden]), select');
    if (primerCampo) primerCampo.focus();
  }

  // Eliminar fila (delegación de eventos)
  document.getElementById('tbody-remitos').addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-eliminar');
    if (!btn) return;
    const tr = btn.closest('tr');
    const filas = document.querySelectorAll('#tbody-remitos tr');
    if (filas.length <= 1) {
      // Si es la única fila, solo limpiar los campos
      tr.querySelectorAll('input').forEach(function (el) { el.value = ''; });
      tr.querySelectorAll('select').forEach(function (el) { el.selectedIndex = 0; });
      tr.querySelector('input').focus();
      return;
    }
    tr.remove();
    renumerarFilas();
    actualizarVacio();
  });

  // Botón agregar
  document.getElementById('btn-agregar').addEventListener('click', agregarRemito);

  // Enter en último campo del último remito → agregar nueva fila
  document.addEventListener('formUltimoCampo', function (e) {
    if (e.target.closest('#tbody-remitos')) {
      e.preventDefault(); // cancela el comportamiento por defecto de forms.js
      agregarRemito();
    }
  });

  // Validación antes de enviar: debe haber al menos un remito con cliente
  document.getElementById('form-ingreso').addEventListener('submit', function (e) {
    const filas = document.querySelectorAll('#tbody-remitos tr');
    if (filas.length === 0) {
      e.preventDefault();
      alert('Agregá al menos un remito antes de guardar.');
      document.getElementById('btn-agregar').focus();
      return;
    }
    let tieneValido = false;
    filas.forEach(function (tr) {
      const nro = tr.querySelector('[name*="nro_remito_propio"]').value.trim();
      const cli = tr.querySelector('[name*="cliente_id"]').value;
      if (nro && cli) tieneValido = true;
    });
    if (!tieneValido) {
      e.preventDefault();
      alert('Cada remito debe tener número (propio) y cliente.');
    }
  });

  // Arrancar con un remito vacío
  agregarRemito();

})();
</script>
</body>
</html>
