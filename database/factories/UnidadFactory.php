<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Unidad;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\Unidad.
 *
 * Usa Sequence implícito vía fake()->unique() para evitar colisión en la columna
 * `nombre` que tiene índice UNIQUE.
 *
 * @extends Factory<Unidad>
 */
class UnidadFactory extends Factory
{
    protected $model = Unidad::class;

    public function definition(): array
    {
        return [
            'nombre'    => 'unidad_'.fake()->unique()->numerify('########'),
            'simbolo'   => fake()->lexify('??'),
            'es_decimal'=> false,
            'activo'    => true,
            'created_by'=> User::factory(),
        ];
    }
}
