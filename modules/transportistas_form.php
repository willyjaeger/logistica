<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

$id = (int)($_GET['id'] ?? 0);
$transportista = null;
$camiones  = [];
$choferes  = [];

if ($id > 0) {
    $st = $db->prepare("SELECT * FROM transportistas WHERE id = ? AND empresa_id = ?");
    $st->execute([$id, $eid]);
    $transportista = $st->fetch();
    if (!$transportista) {
        header('Location: ' . url('modules/transportistas_lista.php'));
        exit;
    }
    $camiones = $db->prepare("SELECT * FROM camiones WHERE transportista_id = ? ORDER BY patente");
    $camiones->execute([$id]);
    $camiones = $camiones->fetchAll();

    $choferes = $db->prepare("SELECT * FROM choferes WHERE transportista_id = ? ORDER BY nombre");
    $choferes->execute([$id]);
    $choferes = $choferes->fetchAll();
}

$error = $_SESSION['form_error'] ?? null;
unset($_SESSION['form_error']);

$nav_modulo = 'transportistas';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $id ? 'Editar' : 'Nuevo' ?> transportista — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #eef1f6; }
        .seccion {
            background: #fff; border: none; border-left: 4px solid #0d6efd;
            border-radius: .5rem; padding: 1rem 1.25rem; margin-bottom: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        .seccion.verde { border-left-color: #16a34a; }
        .seccion.naranja { border-left-color: #f59e0b; }
        .seccion-titulo {
            font-size: .78rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .08em; color: #0d6efd; margin-bottom: .75rem;
            padding-bottom: .4rem; border-bottom: 1px solid #e9ecef;
        }
        .seccion.verde .seccion-titulo { color: #16a34a; }
        .seccion.naranja .seccion-titulo { color: #d97706; }
        .form-label { font-weight: 600; color: #374151; }
        .sub-table thead th {
            background: #1e293b; color: #94a3b8;
            font-size: .75rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .05em; padding: .4rem .75rem; border: none;
        }
        .sub-table td { vertical-align: middle; padding: .4rem .75rem; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-3 px-3" style="max-width:900px">
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= url('modules/transportistas_lista.php') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h5 class="fw-bold mb-0">
            <?= $id ? 'Editar transportista' : 'Nuevo transportista' ?>
        </h5>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible py-2">
        <i class="bi bi-exclamation-circle me-2"></i><?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['ok_camion'])): ?>
    <div class="alert alert-success alert-dismissible py-2">
        <i class="bi bi-check-circle me-2"></i>Camión guardado.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['ok_chofer'])): ?>
    <div class="alert alert-success alert-dismissible py-2">
        <i class="bi bi-check-circle me-2"></i>Chofer guardado.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- DATOS DEL TRANSPORTISTA -->
    <form method="POST" action="<?= url('modules/transportistas_guardar.php') ?>">
        <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
        <div class="seccion">
            <div class="seccion-titulo"><i class="bi bi-person-vcard me-1"></i>Datos de la empresa de transporte</div>
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label form-label-sm mb-1">Nombre / Razón social <span class="text-danger">*</span></label>
                    <input type="text" name="nombre" class="form-control form-control-sm"
                           value="<?= h($transportista['nombre'] ?? '') ?>" required autofocus
                           placeholder="Ej: Transportes García">
                </div>
                <div class="col-sm-3">
                    <label class="form-label form-label-sm mb-1">CUIT <span class="text-muted">(opcional)</span></label>
                    <input type="text" name="cuit" class="form-control form-control-sm font-monospace"
                           value="<?= h($transportista['cuit'] ?? '') ?>"
                           placeholder="20-12345678-9">
                </div>
                <div class="col-sm-3">
                    <label class="form-label form-label-sm mb-1">Teléfono <span class="text-muted">(opcional)</span></label>
                    <input type="text" name="telefono" class="form-control form-control-sm"
                           value="<?= h($transportista['telefono'] ?? '') ?>"
                           placeholder="Ej: 011 1234-5678">
                </div>
                <?php if ($id): ?>
                <div class="col-sm-3">
                    <label class="form-label form-label-sm mb-1">Estado</label>
                    <select name="activo" class="form-select form-select-sm">
                        <option value="1" <?= ($transportista['activo'] ?? 1) ? 'selected' : '' ?>>Activo</option>
                        <option value="0" <?= !($transportista['activo'] ?? 1) ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="<?= url('modules/transportistas_lista.php') ?>" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-floppy me-1"></i><?= $id ? 'Guardar cambios' : 'Crear transportista' ?>
            </button>
        </div>
    </form>

    <?php if ($id): ?>
    <!-- CAMIONES -->
    <div class="seccion verde">
        <div class="seccion-titulo"><i class="bi bi-truck-front me-1"></i>Camiones</div>

        <?php if ($camiones): ?>
        <div class="table-responsive mb-3">
            <table class="table table-sm mb-0 sub-table">
                <thead>
                    <tr>
                        <th>Patente</th>
                        <th>Marca</th>
                        <th>Modelo</th>
                        <th class="text-center">Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($camiones as $c): ?>
                <tr>
                    <td class="fw-semibold font-monospace"><?= h($c['patente']) ?></td>
                    <td><?= h($c['marca'] ?? '—') ?></td>
                    <td><?= h($c['modelo'] ?? '—') ?></td>
                    <td class="text-center">
                        <?= $c['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary me-1"
                                onclick="editarCamion(<?= $c['id'] ?>, '<?= h(addslashes($c['patente'])) ?>', '<?= h(addslashes($c['marca'] ?? '')) ?>', '<?= h(addslashes($c['modelo'] ?? '')) ?>', <?= $c['activo'] ?>)"
                                title="Editar">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" action="<?= url('modules/camiones_guardar.php') ?>" class="d-inline"
                              onsubmit="return confirm('¿Eliminar este camión?')">
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <input type="hidden" name="transportista_id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <button type="button" class="btn btn-sm btn-outline-success" onclick="nuevoCamion()">
            <i class="bi bi-plus-lg me-1"></i>Agregar camión
        </button>
    </div>

    <!-- CHOFERES -->
    <div class="seccion naranja">
        <div class="seccion-titulo"><i class="bi bi-person-badge me-1"></i>Choferes</div>

        <?php if ($choferes): ?>
        <div class="table-responsive mb-3">
            <table class="table table-sm mb-0 sub-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Teléfono</th>
                        <th class="text-center">Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($choferes as $ch): ?>
                <tr>
                    <td class="fw-semibold"><?= h($ch['nombre']) ?></td>
                    <td class="text-muted"><?= h($ch['telefono'] ?? '—') ?></td>
                    <td class="text-center">
                        <?= $ch['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary me-1"
                                onclick="editarChofer(<?= $ch['id'] ?>, '<?= h(addslashes($ch['nombre'])) ?>', '<?= h(addslashes($ch['telefono'] ?? '')) ?>', <?= $ch['activo'] ?>)"
                                title="Editar">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" action="<?= url('modules/choferes_guardar.php') ?>" class="d-inline"
                              onsubmit="return confirm('¿Eliminar este chofer?')">
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="id" value="<?= $ch['id'] ?>">
                            <input type="hidden" name="transportista_id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <button type="button" class="btn btn-sm btn-outline-warning" onclick="nuevoChofer()">
            <i class="bi bi-plus-lg me-1"></i>Agregar chofer
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- MODAL CAMIÓN -->
<div class="modal fade" id="modalCamion" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('modules/camiones_guardar.php') ?>">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id" id="camion_id" value="">
                <input type="hidden" name="transportista_id" value="<?= $id ?>">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold" id="modalCamionTitulo">Nuevo camión</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label form-label-sm fw-semibold">Patente <span class="text-danger">*</span></label>
                        <input type="text" name="patente" id="camion_patente" class="form-control form-control-sm font-monospace text-uppercase"
                               required placeholder="Ej: AB123CD">
                    </div>
                    <div class="row g-2">
                        <div class="col">
                            <label class="form-label form-label-sm fw-semibold">Marca <span class="text-muted">(opcional)</span></label>
                            <input type="text" name="marca" id="camion_marca" class="form-control form-control-sm"
                                   placeholder="Ej: Mercedes">
                        </div>
                        <div class="col">
                            <label class="form-label form-label-sm fw-semibold">Modelo <span class="text-muted">(opcional)</span></label>
                            <input type="text" name="modelo" id="camion_modelo" class="form-control form-control-sm"
                                   placeholder="Ej: Actros 1845">
                        </div>
                    </div>
                    <div class="mt-3" id="camion_estado_wrap" style="display:none">
                        <label class="form-label form-label-sm fw-semibold">Estado</label>
                        <select name="activo" id="camion_activo" class="form-select form-select-sm">
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-floppy me-1"></i>Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL CHOFER -->
<div class="modal fade" id="modalChofer" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('modules/choferes_guardar.php') ?>">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id" id="chofer_id" value="">
                <input type="hidden" name="transportista_id" value="<?= $id ?>">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold" id="modalChoferTitulo">Nuevo chofer</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label form-label-sm fw-semibold">Nombre completo <span class="text-danger">*</span></label>
                        <input type="text" name="nombre" id="chofer_nombre" class="form-control form-control-sm"
                               required placeholder="Nombre y apellido">
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-sm fw-semibold">Teléfono <span class="text-muted">(opcional)</span></label>
                        <input type="text" name="telefono" id="chofer_telefono" class="form-control form-control-sm"
                               placeholder="Ej: 011 1234-5678">
                    </div>
                    <div id="chofer_estado_wrap" style="display:none">
                        <label class="form-label form-label-sm fw-semibold">Estado</label>
                        <select name="activo" id="chofer_activo" class="form-select form-select-sm">
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-floppy me-1"></i>Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const modalCamion = new bootstrap.Modal(document.getElementById('modalCamion'));
const modalChofer = new bootstrap.Modal(document.getElementById('modalChofer'));

function nuevoCamion() {
    document.getElementById('modalCamionTitulo').textContent = 'Nuevo camión';
    document.getElementById('camion_id').value = '';
    document.getElementById('camion_patente').value = '';
    document.getElementById('camion_marca').value = '';
    document.getElementById('camion_modelo').value = '';
    document.getElementById('camion_estado_wrap').style.display = 'none';
    modalCamion.show();
}
function editarCamion(id, patente, marca, modelo, activo) {
    document.getElementById('modalCamionTitulo').textContent = 'Editar camión';
    document.getElementById('camion_id').value = id;
    document.getElementById('camion_patente').value = patente;
    document.getElementById('camion_marca').value = marca;
    document.getElementById('camion_modelo').value = modelo;
    document.getElementById('camion_activo').value = activo ? '1' : '0';
    document.getElementById('camion_estado_wrap').style.display = '';
    modalCamion.show();
}
function nuevoChofer() {
    document.getElementById('modalChoferTitulo').textContent = 'Nuevo chofer';
    document.getElementById('chofer_id').value = '';
    document.getElementById('chofer_nombre').value = '';
    document.getElementById('chofer_telefono').value = '';
    document.getElementById('chofer_estado_wrap').style.display = 'none';
    modalChofer.show();
}
function editarChofer(id, nombre, telefono, activo) {
    document.getElementById('modalChoferTitulo').textContent = 'Editar chofer';
    document.getElementById('chofer_id').value = id;
    document.getElementById('chofer_nombre').value = nombre;
    document.getElementById('chofer_telefono').value = telefono;
    document.getElementById('chofer_activo').value = activo ? '1' : '0';
    document.getElementById('chofer_estado_wrap').style.display = '';
    modalChofer.show();
}
</script>
</body>
</html>
