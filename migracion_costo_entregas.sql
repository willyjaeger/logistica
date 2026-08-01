-- ============================================================
-- MIGRACIÓN: Costo de transporte por viaje (entrega)
-- Permite registrar cuánto cobró el transportista por ese viaje.
-- ============================================================

ALTER TABLE entregas
  ADD COLUMN costo_transporte DECIMAL(10,2) NULL DEFAULT NULL AFTER observaciones;
