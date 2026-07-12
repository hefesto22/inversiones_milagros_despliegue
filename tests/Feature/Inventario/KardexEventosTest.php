<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Application\Services\AjusteInventarioService;
use App\Enums\AjusteMotivo;
use App\Enums\MovimientoInventarioTipo;
use App\Models\Bodega;
use App\Models\BodegaProducto;
use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Tests del circuito completo del Kardex: operaciones REALES de negocio
 * (compra, salida, devolución, merma, ajuste, movimientos de bodega)
 * generan sus asientos vía eventos → listener → Registrador.
 *
 * El listener RegistrarMovimientoKardexListener es auto-descubierto por
 * Laravel 11 — estos tests validan el circuito de punta a punta.
 */
class KardexEventosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('inventario.kardex.habilitado', true);
        Config::set('inventario.kardex.estricto', true); // en tests, cualquier fallo del Kardex debe explotar
    }

    /**
     * @return array{0:int,1:int} [compraId, compraDetalleId]
     */
    private function crearCompraCoherente(Lote $lote): array
    {
        $compra = Compra::factory()->create([
            'proveedor_id' => $lote->proveedor_id,
            'bodega_id'    => $lote->bodega_id,
        ]);

        $detalle = CompraDetalle::factory()->create([
            'compra_id'   => $compra->id,
            'producto_id' => $lote->producto_id,
        ]);

        return [$compra->id, $detalle->id];
    }

    // =================================================================
    // NIVEL LOTE — eventos existentes
    // =================================================================

    public function test_compra_al_lote_asienta_entrada_en_el_kardex(): void
    {
        $lote = Lote::factory()->create();
        [$compraId, $compraDetalleId] = $this->crearCompraCoherente($lote);

        $lote->agregarCompra(
            cartonesFacturados: 100.0,
            cartonesRegalo:     5.0,
            costoCompra:        7815.0,
            compraId:           $compraId,
            compraDetalleId:    $compraDetalleId,
            proveedorId:        $lote->proveedor_id,
        );

        $mov = MovimientoInventario::deLote($lote->id)
            ->deTipo(MovimientoInventarioTipo::Compra)
            ->first();

        $this->assertNotNull($mov);
        // Libro físico: 100 cart facturados + 5 regalo = 3150 huevos
        $this->assertEquals(3150.0, (float) $mov->delta);
        $this->assertEquals(3150.0, (float) $mov->saldo_despues);
        // Valor del asiento ≈ costo de la compra
        $this->assertEqualsWithDelta(7815.0, (float) $mov->valor, 0.05);
        $this->assertSame($compraId, $mov->contexto['compra_id']);
    }

    public function test_salida_de_huevos_asienta_salida_con_saldo_corrido(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();

        $lote->reducirRemanente(cantidadHuevos: 900.0, huevosRegaloUsados: 0.0);

        $mov = MovimientoInventario::deLote($lote->id)->latest('id')->first();

        $this->assertNotNull($mov);
        $this->assertSame(MovimientoInventarioTipo::SalidaReempaque, $mov->tipo);
        $this->assertEquals(-900.0, (float) $mov->delta);
        $this->assertEquals(2100.0, (float) $mov->saldo_despues); // 3000 - 900
        // Valorada al costo efectivo del lote (legacy 2.605 con read_source default)
        $this->assertEqualsWithDelta(2.605, (float) $mov->costo_unitario, 0.001);
    }

    public function test_devolucion_de_huevos_asienta_entrada(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();

        $lote->devolverHuevos(cantidadHuevos: 100.0, huevosRegaloDevueltos: 20.0);

        $mov = MovimientoInventario::deLote($lote->id)
            ->deTipo(MovimientoInventarioTipo::DevolucionReempaque)
            ->first();

        $this->assertNotNull($mov);
        $this->assertEquals(100.0, (float) $mov->delta); // 80 facturados + 20 regalo
        $this->assertEquals(3100.0, (float) $mov->saldo_despues);
    }

    public function test_merma_asienta_salida_con_referencia_al_documento(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();

        $lote->registrarMerma(cantidadHuevos: 150.0);

        $mov = MovimientoInventario::deLote($lote->id)
            ->deTipo(MovimientoInventarioTipo::Merma)
            ->first();

        $this->assertNotNull($mov);
        $this->assertEquals(-150.0, (float) $mov->delta);
        $this->assertEquals(2850.0, (float) $mov->saldo_despues);
        // La merma crea su documento y el asiento lo referencia
        $this->assertNotNull($mov->referencia_type);
        $this->assertNotNull($mov->referencia_id);
    }

    public function test_ajuste_aplicado_asienta_ambos_lados_con_referencia(): void
    {
        $bodega  = Bodega::factory()->create();
        $origen  = Lote::factory()->conCompra(3000.0, 7815.0)->create(['bodega_id' => $bodega->id]);
        $destino = Lote::factory()->conCompra(3000.0, 6000.0)->create(['bodega_id' => $bodega->id]);
        $user    = User::factory()->create();

        $svc = app(AjusteInventarioService::class);
        ['salida' => $salida] = $svc->crearReclasificacion(
            loteOrigen:            $origen,
            loteDestino:           $destino,
            huevosAMover:          120.0,
            costoUnitarioAplicado: null,
            motivo:                AjusteMotivo::ClasificacionIncorrecta,
            descripcion:           'Reclasificación de prueba Kardex',
            evidenciaPath:         null,
            solicitante:           $user,
        );
        $svc->aplicar($salida, $user);

        $movSalida = MovimientoInventario::deLote($origen->id)
            ->deTipo(MovimientoInventarioTipo::AjusteSalida)->first();
        $movEntrada = MovimientoInventario::deLote($destino->id)
            ->deTipo(MovimientoInventarioTipo::AjusteEntrada)->first();

        $this->assertNotNull($movSalida);
        $this->assertNotNull($movEntrada);
        $this->assertEquals(-120.0, (float) $movSalida->delta);
        $this->assertEquals(120.0, (float) $movEntrada->delta);
        $this->assertEquals(2880.0, (float) $movSalida->saldo_despues);
        $this->assertEquals(3120.0, (float) $movEntrada->saldo_despues);

        // Ambos asientos referencian su documento de ajuste
        $this->assertEquals($salida->id, $movSalida->referencia_id);
        $this->assertNotNull($movEntrada->referencia_id);
    }

    // =================================================================
    // NIVEL BODEGA — evento nuevo StockBodegaMovido
    // =================================================================

    public function test_reducir_stock_asienta_salida_de_bodega_con_tipo_del_contexto(): void
    {
        $bp = BodegaProducto::factory()->create([
            'stock'                 => 120.0,
            'costo_promedio_actual' => 45.50,
        ]);

        $bp->reducirStock(10.0, false, [
            'kardex_tipo'        => 'venta',
            'kardex_descripcion' => 'Venta V01-260712-0001',
            'venta_id'           => 55,
        ]);

        $mov = MovimientoInventario::deBodegaProducto($bp->id)->first();

        $this->assertNotNull($mov);
        $this->assertSame(MovimientoInventarioTipo::Venta, $mov->tipo);
        $this->assertSame(MovimientoInventario::NIVEL_BODEGA, $mov->nivel);
        $this->assertEquals(-10.0, (float) $mov->delta);
        $this->assertEquals(110.0, (float) $mov->saldo_despues);
        $this->assertSame('Venta V01-260712-0001', $mov->descripcion);
        $this->assertSame(55, $mov->contexto['venta_id']);
        $this->assertEqualsWithDelta(455.0, (float) $mov->valor, 0.01);
    }

    public function test_entrada_con_costo_asienta_entrada_de_bodega(): void
    {
        $bp = BodegaProducto::factory()->create([
            'stock'                 => 50.0,
            'costo_promedio_actual' => 40.00,
        ]);

        $bp->actualizarCostoPromedio(25.0, 46.00, [
            'kardex_tipo' => 'retorno_viaje',
            'kardex_referencia_type' => 'App\\Models\\Lote', // morph por strings
            'kardex_referencia_id'   => 1,
        ]);

        $mov = MovimientoInventario::deBodegaProducto($bp->id)->first();

        $this->assertNotNull($mov);
        $this->assertSame(MovimientoInventarioTipo::RetornoViaje, $mov->tipo);
        $this->assertEquals(25.0, (float) $mov->delta);
        $this->assertEquals(75.0, (float) $mov->saldo_despues);
        $this->assertEqualsWithDelta(46.00, (float) $mov->costo_unitario, 0.001);
        $this->assertSame('App\\Models\\Lote', $mov->referencia_type);
        $this->assertEquals(1, $mov->referencia_id);
    }

    public function test_movimiento_de_bodega_sin_contexto_cae_en_tipo_otro(): void
    {
        $bp = BodegaProducto::factory()->create(['stock' => 30.0]);

        $bp->agregarStockSinCosto(5.0);

        $mov = MovimientoInventario::deBodegaProducto($bp->id)->first();

        $this->assertNotNull($mov);
        $this->assertSame(MovimientoInventarioTipo::Otro, $mov->tipo);
        $this->assertSame('entrada_sin_costo', $mov->contexto['origen_primitiva']);
        $this->assertEquals(5.0, (float) $mov->delta);
    }

    // =================================================================
    // CONTEXTO ENRIQUECIDO (Bloque 3) — la tubería caller → evento → asiento
    // =================================================================

    public function test_salida_de_lote_respeta_contexto_enriquecido_del_caller(): void
    {
        $lote  = Lote::factory()->conCompra(3000.0, 7815.0)->create();
        $otro  = Lote::factory()->conCompra(600.0, 1500.0)->create(); // hace de "documento"

        // Un caller (ej. carga de viaje) pasa su identidad por el contexto
        $lote->reducirRemanente(300.0, 0.0, [
            'kardex_tipo'            => 'carga_viaje',
            'kardex_descripcion'     => 'Carga al viaje #171',
            'kardex_referencia_type' => $otro->getMorphClass(),
            'kardex_referencia_id'   => $otro->id,
        ]);

        $mov = MovimientoInventario::deLote($lote->id)->latest('id')->first();

        $this->assertNotNull($mov);
        $this->assertSame(MovimientoInventarioTipo::CargaViaje, $mov->tipo);
        $this->assertSame('Carga al viaje #171', $mov->descripcion);
        $this->assertSame($otro->getMorphClass(), $mov->referencia_type);
        $this->assertEquals($otro->id, $mov->referencia_id);
        // Las claves kardex_* no se duplican dentro del contexto persistido
        $this->assertArrayNotHasKey('kardex_tipo', $mov->contexto ?? []);
    }

    public function test_devolucion_de_lote_respeta_contexto_enriquecido_del_caller(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();
        $otro = Lote::factory()->conCompra(600.0, 1500.0)->create();

        $lote->devolverHuevos(90.0, 0.0, [
            'kardex_tipo'            => 'retorno_viaje',
            'kardex_descripcion'     => 'Retorno de viaje #171 — sueltos al lote único',
            'kardex_referencia_type' => $otro->getMorphClass(),
            'kardex_referencia_id'   => $otro->id,
        ]);

        $mov = MovimientoInventario::deLote($lote->id)->latest('id')->first();

        $this->assertNotNull($mov);
        $this->assertSame(MovimientoInventarioTipo::RetornoViaje, $mov->tipo);
        $this->assertSame('Retorno de viaje #171 — sueltos al lote único', $mov->descripcion);
        $this->assertEquals($otro->id, $mov->referencia_id);
        $this->assertEquals(3090.0, (float) $mov->saldo_despues);
    }

    // =================================================================
    // KILL-SWITCH EN FLUJO REAL
    // =================================================================

    public function test_kill_switch_apagado_no_asienta_en_flujo_real(): void
    {
        Config::set('inventario.kardex.habilitado', false);

        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();
        $lote->reducirRemanente(cantidadHuevos: 300.0, huevosRegaloUsados: 0.0);

        $bp = BodegaProducto::factory()->create(['stock' => 20.0]);
        $bp->reducirStock(5.0);

        // Las operaciones de negocio funcionaron…
        $this->assertEquals(2700.0, (float) $lote->fresh()->cantidad_huevos_remanente);
        $this->assertEquals(15.0, (float) $bp->fresh()->stock);

        // …pero el libro quedó vacío
        $this->assertSame(0, MovimientoInventario::count());
    }
}
