<?php

namespace Tests\Feature\Ventas;

use App\Filament\Resources\VentaResource\Pages\CreateVenta;
use App\Models\Cliente;
use App\Models\ClienteProducto;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests de la defensa en profundidad en CreateVenta.
 *
 * Estos tests cubren la capa más crítica: la validación en backend que
 * se ejecuta justo antes de persistir la venta. Si esto pasa, ninguna
 * manipulación del DOM puede saltar el bloqueo de precio.
 *
 * Esquema: usamos reflection para invocar el método protegido
 * mutateFormDataBeforeCreate en isolation, sin necesidad de montar
 * el ciclo Livewire/Filament completo.
 */
class CreateVentaPrecioBloqueadoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Invoca mutateFormDataBeforeCreate vía reflection. Sigue el contrato real
     * sin requerir el ciclo de vida completo de un Livewire CreateRecord.
     */
    private function invocarMutate(array $data): array
    {
        $instance = new CreateVenta;
        $method = new \ReflectionMethod($instance, 'mutateFormDataBeforeCreate');
        $method->setAccessible(true);

        return $method->invoke($instance, $data);
    }

    #[Test]
    public function consumidor_final_con_precio_correcto_pasa_la_validacion(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        $data = [
            'cliente_id' => $consumidorFinal->id,
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    'precio_unitario' => 100.00, // exacto al autorizado
                    'cantidad' => 1,
                ],
            ],
        ];

        $result = $this->invocarMutate($data);

        // No lanza excepción y devuelve la data intacta + metadata default
        $this->assertSame($consumidorFinal->id, $result['cliente_id']);
        $this->assertSame('borrador', $result['estado']);
    }

    #[Test]
    public function consumidor_final_con_precio_distinto_es_rechazado(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        $data = [
            'cliente_id' => $consumidorFinal->id,
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    'precio_unitario' => 80.00, // ❌ distinto al autorizado L 100
                    'cantidad' => 1,
                ],
            ],
        ];

        $this->expectException(ValidationException::class);
        $this->invocarMutate($data);
    }

    #[Test]
    public function consumidor_final_con_precio_autorizado_excepcion_aplica_ese_precio(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        // Admin configuró excepción específica
        ClienteProducto::factory()
            ->conPrecioAutorizado(85.00)
            ->create([
                'cliente_id' => $consumidorFinal->id,
                'producto_id' => $producto->id,
            ]);

        $data = [
            'cliente_id' => $consumidorFinal->id,
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    'precio_unitario' => 85.00, // coincide con excepción
                    'cantidad' => 1,
                ],
            ],
        ];

        // No lanza — pasa la validación con el precio de excepción
        $result = $this->invocarMutate($data);
        $this->assertSame($consumidorFinal->id, $result['cliente_id']);
    }

    #[Test]
    public function consumidor_final_con_precio_max_cuando_hay_excepcion_es_rechazado(): void
    {
        // Cuando hay excepción, el precio_venta_maximo del producto YA NO aplica.
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        ClienteProducto::factory()
            ->conPrecioAutorizado(85.00)
            ->create([
                'cliente_id' => $consumidorFinal->id,
                'producto_id' => $producto->id,
            ]);

        $data = [
            'cliente_id' => $consumidorFinal->id,
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    // Intentar usar el precio "público" en lugar del autorizado: rechazo
                    'precio_unitario' => 100.00,
                    'cantidad' => 1,
                ],
            ],
        ];

        $this->expectException(ValidationException::class);
        $this->invocarMutate($data);
    }

    #[Test]
    public function consumidor_final_sin_precio_configurado_rechaza_cualquier_venta(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => null]);

        $data = [
            'cliente_id' => $consumidorFinal->id,
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    'precio_unitario' => 50.00,
                    'cantidad' => 1,
                ],
            ],
        ];

        $this->expectException(ValidationException::class);
        $this->invocarMutate($data);
    }

    #[Test]
    public function cliente_normal_no_aplica_bloqueo_ni_con_precio_libre(): void
    {
        $clienteNormal = Cliente::factory()->minorista()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        $data = [
            'cliente_id' => $clienteNormal->id,
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    // Cliente normal puede tener cualquier precio (otras reglas lo manejan)
                    'precio_unitario' => 73.50,
                    'cantidad' => 1,
                ],
            ],
        ];

        $result = $this->invocarMutate($data);
        $this->assertSame($clienteNormal->id, $result['cliente_id']);
    }

    #[Test]
    public function consumidor_final_rechaza_si_solo_uno_de_varios_detalles_viola_el_precio(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $productoA = Producto::factory()->create(['precio_venta_maximo' => 100.00]);
        $productoB = Producto::factory()->create(['precio_venta_maximo' => 50.00]);

        $data = [
            'cliente_id' => $consumidorFinal->id,
            'detalles' => [
                [
                    'producto_id' => $productoA->id,
                    'precio_unitario' => 100.00, // ok
                    'cantidad' => 1,
                ],
                [
                    'producto_id' => $productoB->id,
                    'precio_unitario' => 40.00, // ❌ debería ser 50.00
                    'cantidad' => 1,
                ],
            ],
        ];

        $this->expectException(ValidationException::class);
        $this->invocarMutate($data);
    }

    #[Test]
    public function consumidor_final_acepta_tolerancia_de_redondeo_un_centavo(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 99.95]);

        $data = [
            'cliente_id' => $consumidorFinal->id,
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    'precio_unitario' => 99.951, // diferencia de 0.001 = tolerable
                    'cantidad' => 1,
                ],
            ],
        ];

        $result = $this->invocarMutate($data);
        $this->assertSame($consumidorFinal->id, $result['cliente_id']);
    }
}
