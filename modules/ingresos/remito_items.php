<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$eid = empresa_id();
$db  = db();

$remito_id = (int) ($_GET['id'] ?? 0);
if ($remito_id <= 0) {
    header('Location: ' . url('modules/ingresos/remitos.php'));
    exit;
}

// Cargar remito con datos relacionados
$stmt = $db->prepare("
    SELECT r.*,
           c.nombre    AS cliente_nombre,
           c.direccion AS cliente_direccion,
           p.nombre    AS proveedor_nombre,
           i.fecha_ingreso, i.transportista
    FROM remitos r
    JOIN clientes c ON r.cliente_id = c.id
    LEFT JOIN proveedores p ON r.proveedor_id = p.id
    LEFT JOIN ingresos i ON r.ingreso_id = i.id
    WHERE r.id = ? AND r.empresa_id = ?
");
$stmt->execute([$remito_id, $eid]);
$remito = $stmt->fetch();
if (!$remito) {
    header('Location: ' . url('modules/ingresos/remitos.php'));
    exit;
}

// ── Guardar ítem nuevo ────────────────────────────────────────
$ok  = false;
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar') {
    $descripcion = trim($_POST['descripcion'] ?? '');
    $cantidad    = str_replace(',', '.', trim($_POST['cantidad'] ?? ''));
    $unidad      = trim($_POST['unidad'] ?? 'unidad');

    if ($descripcion === '' || !is_numeric($cantidad) || (float)$cantidad <= 0) {
        $err = 'Completá descripción y una cantidad válida.';
    } else {
        $db->prepare("
            INSERT INTO remito_items (remito_id, descripcion, cantidad, unidad, estado)
            VALUES (?, ?, ?, ?, 'pendiente')
        ")->execute([$remito_id, $descripcion, (float)$cantidad, $unidad ?: 'unidad']);
        $ok = true;
        header('Location: remito_items.php?id=' . $remito_id . '&ok=1');
        exit;
    }
}

if (isset($_GET['ok'])) $ok = true;

// ── Eliminar ítem ─────────────────────────────────────────────
if (isset($_GET['eliminar'])) {
    $item_id = (int) $_GET['eliminar'];
    // Solo se puede eliminar si el ítem es pendiente y pertenece a este remito
    $db->prepare("
        DELETE FROM remito_items
        WHERE id = ? AND remito_id = ? AND estado = 'pendiente'
    ")->execute([$item_id, $remito_id]);
    header('Location: remito_items.php?id=' . $remito_id);
    exit;
}

// ── Cargar ítems existentes ───────────────────────────────────
$stmt = $db->prepare("
    SELECT * FROM remito_items WHERE remito_id = ? ORDER BY id
");
$stmt->execute([$remito_id]);
$items = $stmt->fetchAll();

$total_cantidad = array_sum(array_column($items, 'cantidad'));

$nav_modulo = 'remitos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ítems remito <?= h($remito['nro_remito_propio']) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
</head>
<body>

<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container-fluid py-4 px-4">

    <!-- Encabezado -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="d-flex align-items-center">
            <a href="<?= url('modules/ingresos/ver.php') ?>?id=<?= $remito['ingreso_id'] ?>"
               class="btn btn-sm btn-outline-secondary me-3">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div>
                <h5 class="fw-bold mb-0">
                    <i class="bi bi-list-check me-2 text-primary"></i>
                    Remito <?= h($remito['nro_remito_propio']) ?>
                </h5>
                <small class="text-muted">
                    <?= h($remito['cliente_nombre']) ?>
                    <?php if ($remito['cliente_direccion']): ?>
                     — <?= h($remito['cliente_direccion']) ?>
                    <?php endif; ?>
                </small>
            </div>
        </div>
        <span class="badge badge-estado-<?= $remito['estado'] ?> fs-6">
            <?= ucfirst(str_replace('_', ' ', $remito['estado'])) ?>
        </span>
    </div>

    <?php if ($ok): ?>
    <div class="alert alert-success alert-dismissible d-flex align-items-center mb-4">
        <i class="bi bi-check-circle-fill me-2"></i>
        <div>Ítem guardado correctamente.</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($err): ?>
    <div class="alert alert-danger d-flex align-items-center mb-4">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <div><?= h($err) ?></div>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Info del remito -->
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold border-0 pb-0">
                    <i class="bi bi-info-circle text-primary me-2"></i>Datos del remito
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted fw-normal">Proveedor</dt>
                        <dd class="col-7"><?= h($remito['proveedor_nombre'] ?? '—') ?></dd>

                        <dt class="col-5 text-muted fw-normal">Expreso</dt>
                        <dd class="col-7"><?= h($remito['transportista'] ?? '—') ?></dd>

                        <dt class="col-5 text-muted fw-normal">Ingresó</dt>
                        <dd class="col-7"><?= fecha_legible($remito['fecha_ingreso']) ?></dd>

                        <?php if ($remito['nro_remito_proveedor']): ?>
                        <dt class="col-5 text-muted fw-normal">Nro prov.</dt>
                        <dd class="col-7"><?= h($remito['nro_remito_proveedor']) ?></dd>
                        <?php endif; ?>

                        <?php if ($remito['observaciones']): ?>
                        <dt class="col-5 text-muted fw-normal">Obs.</dt>
                        <dd class="col-7"><?= h($remito['observaciones']) ?></dd>
                        <?php endif; ?>

                        <dt class="col-5 text-muted fw-normal mt-2">Total bultos</dt>
                        <dd class="col-7 mt-2 fw-bold"><?= number_format($total_cantidad, 2) ?></dd>
                    </dl>
                </div>
            </div>

            <!-- Formulario agregar ítem -->
            <?php if ($remito['estado'] === 'pendiente' || $remito['estado'] === 'parcialmente_entregado'): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold border-0 pb-0">
                    <i class="bi bi-plus-circle text-success me-2"></i>Agregar ítem
                </div>
                <div class="card-body">
                    <form method="POST" id="form-item">
                        <input type="hidden" name="accion" value="agregar">
                        <div class="mb-3">
                            <label class="form-label">Descripción <span class="text-danger">*</span></label>
                            <input type="text" name="descripcion" class="form-control"
                                   placeholder="Ej: Cajas cartón 20kg" required autofocus
                                   value="<?= h($_POST['descripcion'] ?? '') ?>">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label">Cantidad <span class="text-danger">*</span></label>
                                <input type="text" name="cantidad" class="form-control"
                                       placeholder="Ej: 10" inputmode="decimal"
                                       value="<?= h($_POST['cantidad'] ?? '') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Unidad</label>
                                <select name="unidad" class="form-select">
                                    <?php foreach (['unidad','caja','pallet','kg','bolsa','rollo','otro'] as $u): ?>
                                    <option value="<?= $u ?>" <?= (($_POST['unidad'] ?? 'unidad') === $u) ? 'selected' : '' ?>>
                                        <?= ucfirst($u) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-plus-lg me-2"></i>Agregar
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Lista de ítems -->
        <div class="col-lg-9">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold border-0 pb-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-boxes text-primary me-2"></i>Mercadería</span>
                    <span class="badge bg-primary rounded-pill"><?= count($items) ?> ítems</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($items)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        No hay ítems cargados. Usá el formulario para agregar la mercadería.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Descripción</th>
                                    <th class="text-end">Cantidad</th>
                                    <th>Unidad</th>
                                    <th>Estado</th>
                                    <th class="text-end">Entregado</th>
                                    <th class="text-end">En stock</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $i => $item): ?>
                                <tr>
                                    <td class="text-muted small"><?= $i + 1 ?></td>
                                    <td><?= h($item['descripcion']) ?></td>
                                    <td class="text-end fw-semibold"><?= number_format($item['cantidad'], 2) ?></td>
                                    <td class="text-muted small"><?= h($item['unidad']) ?></td>
                                    <td>
                                        <span class="badge badge-estado-<?= $item['estado'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $item['estado'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-end small"><?= number_format($item['cantidad_entregada'], 2) ?></td>
                                    <td class="text-end small"><?= number_format($item['cantidad_stock'], 2) ?></td>
                                    <td>
                                        <?php if ($item['estado'] === 'pendiente'): ?>
                                        <a href="?id=<?= $remito_id ?>&eliminar=<?= $item['id'] ?>"
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('¿Eliminar este ítem?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="2" class="fw-semibold">Total</td>
                                    <td class="text-end fw-bold"><?= number_format($total_cantidad, 2) ?></td>
                                    <td colspan="5"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/forms.js"></script>
</body>
</html>
