-- Saldo inicial editable por proveedor / mes / año
-- Permite registrar manualmente los pallets en depósito al inicio
-- de cada período, como punto de partida para el cálculo de la CC.
-- Si no hay registro, el sistema lo calcula automáticamente desde los remitos.

CREATE TABLE IF NOT EXISTS cc_saldo_inicial (
    empresa_id   INT             NOT NULL,
    proveedor_id INT             NOT NULL,
    anio         SMALLINT UNSIGNED NOT NULL,
    mes          TINYINT UNSIGNED  NOT NULL,
    saldo        DECIMAL(10,2)   NOT NULL DEFAULT 0,
    PRIMARY KEY (empresa_id, proveedor_id, anio, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
