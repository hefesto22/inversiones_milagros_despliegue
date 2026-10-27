-- =====================================================================
-- CORRECCION EL ARCA / HUEVO PEQUENO - VJ-000427 (Javier) - 2026-10-27
-- Correr DESPUES de 2026-10-27_correccion_precios_vj427_vj428.sql
--
-- Lo real (confirmado por el jefe 27/10 08:56):
--   Se cargaron 12 Opoa Huevo Pequeno 1x30 (el sistema dice 24) y
--   24 Opoa Huevo Pequeno 1x15 (el sistema dice 12).
--   El Arca (VR-427-0002) recibio 12 de 1x30 a 105 y 24 de 1x15 a 52.50
--   y pago 2,520.00 (la factura dice 3,150.00).
--
-- Este script corrige SOLO el lado de la venta:
--   * lineas de VR-427-0002: 1x30 24 -> 12, 1x15 12 -> 24 (precios igual)
--   * total de la venta 3,150 -> 2,520 y total vendido del viaje 37,596 -> 36,966
--   * lo vendido en las cargas: 1x30 24 -> 12, 1x15 12 -> 24
--   * estadisticas de El Arca en cliente_producto
--
-- El inventario NO se toca aqui. Despues de este script, en el panel:
--   VJ-000427 -> Recargar -> "Bajar" 12 de Opoa Huevo Pequeno 1x30
--                         -> "Recargar" 12 de Opoa Huevo Pequeno 1x15
--                         -> Continuar Ruta
-- Eso devuelve 360 huevos al lote 2 y saca 180 (neto +180) con su Kardex,
-- que es lo que fisicamente hay de mas en el lote.
--
-- Mismas protecciones que el de precios: una transaccion, candados con los
-- valores actuales, y si algo no calza se detiene sin guardar nada.
-- Correr con: mysql -h ... -u ... -p BASE < este_archivo.sql  (sin --force)
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET @ahora := UTC_TIMESTAMP();
SET @nota := ' | [CORRECCION 27/10/2026] Opoa Huevo Pequeno: se cargaron 12 de 1x30 (no 24) y 24 de 1x15 (no 12). El Arca VR-427-0002 corregida a 12 + 24 = 2,520.00 (antes 3,150.00). Cantidades de carga ajustadas con Bajar/Recargar.';

DROP TEMPORARY TABLE IF EXISTS _guarda;
CREATE TEMPORARY TABLE _guarda (
  paso VARCHAR(120) NOT NULL,
  ok   TINYINT NOT NULL CHECK (ok = 1)
);

START TRANSACTION;

-- ---------------------------------------------------------------------
-- 0. Estado esperado (precios ya corregidos, nada mas tocado)
-- ---------------------------------------------------------------------
INSERT INTO _guarda VALUES ('0 viaje 427 en ruta, total 37596, precios ya corregidos', IF((SELECT COUNT(*) FROM viajes WHERE id = 427 AND estado = 'en_ruta' AND total_vendido = 37596.00 AND observaciones LIKE '%[CORRECCION 27/10/2026] Precios de carga%' AND observaciones NOT LIKE '%Opoa Huevo Pequeno: se cargaron%') = 1, 1, NULL));
INSERT INTO _guarda VALUES ('0 viaje 427 tiene 4 ventas', IF((SELECT COUNT(*) FROM viaje_ventas WHERE viaje_id = 427) = 4, 1, NULL));
INSERT INTO _guarda VALUES ('0 carga 3530 1x30: 24 cargados, 24 vendidos', IF((SELECT COUNT(*) FROM viaje_cargas WHERE id = 3530 AND viaje_id = 427 AND producto_id = 21 AND cantidad = 24 AND cantidad_vendida = 24 AND cantidad_merma = 0 AND cantidad_devuelta = 0 AND cantidad_de_lote = 24 AND reempaque_id = 2747 AND precio_venta_sugerido = 120.00) = 1, 1, NULL));
INSERT INTO _guarda VALUES ('0 carga 3531 1x15: 12 cargados, 12 vendidos', IF((SELECT COUNT(*) FROM viaje_cargas WHERE id = 3531 AND viaje_id = 427 AND producto_id = 22 AND cantidad = 12 AND cantidad_vendida = 12 AND cantidad_merma = 0 AND cantidad_devuelta = 0 AND cantidad_de_lote = 12 AND reempaque_id = 2748 AND precio_venta_sugerido = 60.00) = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 1. Lineas de la venta de El Arca
-- ---------------------------------------------------------------------
UPDATE viaje_venta_detalles
   SET cantidad = 12.000, subtotal = 1260.00, total_linea = 1260.00, updated_at = @ahora
 WHERE id = 6949 AND viaje_venta_id = 4441 AND viaje_carga_id = 3530 AND producto_id = 21
   AND cantidad = 24.000 AND precio_base = 105.00 AND precio_con_isv = 105.00 AND monto_isv = 0 AND total_isv = 0
   AND subtotal = 2520.00 AND total_linea = 2520.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('1 linea 6949 1x30 24 -> 12', IF(@rc = 1, 1, NULL));

UPDATE viaje_venta_detalles
   SET cantidad = 24.000, subtotal = 1260.00, total_linea = 1260.00, updated_at = @ahora
 WHERE id = 6950 AND viaje_venta_id = 4441 AND viaje_carga_id = 3531 AND producto_id = 22
   AND cantidad = 12.000 AND precio_base = 52.50 AND precio_con_isv = 52.50 AND monto_isv = 0 AND total_isv = 0
   AND subtotal = 630.00 AND total_linea = 630.00;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('1 linea 6950 1x15 12 -> 24', IF(@rc = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 2. Total de la venta y del viaje
-- ---------------------------------------------------------------------
UPDATE viaje_ventas
   SET subtotal = 2520.00, total = 2520.00, updated_at = @ahora
 WHERE id = 4441 AND viaje_id = 427 AND cliente_id = 15 AND tipo_pago = 'contado' AND estado = 'completada' AND deleted_at IS NULL
   AND subtotal = 3150.00 AND total = 3150.00 AND impuesto = 0 AND descuento = 0 AND saldo_pendiente = 0;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('2 venta 4441 3150 -> 2520', IF(@rc = 1, 1, NULL));

UPDATE viajes
   SET total_vendido = 36966.00, observaciones = CONCAT(observaciones, @nota)
 WHERE id = 427 AND estado = 'en_ruta' AND total_vendido = 37596.00
   AND observaciones LIKE '%[CORRECCION 27/10/2026] Precios de carga%' AND observaciones NOT LIKE '%Opoa Huevo Pequeno: se cargaron%';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('2 viaje 427 total 37596 -> 36966', IF(@rc = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 3. Lo vendido en las cargas (el inventario lo ajustan Bajar/Recargar)
-- ---------------------------------------------------------------------
UPDATE viaje_cargas SET cantidad_vendida = 12.000
 WHERE id = 3530 AND viaje_id = 427 AND producto_id = 21 AND cantidad = 24 AND cantidad_vendida = 24;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('3 carga 3530 vendido 24 -> 12', IF(@rc = 1, 1, NULL));

UPDATE viaje_cargas SET cantidad_vendida = 24.000
 WHERE id = 3531 AND viaje_id = 427 AND producto_id = 22 AND cantidad = 12 AND cantidad_vendida = 12;
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('3 carga 3531 vendido 12 -> 24', IF(@rc = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 4. Estadisticas de El Arca (cantidad de la ultima venta y acumulado)
-- ---------------------------------------------------------------------
UPDATE cliente_producto SET cantidad_ultima_venta = 12.00, cantidad_total_vendida = 24.00
 WHERE id = 826 AND cliente_id = 15 AND producto_id = 21 AND cantidad_ultima_venta = 24.00 AND cantidad_total_vendida = 36.00
   AND fecha_ultima_venta = '2026-10-26 18:30:34';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 cliente_producto 826', IF(@rc = 1, 1, NULL));

UPDATE cliente_producto SET cantidad_ultima_venta = 24.00, cantidad_total_vendida = 73.00
 WHERE id = 825 AND cliente_id = 15 AND producto_id = 22 AND cantidad_ultima_venta = 12.00 AND cantidad_total_vendida = 61.00
   AND fecha_ultima_venta = '2026-10-26 18:30:34';
SET @rc := ROW_COUNT();
INSERT INTO _guarda VALUES ('4 cliente_producto 825', IF(@rc = 1, 1, NULL));

-- ---------------------------------------------------------------------
-- 5. Cuadres
-- ---------------------------------------------------------------------
INSERT INTO _guarda VALUES ('5 venta 4441 = suma de lineas', IF((SELECT SUM(total_linea) FROM viaje_venta_detalles WHERE viaje_venta_id = 4441) = 2520.00 AND (SELECT total FROM viaje_ventas WHERE id = 4441) = 2520.00, 1, NULL));
INSERT INTO _guarda VALUES ('5 total vendido 427 = suma de ventas', IF((SELECT total_vendido FROM viajes WHERE id = 427) = (SELECT SUM(total) FROM viaje_ventas WHERE viaje_id = 427 AND estado IN ('confirmada','completada') AND deleted_at IS NULL), 1, NULL));
INSERT INTO _guarda VALUES ('5 vendido en carga = suma de lineas (1x30 y 1x15)', IF((SELECT COUNT(*) FROM viaje_cargas c WHERE c.id IN (3530, 3531) AND c.cantidad_vendida = (SELECT SUM(d.cantidad) FROM viaje_venta_detalles d JOIN viaje_ventas v ON v.id = d.viaje_venta_id WHERE d.viaje_carga_id = c.id AND v.estado IN ('confirmada','completada') AND v.deleted_at IS NULL)) = 2, 1, NULL));

SELECT v.numero_venta AS venta, LEFT(c.nombre, 30) AS cliente, v.total
  FROM viaje_ventas v JOIN clientes c ON c.id = v.cliente_id
 WHERE v.viaje_id = 427 ORDER BY v.id;
SELECT numero_viaje, total_vendido FROM viajes WHERE id = 427;
SELECT p.nombre AS producto, c.cantidad AS cargado, c.cantidad_vendida AS vendido,
       c.cantidad - c.cantidad_vendida - c.cantidad_merma - c.cantidad_devuelta AS disponible
  FROM viaje_cargas c JOIN productos p ON p.id = c.producto_id WHERE c.id IN (3530, 3531);
SELECT cantidad_huevos_remanente AS lote2_huevos_ANTES_de_los_botones FROM lotes WHERE id = 2;
SELECT COUNT(*) AS pasos_verificados_ok FROM _guarda;

COMMIT;
SELECT 'APLICADO - ahora hacer Bajar/Recargar en el panel' AS resultado;
