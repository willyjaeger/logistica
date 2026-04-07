-- ============================================================
-- TABLA DE USUARIOS DEL SISTEMA
-- Ejecutar en phpMyAdmin sobre po000380_logist
-- ============================================================

CREATE TABLE IF NOT EXISTS usuarios (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id  INT UNSIGNED NOT NULL,
  nombre      VARCHAR(100) NOT NULL          COMMENT 'Nombre completo para mostrar',
  usuario     VARCHAR(50)  NOT NULL UNIQUE   COMMENT 'Username para login',
  password    VARCHAR(255) NOT NULL          COMMENT 'Hash bcrypt',
  rol         ENUM('admin','operador') NOT NULL DEFAULT 'operador',
  activo      TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- USUARIO ADMINISTRADOR INICIAL
-- Usuario: admin
-- Password: logistica2024   ← CAMBIAR DESPUÉS DEL PRIMER LOGIN
-- ============================================================
INSERT INTO usuarios (empresa_id, nombre, usuario, password, rol)
VALUES (
  1,
  'Administrador',
  'admin',
  '$2y$12$8K1p/a0dL.EYFqHLpRFymOkKtNO.dBBZ0wfqoilFH3RpC4N8kGvXi',  -- logistica2024
  'admin'
);

-- ============================================================
-- Para generar un nuevo hash desde PHP:
--   echo password_hash('tu_nueva_clave', PASSWORD_BCRYPT);
-- ============================================================
