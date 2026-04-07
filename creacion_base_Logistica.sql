-- ============================================================
-- SISTEMA DE LOGÍSTICA - BASE DE DATOS MULTIEMPRESA
-- Ejecutar en phpMyAdmin sobre una base de datos vacía
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "-03:00"; -- Argentina (ART)

-- ------------------------------------------------------------
-- EMPRESAS (multiempresa: cada registro es una empresa cliente)
-- ------------------------------------------------------------
CREATE TABLE empresas (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre      VARCHAR(100) NOT NULL,
  cuit        VARCHAR(20),
  direccion   VARCHAR(200),
  telefono    VARCHAR(30),
  email       VARCHAR(100),
  activa      TINYINT(1) NOT NULL DEFAULT 1,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- CLIENTES (por empresa)
-- ------------------------------------------------------------
CREATE TABLE clientes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id  INT UNSIGNED NOT NULL,
  nombre      VARCHAR(100) NOT NULL,
  direccion   VARCHAR(200),
  localidad   VARCHAR(100),
  telefono    VARCHAR(30),
  email       VARCHAR(100),
  activo      TINYINT(1) NOT NULL DEFAULT 1,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PROVEEDORES (quienes envían la mercadería)
-- ------------------------------------------------------------
CREATE TABLE proveedores (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id  INT UNSIGNED NOT NULL,
  nombre      VARCHAR(100) NOT NULL,
  cuit        VARCHAR(20),
  activo      TINYINT(1) NOT NULL DEFAULT 1,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- CHOFERES (por empresa)
-- ------------------------------------------------------------
CREATE TABLE choferes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id  INT UNSIGNED NOT NULL,
  nombre      VARCHAR(100) NOT NULL,
  dni         VARCHAR(15),
  licencia    VARCHAR(30),
  telefono    VARCHAR(30),
  activo      TINYINT(1) NOT NULL DEFAULT 1,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- CAMIONES (por empresa)
-- ------------------------------------------------------------
CREATE TABLE camiones (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id  INT UNSIGNED NOT NULL,
  patente     VARCHAR(15) NOT NULL,
  marca       VARCHAR(50),
  modelo      VARCHAR(50),
  tipo        ENUM('camion','camioneta','furgon','otro') NOT NULL DEFAULT 'camion',
  activo      TINYINT(1) NOT NULL DEFAULT 1,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- INGRESOS (cada vez que llega un vehículo con mercadería)
-- ------------------------------------------------------------
CREATE TABLE ingresos (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id          INT UNSIGNED NOT NULL,
  fecha_ingreso       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  transportista       VARCHAR(100)  COMMENT 'Empresa de transporte que trajo la carga',
  patente_camion_ext  VARCHAR(15)   COMMENT 'Patente del camión externo que trajo la carga',
  chofer_externo      VARCHAR(100),
  observaciones       TEXT,
  creado_en           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- REMITOS (un ingreso puede traer N remitos)
-- ------------------------------------------------------------
CREATE TABLE remitos (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ingreso_id              INT UNSIGNED NOT NULL,
  empresa_id              INT UNSIGNED NOT NULL,
  nro_remito_propio       VARCHAR(30) NOT NULL  COMMENT 'Número interno asignado por la empresa logística',
  nro_remito_proveedor    VARCHAR(50)           COMMENT 'Número de remito original del proveedor',
  proveedor_id            INT UNSIGNED,
  cliente_id              INT UNSIGNED NOT NULL,
  fecha_remito            DATE,
  estado                  ENUM('pendiente','parcialmente_entregado','entregado','en_stock') NOT NULL DEFAULT 'pendiente',
  observaciones           TEXT,
  creado_en               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ingreso_id)   REFERENCES ingresos(id),
  FOREIGN KEY (empresa_id)   REFERENCES empresas(id),
  FOREIGN KEY (proveedor_id) REFERENCES proveedores(id),
  FOREIGN KEY (cliente_id)   REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Índice para buscar por número de remito rápidamente
CREATE INDEX idx_remitos_nro_propio      ON remitos(nro_remito_propio);
CREATE INDEX idx_remitos_nro_proveedor   ON remitos(nro_remito_proveedor);
CREATE INDEX idx_remitos_estado          ON remitos(estado);

-- ------------------------------------------------------------
-- ÍTEMS DE REMITO (detalle de cada remito)
-- ------------------------------------------------------------
CREATE TABLE remito_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  remito_id       INT UNSIGNED NOT NULL,
  descripcion     VARCHAR(200) NOT NULL,
  cantidad        DECIMAL(10,2) NOT NULL,
  cantidad_entregada DECIMAL(10,2) NOT NULL DEFAULT 0,
  cantidad_stock  DECIMAL(10,2) NOT NULL DEFAULT 0,
  unidad          VARCHAR(20) DEFAULT 'unidad'  COMMENT 'unidad, caja, pallet, kg, etc.',
  estado          ENUM('pendiente','entregado','en_stock','parcial') NOT NULL DEFAULT 'pendiente',
  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (remito_id) REFERENCES remitos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ENTREGAS (viaje de reparto: 1 camión, 1 chofer, N ítems)
-- ------------------------------------------------------------
CREATE TABLE entregas (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id      INT UNSIGNED NOT NULL,
  camion_id       INT UNSIGNED NOT NULL,
  chofer_id       INT UNSIGNED NOT NULL,
  fecha_salida    DATETIME,
  fecha_llegada   DATETIME,
  estado          ENUM('armando','en_camino','completada','con_incidencias') NOT NULL DEFAULT 'armando',
  observaciones   TEXT,
  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  FOREIGN KEY (camion_id)  REFERENCES camiones(id),
  FOREIGN KEY (chofer_id)  REFERENCES choferes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_entregas_fecha ON entregas(fecha_salida);

-- ------------------------------------------------------------
-- ÍTEMS DE ENTREGA (qué se cargó en cada viaje)
-- ------------------------------------------------------------
CREATE TABLE entrega_items (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entrega_id          INT UNSIGNED NOT NULL,
  remito_item_id      INT UNSIGNED NOT NULL,
  cantidad_enviada    DECIMAL(10,2) NOT NULL,
  cantidad_entregada  DECIMAL(10,2) NOT NULL DEFAULT 0,
  estado              ENUM('cargado','entregado','rechazado','parcial') NOT NULL DEFAULT 'cargado',
  motivo_rechazo      VARCHAR(200)   COMMENT 'Si el cliente no recibió, ¿por qué?',
  observaciones       TEXT,
  creado_en           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (entrega_id)     REFERENCES entregas(id),
  FOREIGN KEY (remito_item_id) REFERENCES remito_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- STOCK (mercadería que queda en depósito)
-- Tipo: 'seguridad' = lo dejaron a propósito, 'rechazo' = el cliente no lo recibió
-- ------------------------------------------------------------
CREATE TABLE stock (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id          INT UNSIGNED NOT NULL,
  remito_item_id      INT UNSIGNED NOT NULL,
  cantidad            DECIMAL(10,2) NOT NULL,
  tipo                ENUM('seguridad','rechazo','devolucion','sobrante') NOT NULL DEFAULT 'rechazo',
  motivo              TEXT,
  entrega_item_id     INT UNSIGNED   COMMENT 'Si vino de un rechazo en entrega',
  fecha_ingreso_stock DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_salida_stock  DATETIME         COMMENT 'Cuando se retira del stock',
  estado              ENUM('disponible','reservado','retirado') NOT NULL DEFAULT 'disponible',
  FOREIGN KEY (empresa_id)      REFERENCES empresas(id),
  FOREIGN KEY (remito_item_id)  REFERENCES remito_items(id),
  FOREIGN KEY (entrega_item_id) REFERENCES entrega_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- VISTA: Detalle de entregas por camión (para facturar)
-- Muestra cuántos camiones se usaron por período
-- ------------------------------------------------------------
CREATE VIEW v_detalle_entregas_por_camion AS
SELECT
  e.empresa_id,
  emp.nombre                          AS empresa,
  en.id                               AS entrega_id,
  c.patente                           AS camion,
  c.tipo                              AS tipo_camion,
  ch.nombre                           AS chofer,
  en.fecha_salida,
  en.fecha_llegada,
  TIMESTAMPDIFF(MINUTE, en.fecha_salida, en.fecha_llegada) AS minutos_viaje,
  en.estado                           AS estado_entrega,
  COUNT(ei.id)                        AS total_items_cargados,
  SUM(CASE WHEN ei.estado = 'entregado' THEN 1 ELSE 0 END) AS items_entregados,
  SUM(CASE WHEN ei.estado = 'rechazado' THEN 1 ELSE 0 END) AS items_rechazados,
  GROUP_CONCAT(DISTINCT cli.nombre ORDER BY cli.nombre SEPARATOR ', ') AS clientes_atendidos
FROM entregas en
JOIN empresas  emp ON en.empresa_id = emp.id
JOIN camiones  c   ON en.camion_id  = c.id
JOIN choferes  ch  ON en.chofer_id  = ch.id
JOIN entrega_items ei ON ei.entrega_id = en.id
JOIN remito_items ri  ON ei.remito_item_id = ri.id
JOIN remitos r        ON ri.remito_id = r.id
JOIN clientes cli     ON r.cliente_id = cli.id
JOIN empresas e       ON en.empresa_id = e.id
GROUP BY en.id;

-- ------------------------------------------------------------
-- VISTA: Stock actual disponible
-- ------------------------------------------------------------
CREATE VIEW v_stock_actual AS
SELECT
  s.empresa_id,
  emp.nombre              AS empresa,
  s.id                    AS stock_id,
  ri.descripcion          AS producto,
  r.nro_remito_propio     AS remito,
  cli.nombre              AS cliente_destino,
  s.cantidad,
  s.tipo,
  s.motivo,
  s.estado,
  s.fecha_ingreso_stock
FROM stock s
JOIN empresas     emp ON s.empresa_id = emp.id
JOIN remito_items ri  ON s.remito_item_id = ri.id
JOIN remitos      r   ON ri.remito_id = r.id
JOIN clientes     cli ON r.cliente_id = cli.id
WHERE s.estado = 'disponible';

-- ------------------------------------------------------------
-- VISTA: Remitos pendientes de entrega
-- ------------------------------------------------------------
CREATE VIEW v_remitos_pendientes AS
SELECT
  r.empresa_id,
  emp.nombre              AS empresa,
  r.id                    AS remito_id,
  r.nro_remito_propio,
  r.nro_remito_proveedor,
  prov.nombre             AS proveedor,
  cli.nombre              AS cliente,
  cli.direccion           AS direccion_entrega,
  i.fecha_ingreso,
  r.estado,
  COUNT(ri.id)            AS total_items,
  SUM(ri.cantidad)        AS cantidad_total
FROM remitos r
JOIN empresas     emp  ON r.empresa_id  = emp.id
JOIN ingresos     i    ON r.ingreso_id  = i.id
JOIN clientes     cli  ON r.cliente_id  = cli.id
LEFT JOIN proveedores prov ON r.proveedor_id = prov.id
LEFT JOIN remito_items ri  ON ri.remito_id = r.id
WHERE r.estado IN ('pendiente', 'parcialmente_entregado')
GROUP BY r.id;

-- ------------------------------------------------------------
-- DATOS DE EJEMPLO (comentar si no los querés)
-- ------------------------------------------------------------
INSERT INTO empresas (nombre, cuit) VALUES
  ('Mi Empresa Logística S.R.L.', '30-12345678-9');

INSERT INTO clientes (empresa_id, nombre, direccion, localidad) VALUES
  (1, 'Supermercado El Sol', 'Av. San Martín 1200', 'San Miguel'),
  (1, 'Ferretería Central', 'Corrientes 450', 'Palermo');

INSERT INTO proveedores (empresa_id, nombre) VALUES
  (1, 'Distribuidora Norte S.A.'),
  (1, 'Importadora Sur');

INSERT INTO choferes (empresa_id, nombre, dni) VALUES
  (1, 'Carlos Pérez', '28.456.789'),
  (1, 'Ramón Rodríguez', '32.111.222');

INSERT INTO camiones (empresa_id, patente, marca, tipo) VALUES
  (1, 'AB 123 CD', 'Mercedes', 'camion'),
  (1, 'EF 456 GH', 'Ford', 'camioneta');

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIN DEL SCRIPT
-- ============================================================