<?php
// ============================================================
// HELPERS DE SESIÓN Y AUTENTICACIÓN
// Incluir en todas las páginas protegidas
// ============================================================

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirige al login si no hay sesión activa
function require_login(): void
{
    if (empty($_SESSION['usuario_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

// Genera una URL absoluta dentro del sistema
function url(string $path = ''): string
{
    return BASE_URL . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

// ID de la empresa de la sesión actual
function empresa_id(): int
{
    return (int) ($_SESSION['empresa_id'] ?? 0);
}

// Nombre del usuario logueado
function usuario_nombre(): string
{
    return $_SESSION['usuario_nombre'] ?? '';
}

// Nombre de la empresa logueada
function empresa_nombre(): string
{
    return $_SESSION['empresa_nombre'] ?? '';
}

// Rol del usuario (admin | operador)
function usuario_rol(): string
{
    return $_SESSION['usuario_rol'] ?? 'operador';
}

// Verifica si el usuario es admin
function es_admin(): bool
{
    return usuario_rol() === 'admin';
}

// Escapa output HTML para prevenir XSS
function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Formato de fecha legible (Y-m-d H:i → d/m/Y H:i)
function fecha_legible(?string $fecha): string
{
    if (!$fecha) return '-';
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $fecha)
       ?: DateTime::createFromFormat('Y-m-d H:i', $fecha)
       ?: DateTime::createFromFormat('Y-m-d', $fecha);
    return $dt ? $dt->format('d/m/Y H:i') : $fecha;
}
