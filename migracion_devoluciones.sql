-- Devoluciones de remitos entregados
-- Registra cuándo y cuántos pallets volvieron del cliente

ALTER TABLE remitos
  ADD COLUMN fecha_devolucion  DATE          NULL DEFAULT NULL AFTER fecha_entrega,
  ADD COLUMN pallets_devueltos DECIMAL(10,2) NULL DEFAULT NULL AFTER fecha_devolucion;
