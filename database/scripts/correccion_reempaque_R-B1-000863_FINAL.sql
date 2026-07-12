-- ============================================================================
-- CORRECCIÓN FINAL — Error de captura en reempaque R-B1-000863
-- Fecha: 2026-05-20
-- ============================================================================
-- Modelo aplicado:
--   - Revertir el reempaque erróneo del Lote Pequeño (le devuelven 2880 huevos)
--   - Atribuirlo al Lote Mediano (real origen físico, costo mantenido en L 32.50)
--   - Los 118 cart 1x15 devueltos se reintegran al Lote Mediano como huevos
--   - Las 3 ventas y todo el viaje cambian de prod 22 (Opoa Pequeño 1x15) a
--     prod 19 (Opoa Mediano 1x15) — lo que físicamente entregaron
--
-- Finanzas: NO se afectan ventas, cuentas por cobrar, caja ni costos de venta.
--           Solo cambia la VALORACIÓN del inventario (reclasificación interna).
--
-- IMPORTANTE: Hacer respaldo completo ANTES de ejecutar este script.
--             mysqldump -u <user> -p <db> > respaldo_antes_correccion.sql
-- ============================================================================

START TRANSACTION;

-- Lock pesimista sobre los lotes afectados
SELECT id FROM lotes WHERE id IN (2, 3) FOR UPDATE;

-- ─── REEMPAQUE — corregir lote y producto ─────────────────────────────────
UPDATE reempaque_lotes
SET lote_id = 3, updated_at = NOW()
WHERE id = 873 AND reempaque_id = 867;

UPDATE reempaque_productos
SET producto_id = 19, categoria_id = 18, updated_at = NOW()
WHERE id = 932 AND reempaque_id = 867;

-- ─── VIAJE 166 — carga, descarga y 3 ventas pasan a prod 19 ───────────────
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
-- remanente: 1364 + 2880 = 4244
-- facturados: 33239 - 2880 = 30359
-- costo_total: 72150 - 6240 = 65910
UPDATE lotes
SET cantidad_huevos_remanente    = cantidad_huevos_remanente + 2880,
    huevos_facturados_acumulados = huevos_facturados_acumulados - 2880,
    costo_total_acumulado        = costo_total_acumulado - 6240.00,
    estado                       = 'disponible',
    updated_at                   = NOW()
WHERE id = 2;

-- ─── LOTE 3 (Mediano) — entra el reempaque + vuelven los 1770 huevos ──────
-- remanente: 7156 - 2880 + 1770 = 6046
-- facturados: 594181 + 2880 - 1770 = 595291
-- costo_total: 1537230 + 6240 - 3835 = 1539635
UPDATE lotes
SET cantidad_huevos_remanente    = cantidad_huevos_remanente - 2880 + 1770,
    huevos_facturados_acumulados = huevos_facturados_acumulados + 2880 - 1770,
    costo_total_acumulado        = costo_total_acumulado + 6240.00 - 3835.00,
    updated_at                   = NOW()
WHERE id = 3;

-- ─── BODEGA_PRODUCTO — los 118 cart 1x15 ya están en el lote Mediano ──────
UPDATE bodega_producto
SET stock = 0, updated_at = NOW()
WHERE producto_id = 22 AND bodega_id = 1;

UPDATE bodega_producto
SET stock = 0, updated_at = NOW()
WHERE producto_id = 19 AND bodega_id = 1;

-- ─── VERIFICACIÓN PRE-COMMIT ──────────────────────────────────────────────
-- Ejecutá las siguientes consultas y confirmá que TODOS los "diff" sean 0
-- antes de hacer el COMMIT final:

-- Lotes
SELECT
  id, numero_lote,
  cantidad_huevos_remanente AS remanente,
  cantidad_huevos_remanente - CASE id WHEN 2 THEN 4244 WHEN 3 THEN 6046 END AS diff_rem,
  huevos_facturados_acumulados AS facturados,
  huevos_facturados_acumulados - CASE id WHEN 2 THEN 30359 WHEN 3 THEN 595291 END AS diff_fact,
  costo_total_acumulado AS costo_tot,
  costo_total_acumulado - CASE id WHEN 2 THEN 65910.00 WHEN 3 THEN 1539635.00 END AS diff_costo
FROM lotes WHERE id IN (2, 3);

-- Bodega_producto (ambos deben ser 0)
SELECT producto_id, stock FROM bodega_producto WHERE producto_id IN (19, 22) AND bodega_id = 1;

-- Reempaque corregido (debe dar lote_id=3, producto_id=19, categoria_id=18)
SELECT rl.lote_id, rp.producto_id, rp.categoria_id
FROM reempaque_lotes rl
JOIN reempaque_productos rp ON rp.reempaque_id = rl.reempaque_id
WHERE rl.reempaque_id = 867;

-- Viaje (todos deben dar producto_id=19)
SELECT 'carga' AS t, producto_id FROM viaje_cargas WHERE id = 1325
UNION ALL SELECT 'descarga', producto_id FROM viaje_descargas WHERE id = 950
UNION ALL SELECT 'venta_3487', producto_id FROM viaje_venta_detalles WHERE id = 3487
UNION ALL SELECT 'venta_3494', producto_id FROM viaje_venta_detalles WHERE id = 3494
UNION ALL SELECT 'venta_3500', producto_id FROM viaje_venta_detalles WHERE id = 3500;

-- ─── CONFIRMAR O DESHACER ─────────────────────────────────────────────────
-- Si TODOS los diff son 0 y todos los productos son 19 → ejecutá:

COMMIT;

-- Si algo no cuadra → ejecutá ROLLBACK; en su lugar.
-- ============================================================================
