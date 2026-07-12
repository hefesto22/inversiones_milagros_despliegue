-- ============================================================================
-- CORRECCIÓN DE ERROR DE CAPTURA — Reempaque R-B1-000863
-- Fecha: 2026-05-20
-- Autorizado por: Mauricio (dueño)
-- ============================================================================
--
-- MOTIVO:
--   En el viaje VJ-000166, el reempaque R-B1-000863 se registró por error
--   como produciendo "Opoa Huevo Pequeño 1x15" (prod 22) cuando físicamente
--   produjo "Opoa Huevo Mediano 1x15" (prod 19). Como consecuencia:
--   - Se consumió del Lote 2 (Pequeño) cuando debió consumir del Lote 3 (Mediano)
--   - Se cargaron, vendieron y devolvieron 192 cart del producto incorrecto
--
-- ALCANCE DE LA CORRECCIÓN:
--   - Reempaque, carga, descarga, 3 ventas en ruta → prod 22 cambia a prod 19
--   - Lote 2 (Pequeño): se le devuelven 2,880 huevos (96 cart)
--   - Lote 3 (Mediano): se le restan 2,880 huevos (96 cart) realmente consumidos
--   - Bodega_producto prod 22: stock 118 → 0
--   - Bodega_producto prod 19: stock 0 → 118
--
-- DECISIONES OPERATIVAS:
--   - Costos del reempaque se MANTIENEN en L 32.50/cart (L 6,240 total)
--     aunque viene del Mediano. No se recalculan los WAC.
--   - Las 3 facturas físicas que tienen los clientes dicen "Opoa Pequeño 1x15"
--     pero en sistema cambian a "Opoa Mediano 1x15" para que historial refleje
--     lo entregado. La diferencia de L 185 entre precios se asume como
--     descuento ya otorgado a los clientes (no se cobra).
--
-- IMPORTANTE — ANTES DE EJECUTAR:
--   1. Hacer respaldo completo (paso 1 abajo).
--   2. Verificar saldos previos (paso 2 abajo).
--   3. Ejecutar en transacción única (paso 3).
--   4. Verificar saldos post-cambio antes de COMMIT (paso 4).
--   5. Si todo cuadra: COMMIT. Si algo no cuadra: ROLLBACK.
-- ============================================================================

-- ============================================================================
-- PASO 1 — RESPALDO (ejecutar en terminal Linux, NO en MySQL):
-- ============================================================================
--   mysqldump -u <usuario> -p u304956828_despliegueopoa > respaldo_pre_correccion_R-B1-000863_2026-05-20.sql
--   → Verificar que el archivo se creó correctamente antes de continuar.

-- ============================================================================
-- PASO 2 — VERIFICACIÓN PREVIA (ejecutar y confirmar valores ANTES de seguir):
-- ============================================================================

-- 2.1 Estado actual de los lotes afectados
SELECT id, numero_lote, producto_id,
       cantidad_huevos_remanente,
       huevos_facturados_acumulados,
       costo_por_huevo
FROM lotes WHERE id IN (2, 3) ORDER BY id;
-- Esperado:
--   Lote 2 (Pequeño): remanente=1364, facturados_acumulados=33239
--   Lote 3 (Mediano): remanente=7156, facturados_acumulados=594181

-- 2.2 Estado actual de bodega_producto
SELECT producto_id, stock, costo_promedio_actual
FROM bodega_producto WHERE producto_id IN (19, 22) AND bodega_id = 1
ORDER BY producto_id;
-- Esperado:
--   prod 19 (Opoa Mediano 1x15): stock=0, costo=40.98
--   prod 22 (Opoa Pequeño 1x15): stock=118, costo=35.2434

-- 2.3 Reempaque y sus líneas (para tener IDs a la vista)
SELECT 'reempaque' AS tabla, id, NULL AS lote_id, NULL AS producto_id, NULL AS cantidad
FROM reempaques WHERE numero_reempaque='R-B1-000863'
UNION ALL
SELECT 'reempaque_lotes', rl.id, rl.lote_id, NULL, rl.cantidad_huevos_usados
FROM reempaque_lotes rl JOIN reempaques r ON r.id=rl.reempaque_id
WHERE r.numero_reempaque='R-B1-000863'
UNION ALL
SELECT 'reempaque_productos', rp.id, NULL, rp.producto_id, rp.cantidad
FROM reempaque_productos rp JOIN reempaques r ON r.id=rp.reempaque_id
WHERE r.numero_reempaque='R-B1-000863';
-- Esperado:
--   reempaque: id=867
--   reempaque_lotes: id=873, lote_id=2, huevos=2880
--   reempaque_productos: id=932, producto_id=22, cantidad=192

-- 2.4 Viaje_cargas y viaje_descargas del prod 22 en VJ-000166
SELECT 'viaje_carga' AS tabla, vc.id, vc.producto_id, vc.cantidad, vc.cantidad_vendida, vc.cantidad_devuelta
FROM viaje_cargas vc WHERE vc.id=1325
UNION ALL
SELECT 'viaje_descarga', vd.id, vd.producto_id, vd.cantidad, NULL, NULL
FROM viaje_descargas vd WHERE vd.id=950;
-- Esperado:
--   viaje_carga id=1325: producto_id=22, cant=192, vendida=74, devuelta=118
--   viaje_descarga id=950: producto_id=22, cant=118

-- 2.5 Ventas en ruta del prod 22 en VJ-000166 (3 ventas)
SELECT id, viaje_venta_id, producto_id, cantidad, precio_base, costo_unitario, subtotal
FROM viaje_venta_detalles
WHERE id IN (3487, 3494, 3500);
-- Esperado: 3 filas con producto_id=22, total cantidad = 24+4+46 = 74 cart

-- ============================================================================
-- PASO 3 — APLICAR CORRECCIÓN EN TRANSACCIÓN ÚNICA
-- ============================================================================

START TRANSACTION;

-- 3.1 Lock pesimista sobre lotes afectados (orden por ID para evitar deadlock)
SELECT id FROM lotes WHERE id IN (2, 3) FOR UPDATE;

-- 3.2 Corregir reempaque_lotes (id=873): el reempaque consumió del Mediano (lote 3), no del Pequeño (lote 2)
UPDATE reempaque_lotes
SET lote_id = 3, updated_at = NOW()
WHERE id = 873 AND reempaque_id = 867;
-- Esperado: 1 fila afectada

-- 3.3 Corregir reempaque_productos (id=932): producto de salida es Opoa Mediano 1x15 (prod 19), no Pequeño 1x15 (22)
UPDATE reempaque_productos
SET producto_id = 19, categoria_id = (SELECT categoria_id FROM productos WHERE id = 19), updated_at = NOW()
WHERE id = 932 AND reempaque_id = 867;
-- Esperado: 1 fila afectada

-- 3.4 Corregir viaje_cargas (id=1325): la carga es del producto correcto
-- Mantener costo_unitario=32.5 según decisión operativa (no recalcular)
UPDATE viaje_cargas
SET producto_id = 19,
    precio_venta_sugerido = (SELECT precio_sugerido FROM productos WHERE id = 19),
    updated_at = NOW()
WHERE id = 1325 AND viaje_id = 166;
-- Esperado: 1 fila afectada

-- 3.5 Corregir viaje_descargas (id=950)
UPDATE viaje_descargas
SET producto_id = 19, updated_at = NOW()
WHERE id = 950 AND viaje_id = 166;
-- Esperado: 1 fila afectada

-- 3.6 Corregir las 3 ventas en ruta (ids 3487, 3494, 3500)
-- Mantener precio_base=45 y costo_unitario=32.5 (no se cobra diferencia a clientes)
UPDATE viaje_venta_detalles
SET producto_id = 19, updated_at = NOW()
WHERE id IN (3487, 3494, 3500);
-- Esperado: 3 filas afectadas

-- 3.7 Ajustar Lote 2 (Pequeño): le vuelven los 2,880 huevos que no debieron salir
UPDATE lotes
SET cantidad_huevos_remanente = cantidad_huevos_remanente + 2880,
    huevos_facturados_acumulados = huevos_facturados_acumulados - 2880,
    estado = 'disponible',
    updated_at = NOW()
WHERE id = 2;
-- Esperado: Lote 2 remanente 1364 → 4244, facturados 33239 → 30359

-- 3.8 Ajustar Lote 3 (Mediano): se restan los 2,880 huevos que realmente salieron
UPDATE lotes
SET cantidad_huevos_remanente = cantidad_huevos_remanente - 2880,
    huevos_facturados_acumulados = huevos_facturados_acumulados + 2880,
    updated_at = NOW()
WHERE id = 3;
-- Esperado: Lote 3 remanente 7156 → 4276, facturados 594181 → 597061

-- 3.9 Ajustar bodega_producto prod 22 (no existe físicamente)
UPDATE bodega_producto
SET stock = 0, updated_at = NOW()
WHERE producto_id = 22 AND bodega_id = 1;
-- Esperado: stock 118 → 0

-- 3.10 Ajustar bodega_producto prod 19 (sí existe físicamente, 118 cart)
-- Mantener costo_promedio_actual sin tocar (decisión operativa de no recalcular costos)
UPDATE bodega_producto
SET stock = 118, updated_at = NOW()
WHERE producto_id = 19 AND bodega_id = 1;
-- Esperado: stock 0 → 118

-- ============================================================================
-- PASO 4 — VERIFICACIÓN ANTES DE COMMIT (ejecutar y revisar TODOS los valores):
-- ============================================================================

-- 4.1 Lotes después del ajuste
SELECT id, numero_lote, cantidad_huevos_remanente,
       CASE id
         WHEN 2 THEN 4244
         WHEN 3 THEN 4276
       END AS remanente_esperado,
       cantidad_huevos_remanente - CASE id
         WHEN 2 THEN 4244
         WHEN 3 THEN 4276
       END AS diff_lote
FROM lotes WHERE id IN (2, 3);
-- Esperado: diff_lote = 0 en ambas filas

-- 4.2 Bodega_producto después del ajuste
SELECT producto_id, stock,
       CASE producto_id WHEN 19 THEN 118 WHEN 22 THEN 0 END AS stock_esperado,
       stock - CASE producto_id WHEN 19 THEN 118 WHEN 22 THEN 0 END AS diff_bp
FROM bodega_producto WHERE producto_id IN (19, 22) AND bodega_id = 1;
-- Esperado: diff_bp = 0 en ambas filas

-- 4.3 Reempaque corregido
SELECT rl.lote_id AS lote_corregido, rp.producto_id AS prod_corregido
FROM reempaque_lotes rl
JOIN reempaque_productos rp ON rp.reempaque_id = rl.reempaque_id
WHERE rl.reempaque_id = 867;
-- Esperado: lote_corregido=3, prod_corregido=19

-- 4.4 Viaje carga, descarga y ventas corregidas
SELECT 'carga' AS t, producto_id FROM viaje_cargas WHERE id=1325
UNION ALL
SELECT 'descarga', producto_id FROM viaje_descargas WHERE id=950
UNION ALL
SELECT 'venta_3487', producto_id FROM viaje_venta_detalles WHERE id=3487
UNION ALL
SELECT 'venta_3494', producto_id FROM viaje_venta_detalles WHERE id=3494
UNION ALL
SELECT 'venta_3500', producto_id FROM viaje_venta_detalles WHERE id=3500;
-- Esperado: TODAS las filas con producto_id=19

-- ============================================================================
-- PASO 5 — CONFIRMAR O DESHACER
-- ============================================================================
--
-- SI TODOS LOS PASOS DE 4.x DIERON DIFERENCIA 0 Y PRODUCTO_ID=19 → ejecutar:
--   COMMIT;
--
-- SI ALGO NO CUADRA → ejecutar:
--   ROLLBACK;
--
-- DESPUÉS DE COMMIT NO HAY VUELTA ATRÁS sin el respaldo del Paso 1.
-- ============================================================================

-- COMMIT;  -- Descomentar SOLO cuando el Paso 4 esté 100% correcto.

-- ============================================================================
-- PASO 6 — VERIFICACIÓN FINAL (ejecutar después de COMMIT, opcional)
-- ============================================================================

-- 6.1 Saldos finales por familia (debe acercarse al físico del cliente)
SELECT 'PEQUEÑO_lote' AS rubro,
       ROUND(cantidad_huevos_remanente / 30, 2) AS cart
FROM lotes WHERE id = 2
UNION ALL SELECT 'PEQUEÑO_bp_prod22 (debe ser 0)',
       ROUND(COALESCE(stock, 0), 2)
FROM bodega_producto WHERE producto_id = 22 AND bodega_id = 1
UNION ALL SELECT 'MEDIANO_lote',
       ROUND(cantidad_huevos_remanente / 30, 2)
FROM lotes WHERE id = 3
UNION ALL SELECT 'MEDIANO_bp_prod19_1x15 (118 cart físicos)',
       ROUND(COALESCE(stock, 0), 2)
FROM bodega_producto WHERE producto_id = 19 AND bodega_id = 1
UNION ALL SELECT 'MEDIANO_total_equivalente_1x30',
       ROUND((SELECT cantidad_huevos_remanente FROM lotes WHERE id = 3) / 30
           + (SELECT COALESCE(stock,0) FROM bodega_producto WHERE producto_id = 19 AND bodega_id = 1) / 2, 2);
-- Esperado aproximado:
--   PEQUEÑO_lote: 141.47 cart
--   PEQUEÑO_bp_prod22: 0
--   MEDIANO_lote: 142.53 cart
--   MEDIANO_bp_prod19_1x15: 118 cart físicos (= 59 cart equiv 1x30)
--   MEDIANO_total_equivalente_1x30: ~201.53 cart

-- ============================================================================
-- COMPARACIÓN FINAL CON FÍSICO DEL CLIENTE
-- ============================================================================
-- Familia        | Sistema corregido    | Físico cliente | Diferencia
-- ---------------+----------------------+----------------+-----------
-- Extra Grande   | 22 cart              | 22 cart        | 0 ✓
-- Grande         | 260 cart             | 256 cart       | -4 cart  (probable rotura)
-- Mediano        | 201.5 cart equiv     | 198 cart       | -3.5 cart (probable rotura)
-- Pequeño        | 141.5 cart           | 139 cart       | -2.5 cart (probable rotura)
-- ============================================================================
-- Total faltante físico residual: ~10 cart (~300 huevos = 1.6% del inventario).
-- Esto es NORMAL en operación y se puede asentar como merma residual usando
-- el módulo de Ajuste de Inventario (Filament).
-- ============================================================================

-- FIN DEL SCRIPT
