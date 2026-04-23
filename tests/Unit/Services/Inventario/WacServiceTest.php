<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Inventario;

use App\Models\Lote;
use App\Services\Inventario\Dto\WacDelta;
use App\Services\Inventario\WacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests de aritmética del WacService.
 *
 * Scope:
 *   - Validar la invariante clave del WAC: salidas NO modifican el costo unitario.
 *   - Validar que entradas recalculan el costo unitario correctamente.
 *   - Validar el manejo explícito del estado "WAC no inicializado" (pre-backfill).
 *   - Validar precisión decimal (6 decimales en costo unitario, 4 en totales).
 *   - Validar que argumentos inválidos lanzan InvalidArgumentException.
 *
 * Usa RefreshDatabase porque el WacService persiste via Eloquent con
 * lockForUpdate(), no hay forma honesta de probarlo sin BD real.
 */
class WacServiceTest extends TestCase
{
    use RefreshDatabase;

    private WacService $wac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wac = new WacService();
    }

    // =================================================================
    // aplicarCompra — entrada que recalcula costo unitario
    // =================================================================

    public function test_compra_inicial_establece_costo_unitario(): void
    {
        $lote = Lote::factory()->create();

        $delta = $this->wac->aplicarCompra(
            lote:             $lote,
            huevosFacturados: 3000.0,
            costoCompra:      7815.0,
        );

        $this->assertInstanceOf(WacDelta::class, $delta);
        $this->assertEquals('compra', $delta->motivo);
        $this->assertEquals(3000.0, $delta->deltaHuevos);
        $this->assertEquals(7815.0, $delta->deltaCostoInventario);
        $this->assertEquals(0.0, $delta->wacCostoPorHuevoAntes);

        // 7815 / 3000 = 2.605 exacto
        $this->assertEquals(2.605, $delta->wacCostoPorHuevoDespues);

        $lote->refresh();
        $this->assertEquals(7815.0, (float) $lote->wac_costo_inventario);
        $this->assertEquals(3000.0, (float) $lote->wac_huevos_inventario);
        $this->assertEquals(2.605, (float) $lote->wac_costo_por_huevo);
        $this->assertEquals(round(2.605 * 30, 4), (float) $lote->wac_costo_por_carton_facturado);
        $this->assertEquals('compra', $lote->wac_motivo_ultima_actualizacion);
        $this->assertNotNull($lote->wac_ultima_actualizacion);
    }

    public function test_compra_subsecuente_recalcula_promedio_ponderado(): void
    {
        // Lote con 1000 huevos a L3.00 c/u (L3000 total)
        $lote = Lote::factory()->wacInicializado(
            huevos: 1000.0,
            costoInventario: 3000.0,
        )->create();

        // Compra 500 huevos a L2.00 c/u (L1000 total)
        // Esperado: (3000 + 1000) / (1000 + 500) = 4000 / 1500 = 2.666666...
        $delta = $this->wac->aplicarCompra(
            lote:             $lote,
            huevosFacturados: 500.0,
            costoCompra:      1000.0,
        );

        $this->assertEquals(3.0, $delta->wacCostoPorHuevoAntes);
        $this->assertEqualsWithDelta(2.666667, $delta->wacCostoPorHuevoDespues, 0.000001);
        $this->assertEquals(4000.0, $delta->wacCostoInventarioDespues);
        $this->assertEquals(1500.0, $delta->wacHuevosInventarioDespues);
    }

    public function test_compra_con_huevos_facturados_cero_lanza_excepcion(): void
    {
        $lote = Lote::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->wac->aplicarCompra($lote, huevosFacturados: 0.0, costoCompra: 100.0);
    }

    public function test_compra_con_costo_negativo_lanza_excepcion(): void
    {
        $lote = Lote::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->wac->aplicarCompra($lote, huevosFacturados: 100.0, costoCompra: -50.0);
    }

    // =================================================================
    // aplicarVenta — salida que preserva costo unitario (INVARIANTE)
    // =================================================================

    public function test_venta_preserva_costo_unitario(): void
    {
        // Lote con 3000 huevos a L2.605 c/u
        $lote = Lote::factory()->wacInicializado(
            huevos: 3000.0,
            costoInventario: 7815.0,
        )->create();

        $delta = $this->wac->aplicarVenta(
            lote:                       $lote,
            huevosFacturadosConsumidos: 900.0,
        );

        $this->assertNotNull($delta);
        $this->assertEquals('venta', $delta->motivo);

        // INVARIANTE CRÍTICA del WAC: costo unitario NO cambia en salidas
        $this->assertTrue(
            $delta->costoPorHuevoPreservado(),
            'El costo unitario DEBE preservarse en una venta (invariante del WAC)'
        );
        $this->assertEquals(2.605, $delta->wacCostoPorHuevoDespues);

        // Delta signos: -900 huevos, -(900 × 2.605) = -2344.50 Lempiras
        $this->assertEquals(-900.0, $delta->deltaHuevos);
        $this->assertEquals(-2344.50, $delta->deltaCostoInventario);

        // Estado resultante: 2100 huevos, L5470.50 inventario
        $this->assertEquals(2100.0, $delta->wacHuevosInventarioDespues);
        $this->assertEquals(5470.50, $delta->wacCostoInventarioDespues);
    }

    public function test_venta_que_vacia_lote_preserva_costo_unit_para_futura_devolucion(): void
    {
        $lote = Lote::factory()->wacInicializado(
            huevos: 100.0,
            costoInventario: 260.50,
        )->create();

        $delta = $this->wac->aplicarVenta(
            lote:                       $lote,
            huevosFacturadosConsumidos: 100.0,
        );

        $this->assertNotNull($delta);
        $this->assertEquals(0.0, $delta->wacHuevosInventarioDespues);
        $this->assertEquals(0.0, $delta->wacCostoInventarioDespues);

        // Cuando el lote queda vacío, preservamos el costo_unit para que una
        // eventual devolución posterior reingrese al costo correcto.
        $this->assertEquals(2.605, $delta->wacCostoPorHuevoDespues);
    }

    public function test_venta_en_lote_sin_wac_inicializado_retorna_null(): void
    {
        $lote = Lote::factory()->wacNoInicializado()->create();

        $delta = $this->wac->aplicarVenta(
            lote:                       $lote,
            huevosFacturadosConsumidos: 100.0,
        );

        $this->assertNull($delta, 'Venta sobre WAC no inicializado debe retornar null sin romper');
    }

    // =================================================================
    // aplicarMerma — misma semántica que venta (salida que preserva costo_unit)
    // =================================================================

    public function test_merma_preserva_costo_unitario(): void
    {
        $lote = Lote::factory()->wacInicializado(
            huevos: 2000.0,
            costoInventario: 5210.0,
        )->create();

        $delta = $this->wac->aplicarMerma(
            lote:              $lote,
            huevosPerdidaReal: 50.0,
        );

        $this->assertNotNull($delta);
        $this->assertEquals('merma', $delta->motivo);
        $this->assertEquals(2.605, $delta->wacCostoPorHuevoDespues);
        $this->assertEquals(-50.0, $delta->deltaHuevos);
        $this->assertEquals(-round(50.0 * 2.605, 4), $delta->deltaCostoInventario);
    }

    // =================================================================
    // aplicarDevolucion — entrada al costo unitario actual
    // =================================================================

    public function test_devolucion_reintegra_al_costo_actual(): void
    {
        $lote = Lote::factory()->wacInicializado(
            huevos: 1000.0,
            costoInventario: 2605.0,
        )->create();

        $delta = $this->wac->aplicarDevolucion(
            lote:                      $lote,
            huevosFacturadosDevueltos: 100.0,
        );

        $this->assertNotNull($delta);
        $this->assertEquals('devolucion', $delta->motivo);
        $this->assertEquals(100.0, $delta->deltaHuevos);
        $this->assertEquals(260.50, $delta->deltaCostoInventario);

        // 1100 huevos × 2.605 = 2865.50
        $this->assertEquals(1100.0, $delta->wacHuevosInventarioDespues);
        $this->assertEquals(2865.50, $delta->wacCostoInventarioDespues);

        // El costo unitario se preserva porque el reintegro es al mismo costo
        $this->assertEquals(2.605, $delta->wacCostoPorHuevoDespues);
    }

    public function test_devolucion_en_lote_sin_wac_inicializado_retorna_null(): void
    {
        $lote = Lote::factory()->wacNoInicializado()->create();

        $delta = $this->wac->aplicarDevolucion(
            lote:                      $lote,
            huevosFacturadosDevueltos: 50.0,
        );

        $this->assertNull($delta);
    }

    // =================================================================
    // Ciclo completo: compra → venta → devolución vuelve al estado original
    // =================================================================

    public function test_ciclo_completo_compra_venta_devolucion_retorna_al_estado_inicial(): void
    {
        $lote = Lote::factory()->create();

        // 1) Compra 1000 huevos a L2.00 c/u
        $this->wac->aplicarCompra($lote, huevosFacturados: 1000.0, costoCompra: 2000.0);
        $lote->refresh();
        $this->assertEquals(2.0, (float) $lote->wac_costo_por_huevo);
        $this->assertEquals(1000.0, (float) $lote->wac_huevos_inventario);

        // 2) Venta de 300 huevos
        $this->wac->aplicarVenta($lote, huevosFacturadosConsumidos: 300.0);
        $lote->refresh();
        $this->assertEquals(2.0, (float) $lote->wac_costo_por_huevo, 'costo_unit preservado en venta');
        $this->assertEquals(700.0, (float) $lote->wac_huevos_inventario);
        $this->assertEquals(1400.0, (float) $lote->wac_costo_inventario);

        // 3) Devolución de los 300 huevos (escenario feliz — WAC estable)
        $this->wac->aplicarDevolucion($lote, huevosFacturadosDevueltos: 300.0);
        $lote->refresh();
        $this->assertEquals(2.0, (float) $lote->wac_costo_por_huevo);
        $this->assertEquals(1000.0, (float) $lote->wac_huevos_inventario);
        $this->assertEquals(2000.0, (float) $lote->wac_costo_inventario);
    }
}
