<?php

namespace Database\Factories;

use App\Models\Venta;
use App\Models\Cliente;
use App\Models\Bodega;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Venta>
 */
class VentaFactory extends Factory
{
    protected $model = Venta::class;

    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory(),
            'bodega_id' => Bodega::factory(),
            'numero_venta' => null,
            'tipo_pago' => $this->faker->randomElement(['contado', 'credito']),
            'subtotal' => $this->faker->randomFloat(2, 100, 5000),
            'total_isv' => $this->faker->randomFloat(2, 15, 750),
            'descuento' => $this->faker->randomFloat(2, 0, 500),
            'total' => $this->faker->randomFloat(2, 100, 5000),
            'monto_pagado' => 0,
            'saldo_pendiente' => $this->faker->randomFloat(2, 100, 5000),
            'fecha_vencimiento' => $this->faker->optional()->dateTime(),
            'estado' => 'borrador',
            'estado_pago' => 'pendiente',
            'nota' => null,
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }

    public function borrador(): self
    {
        return $this->state(fn(array $attributes) => [
            'estado' => 'borrador',
            'estado_pago' => 'pendiente',
            'numero_venta' => null,
        ]);
    }

    public function completada(): self
    {
        return $this->state(fn(array $attributes) => [
            'estado' => 'completada',
            'numero_venta' => $this->faker->bothify('V##-??????-####'),
        ]);
    }

    public function pendientePago(): self
    {
        return $this->state(fn(array $attributes) => [
            'estado' => 'pendiente_pago',
            'estado_pago' => 'pendiente',
            'numero_venta' => $this->faker->bothify('V##-??????-####'),
            'tipo_pago' => 'credito',
        ]);
    }

    public function pagada(): self
    {
        return $this->state(fn(array $attributes) => [
            'estado' => 'pagada',
            'estado_pago' => 'pagado',
            'numero_venta' => $this->faker->bothify('V##-??????-####'),
            'monto_pagado' => $attributes['total'],
            'saldo_pendiente' => 0,
        ]);
    }

    public function cancelada(): self
    {
        return $this->state(fn(array $attributes) => [
            'estado' => 'cancelada',
            'nota' => '[CANCELADA] ' . $this->faker->sentence(),
        ]);
    }

    public function credito(): self
    {
        return $this->state(fn(array $attributes) => [
            'tipo_pago' => 'credito',
            'fecha_vencimiento' => $this->faker->dateTime(),
        ]);
    }

    public function contado(): self
    {
        return $this->state(fn(array $attributes) => [
            'tipo_pago' => 'contado',
        ]);
    }
}
