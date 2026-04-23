<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CompraEstado;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\Compra.
 *
 * Estado por defecto 'borrador' para evitar side effects al crear en tests
 * (estados recibida_* podrían disparar lógica adicional en observers futuros).
 *
 * @extends Factory<Compra>
 */
class CompraFactory extends Factory
{
    protected $model = Compra::class;

    public function definition(): array
    {
        return [
            'proveedor_id'  => Proveedor::factory(),
            'bodega_id'     => Bodega::factory(),
            'numero_compra' => 'COMP-'.fake()->unique()->numerify('########'),
            'tipo_pago'     => 'contado',
            'estado'        => CompraEstado::Borrador,
            'total'         => 0,
        ];
    }
}
