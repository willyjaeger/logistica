-- Ejecutar una sola vez en phpMyAdmin
-- Agrega: cambio de clave obligatorio en primer login + timeout de sesión por empresa

ALTER TABLE usuarios
    ADD COLUMN debe_cambiar_clave TINYINT(1) NOT NULL DEFAULT 0 AFTER activo;

ALTER TABLE empresas
    ADD COLUMN session_timeout_min SMALLINT UNSIGNED NOT NULL DEFAULT 30;
