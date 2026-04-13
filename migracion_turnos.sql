-- ============================================================
-- MIGRACIÓN: Módulo Turnos / Agenda (versión 2)
-- Ejecutar en phpMyAdmin ANTES de subir los archivos
-- ============================================================

-- 1. Limpiar tablas anteriores (si se había corrido migracion_agenda.sql)
DROP TABLE IF EXISTS viaje_paradas;
DROP TABLE IF EXISTS viajes_programados;

-- 2. Tabla principal de turnos
CREATE TABLE IF NOT EXISTS turnos (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id       INT UNSIGNED NOT NULL,
  fecha            DATE         NOT NULL,
  tipo             ENUM('turno','programado') NOT NULL DEFAULT 'programado',
  cliente_id       INT UNSIGNED NULL,
  remito_id        INT UNSIGNED NULL,
  pallets_est      DECIMAL(8,2) NULL,
  hora_turno       TIME         NULL,
  transportista_id INT UNSIGNED NULL,
  camion_id        INT UNSIGNED NULL,
  chofer_id        INT UNSIGNED NULL,
  estado           ENUM('pendiente','en_camino','entregado','cancelado') NOT NULL DEFAULT 'pendiente',
  observaciones    TEXT         NULL,
  creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id)       REFERENCES empresas(id),
  FOREIGN KEY (cliente_id)       REFERENCES clientes(id)       ON DELETE SET NULL,
  FOREIGN KEY (remito_id)        REFERENCES remitos(id)        ON DELETE SET NULL,
  FOREIGN KEY (transportista_id) REFERENCES transportistas(id) ON DELETE SET NULL,
  FOREIGN KEY (camion_id)        REFERENCES camiones(id)       ON DELETE SET NULL,
  FOREIGN KEY (chofer_id)        REFERENCES choferes(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Nuevos estados en remitos (incluido por si no se corrió migracion_agenda.sql)
ALTER TABLE remitos
  MODIFY COLUMN estado
  ENUM('pendiente','parcialmente_entregado','entregado','en_stock','turnado','programado','en_camino')
  NOT NULL DEFAULT 'pendiente';
