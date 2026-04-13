-- ============================================================
-- MIGRACIÓN: Agenda / Viajes programados
-- Ejecutar en phpMyAdmin antes de subir los archivos
-- ============================================================

-- 1. Viajes programados (un camión en una fecha)
CREATE TABLE IF NOT EXISTS viajes_programados (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id       INT UNSIGNED NOT NULL,
  fecha            DATE         NOT NULL,
  tipo             ENUM('turno','programado') NOT NULL DEFAULT 'programado',
  transportista_id INT UNSIGNED NULL,
  camion_id        INT UNSIGNED NULL,
  chofer_id        INT UNSIGNED NULL,
  estado           ENUM('pendiente','en_camino','completado','cancelado') NOT NULL DEFAULT 'pendiente',
  observaciones    TEXT NULL,
  creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id)       REFERENCES empresas(id),
  FOREIGN KEY (transportista_id) REFERENCES transportistas(id) ON DELETE SET NULL,
  FOREIGN KEY (camion_id)        REFERENCES camiones(id)       ON DELETE SET NULL,
  FOREIGN KEY (chofer_id)        REFERENCES choferes(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Paradas dentro de cada viaje
CREATE TABLE IF NOT EXISTS viaje_paradas (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  viaje_id      INT UNSIGNED NOT NULL,
  cliente_id    INT UNSIGNED NULL,
  remito_id     INT UNSIGNED NULL,
  hora_turno    TIME         NULL,
  orden         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  observaciones VARCHAR(255) NULL,
  FOREIGN KEY (viaje_id)   REFERENCES viajes_programados(id) ON DELETE CASCADE,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id)           ON DELETE SET NULL,
  FOREIGN KEY (remito_id)  REFERENCES remitos(id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Nuevos estados en remitos
ALTER TABLE remitos
  MODIFY COLUMN estado
  ENUM('pendiente','parcialmente_entregado','entregado','en_stock','turnado','programado','en_camino')
  NOT NULL DEFAULT 'pendiente';
