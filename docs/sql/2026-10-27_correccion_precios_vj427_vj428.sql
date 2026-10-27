-- =====================================================================
-- CORRECCION DE PRECIOS - rutas VJ-000427 (Javier) y VJ-000428 (Jose)
-- 2026-10-27. Las cargas se hicieron el sabado 24/10 con el precio viejo y el
-- precio nuevo entro el lunes 26/10 de madrugada; las ventas del lunes
-- salieron con el viejo.
--
-- Decision del jefe (27/10 08:34):
--  * Jose (428): todas sus ventas al precio nuevo de lista. El cobro ya se
--    hizo al precio nuevo, asi que el efectivo cuadra al cerrar.
--  * Javier (427): Evenezer, El Arca y La Canasta Familiar (2 facturas) se
--    quedan tal cual (descuento). Sus ventas NO se tocan aqui.
--  * Cargas de las dos rutas al precio nuevo (lo que quede por vender).
--  * Ultimo precio de los clientes de Jose al precio nuevo, para que la
--    proxima venta no vuelva a salir con el viejo.
--
-- NO toca Kardex, lotes, bodega ni costos. Las comisiones se calculan al
-- cerrar el viaje (tarifa fija por carton: el precio no las cambia).
--
-- SEGURIDAD: una sola transaccion. Cada UPDATE lleva candados con los
-- valores actuales y se comprueba cuantas filas cambio. Si algo no calza
-- (alguien vendio o edito despues del respaldo de las 08:14, o el script ya
-- se corrio), se detiene con error y la transaccion se revierte sola: no
-- queda nada a medias. Correrlo dos veces no aplica nada la segunda vez.
--
-- Correr con:  mysql -h ... -u ... -p BASE < este_archivo.sql
-- (NUNCA con --force: --force ignora el error y rompe el candado)
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET @ahora := UTC_TIMESTAMP();

DROP TEMPORARY TABLE IF EXISTS _guarda;
CREATE TEMPORARY TABLE _guarda (
  paso VARCHAR(120) NOT NULL,
  ok   TINYINT NOT NULL CHECK (ok = 1)
);

START TRANSACTION;

-- ---------------------------------------------------------------------
-- 0. El estado es el mismo del respaldo (si no, se detiene aqui)
-- ---------------------------------------------------------------------
INSERT INTO _guarda VALUES ('0 viaje 427 en ruta con total 37596.00', IF((SELECT COUNT(*) FROM viajes WHERE id = 427 AND estado = 'en_ruta' AND total_vendido = 37596.00) = 1, 1, NULL));
INSERT INTO _guarda VALUES ('0 viaje 427 tiene exactamente 4 ventas', IF((SELECT COUNT(*) FROM viaje_ventas WHERE viaje_id = 427) = 4, 1, NULL));
INSERT INTO _guarda VALUES ('0 viaje 427 tiene exactamente 11 cargas', IF((SELECT COUNT(*) FROM viaje_cargas WHERE viaje_id = 427) = 11, 1, NULL));
INSERT INTO _guarda VALUES ('0 viaje 428 en ruta con total 31538.00', IF((SELECT COUNT(*) FROM viajes WHERE id = 428 AND estado = 'en_ruta' AND total_vendido = 31538.00) = 1, 1, NULL));
INSERT INTO _guarda VALUES ('0 viaje 428 tiene exactamente 4 ventas', IF((SELECT COUNT(*) FROM viaje_ventas WHERE viaje_id = 428) = 4, 1, NULL));
INSERT INTO _guarda VALUES ('0 viaje 428 tiene exactamente 11 cargas', IF((SELECT COUNT(*) FROM viaje_cargas WHERE viaje_id = 428) = 11, 1, NULL));

-- ---------------------------------------------------------------------
-- 1. Lineas de venta de Jose al precio nuevo
-- ---------------------------------------------------------------------
-- detalle 6934 (venta 4436): 95.000 x prod 9  120.00 -> 130   (11400.00 -> 12350.00)
UPDATE viaje_venta_detalles
   SET precio_base = 130.00, precio_con_isv = 130.00, subtotal = 12350.00, total_linea = 12350.00, updated_at = @ahora
 WHERE id = 6934 AND viaje_venta_id = 4436 AND producto_id = 9 AND cantidad = 95.000
   AND precio_base = 120.00 AND precio_con_isv = 120.00 AND aplica_isv = 0 AND monto_isv = 0 AND total_isv = 0
   AND subtotal = 11400.00 AND total_linea = 11400.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('1 detalle 6934', IF(@rc = 1, 1, NULL));
-- detalle 6935 (venta 4437): 11.000 x prod 20  115.00 -> 130   (1265.00 -> 1430.00)
UPDATE viaje_venta_detalles
   SET precio_base = 130.00, precio_con_isv = 130.00, subtotal = 1430.00, total_linea = 1430.00, updated_at = @ahora
 WHERE id = 6935 AND viaje_venta_id = 4437 AND producto_id = 20 AND cantidad = 11.000
   AND precio_base = 115.00 AND precio_con_isv = 115.00 AND aplica_isv = 0 AND monto_isv = 0 AND total_isv = 0
   AND subtotal = 1265.00 AND total_linea = 1265.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('1 detalle 6935', IF(@rc = 1, 1, NULL));
-- detalle 6936 (venta 4437): 24.000 x prod 19  57.50 -> 65   (1380.00 -> 1560.00)
UPDATE viaje_venta_detalles
   SET precio_base = 65.00, precio_con_isv = 65.00, subtotal = 1560.00, total_linea = 1560.00, updated_at = @ahora
 WHERE id = 6936 AND viaje_venta_id = 4437 AND producto_id = 19 AND cantidad = 24.000
   AND precio_base = 57.50 AND precio_con_isv = 57.50 AND aplica_isv = 0 AND monto_isv = 0 AND total_isv = 0
   AND subtotal = 1380.00 AND total_linea = 1380.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('1 detalle 6936', IF(@rc = 1, 1, NULL));
-- detalle 6938 (venta 4438): 24.000 x prod 34  130.00 -> 135   (3120.00 -> 3240.00)
UPDATE viaje_venta_detalles
   SET precio_base = 135.00, precio_con_isv = 135.00, subtotal = 3240.00, total_linea = 3240.00, updated_at = @ahora
 WHERE id = 6938 AND viaje_venta_id = 4438 AND producto_id = 34 AND cantidad = 24.000
   AND precio_base = 130.00 AND precio_con_isv = 130.00 AND aplica_isv = 0 AND monto_isv = 0 AND total_isv = 0
   AND subtotal = 3120.00 AND total_linea = 3120.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('1 detalle 6938', IF(@rc = 1, 1, NULL));
-- detalle 6940 (venta 4438): 5.000 x prod 11  115.00 -> 120   (575.00 -> 600.00)
UPDATE viaje_venta_detalles
   SET precio_base = 120.00, precio_con_isv = 120.00, subtotal = 600.00, total_linea = 600.00, updated_at = @ahora
 WHERE id = 6940 AND viaje_venta_id = 4438 AND producto_id = 11 AND cantidad = 5.000
   AND precio_base = 115.00 AND precio_con_isv = 115.00 AND aplica_isv = 0 AND monto_isv = 0 AND total_isv = 0
   AND subtotal = 575.00 AND total_linea = 575.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('1 detalle 6940', IF(@rc = 1, 1, NULL));
-- detalle 6943 (venta 4438): 3.000 x prod 21  115.00 -> 120   (345.00 -> 360.00)
UPDATE viaje_venta_detalles
   SET precio_base = 120.00, precio_con_isv = 120.00, subtotal = 360.00, total_linea = 360.00, updated_at = @ahora
 WHERE id = 6943 AND viaje_venta_id = 4438 AND producto_id = 21 AND cantidad = 3.000
   AND precio_base = 115.00 AND precio_con_isv = 115.00 AND aplica_isv = 0 AND monto_isv = 0 AND total_isv = 0
   AND subtotal = 345.00 AND total_linea = 345.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('1 detalle 6943', IF(@rc = 1, 1, NULL));
-- detalle 6944 (venta 4438): 48.000 x prod 22  57.50 -> 60   (2760.00 -> 2880.00)
UPDATE viaje_venta_detalles
   SET precio_base = 60.00, precio_con_isv = 60.00, subtotal = 2880.00, total_linea = 2880.00, updated_at = @ahora
 WHERE id = 6944 AND viaje_venta_id = 4438 AND producto_id = 22 AND cantidad = 48.000
   AND precio_base = 57.50 AND precio_con_isv = 57.50 AND aplica_isv = 0 AND monto_isv = 0 AND total_isv = 0
   AND subtotal = 2760.00 AND total_linea = 2760.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('1 detalle 6944', IF(@rc = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 2. Totales de las ventas de Jose
-- ---------------------------------------------------------------------
-- venta 4436: 11400.00 -> 12350.00
UPDATE viaje_ventas
   SET subtotal = 12350.00, total = 12350.00, updated_at = @ahora
 WHERE id = 4436 AND viaje_id = 428 AND tipo_pago = 'contado' AND estado = 'completada' AND deleted_at IS NULL
   AND subtotal = 11400.00 AND total = 11400.00 AND impuesto = 0 AND descuento = 0 AND saldo_pendiente = 0;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('2 venta 4436', IF(@rc = 1, 1, NULL));
-- venta 4437: 2645.00 -> 2990.00
UPDATE viaje_ventas
   SET subtotal = 2990.00, total = 2990.00, updated_at = @ahora
 WHERE id = 4437 AND viaje_id = 428 AND tipo_pago = 'contado' AND estado = 'completada' AND deleted_at IS NULL
   AND subtotal = 2645.00 AND total = 2645.00 AND impuesto = 0 AND descuento = 0 AND saldo_pendiente = 0;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('2 venta 4437', IF(@rc = 1, 1, NULL));
-- venta 4438: 13433.00 -> 13713.00
UPDATE viaje_ventas
   SET subtotal = 13713.00, total = 13713.00, updated_at = @ahora
 WHERE id = 4438 AND viaje_id = 428 AND tipo_pago = 'contado' AND estado = 'completada' AND deleted_at IS NULL
   AND subtotal = 13433.00 AND total = 13433.00 AND impuesto = 0 AND descuento = 0 AND saldo_pendiente = 0;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('2 venta 4438', IF(@rc = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 3. Total vendido del viaje de Jose: 31538.00 -> 33113.00
-- ---------------------------------------------------------------------
UPDATE viajes
   SET total_vendido = 33113.00, observaciones = CONCAT(observaciones, ' | [CORRECCION 27/10/2026] Ventas del lunes al precio nuevo (la carga del sabado traia precio viejo). Total vendido 31,538.00 -> 33,113.00. Precios de carga al precio nuevo.'), updated_at = @ahora
 WHERE id = 428 AND estado = 'en_ruta' AND total_vendido = 31538.00 AND observaciones = 'santa rosa de copan ';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('3 viaje 428 total_vendido', IF(@rc = 1, 1, NULL));
UPDATE viajes
   SET observaciones = CONCAT(observaciones, ' | [CORRECCION 27/10/2026] Precios de carga al precio nuevo. Ventas a Evenezer, El Arca y La Canasta Familiar se respetan con su descuento.'), updated_at = @ahora
 WHERE id = 427 AND estado = 'en_ruta' AND total_vendido = 37596.00 AND observaciones = 'san juan intibuca ';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('3 viaje 427 nota', IF(@rc = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 4. Precios de carga de las dos rutas al precio nuevo
--    (precio_venta_minimo es el costo: no se toca)
-- ---------------------------------------------------------------------
-- carga 3528 (viaje 427, prod 19): 60.00 -> 65
UPDATE viaje_cargas
   SET precio_venta_sugerido = 65.00, subtotal_venta = 15600.00, updated_at = @ahora
 WHERE id = 3528 AND viaje_id = 427 AND producto_id = 19 AND cantidad = 240.000
   AND precio_venta_sugerido = 60.00 AND subtotal_venta = 14400.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3528', IF(@rc = 1, 1, NULL));
-- carga 3529 (viaje 427, prod 20): 120.00 -> 130
UPDATE viaje_cargas
   SET precio_venta_sugerido = 130.00, subtotal_venta = 13000.00, updated_at = @ahora
 WHERE id = 3529 AND viaje_id = 427 AND producto_id = 20 AND cantidad = 100.000
   AND precio_venta_sugerido = 120.00 AND subtotal_venta = 12000.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3529', IF(@rc = 1, 1, NULL));
-- carga 3530 (viaje 427, prod 21): 115.00 -> 120
UPDATE viaje_cargas
   SET precio_venta_sugerido = 120.00, subtotal_venta = 2880.00, updated_at = @ahora
 WHERE id = 3530 AND viaje_id = 427 AND producto_id = 21 AND cantidad = 24.000
   AND precio_venta_sugerido = 115.00 AND subtotal_venta = 2760.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3530', IF(@rc = 1, 1, NULL));
-- carga 3531 (viaje 427, prod 22): 57.50 -> 60
UPDATE viaje_cargas
   SET precio_venta_sugerido = 60.00, subtotal_venta = 720.00, updated_at = @ahora
 WHERE id = 3531 AND viaje_id = 427 AND producto_id = 22 AND cantidad = 12.000
   AND precio_venta_sugerido = 57.50 AND subtotal_venta = 690.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3531', IF(@rc = 1, 1, NULL));
-- carga 3533 (viaje 427, prod 18): 60.00 -> 65
UPDATE viaje_cargas
   SET precio_venta_sugerido = 65.00, subtotal_venta = 2340.00, updated_at = @ahora
 WHERE id = 3533 AND viaje_id = 427 AND producto_id = 18 AND cantidad = 36.000
   AND precio_venta_sugerido = 60.00 AND subtotal_venta = 2160.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3533', IF(@rc = 1, 1, NULL));
-- carga 3534 (viaje 427, prod 17): 120.00 -> 130
UPDATE viaje_cargas
   SET precio_venta_sugerido = 130.00, subtotal_venta = 3900.00, updated_at = @ahora
 WHERE id = 3534 AND viaje_id = 427 AND producto_id = 17 AND cantidad = 30.000
   AND precio_venta_sugerido = 120.00 AND subtotal_venta = 3600.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3534', IF(@rc = 1, 1, NULL));
-- carga 3535 (viaje 427, prod 9): 120.00 -> 130
UPDATE viaje_cargas
   SET precio_venta_sugerido = 130.00, subtotal_venta = 1560.00, updated_at = @ahora
 WHERE id = 3535 AND viaje_id = 427 AND producto_id = 9 AND cantidad = 12.000
   AND precio_venta_sugerido = 120.00 AND subtotal_venta = 1440.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3535', IF(@rc = 1, 1, NULL));
-- carga 3536 (viaje 427, prod 11): 110.00 -> 120
UPDATE viaje_cargas
   SET precio_venta_sugerido = 120.00, subtotal_venta = 1440.00, updated_at = @ahora
 WHERE id = 3536 AND viaje_id = 427 AND producto_id = 11 AND cantidad = 12.000
   AND precio_venta_sugerido = 110.00 AND subtotal_venta = 1320.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3536', IF(@rc = 1, 1, NULL));
-- carga 3537 (viaje 427, prod 34): 125.00 -> 135
UPDATE viaje_cargas
   SET precio_venta_sugerido = 135.00, subtotal_venta = 1620.00, updated_at = @ahora
 WHERE id = 3537 AND viaje_id = 427 AND producto_id = 34 AND cantidad = 12.000
   AND precio_venta_sugerido = 125.00 AND subtotal_venta = 1500.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3537', IF(@rc = 1, 1, NULL));
-- carga 3538 (viaje 427, prod 12): 115.00 -> 125
UPDATE viaje_cargas
   SET precio_venta_sugerido = 125.00, subtotal_venta = 1125.00, updated_at = @ahora
 WHERE id = 3538 AND viaje_id = 427 AND producto_id = 12 AND cantidad = 9.000
   AND precio_venta_sugerido = 115.00 AND subtotal_venta = 1035.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3538', IF(@rc = 1, 1, NULL));
-- carga 3540 (viaje 428, prod 9): 120.00 -> 130
UPDATE viaje_cargas
   SET precio_venta_sugerido = 130.00, subtotal_venta = 18720.00, updated_at = @ahora
 WHERE id = 3540 AND viaje_id = 428 AND producto_id = 9 AND cantidad = 144.000
   AND precio_venta_sugerido = 120.00 AND subtotal_venta = 17280.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3540', IF(@rc = 1, 1, NULL));
-- carga 3541 (viaje 428, prod 34): 125.00 -> 135
UPDATE viaje_cargas
   SET precio_venta_sugerido = 135.00, subtotal_venta = 3240.00, updated_at = @ahora
 WHERE id = 3541 AND viaje_id = 428 AND producto_id = 34 AND cantidad = 24.000
   AND precio_venta_sugerido = 125.00 AND subtotal_venta = 3000.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3541', IF(@rc = 1, 1, NULL));
-- carga 3542 (viaje 428, prod 12): 115.00 -> 125
UPDATE viaje_cargas
   SET precio_venta_sugerido = 125.00, subtotal_venta = 3000.00, updated_at = @ahora
 WHERE id = 3542 AND viaje_id = 428 AND producto_id = 12 AND cantidad = 24.000
   AND precio_venta_sugerido = 115.00 AND subtotal_venta = 2760.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3542', IF(@rc = 1, 1, NULL));
-- carga 3543 (viaje 428, prod 11): 110.00 -> 120
UPDATE viaje_cargas
   SET precio_venta_sugerido = 120.00, subtotal_venta = 600.00, updated_at = @ahora
 WHERE id = 3543 AND viaje_id = 428 AND producto_id = 11 AND cantidad = 5.000
   AND precio_venta_sugerido = 110.00 AND subtotal_venta = 550.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3543', IF(@rc = 1, 1, NULL));
-- carga 3544 (viaje 428, prod 19): 60.00 -> 65
UPDATE viaje_cargas
   SET precio_venta_sugerido = 65.00, subtotal_venta = 10920.00, updated_at = @ahora
 WHERE id = 3544 AND viaje_id = 428 AND producto_id = 19 AND cantidad = 168.000
   AND precio_venta_sugerido = 60.00 AND subtotal_venta = 10080.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3544', IF(@rc = 1, 1, NULL));
-- carga 3545 (viaje 428, prod 20): 120.00 -> 130
UPDATE viaje_cargas
   SET precio_venta_sugerido = 130.00, subtotal_venta = 3120.00, updated_at = @ahora
 WHERE id = 3545 AND viaje_id = 428 AND producto_id = 20 AND cantidad = 24.000
   AND precio_venta_sugerido = 120.00 AND subtotal_venta = 2880.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3545', IF(@rc = 1, 1, NULL));
-- carga 3546 (viaje 428, prod 22): 57.50 -> 60
UPDATE viaje_cargas
   SET precio_venta_sugerido = 60.00, subtotal_venta = 4320.00, updated_at = @ahora
 WHERE id = 3546 AND viaje_id = 428 AND producto_id = 22 AND cantidad = 72.000
   AND precio_venta_sugerido = 57.50 AND subtotal_venta = 4140.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3546', IF(@rc = 1, 1, NULL));
-- carga 3547 (viaje 428, prod 21): 115.00 -> 120
UPDATE viaje_cargas
   SET precio_venta_sugerido = 120.00, subtotal_venta = 1440.00, updated_at = @ahora
 WHERE id = 3547 AND viaje_id = 428 AND producto_id = 21 AND cantidad = 12.000
   AND precio_venta_sugerido = 115.00 AND subtotal_venta = 1380.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 carga 3547', IF(@rc = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 5. Ultimo precio de los clientes de Jose (la proxima venta sale al nuevo)
-- ---------------------------------------------------------------------
-- cliente 197 prod 20: 115.00 -> 130
UPDATE cliente_producto
   SET ultimo_precio_venta = 130.00, ultimo_precio_con_isv = 130.00
 WHERE id = 124 AND cliente_id = 197 AND producto_id = 20
   AND ultimo_precio_venta = 115.00 AND ultimo_precio_con_isv = 115.00
   AND cantidad_ultima_venta = 11.00 AND fecha_ultima_venta = '2026-10-26 17:24:14';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('5 cliente_producto 124', IF(@rc = 1, 1, NULL));
-- cliente 197 prod 19: 57.50 -> 65
UPDATE cliente_producto
   SET ultimo_precio_venta = 65.00, ultimo_precio_con_isv = 65.00
 WHERE id = 399 AND cliente_id = 197 AND producto_id = 19
   AND ultimo_precio_venta = 57.50 AND ultimo_precio_con_isv = 57.50
   AND cantidad_ultima_venta = 24.00 AND fecha_ultima_venta = '2026-10-26 17:24:14';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('5 cliente_producto 399', IF(@rc = 1, 1, NULL));
-- cliente 12 prod 34: 130.00 -> 135
UPDATE cliente_producto
   SET ultimo_precio_venta = 135.00, ultimo_precio_con_isv = 135.00
 WHERE id = 460 AND cliente_id = 12 AND producto_id = 34
   AND ultimo_precio_venta = 130.00 AND ultimo_precio_con_isv = 130.00
   AND cantidad_ultima_venta = 24.00 AND fecha_ultima_venta = '2026-10-26 17:28:58';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('5 cliente_producto 460', IF(@rc = 1, 1, NULL));
-- cliente 12 prod 11: 115.00 -> 120
UPDATE cliente_producto
   SET ultimo_precio_venta = 120.00, ultimo_precio_con_isv = 120.00
 WHERE id = 708 AND cliente_id = 12 AND producto_id = 11
   AND ultimo_precio_venta = 115.00 AND ultimo_precio_con_isv = 115.00
   AND cantidad_ultima_venta = 5.00 AND fecha_ultima_venta = '2026-10-26 17:28:58';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('5 cliente_producto 708', IF(@rc = 1, 1, NULL));
-- cliente 248 prod 9: 120.00 -> 130
UPDATE cliente_producto
   SET ultimo_precio_venta = 130.00, ultimo_precio_con_isv = 130.00
 WHERE id = 811 AND cliente_id = 248 AND producto_id = 9
   AND ultimo_precio_venta = 120.00 AND ultimo_precio_con_isv = 120.00
   AND cantidad_ultima_venta = 95.00 AND fecha_ultima_venta = '2026-10-26 17:17:50';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('5 cliente_producto 811', IF(@rc = 1, 1, NULL));
-- cliente 12 prod 21: 115.00 -> 120
UPDATE cliente_producto
   SET ultimo_precio_venta = 120.00, ultimo_precio_con_isv = 120.00
 WHERE id = 953 AND cliente_id = 12 AND producto_id = 21
   AND ultimo_precio_venta = 115.00 AND ultimo_precio_con_isv = 115.00
   AND cantidad_ultima_venta = 3.00 AND fecha_ultima_venta = '2026-10-26 17:28:58';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('5 cliente_producto 953', IF(@rc = 1, 1, NULL));
-- cliente 12 prod 22: 57.50 -> 60
UPDATE cliente_producto
   SET ultimo_precio_venta = 60.00, ultimo_precio_con_isv = 60.00
 WHERE id = 954 AND cliente_id = 12 AND producto_id = 22
   AND ultimo_precio_venta = 57.50 AND ultimo_precio_con_isv = 57.50
   AND cantidad_ultima_venta = 48.00 AND fecha_ultima_venta = '2026-10-26 17:28:58';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('5 cliente_producto 954', IF(@rc = 1, 1, NULL));

-- (El Arca / huevo pequeno de Javier va en un script aparte, despues de este)

-- ---------------------------------------------------------------------
-- 7. Cuadres finales (si algo no cuadra, se detiene y no se guarda nada)
-- ---------------------------------------------------------------------
INSERT INTO _guarda VALUES ('7 cada venta = suma de sus lineas', IF((SELECT COUNT(*) FROM viaje_ventas v WHERE v.viaje_id IN (427,428) AND (v.total <> (SELECT SUM(d.total_linea) FROM viaje_venta_detalles d WHERE d.viaje_venta_id = v.id) - v.descuento OR v.subtotal <> (SELECT SUM(d.subtotal) FROM viaje_venta_detalles d WHERE d.viaje_venta_id = v.id))) = 0, 1, NULL));
INSERT INTO _guarda VALUES ('7 total_vendido = suma de ventas', IF((SELECT COUNT(*) FROM viajes j WHERE j.id IN (427,428) AND j.total_vendido <> (SELECT SUM(v.total) FROM viaje_ventas v WHERE v.viaje_id = j.id AND v.estado IN ('confirmada','completada') AND v.deleted_at IS NULL)) = 0, 1, NULL));
INSERT INTO _guarda VALUES ('7 lineas: subtotal = cantidad x precio', IF((SELECT COUNT(*) FROM viaje_venta_detalles d JOIN viaje_ventas v ON v.id = d.viaje_venta_id WHERE v.viaje_id IN (427,428) AND (d.subtotal <> ROUND(d.cantidad * d.precio_base, 2) OR d.total_linea <> ROUND(d.cantidad * d.precio_con_isv, 2))) = 0, 1, NULL));
INSERT INTO _guarda VALUES ('7 cargas: subtotal_venta = cantidad x precio', IF((SELECT COUNT(*) FROM viaje_cargas WHERE viaje_id IN (427,428) AND subtotal_venta <> ROUND(cantidad * precio_venta_sugerido, 2)) = 0, 1, NULL));
INSERT INTO _guarda VALUES ('7 ninguna carga quedo bajo el precio de bodega', IF((SELECT COUNT(*) FROM viaje_cargas c JOIN bodega_producto bp ON bp.producto_id = c.producto_id AND bp.bodega_id = 1 WHERE c.viaje_id IN (427,428) AND c.precio_venta_sugerido < bp.precio_venta_sugerido) = 0, 1, NULL));
INSERT INTO _guarda VALUES ('7 ninguna venta de Jose quedo bajo el precio nuevo', IF((SELECT COUNT(*) FROM viaje_venta_detalles d JOIN viaje_ventas v ON v.id = d.viaje_venta_id JOIN bodega_producto bp ON bp.producto_id = d.producto_id AND bp.bodega_id = 1 WHERE v.viaje_id = 428 AND d.precio_base < bp.precio_venta_sugerido) = 0, 1, NULL));

-- ---------------------------------------------------------------------
-- 8. Resumen (lo que vas a ver en pantalla)
-- ---------------------------------------------------------------------
SELECT v.numero_venta AS venta, LEFT(c.nombre, 30) AS cliente, v.total
  FROM viaje_ventas v JOIN clientes c ON c.id = v.cliente_id
 WHERE v.viaje_id IN (427, 428) ORDER BY v.viaje_id DESC, v.id;
SELECT numero_viaje, total_vendido FROM viajes WHERE id IN (427, 428);
SELECT COUNT(*) AS pasos_verificados_ok FROM _guarda;

COMMIT;
SELECT 'APLICADO - todo cuadro' AS resultado;
