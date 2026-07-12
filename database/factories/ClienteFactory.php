<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\Cliente.
 *
 * El campo `rtn` tiene constraint UNIQUE en BD y es nullable. Generamos uno
 * único para no colisionar en suites que crean varios clientes.
 *
 * Estados disponibles:
 *  - consumidorFinal() → marca al cliente como el especial CF-0000000000000
 *  - mayorista() / minorista() / distribuidor() / ruta() → tipos de cliente
 *  - conCredito() → habilita compra a crédito con límite
 *
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    public function definition(): array
    {
        return [
            'nombre'                    => fake()->company(),
            'rtn'                       => fake()->unique()->numerify('080#-####-######'),
            'telefono'                  => fake()->phoneNumber(),
            'direccion'                 => fake()->address(),
            'email'                     => fake()->unique()->safeEmail(),
            'tipo'                      => 'minorista',
            'limite_credito'            => 0,
            'saldo_pendiente'           => 0,
            'dias_credito'              => 0,
            'acepta_devolucion'         => false,
            'porcentaje_devolucion_max' => 0,
            'dias_devolucion'           => 0,
            'estado'                    => true,
            'created_by'                => User::factory(),
        ];
    }

    /**
     * Estado: cliente especial "Consumidor Final".
     *
     * Usa el RTN reservado en Cliente::RTN_CONSUMIDOR_FINAL para que
     * Cliente::esConsumidorFinal() lo reconozca correctamente.
     */
    public function consumidorFinal(): self
    {
        return $this->state(fn(array $attributes) => [
            'nombre' => 'Consumidor Final',
            'rtn'    => Cliente::RTN_CONSUMIDOR_FINAL,
            'tipo'   => 'minorista',
        ]);
    }

    public function mayorista(): self
    {
        return $this->state(fn(array $attributes) => ['tipo' => 'mayorista']);
    }

    public function minorista(): self
    {
        return $this->state(fn(array $attributes) => ['tipo' => 'minorista']);
    }

    public function distribuidor(): self
    {
        return $this->state(fn(array $attributes) => ['tipo' => 'distribuidor']);
    }

    public function ruta(): self
    {
        return $this->state(fn(array $attributes) => ['tipo' => 'ruta']);
    }

    /**
     * Estado: cliente con crédito habilitado.
     */
    public function conCredito(float $limite = 10000.00, int $dias = 30): self
    {
        return $this->state(fn(array $attributes) => [
            'limite_credito' => $limite,
            'dias_credito'   => $dias,
        ]);
    }
}
