<?php
require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['usuario_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario']  ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($usuario === '' || $password === '') {
        $error = 'Completá usuario y contraseña.';
    } else {
        $stmt = db()->prepare("
            SELECT u.id, u.nombre, u.password, u.rol, u.activo, u.debe_cambiar_clave,
                   e.id AS empresa_id, e.nombre AS empresa_nombre,
                   COALESCE(e.session_timeout_min, 30) AS session_timeout_min
            FROM usuarios u
            JOIN empresas e ON u.empresa_id = e.id
            WHERE u.usuario = ? AND u.activo = 1 AND e.activa = 1
            LIMIT 1
        ");
        $stmt->execute([$usuario]);
        $row = $stmt->fetch();

        if ($row && password_verify($password, $row['password'])) {
            session_regenerate_id(true);
            $_SESSION['usuario_id']          = $row['id'];
            $_SESSION['usuario_nombre']      = $row['nombre'];
            $_SESSION['usuario_rol']         = $row['rol'];
            $_SESSION['empresa_id']          = $row['empresa_id'];
            $_SESSION['empresa_nombre']      = $row['empresa_nombre'];
            $_SESSION['debe_cambiar_clave']  = (bool)$row['debe_cambiar_clave'];
            $_SESSION['session_timeout_min'] = (int)$row['session_timeout_min'];
            $_SESSION['ultimo_acceso']       = time();

            header('Location: ' . BASE_URL . ($row['debe_cambiar_clave'] ? '/cambiar_clave.php' : '/index.php'));
            exit;
        } else {
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
</head>
<body class="bg-login d-flex align-items-center justify-content-center min-vh-100">

<div class="card shadow-lg border-0 login-card">
    <div class="card-body p-5">

        <div class="text-center mb-4">
            <div class="login-icon mb-3">
                <i class="bi bi-truck fs-1 text-primary"></i>
            </div>
            <h4 class="fw-bold text-dark"><?= APP_NAME ?></h4>
            <p class="text-muted small">Ingresá con tu cuenta</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 py-2">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php elseif (!empty($_GET['timeout'])): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2 py-2">
                <i class="bi bi-clock"></i>
                <span>Tu sesión expiró por inactividad. Volvé a ingresar.</span>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" novalidate>
            <div class="mb-3">
                <label class="form-label fw-semibold">Usuario</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input
                        type="text"
                        name="usuario"
                        class="form-control"
                        placeholder="Tu usuario"
                        value="<?= htmlspecialchars($_POST['usuario'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="off"
                        readonly onfocus="this.removeAttribute('readonly')"
                        autofocus
                        required
                    >
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input
                        type="password"
                        name="password"
                        class="form-control"
                        placeholder="••••••••"
                        autocomplete="new-password"
                        readonly onfocus="this.removeAttribute('readonly')"
                        required
                    >
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                <i class="bi bi-box-arrow-in-right me-2"></i>Ingresar
            </button>
        </form>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
