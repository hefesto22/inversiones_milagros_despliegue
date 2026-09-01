-- ============================================================================
-- Comisiones: se elimina la tarifa reducida  (2026-09-01)
-- ============================================================================
-- Regla nueva: al chofer se le paga la MISMA tarifa venda al precio sugerido
-- o por debajo. El calculo ya ignora chofer_comision_config.comision_reducida
-- (ver Viaje::calcularComisionDetalleRuta), este script solo deja los DATOS
-- coherentes con lo que se paga y llena las categorias que nunca se
-- configuraron.
--
-- Orden de ejecucion:
--   1. Este script
--   2. php artisan comisiones:recalcular --desde=2026-08-01 --hasta=2026-08-31
--      (primero sin --aplicar para revisar, despues con --aplicar)
--
-- Correr PRIMERO en local contra la copia de produccion, verificar el PDF del
-- Estado de Resultados, y solo entonces llevarlo al servidor.
-- ============================================================================

-- --- Antes: foto de lo que se va a tocar ------------------------------------
-- SELECT u.name, c.categoria_id, cat.nombre, c.comision_normal, c.comision_reducida
-- FROM chofer_comision_config c
-- JOIN users u ON u.id = c.user_id
-- JOIN categorias cat ON cat.id = c.categoria_id
-- WHERE c.comision_reducida <> c.comision_normal
-- ORDER BY u.name, cat.nombre;


-- --- 1. La columna reducida queda en espejo de la normal ---------------------
-- Se conserva la columna (hay historico y el enum tipo_comision sigue vivo),
-- pero deja de contradecir lo que realmente se paga.
UPDATE chofer_comision_config
SET comision_reducida = comision_normal,
    updated_at = NOW()
WHERE comision_reducida <> comision_normal;

UPDATE chofer_comision_producto
SET comision_reducida = comision_normal,
    updated_at = NOW()
WHERE comision_reducida <> comision_normal;


-- --- 2. Categorias que nunca tuvieron tarifa --------------------------------
-- Sin fila en chofer_comision_config la venta NO genera ni renglon de comision
-- (Viaje::calcularComisionDetalleRuta corta con `if normal <= 0 return`).
-- Se copia el patron que ya siguen las demas categorias del mismo tipo:
--   Opoa Huevo Pewe (34)      -> L 1.50, igual que las demas Opoa Huevo y Huevo Pewe
--   Huevo Marron Mediano (15) -> L 1.50, igual que la config de Jose
--   Quesillo (20)             -> L 0.80, igual que la config de Jose
-- vigente_desde = 2026-08-01 porque agosto se recalcula con estas tarifas.
INSERT INTO chofer_comision_config
    (user_id, categoria_id, unidad_id, tipo_comision, comision_normal, comision_reducida,
     vigente_desde, vigente_hasta, activo, created_by, created_at, updated_at)
SELECT * FROM (
    SELECT 4 AS user_id, 34 AS categoria_id, NULL AS unidad_id, 'fijo' AS tipo_comision,
           1.50 AS comision_normal, 1.50 AS comision_reducida,
           '2026-08-01' AS vigente_desde, NULL AS vigente_hasta, 1 AS activo,
           2 AS created_by, NOW() AS created_at, NOW() AS updated_at
    UNION ALL SELECT 6, 34, NULL, 'fijo', 1.50, 1.50, '2026-08-01', NULL, 1, 2, NOW(), NOW()
    UNION ALL SELECT 6, 15, NULL, 'fijo', 1.50, 1.50, '2026-08-01', NULL, 1, 2, NOW(), NOW()
    UNION ALL SELECT 6, 20, NULL, 'fijo', 0.80, 0.80, '2026-08-01', NULL, 1, 2, NOW(), NOW()
) AS nuevas
WHERE NOT EXISTS (
    SELECT 1 FROM chofer_comision_config c
    WHERE c.user_id = nuevas.user_id
      AND c.categoria_id = nuevas.categoria_id
);


-- --- 3. Verificacion --------------------------------------------------------
-- No debe devolver ninguna fila:
-- SELECT * FROM chofer_comision_config WHERE comision_reducida <> comision_normal;
--
-- Debe devolver 4 filas (las nuevas):
-- SELECT u.name, cat.nombre, c.comision_normal, c.vigente_desde
-- FROM chofer_comision_config c
-- JOIN users u ON u.id = c.user_id
-- JOIN categorias cat ON cat.id = c.categoria_id
-- WHERE c.categoria_id IN (15, 20, 34)
-- ORDER BY u.name, cat.nombre;
