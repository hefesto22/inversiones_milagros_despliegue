<?php

declare(strict_types=1);

namespace App\Services\Viaje;

use App\Models\ViajeVenta;
use App\Models\ViajeVentaDetalle;

/**
 * Descuento de una línea de venta de ruta contra el precio de lista.
 *
 * El precio de lista de una línea es el precio sugerido de la carga del
 * viaje (viaje_cargas.precio_venta_sugerido, sin ISV). Si la línea se vendió
 * por debajo, la diferencia es el descuento que se le dio al cliente.
 *
 * Reglas:
 *  - Línea a precio 0 = entrega por cambio/reposición: no es descuento ni
 *    tiene precio de lista (invariante 7 del proyecto).
 *  - Vendida al precio de lista o por encima: sin descuento; su precio de
 *    lista se muestra igual al de venta, para que lista − descuento siempre
 *    dé el precio al que se vendió.
 *
 * Es la única fuente de esta regla: la usan el resumen del viaje
 * (ViewViaje) y el detalle de la venta (ventas-detalle-modal). Quien llame
 * debe traer `detalles.viajeCarga` precargado para no caer en N+1.
 */
final class DescuentoLineaRuta
{
    public function esEntregaPorCambio(ViajeVentaDetalle $detalle): bool
    {
        return (float) $detalle->precio_base <= 0;
    }

    public function descuentoUnitario(ViajeVentaDetalle $detalle): float
    {
        if ($this->esEntregaPorCambio($detalle)) {
            return 0.0;
        }

        $precioVenta = (float) $detalle->precio_base;
        $precioCarga = (float) ($detalle->viajeCarga?->precio_venta_sugerido ?? $precioVenta);

        return round(max(0.0, $precioCarga - $precioVenta), 2);
    }

    public function precioLista(ViajeVentaDetalle $detalle): float
    {
        if ($this->esEntregaPorCambio($detalle)) {
            return 0.0;
        }

        return round((float) $detalle->precio_base + $this->descuentoUnitario($detalle), 2);
    }

    public function descuentoLinea(ViajeVentaDetalle $detalle): float
    {
        return round($this->descuentoUnitario($detalle) * (float) $detalle->cantidad, 2);
    }

    /**
     * Totales de la venta a precio de lista (sin ISV).
     *
     * subtotal_lista − descuento = subtotal de la venta (suma de precio_base × cantidad).
     *
     * @return array{subtotal_lista: float, descuento: float}
     */
    public function totales(ViajeVenta $venta): array
    {
        $subtotalLista = 0.0;
        $descuento = 0.0;

        foreach ($venta->detalles as $detalle) {
            $subtotalLista += round($this->precioLista($detalle) * (float) $detalle->cantidad, 2);
            $descuento += $this->descuentoLinea($detalle);
        }

        return [
            'subtotal_lista' => round($subtotalLista, 2),
            'descuento' => round($descuento, 2),
        ];
    }
}
