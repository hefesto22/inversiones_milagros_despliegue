<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Events\Inventario\CompraAplicadaAlLote;
use App\Events\Inventario\DevolucionAplicadaAlLote;
use App\Events\Inventario\MermaAplicadaAlLote;
use App\Events\Inventario\VentaAplicadaAlLote;
use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\Lote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests feature del dual-write en shadow mode (Fase 2 del refactor WAC Perpetuo).
 *
 * Scope:
 *   1. Kill-switch (shadow_mode=false) — no se tocan columnas wac_*.
 *   2. Dual-write activo (shadow_mode=true) — cada operación de lote actualiza wac_*.
 *   3. Los 4 eventos se disparan correctamente desde los 4 métodos de Lote.
 *   4. El listener es atómico con la operación legacy (dentro de la misma transacción).
 *   5. El listener maneja sin romper el caso de WAC no inicializado (pre-backfill).
 */
class ShadowModeDualWriteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper: crea Compra + CompraDetalle reales coherentes con el lote dado,
     * para que las FK compra_id / compra_detalle_id del lote se puedan poblar
     * sin violar constraints.
     *
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
    // KILL-SWITCH: shadow_mode=false
    // =================================================================

    public function test_kill_switch_apagado_no_toca_columnas_wac(): void
    {
        Config::set('inventario.wac.shadow_mode', false);

        $lote = Lote::factory()->create();
        [$compraId, $compraDetalleId] = $this->crearCompraCoherente($lote);

        $lote->agregarCompra(
            cartonesFacturados: 100.0,
            cartonesRegalo:     0,
            costoCompra:        7815.0,
            compraId:           $compraId,
            compraDetalleId:    $compraDetalleId,
            proveedorId:        $lote->proveedor_id,
        );

        $lote->refresh();

        // Legacy actualizado
        $this->assertGreaterThan(0, (float) $lote->costo_total_acumulado);
        $this->assertGreaterThan(0, (float) $lote->huevos_facturados_acumulados);

        // WAC intacto (NULL, como estaba)
        $this->assertNull($lote->wac_costo_inventario);
        $this->assertNull($lote->wac_huevos_inventario);
        $this->assertNull($lote->wac_costo_por_huevo);
    }

    // =================================================================
    // DUAL-WRITE ACTIVO: shadow_mode=true
    // =================================================================

    public function test_compra_actualiza_wac_cuando_shadow_mode_activo(): void
    {
        Config::set('inventario.wac.shadow_mode', true);

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

        $lote->refresh();

        // 100 cartones × 30 = 3000 huevos facturados, L7815
        // Esperado: 7815 / 3000 = 2.605 L/huevo
        $this->assertEquals(7815.0,  (float) $lote->wac_costo_inventario);
        $this->assertEquals(3000.0,  (float) $lote->wac_huevos_inventario);
        $this->assertEquals(2.605,   (float) $lote->wac_costo_por_huevo);
        $this->assertEquals('compra', $lote->wac_motivo_ultima_actualizacion);
        $this->assertNotNull($lote->wac_ultima_actualizacion);
    }

    public function test_venta_preserva_wac_costo_unitario_cuando_shadow_mode_activo(): void
    {
        Config::set('inventario.wac.shadow_mode', true);

        // Lote con WAC ya inicializado a 2.605 L/huevo
        $lote = Lote::factory()->wacInicializado(
            huevos: 3000.0,
            costoInventario: 7815.0,
        )->create();

        // Venta de 900 huevos (reducirRemanente espera TOTAL y huevos de regalo)
        $lote->reducirRemanente(cantidadHuevos: 900.0, huevosRegaloUsados: 0.0);

        $lote->refresh();

        // INVARIANTE: costo unitario NO cambia en venta
        $this->assertEquals(2.605, (float) $lote->wac_costo_por_huevo);

        // Balance de cantidades y costo
        $this->assertEquals(2100.0,  (float) $lote->wac_huevos_inventario);
        $this->assertEquals(5470.50, (float) $lote->wac_costo_inventario);
        $this->assertEquals('venta', $lote->wac_motivo_ultima_actualizacion);
    }

    public function test_merma_solo_afecta_wac_con_perdida_real(): void
    {
        Config::set('inventario.wac.shadow_mode', true);

        // Lote con WAC inicializado y buffer de regalo disponible
        $lote = Lote::factory()->wacInicializado(
            huevos: 3000.0,
            costoInventario: 7815.0,
        )->create();

        // Agregamos huevos de regalo para que haya buffer
        $lote->update([
            'huevos_regalo_acumulados'  => 100.0,
            'cantidad_huevos_remanente' => 3100.0,
        ]);
        $lote->refresh();

        // Merma de 30 huevos — completamente absorbida por buffer (100 disponibles)
        $lote->registrarMerma(cantidadHuevos: 30.0);
        $lote->refresh();

        // WAC intacto: la merma fue absorbida por buffer, no bajó huevos facturados
        $this->assertEquals(3000.0, (float) $lote->wac_huevos_inventario);
        $this->assertEquals(7815.0, (float) $lote->wac_costo_inventario);
        $this->assertEquals(2.605,  (float) $lote->wac_costo_por_huevo);
    }

    public function test_devolucion_reintegra_al_wac_cuando_shadow_mode_activo(): void
    {
        Config::set('inventario.wac.shadow_mode', true);

        $lote = Lote::factory()->wacInicializado(
            huevos: 2000.0,
            costoInventario: 5210.0,
        )->create();

        $lote->devolverHuevos(cantidadHuevos: 100.0, huevosRegaloDevueltos: 0.0);
        $lote->refresh();

        // 100 × 2.605 = 260.50 reintegrado
        $this->assertEquals(2100.0, (float) $lote->wac_huevos_inventario);
        $this->assertEquals(5470.50, (float) $lote->wac_costo_inventario);
        $this->assertEquals(2.605,  (float) $lote->wac_costo_por_huevo);
        $this->assertEquals('devolucion', $lote->wac_motivo_ultima_actualizacion);
    }

    // =================================================================
    // EVENTOS DISPARADOS — validación con Event::fake()
    // =================================================================

    public function test_agregarCompra_dispara_CompraAplicadaAlLote(): void
    {
        Event::fake([CompraAplicadaAlLote::class]);

        $lote = Lote::factory()->create();
        [$compraId, $compraDetalleId] = $this->crearCompraCoherente($lote);

        $lote->agregarCompra(
            cartonesFacturados: 50.0,
            cartonesRegalo:     2.0,
            costoCompra:        1000.0,
            compraId:           $compraId,
            compraDetalleId:    $compraDetalleId,
            proveedorId:        $lote->proveedor_id,
        );

        Event::assertDispatched(
            CompraAplicadaAlLote::class,
            fn (CompraAplicadaAlLote $e) =>
                $e->lote->id === $lote->id
                && $e->huevosFacturados === 1500.0  // 50 cartones × 30
                && $e->huevosRegalo === 60.0        // 2 cartones × 30
                && $e->costoCompra === 1000.0
        );
    }

    public function test_reducirRemanente_dispara_VentaAplicadaAlLote_con_huevos_facturados_correctos(): void
    {
        Event::fake([VentaAplicadaAlLote::class]);

        $lote = Lote::factory()->conCompra(
            huevosFacturados: 3000.0,
            costoCompra: 7815.0,
        )->create();

        // Caller pasa 900 total, 100 son regalo → 800 facturados
        $lote->reducirRemanente(cantidadHuevos: 900.0, huevosRegaloUsados: 100.0);

        Event::assertDispatched(
            VentaAplicadaAlLote::class,
            fn (VentaAplicadaAlLote $e) =>
                $e->lote->id === $lote->id
                && $e->huevosFacturadosConsumidos === 800.0
                && $e->huevosRegaloConsumidos === 100.0
        );
    }

    public function test_registrarMerma_dispara_MermaAplicadaAlLote_con_split_correcto(): void
    {
        Event::fake([MermaAplicadaAlLote::class]);

        $lote = Lote::factory()->conCompra(
            huevosFacturados: 3000.0,
            costoCompra: 7815.0,
        )->create();

        // Buffer: 100 huevos de regalo disponibles
        $lote->update([
            'huevos_regalo_acumulados'  => 100.0,
            'cantidad_huevos_remanente' => 3100.0,
        ]);

        // Merma de 150: 100 absorbidos por buffer, 50 pérdida real
        $lote->registrarMerma(cantidadHuevos: 150.0);

        Event::assertDispatched(
            MermaAplicadaAlLote::class,
            fn (MermaAplicadaAlLote $e) =>
                $e->lote->id === $lote->id
                && $e->huevosCubiertoBuffer === 100.0
                && $e->huevosPerdidaReal === 50.0
        );
    }

    public function test_devolverHuevos_dispara_DevolucionAplicadaAlLote_con_split_correcto(): void
    {
        Event::fake([DevolucionAplicadaAlLote::class]);

        $lote = Lote::factory()->conCompra(
            huevosFacturados: 3000.0,
            costoCompra: 7815.0,
        )->create();

        // Devolución: 100 total, 20 son regalo → 80 facturados
        $lote->devolverHuevos(cantidadHuevos: 100.0, huevosRegaloDevueltos: 20.0);

        Event::assertDispatched(
            DevolucionAplicadaAlLote::class,
            fn (DevolucionAplicadaAlLote $e) =>
                $e->lote->id === $lote->id
                && $e->huevosFacturadosDevueltos === 80.0
                && $e->huevosRegaloDevueltos === 20.0
        );
    }

    // =================================================================
    // ROBUSTEZ: WAC no inicializado no rompe flujo legacy
    // =================================================================

    public function test_venta_sobre_lote_sin_wac_inicializado_no_rompe_flujo_legacy(): void
    {
        Config::set('inventario.wac.shadow_mode', true);

        // Lote con stock legacy pero WAC en NULL (estado Fase 2 pre-backfill)
        $lote = Lote::factory()->conCompra(
            huevosFacturados: 1000.0,
            costoCompra: 2000.0,
        )->create();

        // Confirmación: WAC está en NULL
        $this->assertNull($lote->wac_costo_inventario);

        // La venta NO debe lanzar excepción
        $lote->reducirRemanente(cantidadHuevos: 100.0, huevosRegaloUsados: 0.0);

        $lote->refresh();

        // Legacy sí se actualizó
        $this->assertEquals(900.0, (float) $lote->cantidad_huevos_remanente);

        // WAC sigue en NULL (el listener loguea warning y retorna null)
        $this->assertNull($lote->wac_costo_inventario);
    }
}
