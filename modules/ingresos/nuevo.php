<?php
require_once __DIR__ . '/../../config/auth.php';
require_login();

$eid = empresa_id();
$db  = db();

$stmt = $db->prepare("SELECT id, nombre FROM proveedores WHERE empresa_id = ? AND activo = 1 ORDER BY nombre");
$stmt->execute([$eid]);
$proveedores = $stmt->fetchAll();

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
</head>
<body>

<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="container py-4 px-4" style="max-width:700px">

    <div class="d-flex align-items-center mb-4">
        <a href="<?= url('modules/ingresos/lista.php') ?>" class="btn btn-sm btn-outline-secondary me-3">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div>
            <h5 class="fw-bold mb-0">
                <i class="bi bi-truck-front-fill me-2 text-primary"></i>Registrar ingreso de camión
            </h5>
            <small class="text-muted">Datos del camión y el proveedor que trae</small>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="guardar.php">
                <div class="row g-3">

                    <div class="col-md-4">
                        <label class="form-label" for="fecha_ingreso">Fecha y hora</label>
                        <input type="datetime-local" id="fecha_ingreso" name="fecha_ingreso"
                               class="form-control" value="<?= $fecha_default ?>" required>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label" for="proveedor_id">Proveedor <span class="text-danger">*</span></label>
                        <select id="proveedor_id" name="proveedor_id" class="form-select" required>
                            <option value="">— Seleccioná el proveedor —</option>
                            <?php foreach ($proveedores as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= h($p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="transportista">Transportista (expreso)</label>
                        <input type="text" id="transportista" name="transportista"
                               class="form-control" placeholder="Ej: Expreso Sauer" autocomplete="off">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="patente_camion_ext">Patente</label>
                        <input type="text" id="patente_camion_ext" name="patente_camion_ext"
                               class="form-control text-uppercase" placeholder="AB 123 CD"
                               maxlength="15" oninput="this.value=this.value.toUpperCase()">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="chofer_externo">Chofer</label>
                        <input type="text" id="chofer_externo" name="chofer_externo"
                               class="form-control" placeholder="Nombre">
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="observaciones">Observaciones</label>
                        <textarea id="observaciones" name="observaciones" class="form-control" rows="2"
                                  placeholder="Ej: mercadería con signos de humedad… (opcional)"></textarea>
                    </div>

                    <div class="col-12 d-flex justify-content-between">
                        <a href="<?= url('modules/ingresos/lista.php') ?>" class="btn btn-outline-secondary">
                            Cancelar
                        </a>
                        <button type="submit" class="btn btn-primary px-5">
                            <i class="bi bi-arrow-right-circle me-2"></i>Continuar → cargar remitos
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/forms.js"></script>
</body>
</html>
