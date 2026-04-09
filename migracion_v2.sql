-- ============================================================
-- MIGRACIÓN v2: nro_oc y fecha_entrega en remitos
-- Ejecutar en phpMyAdmin. Si la columna ya existe ignorar error.
-- ============================================================

ALTER TABLE remitos ADD COLUMN IF NOT EXISTS nro_oc VARCHAR(50) NULL AFTER observaciones;
ALTER TABLE remitos ADD COLUMN IF NOT EXISTS fecha_entrega DATE NULL AFTER nro_oc;
