-- ============================================================================
-- ⚠️  ARCHIVO DE REFERENCIA — NO EJECUTAR DIRECTAMENTE
-- ============================================================================
-- Este script quedó como REFERENCIA DOCUMENTAL del cálculo del ajuste.
-- La aplicación REAL del ajuste se hará desde el módulo "Ajuste de Inventario"
-- (Filament Resource) una vez esté terminado, con auditoría completa y
-- aprobación dual registradas en BD.
--
-- NO ejecutar este SQL en producción. Mantener solo para auditoría histórica
-- del cálculo manual que se hizo el 2026-05-20.
-- ============================================================================
--
-- AJUSTE DE INVENTARIO POR CONTEO FÍSICO — 2026-05-20 (REFERENCIA)
-- ============================================================================
-- Cliente: Huevos Opoa
-- Bodega: Central (id=1)
-- Autorización: ver Procedimiento_Reconciliacion_Inventario_2026-05-20.docx
--
-- IMPORTANTE — LEER ANTES DE EJECUTAR:
--   1. Este script debe ejecutarse UNA SOLA VEZ.
--   2. Hacer respaldo COMPLETO de la BD antes de ejecutar (paso 1 abajo).
--   3. Ejecutar en una sola transacción — si algo falla, ROLLBACK automático.
--   4. Después de COMMIT, verificar saldos antes de cerrar la sesión SQL.
--   5. NO ejecutar mientras haya operaciones activas en bodega (cargas, ventas).
--
-- Resumen de movimientos:
--   Lote 1 (Grande):  -120 huevos (4 cart equivalentes 1x30) → destino Lote 2 Pequeño
--   Lote 3 (Mediano): -916 huevos (30.53 cart) → destino Lote 2 Pequeño
--   Lote 3 (Mediano): -300 huevos (10 cart) → merma residual
--   Lote 2 (Pequeño): +1,036 huevos (120 de Grande + 916 de Mediano)
--   Costo aplicado a los huevos que entran a Pequeño: L 65/cart = L 2.166667/huevo
--   Costo de la merma de Mediano: L 75/cart = L 2.50/huevo
--
-- Saldos esperados después del ajuste:
--   Lote 1 (Grande):  7,680 huevos (256 cart) — coincide con físico
--   Lote 2 (Pequeño): 2,400 huevos (80 cart) — equiv 1x30, + 118 cart Opoa 1x15 = 139 cart equiv total
--   Lote 3 (Mediano): 5,940 huevos (198 cart) — coincide con físico
--   Lote 5 (Extra Grande): 660 huevos (22 cart) — sin cambio, ya cuadraba
-- ============================================================================

-- ============================================================================
-- PASO 1 — RESPALDO (ejecutar en terminal Linux, NO en MySQL):
-- ============================================================================
--   mysqldump -u <usuario> -p u304956828_despliegueopoa > respaldo_pre_ajuste_2026-05-20.sql
--   → Verificar que el archivo se haya creado y tenga tamaño > 0 antes de continuar.
-- ============================================================================

-- ============================================================================
-- PASO 2 — VERIFICACIÓN PREVIA (ejecutar y confirmar valores antes de seguir):
-- ============================================================================

SELECT
    id,
    numero_lote,
    producto_id,
    cantidad_huevos_remanente,
    huevos_facturados_acumulados,
    merma_total_acumulada,
    costo_por_carton_facturado,
    estado
FROM lotes
WHERE id IN (1, 2, 3, 5)
ORDER BY id;
-- Esperado:
--   Lote 1 (Grande):  remanente=7800
--   Lote 2 (Pequeño): remanente=1364
--   Lote 3 (Mediano): remanente=7156
--   Lote 5 (XGrande): remanente=660

-- Verificar que no hay operaciones recientes (últimos 5 min) en estos lotes
SELECT 'reempaques' AS tabla, COUNT(*) AS n
FROM reempaque_lotes rl
JOIN reempaques r ON r.id = rl.reempaque_id
WHERE rl.lote_id IN (1, 2, 3, 5)
  AND r.created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
UNION ALL
SELECT 'mermas', COUNT(*) FROM mermas
WHERE lote_id IN (1, 2, 3, 5)
  AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE);
-- Si alguno > 0, ESPERAR a que termine y volver a verificar.

-- ============================================================================
-- PASO 3 — APLICAR AJUSTE EN TRANSACCIÓN ÚNICA
-- ============================================================================

START TRANSACTION;

-- 3.1 — Lock pesimista sobre los 3 lotes afectados
SELECT id FROM lotes WHERE id IN (1, 2, 3) FOR UPDATE;

-- 3.2 — Movimiento 1: GRANDE → PEQUEÑO (4 cart = 120 huevos)
-- Sale del Lote 1 (Grande)
UPDATE lotes
SET cantidad_huevos_remanente = cantidad_huevos_remanente - 120,
    updated_at = NOW()
WHERE id = 1;
-- Esperado: 7800 - 120 = 7680

-- 3.3 — Movimiento 2: MEDIANO → PEQUEÑO (30.533 cart = 916 huevos)
-- Sale del Lote 3 (Mediano)
UPDATE lotes
SET cantidad_huevos_remanente = cantidad_huevos_remanente - 916,
    updated_at = NOW()
WHERE id = 3;
-- Después de este paso: Lote 3 remanente = 7156 - 916 = 6240

-- 3.4 — Movimiento 3: MEDIANO → MERMA RESIDUAL (10 cart = 300 huevos)
-- Sale del Lote 3 y se registra como merma con costo Mediano
UPDATE lotes
SET cantidad_huevos_remanente = cantidad_huevos_remanente - 300,
    merma_total_acumulada      = merma_total_acumulada + 300,
    huevos_facturados_acumulados = huevos_facturados_acumulados + 300,
    updated_at = NOW()
WHERE id = 3;
-- Esperado Lote 3: 6240 - 300 = 5940

-- 3.5 — Movimiento 4: PEQUEÑO recibe 120 (Grande) + 916 (Mediano) = 1,036 huevos
-- al costo del Pequeño (no cambia el WAC porque entra al mismo costo)
UPDATE lotes
SET cantidad_huevos_remanente = cantidad_huevos_remanente + 1036,
    updated_at = NOW()
WHERE id = 2;
-- Esperado: 1364 + 1036 = 2400

-- 3.6 — Registrar la merma residual del Mediano
INSERT INTO mermas (
    lote_id, bodega_id, producto_id, numero_merma,
    cantidad_huevos, cubierto_por_regalo, perdida_real_huevos, perdida_real_lempiras,
    motivo, descripcion,
    buffer_antes, buffer_despues,
    created_by, created_at, updated_at
)
SELECT
    3, 1, 12,
    CONCAT('M-B1-', LPAD((SELECT IFNULL(MAX(CAST(SUBSTRING(numero_merma, 6) AS UNSIGNED)), 0) + 1 FROM mermas m WHERE m.bodega_id = 1), 6, '0')),
    300, 0, 300, 300 * 2.50,
    'otros',
    'Ajuste de inventario por conteo físico 2026-05-20. Residuo de 10 cart Mediano que no se pudo reclasificar a Pequeño. Ver Procedimiento_Reconciliacion_Inventario_2026-05-20.docx.',
    0, 0,
    1, NOW(), NOW();

-- 3.7 — Registrar bitácora textual del ajuste (en una tabla de auditoría general)
-- Si no existe la tabla, esta línea se omite. La auditoría queda en el documento firmado.
-- INSERT INTO ajustes_inventario (...) VALUES (...);  -- Pendiente del módulo nuevo

-- ============================================================================
-- PASO 4 — VERIFICACIÓN ANTES DE COMMIT (ejecutar y revisar):
-- ============================================================================

SELECT
    id,
    numero_lote,
    producto_id,
    cantidad_huevos_remanente AS remanente_actual,
    CASE id
        WHEN 1 THEN 7680
        WHEN 2 THEN 2400
        WHEN 3 THEN 5940
        WHEN 5 THEN 660
    END AS remanente_esperado,
    cantidad_huevos_remanente - CASE id
        WHEN 1 THEN 7680
        WHEN 2 THEN 2400
        WHEN 3 THEN 5940
        WHEN 5 THEN 660
    END AS diferencia
FROM lotes
WHERE id IN (1, 2, 3, 5)
ORDER BY id;
-- Esperado: la columna 'diferencia' debe ser 0 en todas las filas.

-- Verificar que la merma se registró
SELECT * FROM mermas
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
  AND lote_id = 3;
-- Debe haber 1 fila con cantidad_huevos=300, motivo='otros'.

-- ============================================================================
-- PASO 5 — CONFIRMAR O DESHACER:
-- ============================================================================
--
-- SI TODO CUADRA → ejecutar:
--   COMMIT;
--
-- SI ALGO NO CUADRA → ejecutar:
--   ROLLBACK;
--
-- DESPUÉS DE COMMIT, NO HAY VUELTA ATRÁS — el respaldo del Paso 1 sería
-- la única forma de revertir.
-- ============================================================================

-- COMMIT;  -- Descomentar SOLO cuando el Paso 4 muestre diferencia=0 en todas las filas.

-- ============================================================================
-- PASO 6 — VERIFICACIÓN POST-COMMIT (ejecutar después de COMMIT):
-- ============================================================================

-- Estado final de lotes
SELECT
    id, numero_lote, producto_id,
    cantidad_huevos_remanente,
    ROUND(cantidad_huevos_remanente / 30, 2) AS cartones_1x30_equiv,
    merma_total_acumulada,
    estado
FROM lotes
WHERE id IN (1, 2, 3, 5)
ORDER BY id;

-- Total Pequeño (lote 1x30 + bodega_producto 1x15 equiv)
SELECT
    'Total Pequeño (cart equiv 1x30)' AS metric,
    ROUND(
        (SELECT cantidad_huevos_remanente FROM lotes WHERE id = 2) / 30
      + (SELECT COALESCE(stock, 0) FROM bodega_producto WHERE producto_id = 22 AND bodega_id = 1) * 15 / 30
    , 2) AS valor_esperado_139;

-- ============================================================================
-- FIN DEL SCRIPT
-- ============================================================================
-- Una vez aplicado:
--   1. Tomar capturas de pantalla del panel de Lotes en Filament (antes/después).
--   2. Guardar este archivo SQL en la carpeta de auditoría del cliente.
--   3. Firmar el documento Procedimiento_Reconciliacion_Inventario_2026-05-20.docx.
--   4. Avisar a Mauricio para que el módulo de Ajuste de Inventario (en desarrollo)
--      arranque con la BD ya cuadrada.
-- ============================================================================
