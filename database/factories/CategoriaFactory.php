<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\Categoria.
 *
 * Usa fake()->unique() en `nombre` por índice UNIQUE en la tabla categorias.
 *
 * @extends Factory<Categoria>
 */
class CategoriaFactory extends Factory
{
    protected $model = Categoria::class;

    public function definition(): array
    {
        return [
            'nombre'     => 'cat_'.fake()->unique()->numerify('########'),
            'aplica_isv' => false,
            'activo'     => true,
            'created_by' => User::factory(),
        ];
    }
}
