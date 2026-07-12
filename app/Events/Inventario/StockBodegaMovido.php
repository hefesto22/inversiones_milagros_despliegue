<?php

declare(strict_types=1);

namespace App\Events\Inventario;

use App\Models\BodegaProducto;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara cuando el stock de un producto terminado (empacado / lácteos)
 * cambia en bodega_producto, DESPUÉS de persistir el nuevo saldo y dentro
 * de la transacción del caller.
 *
 * Es el equivalente a nivel BODEGA de los eventos de dominio del lote
 * (CompraAplicadaAlLote, VentaAplicadaAlLote, ...) — nació con el Kardex
 * porque las primitivas de BodegaProducto no emitían ningún evento.
 *
 * Consumidores:
 *   - RegistrarMovimientoKardexListener → asienta la fila en movimientos_inventario.
 *
 * Contexto Kardex (claves reconocidas en $contexto):
 *   - 'kardex_tipo'        → valor de MovimientoInventarioTipo para clasificar
 *                            el asiento (ej. 'venta', 'retorno_viaje'). Si no
 *                            viene, el listener usa 'otro' + el origen.
 *   - 'kardex_descripcion' → resumen legible para la pantalla del Kardex.
 *   - 'kardex_referencia_type' / 'kardex_referencia_id' → documento origen.
 */
final readonly class StockBodegaMovido
{
    use Dispatchable;

    /**
     * @param BodegaProducto $bodegaProducto Registro YA persistido con el stock post-movimiento
     * @param float          $delta          Unidades: positivo = entra, negativo = sale
     * @param float|null     $costoUnitario  Costo unitario del movimiento (null = sin valorar)
     * @param string         $origen         Primitiva que originó el cambio (reducir_stock, entrada_con_costo, ...)
     * @param array          $contexto       Metadatos + claves kardex_* (ver docblock)
     */
    public function __construct(
        public BodegaProducto $bodegaProducto,
        public float          $delta,
        public ?float         $costoUnitario,
        public string         $origen,
        public array          $contexto = [],
    ) {}
}
