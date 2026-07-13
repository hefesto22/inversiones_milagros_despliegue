<?php

declare(strict_types=1);

namespace Tests\Feature\Viajes;

use App\Application\Services\ReempaqueService;
use App\Application\Services\TransferenciaCargaService;
use App\Enums\LoteEstado;
use App\Models\Bodega;
use App\Models\BodegaProducto;
use App\Models\Camion;
use App\Models\Categoria;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\ReempaqueProducto;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Viaje;
use App\Models\ViajeCarga;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests de TransferenciaCargaService — transferir carga de un camión a otro.
 *
 * Reglas cubiertas:
 *   - La transferencia es "bajar de A + subir a B" en una transacción:
 *     el lote/bodega quedan netos y ambos viajes con sus cargas correctas.
 *   - Transferencia total retira la carga del origen por completo.
 *   - Si el destino ya lleva el producto, se consolida en su carga.
 *   - No se transfiere lo ya vendido; destino debe ser activo y de la
 *     misma bodega; nota [TRANSFERENCIA] queda en ambos viajes.
 *   - La comisión no se toca: es por viaje/chofer (ya cubierta en
 *     ComisionPrecioCeroTest y el flujo de ventas de ruta).
 *
 * Escenario base: lote de 3,000 huevos a L 7,500 (L 2.50/huevo, L 75/cartón).
 */
class TransferenciaCargaServiceTest extends TestCase
{
    use RefreshDatabase;

    private static int $contadorCamion = 700;

    private Bodega $bodega;

    private Categoria $categoriaBase;

    private Producto $productoBase;

    private Lote $lote;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('inventario.wac.shadow_mode', true);

        $this->actingAs(User::factory()->create());

        $this->bodega = Bodega::factory()->create();

        $this->categoriaBase = Categoria::factory()->create();
        $this->categoriaBase->update(['categoria_origen_id' => $this->categoriaBase->id]);
        $this->categoriaBase->refresh();

        $unidad30 = Unidad::factory()->create([
            'nombre' => 'carton_treinta_'.fake()->unique()->lexify('??????'),
        ]);

        $this->productoBase = Producto::factory()->create([
            'categoria_id' => $this->categoriaBase->id,
            'unidad_id' => $unidad30->id,
            'formato_empaque' => '1x30',
            'unidades_por_bulto' => 30,
        ]);

        $this->lote = Lote::factory()
            ->wacInicializado(huevos: 3000.0, costoInventario: 7500.0)
            ->create([
                'numero_lote' => "LU-B{$this->bodega->id}-P{$this->productoBase->id}",
                'producto_id' => $this->productoBase->id,
                'bodega_id' => $this->bodega->id,
                'estado' => LoteEstado::Disponible,
                'costo_por_huevo' => 2.5,
                'costo_por_carton_facturado' => 75.0,
            ]);
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function crearViaje(string $estado = 'en_ruta', ?Bodega $bodega = null): Viaje
    {
        $bodega = $bodega ?? $this->bodega;
        $chofer = User::factory()->create();
        $admin = User::factory()->create();

        $n = str_pad((string) ++self::$contadorCamion, 6, '0', STR_PAD_LEFT);
        $camion = Camion::create([
            'codigo' => 'CAM-T-'.$n,
            'placa' => 'TST-'.$n,
            'bodega_id' => $bodega->id,
            'activo' => true,
            'created_by' => $admin->id,
        ]);

        return Viaje::create([
            'camion_id' => $camion->id,
            'chofer_id' => $chofer->id,
            'bodega_origen_id' => $bodega->id,
            'fecha_salida' => now(),
            'estado' => $estado,
        ]);
    }

    private function cargarDesdeLote(Viaje $viaje, Producto $producto, float $cantidad): ViajeCarga
    {
        $resultado = DB::transaction(fn () => app(ReempaqueService::class)
            ->ejecutarReempaqueAutomatico(
                $producto->id,
                $this->bodega->id,
                $cantidad,
                "Viaje #{$viaje->id} test"
            ));

        return ViajeCarga::create([
            'viaje_id' => $viaje->id,
            'reempaque_id' => $resultado['reempaque_id'],
            'producto_id' => $producto->id,
            'unidad_id' => $producto->unidad_id,
            'cantidad' => $cantidad,
            'costo_unitario' => $resultado['costo_unitario'],
            'costo_unitario_lote' => $resultado['costo_unitario'],
            'cantidad_de_bodega' => 0,
            'cantidad_de_lote' => $cantidad,
            'precio_venta_sugerido' => 100,
            'precio_venta_minimo' => 90,
        ]);
    }

    private function stockEnBodega(int $productoId): float
    {
        return (float) (BodegaProducto::where('bodega_id', $this->bodega->id)
            ->where('producto_id', $productoId)
            ->value('stock') ?? 0.0);
    }

    // =========================================================
    // Transferencias
    // =========================================================

    #[Test]
    public function transferencia_parcial_mueve_la_carga_al_destino(): void
    {
        $origen = $this->crearViaje('en_ruta');
        $destino = $this->crearViaje('en_ruta');

        // Origen carga 30 (900 huevos) → remanente 2,100
        $carga = $this->cargarDesdeLote($origen, $this->productoBase, 30);

        $resultado = app(TransferenciaCargaService::class)
            ->transferir($origen, $carga, $destino, 10);

        $carga->refresh();
        $this->lote->refresh();
        $cargaDestino = $destino->cargas()->where('producto_id', $this->productoBase->id)->first();

        $this->assertFalse($resultado['transferencia_total']);

        // Origen quedó con 20; destino con 10
        $this->assertSame(20.0, (float) $carga->cantidad);
        $this->assertNotNull($cargaDestino);
        $this->assertSame(10.0, (float) $cargaDestino->cantidad);
        $this->assertSame(10.0, (float) $cargaDestino->cantidad_de_lote);

        // El lote queda NETO: 3,000 − 900 originales + 300 devueltos − 300 tomados = 2,100
        $this->assertSame(2100.0, (float) $this->lote->cantidad_huevos_remanente);
        $this->assertSame(2100.0, (float) $this->lote->wac_huevos_inventario);

        // Cada viaje con su propio reempaque; costo y precio se preservan
        $this->assertNotSame($carga->reempaque_id, $cargaDestino->reempaque_id);
        $this->assertEqualsWithDelta(75.0, (float) $cargaDestino->costo_unitario, 0.01);
        $this->assertSame(100.0, (float) $cargaDestino->precio_venta_sugerido);

        // Nada quedó varado en bodega (producto base vive en el lote)
        $this->assertSame(0.0, $this->stockEnBodega($this->productoBase->id));

        // Nota de trazabilidad en ambos viajes
        $origen->refresh();
        $destino->refresh();
        $this->assertStringContainsString('[TRANSFERENCIA', (string) $origen->observaciones);
        $this->assertStringContainsString('[TRANSFERENCIA', (string) $destino->observaciones);
    }

    #[Test]
    public function transferencia_total_retira_la_carga_del_origen(): void
    {
        $origen = $this->crearViaje('en_ruta');
        $destino = $this->crearViaje('recargando');

        $carga = $this->cargarDesdeLote($origen, $this->productoBase, 10);
        $cargaId = $carga->id;

        $resultado = app(TransferenciaCargaService::class)
            ->transferir($origen, $carga, $destino, 10);

        $this->lote->refresh();

        $this->assertTrue($resultado['transferencia_total']);
        $this->assertNull(ViajeCarga::find($cargaId), 'La carga origen debe eliminarse');

        $cargaDestino = $destino->cargas()->where('producto_id', $this->productoBase->id)->first();
        $this->assertSame(10.0, (float) $cargaDestino->cantidad);

        // Lote neto: 3,000 − 300 (siguen en la calle, ahora en el otro camión)
        $this->assertSame(2700.0, (float) $this->lote->cantidad_huevos_remanente);
    }

    #[Test]
    public function transferencia_consolida_cuando_el_destino_ya_lleva_el_producto(): void
    {
        $origen = $this->crearViaje('en_ruta');
        $destino = $this->crearViaje('en_ruta');

        $cargaOrigen = $this->cargarDesdeLote($origen, $this->productoBase, 10);
        $cargaDestino = $this->cargarDesdeLote($destino, $this->productoBase, 5);

        app(TransferenciaCargaService::class)
            ->transferir($origen, $cargaOrigen, $destino, 4);

        $cargaOrigen->refresh();
        $cargaDestino->refresh();

        $this->assertSame(6.0, (float) $cargaOrigen->cantidad);
        $this->assertSame(9.0, (float) $cargaDestino->cantidad, 'Debe consolidar en la carga existente');
        $this->assertSame(
            1,
            $destino->cargas()->where('producto_id', $this->productoBase->id)->count(),
            'El destino debe seguir con UNA carga del producto'
        );

        // Reempaque consolidado del destino refleja las 9 unidades
        $this->assertSame(
            9.0,
            (float) ReempaqueProducto::where('reempaque_id', $cargaDestino->reempaque_id)
                ->where('producto_id', $this->productoBase->id)
                ->value('cantidad')
        );
    }

    #[Test]
    public function no_permite_transferir_mas_de_lo_disponible(): void
    {
        $origen = $this->crearViaje('en_ruta');
        $destino = $this->crearViaje('en_ruta');

        $carga = $this->cargarDesdeLote($origen, $this->productoBase, 30);
        $carga->update(['cantidad_vendida' => 25]); // disponible = 5

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/disponibles en el camión/');

        try {
            app(TransferenciaCargaService::class)->transferir($origen, $carga->refresh(), $destino, 10);
        } finally {
            $carga->refresh();
            $this->lote->refresh();

            $this->assertSame(30.0, (float) $carga->cantidad, 'La carga origen no debe cambiar');
            $this->assertSame(2100.0, (float) $this->lote->cantidad_huevos_remanente, 'El lote no debe cambiar');
            $this->assertSame(0, $destino->cargas()->count(), 'El destino no debe recibir nada');
        }
    }

    #[Test]
    public function no_permite_destino_cerrado_ni_mismo_viaje_ni_otra_bodega(): void
    {
        $origen = $this->crearViaje('en_ruta');
        $carga = $this->cargarDesdeLote($origen, $this->productoBase, 10);
        $servicio = app(TransferenciaCargaService::class);

        // Mismo viaje
        try {
            $servicio->transferir($origen, $carga, $origen, 5);
            $this->fail('Debió rechazar el mismo viaje como destino');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('distinto', $e->getMessage());
        }

        // Destino cerrado
        $cerrado = $this->crearViaje('cerrado');
        try {
            $servicio->transferir($origen, $carga->refresh(), $cerrado, 5);
            $this->fail('Debió rechazar un destino cerrado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no puede recibir', $e->getMessage());
        }

        // Otra bodega
        $otraBodega = Bodega::factory()->create();
        $viajeOtraBodega = $this->crearViaje('en_ruta', $otraBodega);
        try {
            $servicio->transferir($origen, $carga->refresh(), $viajeOtraBodega, 5);
            $this->fail('Debió rechazar un destino de otra bodega');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('misma bodega', $e->getMessage());
        }

        // Nada cambió
        $carga->refresh();
        $this->lote->refresh();
        $this->assertSame(10.0, (float) $carga->cantidad);
        $this->assertSame(2700.0, (float) $this->lote->cantidad_huevos_remanente);
    }

    #[Test]
    public function origen_debe_estar_en_ruta_o_recargando(): void
    {
        $origen = $this->crearViaje('cargando');
        $destino = $this->crearViaje('en_ruta');
        $carga = $this->cargarDesdeLote($origen, $this->productoBase, 10);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/En Ruta o Recargando/');

        app(TransferenciaCargaService::class)->transferir($origen, $carga, $destino, 5);
    }

    #[Test]
    public function destinos_disponibles_filtra_por_bodega_estado_y_excluye_el_origen(): void
    {
        $origen = $this->crearViaje('en_ruta');
        $activoMismaBodega = $this->crearViaje('recargando');
        $cerrado = $this->crearViaje('cerrado');
        $otraBodega = $this->crearViaje('en_ruta', Bodega::factory()->create());

        $destinos = app(TransferenciaCargaService::class)->destinosDisponibles($origen);

        $this->assertTrue($destinos->has($activoMismaBodega->id), 'Viaje activo de la misma bodega debe aparecer');
        $this->assertFalse($destinos->has($origen->id), 'El propio viaje no debe aparecer');
        $this->assertFalse($destinos->has($cerrado->id), 'Un viaje cerrado no debe aparecer');
        $this->assertFalse($destinos->has($otraBodega->id), 'Un viaje de otra bodega no debe aparecer');
    }
}
