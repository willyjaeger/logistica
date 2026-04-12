-- ============================================================
-- MIGRACIÓN: Módulo Entregas + Transportistas
-- Ejecutar en phpMyAdmin antes de subir los archivos
-- ============================================================

-- 1. Empresas de transporte (facturan a Logax)
CREATE TABLE IF NOT EXISTS transportistas (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  nombre    VARCHAR(150) NOT NULL,
  cuit      VARCHAR(20)  NULL,
  telefono  VARCHAR(50)  NULL,
  activo    TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Camiones de cada transportista
CREATE TABLE IF NOT EXISTS camiones (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transportista_id INT UNSIGNED NOT NULL,
  patente          VARCHAR(20)  NOT NULL,
  marca            VARCHAR(80)  NULL,
  modelo           VARCHAR(80)  NULL,
  activo           TINYINT(1)   NOT NULL DEFAULT 1,
  FOREIGN KEY (transportista_id) REFERENCES transportistas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Choferes de cada transportista
CREATE TABLE IF NOT EXISTS choferes (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transportista_id INT UNSIGNED NOT NULL,
  nombre           VARCHAR(150) NOT NULL,
  telefono         VARCHAR(50)  NULL,
  activo           TINYINT(1)   NOT NULL DEFAULT 1,
  FOREIGN KEY (transportista_id) REFERENCES transportistas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Cabecera de cada entrega
CREATE TABLE IF NOT EXISTS entregas (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id       INT UNSIGNED NOT NULL,
  fecha            DATE         NOT NULL,
  transportista_id INT UNSIGNED NULL,
  camion_id        INT UNSIGNED NULL,
  chofer_id        INT UNSIGNED NULL,
  observaciones    TEXT         NULL,
  creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id)       REFERENCES empresas(id),
  FOREIGN KEY (transportista_id) REFERENCES transportistas(id) ON DELETE SET NULL,
  FOREIGN KEY (camion_id)        REFERENCES camiones(id)       ON DELETE SET NULL,
  FOREIGN KEY (chofer_id)        REFERENCES choferes(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Remitos incluidos en cada entrega
CREATE TABLE IF NOT EXISTS entrega_remitos (
  entrega_id INT UNSIGNED NOT NULL,
  remito_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (entrega_id, remito_id),
  FOREIGN KEY (entrega_id) REFERENCES entregas(id)  ON DELETE CASCADE,
  FOREIGN KEY (remito_id)  REFERENCES remitos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
