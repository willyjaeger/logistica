-- Tabla para registrar camiones usados por día en la cuenta corriente
CREATE TABLE IF NOT EXISTS cc_viajes (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id   INT UNSIGNED NOT NULL,
    proveedor_id INT UNSIGNED NOT NULL,
    fecha        DATE NOT NULL,
    camiones     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_cc_viajes (empresa_id, proveedor_id, fecha),
    FOREIGN KEY (empresa_id)   REFERENCES empresas(id),
    FOREIGN KEY (proveedor_id) REFERENCES proveedores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
