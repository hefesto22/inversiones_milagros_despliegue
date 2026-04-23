<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\Proveedor.
 *
 * RTN hondureño formato: 14 dígitos. Generamos uno sintético único para evitar
 * colisión con el índice UNIQUE en la columna rtn.
 *
 * @extends Factory<Proveedor>
 */
class ProveedorFactory extends Factory
{
    protected $model = Proveedor::class;

    public function definition(): array
    {
        return [
            'nombre'    => 'Proveedor '.fake()->unique()->numerify('####'),
            'rtn'       => fake()->unique()->numerify('##############'),
            'telefono'  => fake()->numerify('####-####'),
            'direccion' => fake()->address(),
            'email'     => fake()->unique()->safeEmail(),
            'estado'    => true,
        ];
    }
}
