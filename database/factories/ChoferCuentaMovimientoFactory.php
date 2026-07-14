<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChoferCuentaMovimiento;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\ChoferCuentaMovimiento.
 *
 * Default: comisión de L 100.00 para un chofer nuevo, sin viaje ni
 * liquidación asociados (las FKs son nullable en el esquema).
 *
 * @extends Factory<ChoferCuentaMovimiento>
 */
class ChoferCuentaMovimientoFactory extends Factory
{
    protected $model = ChoferCuentaMovimiento::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tipo' => ChoferCuentaMovimiento::TIPO_COMISION,
            'monto' => 100.00,
            'saldo_anterior' => 0,
            'saldo_nuevo' => 100.00,
            'viaje_id' => null,
            'liquidacion_id' => null,
            'concepto' => 'Movimiento de prueba',
            'created_by' => null,
        ];
    }

    /**
     * Comisión ganada por el monto dado.
     */
    public function comision(float $monto): static
    {
        return $this->state(fn () => [
            'tipo' => ChoferCuentaMovimiento::TIPO_COMISION,
            'monto' => $monto,
            'saldo_anterior' => 0,
            'saldo_nuevo' => $monto,
        ]);
    }

    /**
     * Pago de liquidación por el monto dado.
     */
    public function pagoLiquidacion(float $monto): static
    {
        return $this->state(fn () => [
            'tipo' => ChoferCuentaMovimiento::TIPO_PAGO_LIQUIDACION,
            'monto' => $monto,
            'saldo_anterior' => $monto,
            'saldo_nuevo' => 0,
        ]);
    }

    /**
     * Asignar el movimiento a un chofer existente.
     */
    public function paraChofer(User $chofer): static
    {
        return $this->state(fn () => ['user_id' => $chofer->id]);
    }

    /**
     * Fijar la fecha del movimiento (el Estado de Resultados filtra
     * por created_at).
     */
    public function creadoEl(string $fechaHora): static
    {
        return $this->state(fn () => [
            'created_at' => $fechaHora,
            'updated_at' => $fechaHora,
        ]);
    }
}
