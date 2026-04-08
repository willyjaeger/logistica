<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$eid = empresa_id();
$db  = db();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . url('modules/ingresos/lista.php'));
    exit;
}

// Cargar ingreso
$stmt = $db->prepare("
    SELECT i.*, p.nombre AS proveedor_nombre
    FROM ingresos i
    LEFT JOIN proveedores p ON p.id = (
        SELECT proveedor_id FROM remitos
        WHERE ingreso_id = i.id LIMIT 1
    )
    WHERE i.id = ? AND i.empresa_id = ?
");
$stmt->execute([$id, $eid]);
$ingreso = $stmt->fetch();
if (!$ingreso) {
    header('Location: ' . url('modules/ingresos/lista.php'));
    exit;
}

// ── Guardar remito + ítems ────────────────────────────────────
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_remito') {

    $letra      = strtoupper(trim($_POST['letra']      ?? ''));
    $pv         = trim($_POST['punto_venta'] ?? '');
    $nro        = trim($_POST['nro_remito']  ?? '');
    $nro_prov   = trim($_POST['nro_remito_proveedor'] ?? '');
    $cliente_id = (int) ($_POST['cliente_id'] ?? 0);
    $fecha_rem  = trim($_POST['fecha_remito'] ?? '') ?: null;
    $obs_rem    = trim($_POST['observaciones'] ?? '') ?: null;
    $prov_id    = !empty($_POST['proveedor_id']) ? (int) $_POST['proveedor_id'] : null;

    // Armar nro_remito_propio
    $nro_propio = '';
    if ($letra !== '' && $pv !== '' && $nro !== '') {
        $nro_propio = $letra . '-' . $pv . '-' . $nro;
    } elseif ($nro !== '') {
        $nro_propio = $nro;
    }

    if ($nro_propio === '') {
        $err = 'El número de remito es obligatorio.';
    } elseif ($cliente_id === 0) {
        $err = 'Seleccioná el cliente del remito.';
    } else {
        // Filtrar ítems: solo filas con descripción no vacía
        $items_raw = $_POST['items'] ?? [];
        $items = [];
        foreach ($items_raw as $item) {
            $desc = trim($item['descripcion'] ?? '');
            $cant = str_replace(',', '.', trim($item['cantidad'] ?? ''));
            if ($desc === '' || !is_numeric($cant) || (float)$cant <= 0) continue;
            $items[] = [
                'descripcion' => $desc,
                'cantidad'    => (float)$cant,
                'unidad'      => trim($item['unidad'] ?? 'unidad') ?: 'unidad',
            ];
        }

        try {
            $db->beginTransaction();

            $stmtR = $db->prepare("
                INSERT INTO remitos
                    (ingreso_id, empresa_id, nro_remito_propio, nro_remito_proveedor,
                     proveedor_id, cliente_id, fecha_remito, observaciones, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')
            ");
            $stmtR->execute([
                $id, $eid, $nro_propio, $nro_prov ?: null,
                $prov_id, $cliente_id, $fecha_rem, $obs_rem,
            ]);
            $remito_id = (int) $db->lastInsertId();

            if (!empty($items)) {
                $stmtI = $db->prepare("
                    INSERT INTO remito_items (remito_id, descripcion, cantidad, unidad, estado)
                    VALUES (?, ?, ?, ?, 'pendiente')
                ");
                foreach ($items as $item) {
                    $stmtI->execute([$remito_id, $item['descripcion'], $item['cantidad'], $item['unidad']]);
                }
            }

            $db->commit();

            // Guardar defaults para el próximo remito
            $_SESSION['remito_defaults'] = [
                'letra'       => $letra,
                'punto_venta' => $pv,
                'proveedor_id'=> $prov_id,
            ];

            header('Location: ver.php?id=' . $id . '&ok=1');
            exit;

        } catch (Throwable $e) {
            $db->rollBack();
            error_log('guardar remito: ' . $e->getMessage());
            $err = 'Error al guardar. Intentá de nuevo.';
        }
    }
}

// ── Cargar remitos del ingreso ────────────────────────────────
$stmt = $db->prepare("
    SELECT r.*, c.nombre AS cliente_nombre,
           COUNT(ri.id) AS total_items,
           SUM(ri.cantidad) AS total_cantidad
    FROM remitos r
    JOIN clientes c ON r.cliente_id = c.id
    LEFT JOIN remito_items ri ON ri.remito_id = r.id
    WHERE r.ingreso_id = ? AND r.empresa_id = ?
    GROUP BY r.id
    ORDER BY r.id
");
$stmt->execute([$id, $eid]);
$remitos = $stmt->fetchAll();

// ── Clientes y proveedores para el formulario ─────────────────
$clientes = $db->prepare("SELECT id, nombre FROM clientes WHERE empresa_id = ? AND activo = 1 ORDER BY nombre");
$clientes->execute([$eid]);
$clientes = $clientes->fetchAll();

$proveedores = $db->prepare("SELECT id, nombre FROM proveedores WHERE empresa_id = ? AND activo = 1 ORDER BY nombre");
$proveedores->execute([$eid]);
$proveedores = $proveedores->fetchAll();

// Defaults para el form (del último remito o de la sesión)
$def = $_SESSION['remito_defaults'] ?? [];
$def_letra = $def['letra']        ?? '';
$def_pv    = $def['punto_venta']  ?? '';
$def_prov  = $def['proveedor_id'] ?? ($_SESSION['ultimo_proveedor_id'] ?? '');

// Si hay remitos cargados, tomar defaults del último
if (!empty($remitos)) {
    $ultimo = end($remitos);
    $parts  = explode('-', $ultimo['nro_remito_propio'], 3);
    if (count($parts) === 3) {
        $def_letra = $def_letra ?: $parts[0];
        $def_pv    = $def_pv    ?: $parts[1];
    }
    $def_prov = $def_prov ?: ($ultimo['proveedor_id'] ?? '');
}

$nuevo  = isset($_GET['nuevo']);
$ok     = isset($_GET['ok']);

$nav_modulo = 'ingresos';
$ITEMS_VACIOS = 10;
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
        .tabla-items td, .tabla-items th { vertical-align: middle; font-size: .88rem; }
        .col-cant  { width: 90px; }
        .col-unid  { width: 110px; }
        .nro-remito-group input { text-align: center; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-4 px-4">

    <!-- Encabezado -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex align-items-center">
            <a href="<?= url('modules/ingresos/lista.php') ?>" class="btn btn-sm btn-outline-secondary me-3">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div>
                <h5 class="fw-bold mb-0">
                    <i class="bi bi-box-arrow-in-down me-2 text-primary"></i>
                    Ingreso #<?= $id ?>
                    <?php if ($ingreso['proveedor_nombre']): ?>
                    <span class="text-muted fw-normal fs-6 ms-2">— <?= h($ingreso['proveedor_nombre']) ?></span>
                    <?php endif; ?>
                </h5>
                <small class="text-muted">
                    <?= fecha_legible($ingreso['fecha_ingreso']) ?>
                    <?php if ($ingreso['transportista']): ?>
                     · <?= h($ingreso['transportista']) ?>
                    <?php endif; ?>
                    <?php if ($ingreso['patente_camion_ext']): ?>
                     · <span class="badge bg-secondary"><?= h($ingreso['patente_camion_ext']) ?></span>
                    <?php endif; ?>
                </small>
            </div>
        </div>
        <span class="badge bg-primary rounded-pill fs-6"><?= count($remitos) ?> remito<?= count($remitos) !== 1 ? 's' : '' ?></span>
    </div>

    <?php if ($nuevo && empty($remitos)): ?>
    <div class="alert alert-success alert-dismissible d-flex align-items-center mb-3">
        <i class="bi bi-check-circle-fill me-2"></i>
        <div>Camión registrado. Ahora cargá los remitos uno por uno.</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($ok): ?>
    <div class="alert alert-success alert-dismissible d-flex align-items-center mb-3">
        <i class="bi bi-check-circle-fill me-2"></i>
        <div>Remito guardado correctamente.</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($err): ?>
    <div class="alert alert-danger d-flex align-items-center mb-3">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <div><?= h($err) ?></div>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ══ FORMULARIO AGREGAR REMITO ══ -->
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold border-0 pb-0">
                    <i class="bi bi-file-earmark-plus text-success me-2"></i>Agregar remito
                </div>
                <div class="card-body">
                    <form method="POST" id="form-remito">
                        <input type="hidden" name="accion" value="guardar_remito">

                        <!-- Nro de remito -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nro de remito <span class="text-danger">*</span></label>
                            <div class="input-group nro-remito-group">
                                <input type="text" name="letra" id="letra" class="form-control"
                                       style="max-width:60px" placeholder="A"
                                       maxlength="2" value="<?= h($def_letra) ?>"
                                       title="Letra del comprobante">
                                <span class="input-group-text">-</span>
                                <input type="text" name="punto_venta" id="punto_venta" class="form-control"
                                       style="max-width:80px" placeholder="0001"
                                       maxlength="5" value="<?= h($def_pv) ?>"
                                       title="Punto de venta">
                                <span class="input-group-text">-</span>
                                <input type="text" name="nro_remito" id="nro_remito" class="form-control"
                                       placeholder="00000001" required
                                       title="Número de remito">
                            </div>
                            <small class="text-muted">Letra · Punto de venta · Número</small>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Nro remito del proveedor</label>
                                <input type="text" name="nro_remito_proveedor" class="form-control"
                                       placeholder="Nro original del proveedor">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha del remito</label>
                                <input type="date" name="fecha_remito" class="form-control"
                                       value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Cliente <span class="text-danger">*</span></label>
                                <select name="cliente_id" class="form-select" required>
                                    <option value="">— Seleccioná el cliente —</option>
                                    <?php foreach ($clientes as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= h($c['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Proveedor</label>
                                <select name="proveedor_id" class="form-select">
                                    <option value="">— Sin proveedor —</option>
                                    <?php foreach ($proveedores as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= ((string)$def_prov === (string)$p['id']) ? 'selected' : '' ?>>
                                        <?= h($p['nombre']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Observaciones del remito</label>
                                <input type="text" name="observaciones" class="form-control"
                                       placeholder="Opcional">
                            </div>
                        </div>

                        <!-- Detalle de ítems -->
                        <div class="fw-semibold mb-2">
                            <i class="bi bi-list-ul text-primary me-1"></i>Detalle de mercadería
                        </div>
                        <div class="table-responsive mb-3">
                            <table class="table tabla-items table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Descripción</th>
                                        <th class="col-cant text-center">Cantidad</th>
                                        <th class="col-unid">Unidad</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php for ($i = 0; $i < $ITEMS_VACIOS; $i++): ?>
                                <tr>
                                    <td>
                                        <input type="text" name="items[<?= $i ?>][descripcion]"
                                               class="form-control form-control-sm border-0"
                                               placeholder="Ej: Cajas cartón 20kg">
                                    </td>
                                    <td>
                                        <input type="text" name="items[<?= $i ?>][cantidad]"
                                               class="form-control form-control-sm border-0 text-center"
                                               inputmode="decimal" placeholder="0">
                                    </td>
                                    <td>
                                        <select name="items[<?= $i ?>][unidad]" class="form-select form-select-sm border-0">
                                            <?php foreach (['unidad','caja','pallet','kg','bolsa','rollo','otro'] as $u): ?>
                                            <option value="<?= $u ?>"><?= ucfirst($u) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>

                        <button type="submit" class="btn btn-success w-100 btn-lg">
                            <i class="bi bi-check2-circle me-2"></i>Guardar remito
                        </button>

                    </form>
                </div>
            </div>
        </div>

        <!-- ══ LISTA DE REMITOS ══ -->
        <div class="col-xl-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold border-0 pb-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-file-earmark-text text-primary me-2"></i>Remitos cargados</span>
                    <span class="badge bg-primary rounded-pill"><?= count($remitos) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($remitos)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        Todavía no hay remitos. Usá el formulario para cargar el primero.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Nro remito</th>
                                    <th>Cliente</th>
                                    <th class="text-center">Ítems</th>
                                    <th class="text-end">Bultos</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($remitos as $r): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($r['nro_remito_propio']) ?></strong>
                                        <?php if ($r['nro_remito_proveedor']): ?>
                                        <br><small class="text-muted"><?= h($r['nro_remito_proveedor']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($r['cliente_nombre']) ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-primary rounded-pill"><?= (int)$r['total_items'] ?></span>
                                    </td>
                                    <td class="text-end fw-semibold">
                                        <?= $r['total_cantidad'] ? number_format($r['total_cantidad'], 2) : '—' ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-estado-<?= $r['estado'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $r['estado'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="remito_items.php?id=<?= $r['id'] ?>"
                                           class="btn btn-sm btn-outline-primary" title="Ver / editar ítems">
                                            <i class="bi bi-list-check"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($remitos)): ?>
            <div class="text-end mt-3">
                <a href="<?= url('modules/ingresos/lista.php') ?>" class="btn btn-outline-secondary me-2">
                    <i class="bi bi-list-ul me-1"></i>Ver todos los ingresos
                </a>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/forms.js"></script>
<script>
// Auto-mayúsculas en letra
document.getElementById('letra').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});
// Foco automático en nro_remito al cargar
document.getElementById('nro_remito').focus();
</script>
</body>
</html>
