<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bodega;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\Bodega.
 *
 * La columna `codigo` tiene índice UNIQUE y es nullable — generamos un valor
 * único con prefijo predecible para que los tests sean debuggeables si uno falla.
 *
 * @extends Factory<Bodega>
 */
class BodegaFactory extends Factory
{
    protected $model = Bodega::class;

    public function definition(): array
    {
        return [
            'nombre'    => 'Bodega '.fake()->unique()->numerify('####'),
            'codigo'    => 'B'.fake()->unique()->numerify('######'),
            'ubicacion' => fake()->address(),
            'activo'    => true,
            'created_by'=> User::factory(),
        ];
    }
}
