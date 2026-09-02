-- ============================================================
-- Corrección de remitos/entregas que quedaron abiertos aunque
-- la fecha de la salida ya pasó.
--
-- Causas combinadas:
-- 1) entrega_dia_guardar.php marcaba 'programado' a todo remito
--    agregado a una entrega, incluso si esa entrega ya había
--    salido (en_camino). Ya corregido (commit 4065ec1).
-- 2) La transición automática diaria solo cerraba entregas que
--    estaban en 'en_camino', dejando afuera las que quedaron en
--    'armando' porque nadie hizo clic en "Confirmar salida" a
--    tiempo, aunque el viaje ya haya salido y la fecha ya pasó.
--    Ya corregido en config/auth.php (ahora también contempla
--    'armando').
--
-- Este script repara los datos históricos afectados por ambas
-- causas. Es seguro correrlo más de una vez (solo toca filas
-- vencidas: fecha < hoy).
-- ============================================================

-- 1) Ver qué entregas se van a cerrar (armando/en_camino, fecha ya pasada)
SELECT id, fecha, estado
FROM entregas
WHERE estado IN ('armando','en_camino')
  AND fecha < CURDATE();

-- 2) Cerrar esas entregas
UPDATE entregas
SET estado = 'completada'
WHERE estado IN ('armando','en_camino')
  AND fecha < CURDATE();

-- 3) Pasar a 'entregado' los remitos de cualquier entrega ya cerrada
--    (incluye las recién cerradas en el paso 2 y cualquier otra
--    que ya estuviera cerrada pero con remitos sin actualizar)
UPDATE remitos r
JOIN entrega_remitos er ON er.remito_id = r.id
JOIN entregas en ON en.id = er.entrega_id
SET r.estado = 'entregado'
WHERE r.estado IN ('programado','en_camino')
  AND en.estado IN ('completada','entregado','con_incidencias')
  AND (er.resultado = 'entregado' OR er.resultado IS NULL);

-- 4) Alinear turnos asociados
UPDATE turnos t
JOIN remitos r ON r.id = t.remito_id
SET t.estado = 'entregado'
WHERE r.estado = 'entregado'
  AND t.estado IN ('pendiente','en_camino');
