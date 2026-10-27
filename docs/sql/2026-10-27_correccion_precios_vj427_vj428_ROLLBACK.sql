-- =====================================================================
-- ROLLBACK de 2026-10-27_correccion_precios_vj427_vj428.sql
-- Deja todo EXACTAMENTE como estaba en el respaldo de las 08:14, incluidos
-- updated_at y observaciones. Mismas reglas: una transaccion, candados con
-- los valores corregidos; si algo no calza se detiene y no cambia nada.
-- Correr con: mysql -h ... -u ... -p BASE < este_archivo.sql  (sin --force)
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

DROP TEMPORARY TABLE IF EXISTS _guarda;
CREATE TEMPORARY TABLE _guarda (
  paso VARCHAR(120) NOT NULL,
  ok   TINYINT NOT NULL CHECK (ok = 1)
);

START TRANSACTION;


UPDATE viaje_venta_detalles
   SET precio_base = 120.00, precio_con_isv = 120.00, subtotal = 11400.00, total_linea = 11400.00, updated_at = '2026-10-26 17:17:50'
 WHERE id = 6934 AND precio_base = 130.00 AND subtotal = 12350.00 AND total_linea = 12350.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R detalle 6934', IF(@rc = 1, 1, NULL));
UPDATE viaje_venta_detalles
   SET precio_base = 115.00, precio_con_isv = 115.00, subtotal = 1265.00, total_linea = 1265.00, updated_at = '2026-10-26 17:24:14'
 WHERE id = 6935 AND precio_base = 130.00 AND subtotal = 1430.00 AND total_linea = 1430.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R detalle 6935', IF(@rc = 1, 1, NULL));
UPDATE viaje_venta_detalles
   SET precio_base = 57.50, precio_con_isv = 57.50, subtotal = 1380.00, total_linea = 1380.00, updated_at = '2026-10-26 17:24:14'
 WHERE id = 6936 AND precio_base = 65.00 AND subtotal = 1560.00 AND total_linea = 1560.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R detalle 6936', IF(@rc = 1, 1, NULL));
UPDATE viaje_venta_detalles
   SET precio_base = 130.00, precio_con_isv = 130.00, subtotal = 3120.00, total_linea = 3120.00, updated_at = '2026-10-26 17:28:58'
 WHERE id = 6938 AND precio_base = 135.00 AND subtotal = 3240.00 AND total_linea = 3240.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R detalle 6938', IF(@rc = 1, 1, NULL));
UPDATE viaje_venta_detalles
   SET precio_base = 115.00, precio_con_isv = 115.00, subtotal = 575.00, total_linea = 575.00, updated_at = '2026-10-26 17:28:58'
 WHERE id = 6940 AND precio_base = 120.00 AND subtotal = 600.00 AND total_linea = 600.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R detalle 6940', IF(@rc = 1, 1, NULL));
UPDATE viaje_venta_detalles
   SET precio_base = 115.00, precio_con_isv = 115.00, subtotal = 345.00, total_linea = 345.00, updated_at = '2026-10-26 17:28:58'
 WHERE id = 6943 AND precio_base = 120.00 AND subtotal = 360.00 AND total_linea = 360.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R detalle 6943', IF(@rc = 1, 1, NULL));
UPDATE viaje_venta_detalles
   SET precio_base = 57.50, precio_con_isv = 57.50, subtotal = 2760.00, total_linea = 2760.00, updated_at = '2026-10-26 17:28:58'
 WHERE id = 6944 AND precio_base = 60.00 AND subtotal = 2880.00 AND total_linea = 2880.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R detalle 6944', IF(@rc = 1, 1, NULL));
UPDATE viaje_ventas SET subtotal = 11400.00, total = 11400.00, updated_at = '2026-10-26 17:17:50'
 WHERE id = 4436 AND subtotal = 12350.00 AND total = 12350.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R venta 4436', IF(@rc = 1, 1, NULL));
UPDATE viaje_ventas SET subtotal = 2645.00, total = 2645.00, updated_at = '2026-10-26 17:24:14'
 WHERE id = 4437 AND subtotal = 2990.00 AND total = 2990.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R venta 4437', IF(@rc = 1, 1, NULL));
UPDATE viaje_ventas SET subtotal = 13433.00, total = 13433.00, updated_at = '2026-10-26 17:28:58'
 WHERE id = 4438 AND subtotal = 13713.00 AND total = 13713.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R venta 4438', IF(@rc = 1, 1, NULL));
UPDATE viajes SET total_vendido = 31538.00, observaciones = 'santa rosa de copan ', updated_at = '2026-10-26 17:31:17'
 WHERE id = 428 AND total_vendido = 33113.00 AND observaciones = 'santa rosa de copan  | [CORRECCION 27/10/2026] Ventas del lunes al precio nuevo (la carga del sabado traia precio viejo). Total vendido 31,538.00 -> 33,113.00. Precios de carga al precio nuevo.';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R viaje 428', IF(@rc = 1, 1, NULL));
UPDATE viajes SET observaciones = 'san juan intibuca ', updated_at = '2026-10-27 05:47:15'
 WHERE id = 427 AND observaciones = 'san juan intibuca  | [CORRECCION 27/10/2026] Precios de carga al precio nuevo. Ventas a Evenezer, El Arca y La Canasta Familiar se respetan con su descuento.';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R viaje 427', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 60.00, subtotal_venta = 14400.00, updated_at = '2026-10-26 18:19:33'
 WHERE id = 3528 AND precio_venta_sugerido = 65.00 AND subtotal_venta = 15600.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3528', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 120.00, subtotal_venta = 12000.00, updated_at = '2026-10-27 05:49:19'
 WHERE id = 3529 AND precio_venta_sugerido = 130.00 AND subtotal_venta = 13000.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3529', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 115.00, subtotal_venta = 2760.00, updated_at = '2026-10-26 18:30:34'
 WHERE id = 3530 AND precio_venta_sugerido = 120.00 AND subtotal_venta = 2880.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3530', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 57.50, subtotal_venta = 690.00, updated_at = '2026-10-26 18:30:34'
 WHERE id = 3531 AND precio_venta_sugerido = 60.00 AND subtotal_venta = 720.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3531', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 60.00, subtotal_venta = 2160.00, updated_at = '2026-10-26 18:33:31'
 WHERE id = 3533 AND precio_venta_sugerido = 65.00 AND subtotal_venta = 2340.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3533', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 120.00, subtotal_venta = 3600.00, updated_at = '2026-10-26 18:33:31'
 WHERE id = 3534 AND precio_venta_sugerido = 130.00 AND subtotal_venta = 3900.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3534', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 120.00, subtotal_venta = 1440.00, updated_at = '2026-10-27 05:47:15'
 WHERE id = 3535 AND precio_venta_sugerido = 130.00 AND subtotal_venta = 1560.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3535', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 110.00, subtotal_venta = 1320.00, updated_at = '2026-10-27 05:47:15'
 WHERE id = 3536 AND precio_venta_sugerido = 120.00 AND subtotal_venta = 1440.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3536', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 125.00, subtotal_venta = 1500.00, updated_at = '2026-10-27 05:47:15'
 WHERE id = 3537 AND precio_venta_sugerido = 135.00 AND subtotal_venta = 1620.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3537', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 115.00, subtotal_venta = 1035.00, updated_at = '2026-10-27 05:47:15'
 WHERE id = 3538 AND precio_venta_sugerido = 125.00 AND subtotal_venta = 1125.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3538', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 120.00, subtotal_venta = 17280.00, updated_at = '2026-10-27 06:23:28'
 WHERE id = 3540 AND precio_venta_sugerido = 130.00 AND subtotal_venta = 18720.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3540', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 125.00, subtotal_venta = 3000.00, updated_at = '2026-10-26 17:28:58'
 WHERE id = 3541 AND precio_venta_sugerido = 135.00 AND subtotal_venta = 3240.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3541', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 115.00, subtotal_venta = 2760.00, updated_at = '2026-10-26 17:28:58'
 WHERE id = 3542 AND precio_venta_sugerido = 125.00 AND subtotal_venta = 3000.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3542', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 110.00, subtotal_venta = 550.00, updated_at = '2026-10-26 17:28:58'
 WHERE id = 3543 AND precio_venta_sugerido = 120.00 AND subtotal_venta = 600.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3543', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 60.00, subtotal_venta = 10080.00, updated_at = '2026-10-27 06:24:03'
 WHERE id = 3544 AND precio_venta_sugerido = 65.00 AND subtotal_venta = 10920.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3544', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 120.00, subtotal_venta = 2880.00, updated_at = '2026-10-26 17:24:14'
 WHERE id = 3545 AND precio_venta_sugerido = 130.00 AND subtotal_venta = 3120.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3545', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 57.50, subtotal_venta = 4140.00, updated_at = '2026-10-26 17:28:58'
 WHERE id = 3546 AND precio_venta_sugerido = 60.00 AND subtotal_venta = 4320.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3546', IF(@rc = 1, 1, NULL));
UPDATE viaje_cargas SET precio_venta_sugerido = 115.00, subtotal_venta = 1380.00, updated_at = '2026-10-26 17:28:58'
 WHERE id = 3547 AND precio_venta_sugerido = 120.00 AND subtotal_venta = 1440.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R carga 3547', IF(@rc = 1, 1, NULL));
UPDATE cliente_producto SET ultimo_precio_venta = 115.00, ultimo_precio_con_isv = 115.00
 WHERE id = 124 AND ultimo_precio_venta = 130.00 AND fecha_ultima_venta = '2026-10-26 17:24:14';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R cliente_producto 124', IF(@rc = 1, 1, NULL));
UPDATE cliente_producto SET ultimo_precio_venta = 57.50, ultimo_precio_con_isv = 57.50
 WHERE id = 399 AND ultimo_precio_venta = 65.00 AND fecha_ultima_venta = '2026-10-26 17:24:14';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R cliente_producto 399', IF(@rc = 1, 1, NULL));
UPDATE cliente_producto SET ultimo_precio_venta = 130.00, ultimo_precio_con_isv = 130.00
 WHERE id = 460 AND ultimo_precio_venta = 135.00 AND fecha_ultima_venta = '2026-10-26 17:28:58';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R cliente_producto 460', IF(@rc = 1, 1, NULL));
UPDATE cliente_producto SET ultimo_precio_venta = 115.00, ultimo_precio_con_isv = 115.00
 WHERE id = 708 AND ultimo_precio_venta = 120.00 AND fecha_ultima_venta = '2026-10-26 17:28:58';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R cliente_producto 708', IF(@rc = 1, 1, NULL));
UPDATE cliente_producto SET ultimo_precio_venta = 120.00, ultimo_precio_con_isv = 120.00
 WHERE id = 811 AND ultimo_precio_venta = 130.00 AND fecha_ultima_venta = '2026-10-26 17:17:50';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R cliente_producto 811', IF(@rc = 1, 1, NULL));
UPDATE cliente_producto SET ultimo_precio_venta = 115.00, ultimo_precio_con_isv = 115.00
 WHERE id = 953 AND ultimo_precio_venta = 120.00 AND fecha_ultima_venta = '2026-10-26 17:28:58';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R cliente_producto 953', IF(@rc = 1, 1, NULL));
UPDATE cliente_producto SET ultimo_precio_venta = 57.50, ultimo_precio_con_isv = 57.50
 WHERE id = 954 AND ultimo_precio_venta = 60.00 AND fecha_ultima_venta = '2026-10-26 17:28:58';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('R cliente_producto 954', IF(@rc = 1, 1, NULL));

SELECT COUNT(*) AS pasos_revertidos_ok FROM _guarda;
COMMIT;
SELECT 'REVERTIDO - quedo como en el respaldo' AS resultado;
