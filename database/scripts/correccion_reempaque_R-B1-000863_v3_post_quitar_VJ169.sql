-- ============================================================================
-- CORRECCIÓN v3 — Adaptada al dump 65 DESPUÉS de quitar la carga del VJ-169
-- ============================================================================
-- PRE-REQUISITO: ya quitaste desde la UI de Filament la carga de 24 cart
-- prod 22 (Opoa Pequeño 1x15) del viaje VJ-000169. Los 24 cart deben haber
-- vuelto a bodega → BP prod 22 stock = 118 (no 94).
--
-- Saldos al ejecutar este script:
--   Lote 2 (Pequeño):  rem=854   fact=33,239   costo=72,150.00
--   Lote 3 (Mediano):  rem=15,316 fact=606,271  costo=1,565,440.00
--   BP prod 22:        stock=118 (después de quitar la carga del VJ-169)
--   BP prod 19:        stock=0
--
-- Saldos esperados DESPUÉS del COMMIT:
--   Lote 2: rem=3,734  fact=30,359  costo=65,910.00      → 124.5 cart
--   Lote 3: rem=14,206 fact=607,741 costo=1,568,495.00   → 473.5 cart
--   BP prod 22: 0  |  BP prod 19: 0
--
-- Tus objetivos físicos:
--   Lote Grande: 164 cart  (sistema final: 168 cart, diff -4 = rotura)
--   Lote Mediano: 466.5 cart (sistema final: 473.5 cart, diff -7 = rotura)
--   Lote Pequeño: 110 cart   (sistema final: 124.5 cart, diff -14.5)
-- ============================================================================

START TRANSACTION;

SELECT id FROM lotes WHERE id IN (2, 3) FOR UPDATE;

-- ─── REEMPAQUE — corregir lote y producto ─────────────────────────────────
UPDATE reempaque_lotes
SET lote_id = 3, updated_at = NOW()
WHERE id = 873 AND reempaque_id = 867;

UPDATE reempaque_productos
SET producto_id = 19, categoria_id = 18, updated_at = NOW()
WHERE id = 932 AND reempaque_id = 867;

-- ─── VIAJE 166 — carga, descarga, 3 ventas pasan a prod 19 ────────────────
UPDATE viaje_cargas
SET producto_id = 19, updated_at = NOW()
WHERE id = 1325 AND viaje_id = 166;

UPDATE viaje_descargas
SET producto_id = 19, updated_at = NOW()
WHERE id = 950 AND viaje_id = 166;

UPDATE viaje_venta_detalles
SET producto_id = 19, updated_at = NOW()
WHERE id IN (3487, 3494, 3500);

-- ─── LOTE 2 (Pequeño) — revertir el reempaque erróneo ─────────────────────
-- 854 + 2880 = 3734
-- 33239 - 2880 = 30359
-- 72150 - 6240 = 65910
UPDATE lotes
SET cantidad_huevos_remanente    = cantidad_huevos_remanente + 2880,
    huevos_facturados_acumulados = huevos_facturados_acumulados - 2880,
    costo_total_acumulado        = costo_total_acumulado - 6240.00,
    estado                       = 'disponible',
    updated_at                   = NOW()
WHERE id = 2;

-- ─── LOTE 3 (Mediano) — entra reempaque + 118 cart 1x15 que están en bodega
-- 118 cart × 15 huevos = 1770 huevos vuelven al lote
-- 1770 × 2.1667 = 3835 L de costo asociado
-- remanente: 15316 - 2880 + 1770 = 14206
-- facturados: 606271 + 2880 - 1770 = 607381
-- costo_total: 1565440 + 6240 - 3835 = 1567845
UPDATE lotes
SET cantidad_huevos_remanente    = cantidad_huevos_remanente - 2880 + 1770,
    huevos_facturados_acumulados = huevos_facturados_acumulados + 2880 - 1770,
    costo_total_acumulado        = costo_total_acumulado + 6240.00 - 3835.00,
    updated_at                   = NOW()
WHERE id = 3;

-- ─── BODEGA_PRODUCTO — eliminar los 118 cart fantasma ─────────────────────
UPDATE bodega_producto
SET stock = 0, updated_at = NOW()
WHERE producto_id = 22 AND bodega_id = 1;

UPDATE bodega_producto
SET stock = 0, updated_at = NOW()
WHERE producto_id = 19 AND bodega_id = 1;

-- ─── VERIFICACIÓN PRE-COMMIT (todos los diff deben dar 0) ─────────────────

SELECT id, numero_lote,
  cantidad_huevos_remanente - CASE id WHEN 2 THEN 3734 WHEN 3 THEN 14206 END AS diff_rem,
  huevos_facturados_acumulados - CASE id WHEN 2 THEN 30359 WHEN 3 THEN 607381 END AS diff_fact,
  costo_total_acumulado - CASE id WHEN 2 THEN 65910.00 WHEN 3 THEN 1567845.00 END AS diff_costo
FROM lotes WHERE id IN (2, 3);

SELECT producto_id, stock FROM bodega_producto WHERE producto_id IN (19, 22) AND bodega_id = 1;
-- Esperado: ambos en 0

SELECT rl.lote_id, rp.producto_id, rp.categoria_id
FROM reempaque_lotes rl JOIN reempaque_productos rp ON rp.reempaque_id = rl.reempaque_id
WHERE rl.reempaque_id = 867;
-- Esperado: lote_id=3, producto_id=19, categoria_id=18

SELECT 'carga_v166' AS t, producto_id FROM viaje_cargas WHERE id = 1325
UNION ALL SELECT 'descarga_v166', producto_id FROM viaje_descargas WHERE id = 950
UNION ALL SELECT 'venta_3487', producto_id FROM viaje_venta_detalles WHERE id = 3487
UNION ALL SELECT 'venta_3494', producto_id FROM viaje_venta_detalles WHERE id = 3494
UNION ALL SELECT 'venta_3500', producto_id FROM viaje_venta_detalles WHERE id = 3500;
-- Esperado: TODOS con producto_id=19

-- Si todo cuadra:
COMMIT;
-- Si algo falla: ROLLBACK;
-- ============================================================================
