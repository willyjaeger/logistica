-- ============================================================
-- ALTER: Agregar columnas faltantes para módulo Entregas v2
-- Ejecutar en phpMyAdmin ANTES de subir los archivos PHP
-- ============================================================

-- Agregar transportista_id a camiones
ALTER TABLE camiones
  ADD COLUMN transportista_id INT UNSIGNED NULL AFTER empresa_id,
  ADD CONSTRAINT fk_cam_trans FOREIGN KEY (transportista_id) REFERENCES transportistas(id) ON DELETE SET NULL;

-- Agregar transportista_id a choferes
ALTER TABLE choferes
  ADD COLUMN transportista_id INT UNSIGNED NULL AFTER empresa_id,
  ADD CONSTRAINT fk_cho_trans FOREIGN KEY (transportista_id) REFERENCES transportistas(id) ON DELETE SET NULL;

-- Agregar transportista_id y fecha a entregas
ALTER TABLE entregas
  ADD COLUMN transportista_id INT UNSIGNED NULL AFTER empresa_id,
  ADD COLUMN fecha DATE NULL AFTER transportista_id,
  ADD CONSTRAINT fk_ent_trans FOREIGN KEY (transportista_id) REFERENCES transportistas(id) ON DELETE SET NULL;
