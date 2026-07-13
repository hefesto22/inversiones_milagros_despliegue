<?php

namespace Tests\Feature\Ventas;

use App\Filament\Resources\VentaResource\Pages\EditVenta;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests de la defensa en profundidad en EditVenta.
 *
 * Cierra el hueco también al editar ventas existentes: si alguien intenta
 * cambiar el precio_unitario de un detalle a Consumidor Final, el guardado
 * debe rechazarse antes de persistir.
 *
 * Como EditVenta necesita una venta existente, usamos reflection para
 * invocar el método protegido pasándole un objeto con record falso pero
 * con la propiedad cliente_id que esperamos.
 */
class EditVentaPrecioBloqueadoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Invoca mutateFormDataBeforeSave vía reflection con un "record" simulado.
     *
     * EditRecord en Filament espera $this->record para fallback de cliente_id,
     * pero si pasamos cliente_id en $data el método no usa $this->record.
     */
    private function invocarMutate(array $data): array
    {
        $instance = new EditVenta;
        $method = new \ReflectionMethod($instance, 'mutateFormDataBeforeSave');
        $method->setAccessible(true);

        return $method->invoke($instance, $data);
    }

    #[Test]
    public function editar_venta_consumidor_final_con_precio_correcto_pasa(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        $data = [
            'cliente_id' => $consumidorFinal->id,
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    'precio_unitario' => 100.00,
                    'cantidad' => 5,
                ],
            ],
        ];

        $result = $this->invocarMutate($data);

        $this->assertSame($consumidorFinal->id, $result['cliente_id']);
    }

    #[Test]
    public function editar_venta_consumidor_final_intentando_cambiar_precio_es_rechazado(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        $data = [
            'cliente_id' => $consumidorFinal->id,
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    'precio_unitario' => 75.00, // ❌ modificación que viola el bloqueo
                    'cantidad' => 5,
                ],
            ],
        ];

        $this->expectException(ValidationException::class);
        $this->invocarMutate($data);
    }

    #[Test]
    public function editar_venta_de_cliente_normal_no_aplica_bloqueo(): void
    {
        $clienteNormal = Cliente::factory()->mayorista()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        $data = [
            'cliente_id' => $clienteNormal->id,
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    'precio_unitario' => 60.00, // libre porque no es Consumidor Final
                    'cantidad' => 1,
                ],
            ],
        ];

        $result = $this->invocarMutate($data);
        $this->assertSame($clienteNormal->id, $result['cliente_id']);
    }
}
