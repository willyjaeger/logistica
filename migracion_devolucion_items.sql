-- Tabla de artículos devueltos por remito
-- Cada devolución tiene sus propias líneas de detalle (igual que remito_items)

CREATE TABLE IF NOT EXISTS devolucion_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id  INT          NOT NULL,
    remito_id   INT          NOT NULL,
    articulo_id INT          NULL,
    descripcion VARCHAR(255) NOT NULL DEFAULT '',
    cantidad    DECIMAL(12,2) NOT NULL DEFAULT 0,
    pallets     DECIMAL(10,3) NOT NULL DEFAULT 0,
    KEY idx_remito (remito_id, empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
