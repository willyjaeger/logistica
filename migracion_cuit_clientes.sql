-- ============================================================
-- MIGRACIÓN: Agregar campo CUIT a la tabla clientes
-- Ejecutar en phpMyAdmin si da error "Column already exists", ignorarlo.
-- ============================================================
ALTER TABLE clientes ADD COLUMN cuit VARCHAR(20) NULL AFTER nombre;
