<?php
require_once __DIR__ . '/config/auth.php';
require_login();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nueva     = $_POST['nueva']     ?? '';
    $confirmar = $_POST['confirmar'] ?? '';

    if (strlen($nueva) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($nueva !== $confirmar) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $db = db();
        $db->prepare("UPDATE usuarios SET password = ?, debe_cambiar_clave = 0 WHERE id = ?")
           ->execute([password_hash($nueva, PASSWORD_BCRYPT), $_SESSION['usuario_id']]);
        $_SESSION['debe_cambiar_clave'] = false;
        header('Location: ' . url('index.php'));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cambiar contraseña — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body class="bg-login d-flex align-items-center justify-content-center min-vh-100">

<div class="card shadow-lg border-0 login-card">
    <div class="card-body p-5">

        <div class="text-center mb-4">
            <div class="login-icon mb-3">
                <i class="bi bi-key fs-1 text-warning"></i>
            </div>
            <h4 class="fw-bold text-dark">Cambiá tu contraseña</h4>
            <p class="text-muted small">Es tu primer ingreso. Elegí una contraseña nueva para continuar.</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 py-2">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" novalidate>
            <div class="mb-3">
                <label class="form-label fw-semibold">Nueva contraseña</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" name="nueva" class="form-control"
                           placeholder="Mínimo 6 caracteres" autofocus required>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Confirmar contraseña</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" name="confirmar" class="form-control"
                           placeholder="Repetí la contraseña" required>
                </div>
            </div>
            <button type="submit" class="btn btn-warning w-100 py-2 fw-semibold">
                <i class="bi bi-check-lg me-2"></i>Guardar y continuar
            </button>
        </form>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
