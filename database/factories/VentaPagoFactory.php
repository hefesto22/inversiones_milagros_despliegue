<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Venta;
use App\Models\VentaPago;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\VentaPago.
 *
 * Default: pago de 100 L en efectivo, sin referencia ni nota.
 *
 * @extends Factory<VentaPago>
 */
class VentaPagoFactory extends Factory
{
    protected $model = VentaPago::class;

    public function definition(): array
    {
        return [
            'venta_id'    => Venta::factory(),
            'monto'       => 100.00,
            'metodo_pago' => 'efectivo',
            'referencia'  => null,
            'nota'        => null,
            'created_by'  => User::factory(),
        ];
    }
}
