<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$db  = db();
$eid = empresa_id();

$entrega_id = (int)($_GET['entrega_id'] ?? 0);
$back       = $_GET['back']  ?? 'agenda';
$fecha      = $_GET['fecha'] ?? date('Y-m-d');

if (!$entrega_id) {
    header('Location: ' . url('modules/agenda.php'));
    exit;
}

$st = $db->prepare("
    SELECT e.*, tr.nombre AS trans_nombre, cam.patente, ch.nombre AS cho_nombre
    FROM entregas e
    LEFT JOIN transportistas tr  ON tr.id = e.transportista_id
    LEFT JOIN camiones       cam ON cam.id = e.camion_id
    LEFT JOIN choferes       ch  ON ch.id = e.chofer_id
    WHERE e.id=? AND e.empresa_id=?
");
$st->execute([$entrega_id, $eid]);
$entrega = $st->fetch();

if (!$entrega) {
    $url_back = $back === 'lista' ? url('modules/entregas_lista.php') : url('modules/agenda.php') . '?fecha=' . urlencode($fecha);
    header('Location: ' . $url_back);
    exit;
}

$sr = $db->prepare("
    SELECT er.remito_id, er.resultado, er.observacion,
           r.nro_remito_propio, r.total_pallets,
           c.nombre AS cliente
    FROM entrega_remitos er
    JOIN remitos r ON r.id = er.remito_id
    JOIN clientes c ON c.id = r.cliente_id
    WHERE er.entrega_id = ?
    ORDER BY c.nombre, r.nro_remito_propio
");
$sr->execute([$entrega_id]);
$remitos = $sr->fetchAll();

$back_url    = $back === 'lista' ? url('modules/entregas_lista.php') : url('modules/agenda.php') . '?fecha=' . urlencode($entrega['fecha']);
$es_correccion = in_array($entrega['estado'], ['completada', 'con_incidencias', 'entregado']);
$nav_modulo  = 'agenda';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cerrar entrega — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        body { background: #eef1f6; }
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
        .remito-card {
            border: 1px solid #e2e8f0;
            border-radius: .5rem;
            padding: .75rem 1rem;
            margin-bottom: .75rem;
            background: #f8faff;
            transition: background .15s;
        }
        .remito-card.no-entregado {
            background: #fff7ed;
            border-color: #fed7aa;
        }
        .obs-wrap { display: none; margin-top: .5rem; }
        .obs-wrap.visible { display: block; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container-fluid py-3 px-3 px-lg-4">

    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= $back_url ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h5 class="fw-bold mb-0">
            <?= $es_correccion ? 'Corregir resultado de entrega' : 'Cerrar entrega' ?>
        </h5>
    </div>

    <!-- Info de la entrega -->
    <div class="seccion">
        <div class="seccion-titulo"><i class="bi bi-truck me-1"></i>Datos del viaje</div>
        <div class="row g-2 small">
            <div class="col-sm-3">
                <span class="text-muted fw-semibold">Fecha</span><br>
                <?= h(fecha_legible($entrega['fecha'] . ' 00:00:00')) ?>
            </div>
            <?php if ($entrega['trans_nombre']): ?>
            <div class="col-sm-3">
                <span class="text-muted fw-semibold">Transportista</span><br>
                <?= h($entrega['trans_nombre']) ?>
            </div>
            <?php endif; ?>
            <?php if ($entrega['patente']): ?>
            <div class="col-sm-2">
                <span class="text-muted fw-semibold">Patente</span><br>
                <span class="font-monospace fw-bold"><?= h($entrega['patente']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($entrega['cho_nombre']): ?>
            <div class="col-sm-3">
                <span class="text-muted fw-semibold">Chofer</span><br>
                <?= h($entrega['cho_nombre']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$remitos): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>Esta entrega no tiene remitos asignados.
    </div>
    <?php else: ?>

    <form method="POST" action="<?= url('modules/entrega_completar.php') ?>">
        <input type="hidden" name="entrega_id" value="<?= $entrega_id ?>">
        <input type="hidden" name="fecha"      value="<?= h($entrega['fecha']) ?>">
        <input type="hidden" name="back"       value="<?= h($back) ?>">

        <!-- Remitos -->
        <div class="seccion">
            <div class="seccion-titulo"><i class="bi bi-file-earmark-text me-1"></i>Resultado por remito</div>

            <?php foreach ($remitos as $r):
                $rid     = $r['remito_id'];
                $prev    = $r['resultado'] ?? 'entregado';
                $obs     = $r['observacion'] ?? '';
                $partes  = explode('-', $r['nro_remito_propio'] ?? '');
                $nro_fmt = count($partes) === 3 ? 'R-' . $partes[1] . '-' . $partes[2] : h($r['nro_remito_propio']);
            ?>
            <div class="remito-card <?= $prev === 'no_entregado' ? 'no-entregado' : '' ?>" id="card-<?= $rid ?>">
                <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                    <div>
                        <span class="fw-bold font-monospace"><?= $nro_fmt ?></span>
                        <span class="text-muted mx-1">—</span>
                        <span><?= h($r['cliente']) ?></span>
                        <?php if ($r['total_pallets']): ?>
                        <span class="badge bg-secondary ms-2"><?= h($r['total_pallets']) ?> pal.</span>
                        <?php endif; ?>
                    </div>
                    <div class="btn-group btn-group-sm" role="group">
                        <input type="radio" class="btn-check resultado-radio"
                               name="resultados[<?= $rid ?>]"
                               id="ok-<?= $rid ?>"
                               value="entregado"
                               data-rid="<?= $rid ?>"
                               <?= $prev !== 'no_entregado' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-success" for="ok-<?= $rid ?>">
                            <i class="bi bi-check-lg me-1"></i>Entregado
                        </label>

                        <input type="radio" class="btn-check resultado-radio"
                               name="resultados[<?= $rid ?>]"
                               id="no-<?= $rid ?>"
                               value="no_entregado"
                               data-rid="<?= $rid ?>"
                               <?= $prev === 'no_entregado' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-warning" for="no-<?= $rid ?>">
                            <i class="bi bi-x-lg me-1"></i>No entregado
                        </label>
                    </div>
                </div>
                <div class="obs-wrap <?= $prev === 'no_entregado' ? 'visible' : '' ?>" id="obs-wrap-<?= $rid ?>">
                    <textarea name="observaciones[<?= $rid ?>]"
                              class="form-control form-control-sm mt-1"
                              rows="2"
                              placeholder="Motivo (cliente ausente, dirección incorrecta, rechazó mercadería...)"><?= h($obs) ?></textarea>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="<?= $back_url ?>" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-circle me-1"></i>Confirmar y cerrar entrega
            </button>
        </div>
    </form>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';
document.querySelectorAll('.resultado-radio').forEach(function(radio) {
    radio.addEventListener('change', function() {
        const rid     = this.dataset.rid;
        const card    = document.getElementById('card-' + rid);
        const obsWrap = document.getElementById('obs-wrap-' + rid);
        const isNo    = this.value === 'no_entregado';
        card.classList.toggle('no-entregado', isNo);
        obsWrap.classList.toggle('visible', isNo);
        if (isNo) obsWrap.querySelector('textarea').focus();
    });
});
</script>
</body>
</html>
