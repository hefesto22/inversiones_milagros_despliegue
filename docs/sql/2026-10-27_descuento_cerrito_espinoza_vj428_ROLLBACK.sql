-- =====================================================================
-- ROLLBACK de 2026-10-27_descuento_cerrito_espinoza_vj428.sql
-- Vuelve Cerrito y Espinoza al precio nuevo (130 / 65), como quedaron tras
-- la correccion de precios. updated_at de esas filas queda con la hora del
-- rollback. Mismas protecciones: si algo no calza, no cambia nada.
-- Correr con: mysql -h ... -u ... -p BASE < este_archivo.sql  (sin --force)
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET @ahora := UTC_TIMESTAMP();
SET @nota := ' | [CORRECCION 27/10/2026] Cafeina el Cerrito y Inversiones Espinoza se respetan a su precio anterior (descuento de 1,295.00 contra el precio nuevo).';

DROP TEMPORARY TABLE IF EXISTS _guarda;
CREATE TEMPORARY TABLE _guarda (paso VARCHAR(120) NOT NULL, ok TINYINT NOT NULL CHECK (ok = 1));

START TRANSACTION;

UPDATE viaje_venta_detalles SET precio_base = 130.00, precio_con_isv = 130.00, subtotal = 12350.00, total_linea = 12350.00, updated_at = @ahora
 WHERE id = 6934 AND precio_base = 120.00 AND subtotal = 11400.00 AND total_linea = 11400.00;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R 6934', IF(@rc = 1, 1, NULL));
UPDATE viaje_venta_detalles SET precio_base = 130.00, precio_con_isv = 130.00, subtotal = 1430.00, total_linea = 1430.00, updated_at = @ahora
 WHERE id = 6935 AND precio_base = 115.00 AND subtotal = 1265.00 AND total_linea = 1265.00;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R 6935', IF(@rc = 1, 1, NULL));
UPDATE viaje_venta_detalles SET precio_base = 65.00, precio_con_isv = 65.00, subtotal = 1560.00, total_linea = 1560.00, updated_at = @ahora
 WHERE id = 6936 AND precio_base = 57.50 AND subtotal = 1380.00 AND total_linea = 1380.00;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R 6936', IF(@rc = 1, 1, NULL));
UPDATE viaje_ventas SET subtotal = 12350.00, total = 12350.00, updated_at = @ahora WHERE id = 4436 AND subtotal = 11400.00 AND total = 11400.00;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R 4436', IF(@rc = 1, 1, NULL));
UPDATE viaje_ventas SET subtotal = 2990.00, total = 2990.00, updated_at = @ahora WHERE id = 4437 AND subtotal = 2645.00 AND total = 2645.00;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R 4437', IF(@rc = 1, 1, NULL));
UPDATE viajes SET total_vendido = total_vendido + 1295.00, observaciones = LEFT(observaciones, CHAR_LENGTH(observaciones) - CHAR_LENGTH(@nota))
 WHERE id = 428 AND BINARY RIGHT(observaciones, CHAR_LENGTH(@nota)) = BINARY @nota;
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R viaje 428', IF(@rc = 1, 1, NULL));
UPDATE cliente_producto SET ultimo_precio_venta = 130.00, ultimo_precio_con_isv = 130.00 WHERE id = 811 AND ultimo_precio_venta = 120.00 AND fecha_ultima_venta = '2026-10-26 17:17:50';
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R 811', IF(@rc = 1, 1, NULL));
UPDATE cliente_producto SET ultimo_precio_venta = 130.00, ultimo_precio_con_isv = 130.00 WHERE id = 124 AND ultimo_precio_venta = 115.00 AND fecha_ultima_venta = '2026-10-26 17:24:14';
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R 124', IF(@rc = 1, 1, NULL));
UPDATE cliente_producto SET ultimo_precio_venta = 65.00, ultimo_precio_con_isv = 65.00 WHERE id = 399 AND ultimo_precio_venta = 57.50 AND fecha_ultima_venta = '2026-10-26 17:24:14';
SET @rc := ROW_COUNT(); INSERT INTO _guarda VALUES ('R 399', IF(@rc = 1, 1, NULL));
INSERT INTO _guarda VALUES ('R total vendido = suma de ventas', IF((SELECT total_vendido FROM viajes WHERE id = 428) = (SELECT SUM(total) FROM viaje_ventas WHERE viaje_id = 428 AND estado IN ('confirmada','completada') AND deleted_at IS NULL), 1, NULL));

SELECT COUNT(*) AS pasos_revertidos_ok FROM _guarda;
COMMIT;
SELECT 'REVERTIDO - Cerrito y Espinoza al precio nuevo' AS resultado;
