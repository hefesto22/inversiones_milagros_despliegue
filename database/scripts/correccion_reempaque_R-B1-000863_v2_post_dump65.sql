-- ============================================================================
-- CORRECCIÓN v2 — Adaptada al estado del dump 65 (21-may 02:59)
-- ============================================================================
-- Cambios respecto a v1:
--   - Saldos de lotes ya son distintos (hubo más operaciones encima)
--   - Bodega prod 22 ahora tiene 94 cart (no 118) — 24 ya se cargaron al VJ-169
--   - VJ-169 (en ruta) lleva una carga fantasma de 24 cart prod 22 que debe
--     corregirse también a prod 19 (físicamente Opoa Mediano 1x15)
--
-- Resultado esperado: sin productos fantasma en bodega ni en cargas activas.
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

-- ─── NUEVO: VIAJE 169 (en ruta) — carga fantasma de 24 cart prod 22 ───────
-- Físicamente esos 24 cart son Opoa Mediano 1x15 (prod 19), no Opoa Pequeño 1x15
UPDATE viaje_cargas
SET producto_id = 19, updated_at = NOW()
WHERE id = 1351 AND viaje_id = 169;

-- ─── LOTE 2 (Pequeño) — revertir el reempaque erróneo ─────────────────────
-- 854 + 2880 = 3734  |  33239 - 2880 = 30359  |  72150 - 6240 = 65910
UPDATE lotes
SET cantidad_huevos_remanente    = cantidad_huevos_remanente + 2880,
    huevos_facturados_acumulados = huevos_facturados_acumulados - 2880,
    costo_total_acumulado        = costo_total_acumulado - 6240.00,
    estado                       = 'disponible',
    updated_at                   = NOW()
WHERE id = 2;

-- ─── LOTE 3 (Mediano) — entra reempaque + 94 cart 1x15 que quedan en bodega
-- (no son 118 cart como en v1 porque 24 ya se cargaron al VJ-169 corregido)
-- 94 cart × 15 huevos = 1410 huevos | 1410 × 2.1667 = 3055 L
-- remanente: 15316 - 2880 + 1410 = 13846
-- facturados: 606271 + 2880 - 1410 = 607741
-- costo_total: 1565440 + 6240 - 3055 = 1568625
UPDATE lotes
SET cantidad_huevos_remanente    = cantidad_huevos_remanente - 2880 + 1410,
    huevos_facturados_acumulados = huevos_facturados_acumulados + 2880 - 1410,
    costo_total_acumulado        = costo_total_acumulado + 6240.00 - 3055.00,
    updated_at                   = NOW()
WHERE id = 3;

-- ─── BODEGA_PRODUCTO — eliminar los 94 cart fantasma ──────────────────────
UPDATE bodega_producto
SET stock = 0, updated_at = NOW()
WHERE producto_id = 22 AND bodega_id = 1;

-- prod 19 ya está en 0, se asegura
UPDATE bodega_producto
SET stock = 0, updated_at = NOW()
WHERE producto_id = 19 AND bodega_id = 1;

-- ─── VERIFICACIÓN PRE-COMMIT (todos los diff deben dar 0) ─────────────────

SELECT id, numero_lote,
  cantidad_huevos_remanente - CASE id WHEN 2 THEN 3734 WHEN 3 THEN 13846 END AS diff_rem,
  huevos_facturados_acumulados - CASE id WHEN 2 THEN 30359 WHEN 3 THEN 607741 END AS diff_fact,
  costo_total_acumulado - CASE id WHEN 2 THEN 65910.00 WHEN 3 THEN 1568625.00 END AS diff_costo
FROM lotes WHERE id IN (2, 3);

SELECT producto_id, stock FROM bodega_producto WHERE producto_id IN (19, 22) AND bodega_id = 1;

SELECT rl.lote_id, rp.producto_id, rp.categoria_id
FROM reempaque_lotes rl JOIN reempaque_productos rp ON rp.reempaque_id = rl.reempaque_id
WHERE rl.reempaque_id = 867;

SELECT 'carga_v166' AS t, producto_id FROM viaje_cargas WHERE id = 1325
UNION ALL SELECT 'descarga_v166', producto_id FROM viaje_descargas WHERE id = 950
UNION ALL SELECT 'venta_3487', producto_id FROM viaje_venta_detalles WHERE id = 3487
UNION ALL SELECT 'venta_3494', producto_id FROM viaje_venta_detalles WHERE id = 3494
UNION ALL SELECT 'venta_3500', producto_id FROM viaje_venta_detalles WHERE id = 3500
UNION ALL SELECT 'carga_v169_NUEVO', producto_id FROM viaje_cargas WHERE id = 1351;

-- Si TODOS los diff son 0, ambos stock son 0, reempaque lote=3 prod=19 cat=18,
-- y TODOS los producto_id son 19 → ejecutá:

COMMIT;

-- Si algo no cuadra → ROLLBACK;
-- ============================================================================
