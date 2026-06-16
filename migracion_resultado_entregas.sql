-- ============================================================
-- MIGRACIÓN: Resultado por remito en entregas
-- Permite registrar si cada remito fue entregado o no,
-- y una observación del motivo en caso de no entrega.
-- ============================================================

ALTER TABLE entrega_remitos
    ADD COLUMN resultado   ENUM('entregado','no_entregado') NULL DEFAULT NULL AFTER remito_id,
    ADD COLUMN observacion TEXT                             NULL              AFTER resultado;
