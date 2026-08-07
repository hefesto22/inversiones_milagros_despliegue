<?php

declare(strict_types=1);

namespace Tests\Feature\Viajes;

use App\Exceptions\LimiteVentaDiariaException;
use App\Models\AutorizacionSegundaVenta;
use App\Models\Bodega;
use App\Models\Camion;
use App\Models\Cliente;
use App\Models\User;
use App\Models\Viaje;
use App\Models\ViajeVenta;
use App\Services\Viaje\LimiteVentaDiariaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Control: una sola factura de ruta por cliente por día.
 *
 * Regla completa en LimiteVentaDiariaService:
 *   - Consumidor Final no tiene límite (es el mostrador, no un cliente real).
 *   - Un cliente registrado: una venta viva por día, en cualquier camión.
 *   - Las canceladas no cuentan.
 *   - Jefe/Encargado puede autorizar una venta extra; cada autorización vale
 *     por una sola.
 */
class LimiteVentaDiariaTest extends TestCase
{
    use RefreshDatabase;

    private static int $contadorCamion = 800;

    private Bodega $bodega;

    private User $usuario;

    private LimiteVentaDiariaService $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bodega = Bodega::factory()->create();
        $this->usuario = User::factory()->create();
        $this->servicio = app(LimiteVentaDiariaService::class);
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function crearViaje(): Viaje
    {
        $n = str_pad((string) ++self::$contadorCamion, 6, '0', STR_PAD_LEFT);

        $camion = Camion::create([
            'codigo' => 'CAM-L-'.$n,
            'placa' => 'TSL-'.$n,
            'bodega_id' => $this->bodega->id,
            'activo' => true,
            'created_by' => $this->usuario->id,
        ]);

        return Viaje::create([
            'camion_id' => $camion->id,
            'chofer_id' => User::factory()->create()->id,
            'bodega_origen_id' => $this->bodega->id,
            'fecha_salida' => now(),
            'estado' => Viaje::ESTADO_EN_RUTA,
        ]);
    }

    private function crearVenta(
        Viaje $viaje,
        Cliente $cliente,
        ?\Carbon\CarbonInterface $fecha = null,
        string $estado = 'completada'
    ): ViajeVenta {
        return ViajeVenta::create([
            'viaje_id' => $viaje->id,
            'cliente_id' => $cliente->id,
            'numero_venta' => ViajeVenta::generarNumeroVenta($viaje->id),
            'fecha_venta' => $fecha ?? now(),
            'tipo_pago' => 'contado',
            'plazo_dias' => 0,
            'subtotal' => 100,
            'impuesto' => 0,
            'descuento' => 0,
            'total' => 100,
            'saldo_pendiente' => 0,
            'estado' => $estado,
            'user_id' => $this->usuario->id,
        ]);
    }

    private function autorizar(Cliente $cliente, ?string $fecha = null): AutorizacionSegundaVenta
    {
        return AutorizacionSegundaVenta::create([
            'cliente_id' => $cliente->id,
            'fecha' => $fecha ?? today()->toDateString(),
            'autorizado_por' => $this->usuario->id,
            'motivo' => 'El cliente pidió más producto en la tarde',
        ]);
    }

    // =========================================================
    // Regla base
    // =========================================================

    #[Test]
    public function la_primera_venta_del_dia_pasa_sin_problema(): void
    {
        $cliente = Cliente::factory()->create();

        $this->assertNull($this->servicio->verificar($cliente));
    }

    #[Test]
    public function una_segunda_venta_al_mismo_cliente_el_mismo_dia_se_rechaza(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = $this->crearVenta($this->crearViaje(), $cliente);

        try {
            $this->servicio->verificar($cliente);
            $this->fail('Se esperaba LimiteVentaDiariaException');
        } catch (LimiteVentaDiariaException $e) {
            // El mensaje va derecho a la pantalla del chofer: debe decirle a
            // quién y con qué factura, no solo "no se puede".
            $this->assertStringContainsString($cliente->nombre, $e->getMessage());
            $this->assertStringContainsString($venta->numero_venta, $e->getMessage());
        }
    }

    #[Test]
    public function el_limite_es_del_cliente_no_del_viaje(): void
    {
        // Dos camiones distintos, el mismo cliente, el mismo día.
        $cliente = Cliente::factory()->create();
        $this->crearVenta($this->crearViaje(), $cliente);

        $this->expectException(LimiteVentaDiariaException::class);

        $this->servicio->verificar($cliente);
    }

    #[Test]
    public function una_venta_de_otro_dia_no_bloquea(): void
    {
        $cliente = Cliente::factory()->create();
        $this->crearVenta($this->crearViaje(), $cliente, now()->subDay());

        $this->assertNull($this->servicio->verificar($cliente));
    }

    #[Test]
    public function el_limite_no_alcanza_a_otros_clientes(): void
    {
        $atendido = Cliente::factory()->create();
        $otro = Cliente::factory()->create();

        $this->crearVenta($this->crearViaje(), $atendido);

        $this->assertNull($this->servicio->verificar($otro));
    }

    // =========================================================
    // Excepciones a la regla
    // =========================================================

    #[Test]
    public function consumidor_final_no_tiene_limite(): void
    {
        // Es el mostrador: si se le aplicara el límite, el chofer haría una
        // sola venta sin cliente registrado en todo el día.
        $consumidorFinal = Cliente::consumidorFinal();
        $viaje = $this->crearViaje();

        $this->crearVenta($viaje, $consumidorFinal);
        $this->crearVenta($viaje, $consumidorFinal);
        $this->crearVenta($viaje, $consumidorFinal);

        $this->assertNull($this->servicio->verificar($consumidorFinal));
        $this->assertSame(3, $this->servicio->ventasVivasDelDia($consumidorFinal));
    }

    #[Test]
    public function una_venta_cancelada_libera_el_cupo(): void
    {
        $cliente = Cliente::factory()->create();
        $this->crearVenta($this->crearViaje(), $cliente, null, 'cancelada');

        $this->assertNull($this->servicio->verificar($cliente));
        $this->assertSame(0, $this->servicio->ventasVivasDelDia($cliente));
    }

    // =========================================================
    // Autorización del encargado
    // =========================================================

    #[Test]
    public function con_autorizacion_la_segunda_venta_pasa(): void
    {
        $cliente = Cliente::factory()->create();
        $this->crearVenta($this->crearViaje(), $cliente);
        $autorizacion = $this->autorizar($cliente);

        $devuelta = $this->servicio->verificar($cliente);

        $this->assertNotNull($devuelta);
        $this->assertSame($autorizacion->id, $devuelta->id);
    }

    #[Test]
    public function la_autorizacion_sirve_una_sola_vez(): void
    {
        $cliente = Cliente::factory()->create();
        $viaje = $this->crearViaje();
        $this->crearVenta($viaje, $cliente);
        $this->autorizar($cliente);

        // Segunda venta: la autorización la habilita y queda consumida.
        $autorizacion = $this->servicio->verificar($cliente);
        $segundaVenta = $this->crearVenta($viaje, $cliente);
        $autorizacion->consumir($segundaVenta);

        $this->assertNotNull($autorizacion->fresh()->consumida_at);
        $this->assertSame($segundaVenta->id, $autorizacion->fresh()->consumida_en_viaje_venta_id);

        // Tercera venta: sin autorización nueva, se rechaza.
        $this->expectException(LimiteVentaDiariaException::class);

        $this->servicio->verificar($cliente);
    }

    #[Test]
    public function una_autorizacion_de_ayer_no_sirve_hoy(): void
    {
        $cliente = Cliente::factory()->create();
        $this->crearVenta($this->crearViaje(), $cliente);
        $this->autorizar($cliente, today()->subDay()->toDateString());

        $this->expectException(LimiteVentaDiariaException::class);

        $this->servicio->verificar($cliente);
    }

    #[Test]
    public function una_autorizacion_de_otro_cliente_no_sirve(): void
    {
        $cliente = Cliente::factory()->create();
        $otro = Cliente::factory()->create();

        $this->crearVenta($this->crearViaje(), $cliente);
        $this->autorizar($otro);

        $this->expectException(LimiteVentaDiariaException::class);

        $this->servicio->verificar($cliente);
    }

    #[Test]
    public function una_autorizacion_ya_consumida_no_vuelve_a_servir(): void
    {
        $cliente = Cliente::factory()->create();
        $viaje = $this->crearViaje();
        $venta = $this->crearVenta($viaje, $cliente);

        $autorizacion = $this->autorizar($cliente);
        $autorizacion->consumir($venta);

        $this->assertFalse($autorizacion->fresh()->estaDisponible());
        $this->assertSame(0, $this->servicio->autorizacionesDisponibles($cliente));

        $this->expectException(LimiteVentaDiariaException::class);

        $this->servicio->verificar($cliente);
    }

    // =========================================================
    // Contadores que ve quien autoriza
    // =========================================================

    #[Test]
    public function los_contadores_reflejan_la_situacion_del_dia(): void
    {
        $cliente = Cliente::factory()->create();
        $viaje = $this->crearViaje();

        $this->assertSame(0, $this->servicio->ventasVivasDelDia($cliente));
        $this->assertSame(0, $this->servicio->autorizacionesDisponibles($cliente));

        $this->crearVenta($viaje, $cliente);
        $this->autorizar($cliente);

        $this->assertSame(1, $this->servicio->ventasVivasDelDia($cliente));
        $this->assertSame(1, $this->servicio->autorizacionesDisponibles($cliente));

        // Una venta de ayer no entra en el conteo de hoy.
        $this->crearVenta($viaje, $cliente, now()->subDay());

        $this->assertSame(1, $this->servicio->ventasVivasDelDia($cliente));
    }
}
