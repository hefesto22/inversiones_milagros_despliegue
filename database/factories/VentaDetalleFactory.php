<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Producto;
use App\Models\Unidad;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\VentaDetalle.
 *
 * Defaults coherentes para que los totales internos cuadren entre sí:
 *  - cantidad 1, precio 20, costo 10 → ganancia clara
 *  - subtotal = cantidad * precio_unitario (sin ISV)
 *  - total_linea = subtotal + total_isv - descuento_monto
 *
 * Si pasás overrides parciales, asegurate de mantener la consistencia
 * o aceptar que recalcularTotales() lo arregle.
 *
 * @extends Factory<VentaDetalle>
 */
class VentaDetalleFactory extends Factory
{
    protected $model = VentaDetalle::class;

    public function definition(): array
    {
        $cantidad = 1;
        $precioUnitario = 20.00;
        $costoUnitario = 10.00;
        $isvUnitario = 0;
        $aplicaIsv = false;

        $subtotal = $cantidad * $precioUnitario;
        $totalIsv = $cantidad * $isvUnitario;
        $totalLinea = $subtotal + $totalIsv;

        return [
            'venta_id'             => Venta::factory(),
            'producto_id'          => Producto::factory(),
            'unidad_id'            => Unidad::factory(),
            'cantidad'             => $cantidad,
            'precio_unitario'      => $precioUnitario,
            'precio_con_isv'       => $precioUnitario,
            'costo_unitario'       => $costoUnitario,
            'aplica_isv'           => $aplicaIsv,
            'isv_unitario'         => $isvUnitario,
            'descuento_porcentaje' => 0,
            'descuento_monto'      => 0,
            'subtotal'             => $subtotal,
            'total_isv'            => $totalIsv,
            'total_linea'          => $totalLinea,
            'precio_anterior'      => null,
        ];
    }
}
