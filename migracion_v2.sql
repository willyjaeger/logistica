-- ============================================================
-- MIGRACIÓN v2
-- ============================================================

ALTER TABLE remitos ADD COLUMN nro_oc VARCHAR(50) NULL AFTER observaciones;
ALTER TABLE remitos ADD COLUMN fecha_entrega DATE NULL AFTER nro_oc;
