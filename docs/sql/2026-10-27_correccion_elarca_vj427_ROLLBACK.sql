-- =====================================================================
-- ROLLBACK de 2026-10-27_correccion_elarca_vj427.sql
-- SOLO sirve ANTES de usar Bajar/Recargar en el panel (comprueba que las
-- cargas sigan en 24 y 12). Si ya se usaron los botones, primero hay que
-- deshacerlos en el panel (Recargar 12 de 1x30 y Bajar 12 de 1x15).
-- Correr con: mysql -h ... -u ... -p BASE < este_archivo.sql  (sin --force)
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET @nota := ' | [CORRECCION 27/10/2026] Opoa Huevo Pequeno: se cargaron 12 de 1x30 (no 24) y 24 de 1x15 (no 12). El Arca VR-427-0002 corregida a 12 + 24 = 2,520.00 (antes 3,150.00). Cantidades de carga ajustadas con Bajar/Recargar.';

DROP TEMPORARY TABLE IF EXISTS _guarda;
CREATE TEMPORARY TABLE _guarda (paso VARCHAR(120) NOT NULL, ok TINYINT NOT NULL CHECK (ok = 1));

START TRANSACTION;

INSERT INTO _guarda VALUES ('0 botones aun no usados (cargas en 24 y 12)', IF((SELECT COUNT(*) FROM viaje_cargas WHERE (id = 3530 AND cantidad = 24 AND cantidad_vendida = 12) OR (id = 3531 AND cantidad = 12 AND cantidad_vendida = 24)) = 2, 1, NULL));

UPDATE viaje_venta_detalles SET cantidad = 24.000, subtotal = 2520.00, total_linea = 2520.00, updated_at = '2026-10-26 18:30:34'
 WHERE id = 6949 AND cantidad = 12.000 AND subtotal = 1260.00 AND total_linea = 1260.00;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R linea 6949', IF(@rc = 1, 1, NULL));
UPDATE viaje_venta_detalles SET cantidad = 12.000, subtotal = 630.00, total_linea = 630.00, updated_at = '2026-10-26 18:30:34'
 WHERE id = 6950 AND cantidad = 24.000 AND subtotal = 1260.00 AND total_linea = 1260.00;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R linea 6950', IF(@rc = 1, 1, NULL));
UPDATE viaje_ventas SET subtotal = 3150.00, total = 3150.00, updated_at = '2026-10-26 18:30:34'
 WHERE id = 4441 AND subtotal = 2520.00 AND total = 2520.00;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R venta 4441', IF(@rc = 1, 1, NULL));
UPDATE viajes SET total_vendido = 37596.00, observaciones = LEFT(observaciones, CHAR_LENGTH(observaciones) - CHAR_LENGTH(@nota))
 WHERE id = 427 AND total_vendido = 36966.00 AND BINARY RIGHT(observaciones, CHAR_LENGTH(@nota)) = BINARY @nota;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R viaje 427', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET cantidad_vendida = 24.000 WHERE id = 3530 AND cantidad = 24 AND cantidad_vendida = 12;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R carga 3530', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET cantidad_vendida = 12.000 WHERE id = 3531 AND cantidad = 12 AND cantidad_vendida = 24;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R carga 3531', IF(@rc = 1, 1, NULL));
UPDATE cliente_producto SET cantidad_ultima_venta = 24.00, cantidad_total_vendida = 36.00 WHERE id = 826 AND cantidad_ultima_venta = 12.00 AND cantidad_total_vendida = 24.00;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R cliente_producto 826', IF(@rc = 1, 1, NULL));
UPDATE cliente_producto SET cantidad_ultima_venta = 12.00, cantidad_total_vendida = 61.00 WHERE id = 825 AND cantidad_ultima_venta = 24.00 AND cantidad_total_vendida = 73.00;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R cliente_producto 825', IF(@rc = 1, 1, NULL));

SELECT COUNT(*) AS pasos_revertidos_ok FROM _guarda;
COMMIT;
SELECT 'REVERTIDO - El Arca quedo como antes' AS resultado;
