-- =====================================================================
-- DESCUENTO CAFEINA EL CERRITO E INVERSIONES ESPINOZA - VJ-000428 (Jose)
-- 2026-10-27. Correr DESPUES de 2026-10-27_correccion_precios_vj427_vj428.sql
--
-- Aclaracion del jefe (27/10 10:04): a Cafeina el Cerrito y a Inversiones
-- Espinoza se les vendio al precio de antes. El precio de lista es el nuevo
-- (130 / 65) y la diferencia es su descuento. Sus dos facturas vuelven a lo
-- que pagaron; la carga se queda en el precio nuevo, asi la pantalla del
-- viaje muestra la diferencia como "Descuentos Otorgados".
--
--   VR-428-0001 Cafeina el Cerrito    95 Huevo Grande 1x30  130 -> 120   12,350 -> 11,400
--   VR-428-0002 Inversiones Espinoza  11 Opoa Mediano 1x30  130 -> 115
--                                     24 Opoa Mediano 1x15   65 -> 57.50  2,990 -> 2,645
--   Total vendido del viaje: baja 1,295.00 (33,113 -> 31,818 si no hubo ventas nuevas)
--   Descuento que va a mostrar la pantalla: 950 + 165 + 180 = 1,295.00
--
-- Las lineas y las ventas vuelven EXACTAMENTE a como estaban en el respaldo
-- de las 08:14 (incluido updated_at). Cafeina Fredy y Villanueva no se tocan.
-- El ultimo precio de esos dos clientes vuelve a lo que realmente pagaron.
--
-- Mismas protecciones: una transaccion, candados con los valores actuales,
-- y si algo no calza se detiene sin guardar nada. Si Jose vendio algo hoy,
-- no estorba: el total del viaje se ajusta restando 1,295 y se comprueba
-- contra la suma real de sus ventas.
-- Correr con: mysql -h ... -u ... -p BASE < este_archivo.sql  (sin --force)
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET @nota := ' | [CORRECCION 27/10/2026] Cafeina el Cerrito y Inversiones Espinoza se respetan a su precio anterior (descuento de 1,295.00 contra el precio nuevo).';

DROP TEMPORARY TABLE IF EXISTS _guarda;
CREATE TEMPORARY TABLE _guarda (
  paso VARCHAR(120) NOT NULL,
  ok   TINYINT NOT NULL CHECK (ok = 1)
);

START TRANSACTION;

INSERT INTO _guarda VALUES ('0 viaje 428 en ruta, precios ya corregidos, este script no corrido', IF((SELECT COUNT(*) FROM viajes WHERE id = 428 AND estado = 'en_ruta' AND observaciones LIKE '%[CORRECCION 27/10/2026] Ventas del lunes al precio nuevo%' AND observaciones NOT LIKE '%Cafeina el Cerrito y Inversiones Espinoza%') = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 1. Lineas de venta al precio que pagaron (como en el respaldo)
-- ---------------------------------------------------------------------
UPDATE viaje_venta_detalles
   SET precio_base = 120.00, precio_con_isv = 120.00, subtotal = 11400.00, total_linea = 11400.00, updated_at = '2026-10-26 17:17:50'
 WHERE id = 6934 AND viaje_venta_id = 4436 AND producto_id = 9 AND cantidad = 95.000
   AND precio_base = 130.00 AND precio_con_isv = 130.00 AND monto_isv = 0 AND total_isv = 0
   AND subtotal = 12350.00 AND total_linea = 12350.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('1 Cerrito 95 Huevo Grande 130 -> 120', IF(@rc = 1, 1, NULL));

UPDATE viaje_venta_detalles
   SET precio_base = 115.00, precio_con_isv = 115.00, subtotal = 1265.00, total_linea = 1265.00, updated_at = '2026-10-26 17:24:14'
 WHERE id = 6935 AND viaje_venta_id = 4437 AND producto_id = 20 AND cantidad = 11.000
   AND precio_base = 130.00 AND precio_con_isv = 130.00 AND monto_isv = 0 AND total_isv = 0
   AND subtotal = 1430.00 AND total_linea = 1430.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('1 Espinoza 11 Opoa Mediano 1x30 130 -> 115', IF(@rc = 1, 1, NULL));

UPDATE viaje_venta_detalles
   SET precio_base = 57.50, precio_con_isv = 57.50, subtotal = 1380.00, total_linea = 1380.00, updated_at = '2026-10-26 17:24:14'
 WHERE id = 6936 AND viaje_venta_id = 4437 AND producto_id = 19 AND cantidad = 24.000
   AND precio_base = 65.00 AND precio_con_isv = 65.00 AND monto_isv = 0 AND total_isv = 0
   AND subtotal = 1560.00 AND total_linea = 1560.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('1 Espinoza 24 Opoa Mediano 1x15 65 -> 57.50', IF(@rc = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 2. Totales de las dos ventas (como en el respaldo)
-- ---------------------------------------------------------------------
UPDATE viaje_ventas
   SET subtotal = 11400.00, total = 11400.00, updated_at = '2026-10-26 17:17:50'
 WHERE id = 4436 AND viaje_id = 428 AND cliente_id = 248 AND tipo_pago = 'contado' AND estado = 'completada' AND deleted_at IS NULL
   AND subtotal = 12350.00 AND total = 12350.00 AND impuesto = 0 AND descuento = 0 AND saldo_pendiente = 0;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('2 VR-428-0001 12350 -> 11400', IF(@rc = 1, 1, NULL));

UPDATE viaje_ventas
   SET subtotal = 2645.00, total = 2645.00, updated_at = '2026-10-26 17:24:14'
 WHERE id = 4437 AND viaje_id = 428 AND cliente_id = 197 AND tipo_pago = 'contado' AND estado = 'completada' AND deleted_at IS NULL
   AND subtotal = 2990.00 AND total = 2990.00 AND impuesto = 0 AND descuento = 0 AND saldo_pendiente = 0;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('2 VR-428-0002 2990 -> 2645', IF(@rc = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 3. Total vendido del viaje: resta 1,295 (respeta ventas nuevas de hoy)
-- ---------------------------------------------------------------------
UPDATE viajes
   SET total_vendido = total_vendido - 1295.00, observaciones = CONCAT(observaciones, @nota)
 WHERE id = 428 AND estado = 'en_ruta' AND observaciones NOT LIKE '%Cafeina el Cerrito y Inversiones Espinoza%';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('3 viaje 428 total vendido -1295', IF(@rc = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 4. Ultimo precio de esos dos clientes = lo que realmente pagaron
-- ---------------------------------------------------------------------
UPDATE cliente_producto SET ultimo_precio_venta = 120.00, ultimo_precio_con_isv = 120.00
 WHERE id = 811 AND cliente_id = 248 AND producto_id = 9 AND ultimo_precio_venta = 130.00
   AND cantidad_ultima_venta = 95.00 AND fecha_ultima_venta = '2026-10-26 17:17:50';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 cliente_producto 811 Cerrito', IF(@rc = 1, 1, NULL));

UPDATE cliente_producto SET ultimo_precio_venta = 115.00, ultimo_precio_con_isv = 115.00
 WHERE id = 124 AND cliente_id = 197 AND producto_id = 20 AND ultimo_precio_venta = 130.00
   AND cantidad_ultima_venta = 11.00 AND fecha_ultima_venta = '2026-10-26 17:24:14';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 cliente_producto 124 Espinoza 1x30', IF(@rc = 1, 1, NULL));

UPDATE cliente_producto SET ultimo_precio_venta = 57.50, ultimo_precio_con_isv = 57.50
 WHERE id = 399 AND cliente_id = 197 AND producto_id = 19 AND ultimo_precio_venta = 65.00
   AND cantidad_ultima_venta = 24.00 AND fecha_ultima_venta = '2026-10-26 17:24:14';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 cliente_producto 399 Espinoza 1x15', IF(@rc = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 5. Cuadres
-- ---------------------------------------------------------------------
INSERT INTO _guarda VALUES ('5 cada venta = suma de sus lineas', IF((SELECT COUNT(*) FROM viaje_ventas v WHERE v.viaje_id = 428 AND (v.total <> (SELECT SUM(d.total_linea) FROM viaje_venta_detalles d WHERE d.viaje_venta_id = v.id) - v.descuento OR v.subtotal <> (SELECT SUM(d.subtotal) FROM viaje_venta_detalles d WHERE d.viaje_venta_id = v.id))) = 0, 1, NULL));
INSERT INTO _guarda VALUES ('5 total vendido = suma de ventas', IF((SELECT total_vendido FROM viajes WHERE id = 428) = (SELECT SUM(total) FROM viaje_ventas WHERE viaje_id = 428 AND estado IN ('confirmada','completada') AND deleted_at IS NULL), 1, NULL));

SELECT v.numero_venta AS venta, LEFT(c.nombre, 30) AS cliente, v.total
  FROM viaje_ventas v JOIN clientes c ON c.id = v.cliente_id
 WHERE v.viaje_id = 428 ORDER BY v.id;
SELECT numero_viaje, total_vendido FROM viajes WHERE id = 428;
SELECT ROUND(SUM(GREATEST(c.precio_venta_sugerido - d.precio_base, 0) * d.cantidad), 2) AS descuentos_que_mostrara_la_pantalla
  FROM viaje_venta_detalles d JOIN viaje_ventas v ON v.id = d.viaje_venta_id JOIN viaje_cargas c ON c.id = d.viaje_carga_id
 WHERE v.viaje_id = 428 AND v.estado IN ('confirmada','completada') AND v.deleted_at IS NULL AND d.precio_base > 0;
SELECT COUNT(*) AS pasos_verificados_ok FROM _guarda;

COMMIT;
SELECT 'APLICADO - descuento de Cerrito y Espinoza' AS resultado;
