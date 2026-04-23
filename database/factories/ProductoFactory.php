<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Unidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\Producto.
 *
 * SKU tiene índice UNIQUE y es nullable. Generamos uno único para que tests
 * que crean múltiples productos no colisionen.
 *
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    protected $model = Producto::class;

    public function definition(): array
    {
        return [
            'nombre'                => 'Producto '.fake()->unique()->numerify('####'),
            'sku'                   => 'SKU-'.fake()->unique()->numerify('########'),
            'categoria_id'          => Categoria::factory(),
            'unidad_id'             => Unidad::factory(),
            'precio_sugerido'       => fake()->randomFloat(2, 10, 500),
            'descripcion'           => fake()->sentence(),
            'margen_ganancia'       => 5.00,
            'tipo_margen'           => 'monto',
            'aplica_isv'            => false,
            'margen_minimo_seguridad' => 3.00,
            'activo'                => true,
        ];
    }
}
