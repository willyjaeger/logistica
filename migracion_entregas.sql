-- ============================================================
-- MIGRACIÓN: Módulo Entregas
-- Ejecutar en phpMyAdmin antes de subir los archivos
-- ============================================================

CREATE TABLE IF NOT EXISTS entregas (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id    INT UNSIGNED NOT NULL,
  fecha         DATE         NOT NULL,
  chofer        VARCHAR(100) NULL,
  patente       VARCHAR(20)  NULL,
  transportista VARCHAR(100) NULL,
  observaciones TEXT         NULL,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entrega_remitos (
  entrega_id INT UNSIGNED NOT NULL,
  remito_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (entrega_id, remito_id),
  FOREIGN KEY (entrega_id) REFERENCES entregas(id) ON DELETE CASCADE,
  FOREIGN KEY (remito_id)  REFERENCES remitos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
