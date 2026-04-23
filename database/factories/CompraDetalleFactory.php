<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\Producto;
use App\Models\Unidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\CompraDetalle.
 *
 * Importante: la columna `subtotal` no aparece en $fillable del modelo pero
 * es obligatoria en BD (default 0). Se crea automáticamente por BD.
 *
 * @extends Factory<CompraDetalle>
 */
class CompraDetalleFactory extends Factory
{
    protected $model = CompraDetalle::class;

    public function definition(): array
    {
        return [
            'compra_id'          => Compra::factory(),
            'producto_id'        => Producto::factory(),
            'unidad_id'          => Unidad::factory(),
            'cantidad_facturada' => 100,
            'cantidad_regalo'    => 0,
            'cantidad_recibida'  => 100,
            'precio_unitario'    => 78.15,
            'descuento'          => 0,
            'impuesto'           => 0,
        ];
    }
}
