-- ============================================================
-- MIGRACIÓN: Comprobantes de entrega (remito firmado escaneado / foto)
--            + usuarios de proveedor (portal de consulta)
-- Ejecutar una sola vez en phpMyAdmin ANTES de subir el código.
-- ============================================================

CREATE TABLE IF NOT EXISTS remito_comprobantes (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id       INT UNSIGNED NOT NULL,
  remito_id        INT UNSIGNED NOT NULL,
  archivo          VARCHAR(255) NOT NULL  COMMENT 'Ruta relativa dentro de uploads/comprobantes/',
  nombre_original  VARCHAR(255)           COMMENT 'Nombre del archivo subido, si lo hay',
  mime             VARCHAR(60)  NOT NULL,
  tamano           INT UNSIGNED NOT NULL DEFAULT 0,
  origen           ENUM('escaner','foto','archivo') NOT NULL DEFAULT 'archivo',
  subido_por       INT UNSIGNED,
  creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_comp_remito (empresa_id, remito_id),
  FOREIGN KEY (remito_id) REFERENCES remitos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rol "proveedor": solo accede al portal y ve los remitos de su proveedor
ALTER TABLE usuarios
    MODIFY COLUMN rol ENUM('admin','operador','proveedor') NOT NULL DEFAULT 'operador',
    ADD COLUMN proveedor_id INT UNSIGNED NULL AFTER rol;
