<?php

namespace Tests\Unit\Services;

use App\Models\Cliente;
use App\Models\ClienteProducto;
use App\Models\Producto;
use App\Services\PrecioVentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests del servicio que bloquea el precio para Consumidor Final.
 *
 * Cierran el hueco de control donde vendedores reportaban precios
 * menores a los cobrados. Escenarios cubiertos:
 *   1. Consumidor Final con precio_autorizado configurado
 *   2. Consumidor Final sin excepción → usa precio_venta_maximo
 *   3. Consumidor Final sin nada configurado → retorna null
 *   4. Cliente NO Consumidor Final → no aplica bloqueo
 *   5. Validaciones de coincidencia exacta y tolerancia
 *   6. Idempotencia del registro Consumidor Final
 *   7. Mensajes de bloqueo correctos
 */
class PrecioVentaServiceTest extends TestCase
{
    use RefreshDatabase;

    private PrecioVentaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PrecioVentaService;
    }

    #[Test]
    public function consumidor_final_con_precio_autorizado_devuelve_ese_precio_exacto(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        ClienteProducto::factory()
            ->conPrecioAutorizado(95.50)
            ->create([
                'cliente_id' => $consumidorFinal->id,
                'producto_id' => $producto->id,
            ]);

        $precio = $this->service->obtenerPrecioBloqueado($consumidorFinal, $producto);

        $this->assertSame(95.50, $precio);
    }

    #[Test]
    public function consumidor_final_sin_excepcion_usa_precio_venta_maximo(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        $precio = $this->service->obtenerPrecioBloqueado($consumidorFinal, $producto);

        $this->assertSame(100.00, $precio);
    }

    #[Test]
    public function consumidor_final_sin_nada_configurado_devuelve_null(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => null]);

        $precio = $this->service->obtenerPrecioBloqueado($consumidorFinal, $producto);

        $this->assertNull($precio);
    }

    #[Test]
    public function cliente_normal_no_aplica_bloqueo(): void
    {
        $clienteNormal = Cliente::factory()->minorista()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        $precio = $this->service->obtenerPrecioBloqueado($clienteNormal, $producto);

        $this->assertNull($precio);
    }

    #[Test]
    public function precio_coincide_acepta_valor_exacto_para_consumidor_final(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        $this->assertTrue($this->service->precioCoincide($consumidorFinal, $producto, 100.00));
    }

    #[Test]
    public function precio_coincide_acepta_tolerancia_de_un_centavo(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        $this->assertTrue($this->service->precioCoincide($consumidorFinal, $producto, 100.001));
        $this->assertTrue($this->service->precioCoincide($consumidorFinal, $producto, 99.999));
    }

    #[Test]
    public function precio_coincide_rechaza_precio_distinto_al_autorizado(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        $this->assertFalse($this->service->precioCoincide($consumidorFinal, $producto, 90.00));
        $this->assertFalse($this->service->precioCoincide($consumidorFinal, $producto, 110.00));
    }

    #[Test]
    public function precio_coincide_rechaza_venta_si_producto_sin_precio_para_consumidor_final(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => null]);

        $this->assertFalse($this->service->precioCoincide($consumidorFinal, $producto, 50.00));
        $this->assertFalse($this->service->precioCoincide($consumidorFinal, $producto, 100.00));
    }

    #[Test]
    public function precio_coincide_devuelve_true_para_cliente_normal_sin_aplicar_bloqueo(): void
    {
        $clienteNormal = Cliente::factory()->mayorista()->create();
        $producto = Producto::factory()->create(['precio_venta_maximo' => 100.00]);

        // Para cliente normal el servicio NO impone bloqueo de precio fijo;
        // otras reglas (descuento máximo, costo+1) las maneja PuntoVentaRuta.
        $this->assertTrue($this->service->precioCoincide($clienteNormal, $producto, 100.00));
        $this->assertTrue($this->service->precioCoincide($clienteNormal, $producto, 50.00));
    }

    #[Test]
    public function consumidor_final_es_idempotente_y_no_duplica_registros(): void
    {
        $primero = Cliente::consumidorFinal();
        $segundo = Cliente::consumidorFinal();
        $tercero = Cliente::consumidorFinal();

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame($primero->id, $tercero->id);
        $this->assertSame(1, Cliente::where('rtn', Cliente::RTN_CONSUMIDOR_FINAL)->count());
    }

    #[Test]
    public function mensaje_de_bloqueo_incluye_el_precio_autorizado(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create([
            'nombre' => 'Huevo Grande 1x30',
            'precio_venta_maximo' => 100.00,
        ]);

        $mensaje = $this->service->obtenerMensajeBloqueo($consumidorFinal, $producto);

        $this->assertStringContainsString('100.00', $mensaje);
        $this->assertStringContainsString('Consumidor Final', $mensaje);
    }

    #[Test]
    public function mensaje_de_bloqueo_explica_falta_de_configuracion(): void
    {
        $consumidorFinal = Cliente::factory()->consumidorFinal()->create();
        $producto = Producto::factory()->create([
            'nombre' => 'Producto Sin Precio',
            'precio_venta_maximo' => null,
        ]);

        $mensaje = $this->service->obtenerMensajeBloqueo($consumidorFinal, $producto);

        $this->assertStringContainsString('Producto Sin Precio', $mensaje);
        $this->assertStringContainsString('administrador', $mensaje);
    }
}
