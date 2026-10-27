-- Verificacion DESPUES de Bajar/Recargar en el panel (solo lectura, no cambia nada)
SET time_zone = '+00:00';
SELECT n, chequeo, actual, esperado, IF(actual <=> esperado, 'OK', '>>> REVISAR') AS estado FROM (
  SELECT 1 n, 'Carga 1x30 cargado' chequeo, CAST((SELECT cantidad FROM viaje_cargas WHERE id = 3530) AS CHAR) actual, '12.000' esperado
  UNION ALL SELECT 2, 'Carga 1x30 vendido', CAST((SELECT cantidad_vendida FROM viaje_cargas WHERE id = 3530) AS CHAR), '12.000'
  UNION ALL SELECT 3, 'Carga 1x15 cargado', CAST((SELECT cantidad FROM viaje_cargas WHERE id = 3531) AS CHAR), '24.000'
  UNION ALL SELECT 4, 'Carga 1x15 vendido', CAST((SELECT cantidad_vendida FROM viaje_cargas WHERE id = 3531) AS CHAR), '24.000'
  UNION ALL SELECT 5, 'Viaje 427 estado', (SELECT estado FROM viajes WHERE id = 427), 'en_ruta'
  UNION ALL SELECT 6, 'Viaje 427 total vendido', CAST((SELECT total_vendido FROM viajes WHERE id = 427) AS CHAR), '36966.00'
  UNION ALL SELECT 7, 'Venta El Arca total', CAST((SELECT total FROM viaje_ventas WHERE id = 4441) AS CHAR), '2520.00'
  UNION ALL SELECT 8, 'Kardex lote 2 neto de esas cargas hoy (huevos)', CAST((SELECT SUM(delta) FROM movimientos_inventario WHERE lote_id = 2 AND referencia_type = 'App\\Models\\Reempaque' AND referencia_id IN (2747, 2748, (SELECT reempaque_id FROM viaje_cargas WHERE id = 3531)) AND created_at >= '2026-10-27 14:00:00') AS CHAR), '180.000'
) t ORDER BY n;
SELECT cantidad_huevos_remanente AS lote2_huevos_AHORA FROM lotes WHERE id = 2;
SELECT id, ocurrido_en, tipo, delta, saldo_despues, referencia_id, LEFT(descripcion, 60) AS descripcion
  FROM movimientos_inventario WHERE lote_id = 2 AND created_at >= '2026-10-27 14:00:00' ORDER BY id;
