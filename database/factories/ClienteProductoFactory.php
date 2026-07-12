<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\ClienteProducto;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\ClienteProducto (tabla pivot cliente_producto).
 *
 * El UNIQUE compuesto (cliente_id, producto_id) garantiza que no haya
 * registros duplicados. En tests que generan muchos pivots, usar
 * combinaciones explícitas o forCliente()/forProducto() en lugar de
 * dejar que el factory cree dos veces el mismo par.
 *
 * Estados disponibles:
 *  - conPrecioAutorizado(float) → fija un precio_autorizado específico
 *  - conDescuentoOverride(float) → fija un descuento_maximo_override
 *  - conHistorial(int $ventas) → simula registros con ventas pasadas
 *
 * @extends Factory<ClienteProducto>
 */
class ClienteProductoFactory extends Factory
{
    protected $model = ClienteProducto::class;

    public function definition(): array
    {
        return [
            'cliente_id'                => Cliente::factory(),
            'producto_id'               => Producto::factory(),
            'ultimo_precio_venta'       => null,
            'ultimo_precio_con_isv'     => null,
            'cantidad_ultima_venta'     => null,
            'fecha_ultima_venta'        => null,
            'total_ventas'              => 0,
            'cantidad_total_vendida'    => 0,
            'descuento_maximo_override' => null,
            'precio_autorizado'         => null,
        ];
    }

    /**
     * Estado: registro con precio_autorizado configurado.
     *
     * Lo usás en tests del bloqueo para Consumidor Final.
     */
    public function conPrecioAutorizado(float $precio): self
    {
        return $this->state(fn(array $attributes) => [
            'precio_autorizado' => $precio,
        ]);
    }

    /**
     * Estado: registro con descuento_maximo_override configurado.
     */
    public function conDescuentoOverride(float $descuento): self
    {
        return $this->state(fn(array $attributes) => [
            'descuento_maximo_override' => $descuento,
        ]);
    }

    /**
     * Estado: registro con historial de ventas previas.
     */
    public function conHistorial(int $ventas = 5, float $ultimoPrecio = 50.00): self
    {
        return $this->state(fn(array $attributes) => [
            'ultimo_precio_venta'    => $ultimoPrecio,
            'ultimo_precio_con_isv'  => round($ultimoPrecio * 1.15, 2),
            'cantidad_ultima_venta'  => 10,
            'fecha_ultima_venta'     => now()->subDays(7),
            'total_ventas'           => $ventas,
            'cantidad_total_vendida' => $ventas * 10,
        ]);
    }
}
