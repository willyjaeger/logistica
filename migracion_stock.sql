-- ============================================================
-- MIGRACIÓN: Módulo de stock
-- Ejecutar en phpMyAdmin. Si algún ALTER da error por columna
-- ya existente, ignorarlo.
-- ============================================================

CREATE TABLE IF NOT EXISTS stock_movimientos (
    id            INT           AUTO_INCREMENT PRIMARY KEY,
    empresa_id    INT           NOT NULL,
    lote_id       INT           NULL COMMENT 'Agrupa ítems del mismo comprobante',
    fecha         DATE          NOT NULL,
    tipo          ENUM(
                      'carga_inicial',
                      'ingreso_remito',
                      'ingreso_devolucion',
                      'ingreso_expreso',
                      'ingreso_stock_seg',
                      'salida_entrega',
                      'salida_consumo',
                      'ajuste_positivo',
                      'ajuste_negativo'
                  )             NOT NULL,
    articulo_id   INT UNSIGNED  NULL,
    descripcion   VARCHAR(200)  NOT NULL DEFAULT '',
    cantidad      DECIMAL(10,2) NOT NULL,
    remito_id     INT           NULL,
    observaciones TEXT          NULL COMMENT 'Origen, referencia, motivo editable',
    usuario_id    INT           NOT NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stk_emp_art   (empresa_id, articulo_id),
    INDEX idx_stk_emp_fecha (empresa_id, fecha),
    INDEX idx_stk_lote      (lote_id),
    INDEX idx_stk_remito    (remito_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Flag en remitos: ¿la mercadería llega físicamente con este remito?
-- 0 = remito virtual (usa stock de seguridad existente)
ALTER TABLE remitos
    ADD COLUMN entrega_fisica TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1=mercadería física; 0=remito virtual (usa stock existente)';
