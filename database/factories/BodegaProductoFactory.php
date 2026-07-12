<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bodega;
use App\Models\BodegaProducto;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\BodegaProducto (tabla pivot bodega_producto).
 *
 * El UNIQUE compuesto (bodega_id, producto_id) garantiza que no haya
 * duplicados. En tests que generen múltiples pivotes para la misma
 * bodega y producto, instancia uno y reutilizá.
 *
 * Defaults razonables para tests de venta:
 *   - stock 100 (suficiente para cualquier escenario común)
 *   - costo_promedio_actual 10.0 (margen claro vs precio_venta_sugerido 20)
 *   - precio_venta_sugerido 20.0
 *
 * Estados disponibles:
 *  - conStock(float) → fija stock específico
 *  - sinStock() → stock en 0
 *  - conReservado(float) → fija stock reservado
 *
 * @extends Factory<BodegaProducto>
 */
class BodegaProductoFactory extends Factory
{
    protected $model = BodegaProducto::class;

    public function definition(): array
    {
        return [
            'bodega_id'             => Bodega::factory(),
            'producto_id'           => Producto::factory(),
            'stock'                 => 100,
            'stock_reservado'       => 0,
            'stock_minimo'          => 10,
            'costo_promedio_actual' => 10.0000,
            'precio_venta_sugerido' => 20.0000,
            'activo'                => true,
        ];
    }

    public function conStock(float $stock): self
    {
        return $this->state(fn(array $attributes) => ['stock' => $stock]);
    }

    public function sinStock(): self
    {
        return $this->state(fn(array $attributes) => ['stock' => 0]);
    }

    public function conReservado(float $reservado): self
    {
        return $this->state(fn(array $attributes) => ['stock_reservado' => $reservado]);
    }
}
