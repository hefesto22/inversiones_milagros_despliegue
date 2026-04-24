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

    /**
     * Test crítico del refactor NULL vs agotado-con-memoria.
     *
     * Habilita Escenario A: un lote fue agotado previamente (preserva costo_unit)
     * y llega una NUEVA compra — la memoria del costo_unit anterior debe
     * DESCARTARSE, el nuevo WAC se establece desde la compra.
     *
     * Matemáticamente: inv_antes=0, huevos_antes=0 ⇒ inv_despues = costoCompra,
     * huevos_despues = huevosCompra ⇒ nuevo costo_unit = costoCompra/huevosCompra.
     * La memoria vieja del costo_unit NO contamina el cálculo porque 0+X/0+Y = X/Y.
     */
    public function test_compra_en_lote_agotado_con_memoria_reinicia_wac_desde_cero(): void
    {
        // Lote agotado con memoria costo_unit=5.00 (valor alto, intencionalmente
        // distinto del nuevo costo para detectar contaminación)
        $lote = Lote::factory()->wacAgotadoConMemoria(costoUnitMemoria: 5.0)->create();

        // Nueva compra a costo unitario distinto: 2000 huevos / 4000 L = 2.00
        $delta = $this->wac->aplicarCompra(
            lote:             $lote,
            huevosFacturados: 2000.0,
            costoCompra:      4000.0,
        );

        $this->assertEquals('compra', $delta->motivo);

        // El costo_unit_antes en el delta refleja la memoria (5.00), pero la
        // aritmética se hace sobre inv=0, huevos=0 (coalesce) — no hay
        // contaminación.
        $this->assertEquals(5.0, $delta->wacCostoPorHuevoAntes, 'Memoria del costo_unit se reporta en el delta');
        $this->assertEquals(0.0, $delta->wacCostoInventarioAntes, 'Numerador era 0');
        $this->assertEquals(0.0, $delta->wacHuevosInventarioAntes, 'Denominador era 0');

        // Resultado: WAC nuevo basado únicamente en la compra recién aplicada
        $this->assertEquals(2.0, $delta->wacCostoPorHuevoDespues, 'Nuevo WAC = 4000/2000 = 2.00 (no influenciado por memoria 5.00)');
        $this->assertEquals(4000.0, $delta->wacCostoInventarioDespues);
        $this->assertEquals(2000.0, $delta->wacHuevosInventarioDespues);

        $lote->refresh();
        $this->assertEquals(2.0, (float) $lote->wac_costo_por_huevo);
    }

    public function test_compra_en_lote_sin_wac_inicializado_establece_costo_desde_cero(): void
    {
        // Lote default (wac_* = NULL) — equivalente a "primera compra del ciclo"
        // o "lote pre-backfill de Fase 3". La aritmética coalesce NULL→0.
        $lote = Lote::factory()->create();
        $this->assertNull($lote->wac_costo_por_huevo, 'Precondición: wac=NULL');

        $delta = $this->wac->aplicarCompra(
            lote:             $lote,
            huevosFacturados: 1000.0,
            costoCompra:      2500.0,
        );

        $this->assertEquals(0.0, $delta->wacCostoPorHuevoAntes, 'NULL coalescido a 0 en el delta');
        $this->assertEquals(2.5, $delta->wacCostoPorHuevoDespues, '2500/1000 = 2.50');
        $this->assertEquals(2500.0, $delta->wacCostoInventarioDespues);
        $this->assertEquals(1000.0, $delta->wacHuevosInventarioDespues);

        $lote->refresh();
        $this->assertEquals(2500.0, (float) $lote->wac_costo_inventario);
        $this->assertEquals(1000.0, (float) $lote->wac_huevos_inventario);
        $this->assertEquals(2.5, (float) $lote->wac_costo_por_huevo);
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
        // Lote por default tiene wac_* = NULL (estado pre-backfill de Fase 3)
        $lote = Lote::factory()->create();

        $delta = $this->wac->aplicarVenta(
            lote:                       $lote,
            huevosFacturadosConsumidos: 100.0,
        );

        $this->assertNull($delta, 'Venta sobre wac_*=NULL debe retornar null sin romper');
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
        // Lote por default tiene wac_* = NULL (estado pre-backfill de Fase 3)
        $lote = Lote::factory()->create();

        $delta = $this->wac->aplicarDevolucion(
            lote:                      $lote,
            huevosFacturadosDevueltos: 50.0,
        );

        $this->assertNull($delta, 'Devolución sobre wac_*=NULL debe retornar null sin romper');
    }

    /**
     * Test crítico del refactor NULL vs agotado-con-memoria.
     *
     * Habilita Escenario B: un lote fue agotado por ventas/mermas (aplicarSalida
     * preserva costo_unit cuando huevos=0), luego se revierte un reempaque o se
     * procesa una devolución de cliente → Lote::devolverHuevos dispara
     * DevolucionAplicadaAlLote → WacService::aplicarDevolucion debe reintegrar
     * los huevos al costo_unit PRESERVADO, no omitirlos.
     *
     * Antes del refactor: wacNoInicializado usaba `inv==0 && huevos==0` que era
     * true para agotado-con-memoria, por lo que la devolución se OMITÍA
     * silenciosamente — el lote quedaba con huevos reintegrados pero sin costo
     * reflejado en WAC. Inconsistencia de costos.
     */
    public function test_devolucion_en_lote_agotado_con_memoria_reintegra_con_costo_unit_preservado(): void
    {
        // Lote con inv=0, huevos=0, costo_unit=3.00 preservado tras agotamiento
        $lote = Lote::factory()->wacAgotadoConMemoria(costoUnitMemoria: 3.0)->create();

        $delta = $this->wac->aplicarDevolucion(
            lote:                      $lote,
            huevosFacturadosDevueltos: 200.0,
        );

        $this->assertNotNull(
            $delta,
            'Devolución sobre lote agotado-con-memoria NO debe omitirse — el costo_unit preservado es válido para reintegrar'
        );
        $this->assertEquals('devolucion', $delta->motivo);
        $this->assertEquals(200.0, $delta->deltaHuevos);

        // 200 huevos × 3.00 L/huevo = 600.00 L reintegrados al numerador
        $this->assertEquals(600.0, $delta->deltaCostoInventario);
        $this->assertEquals(200.0, $delta->wacHuevosInventarioDespues);
        $this->assertEquals(600.0, $delta->wacCostoInventarioDespues);

        // El costo_unit se preserva: 600 / 200 = 3.00 (reintegro al mismo costo)
        $this->assertEquals(3.0, $delta->wacCostoPorHuevoDespues);

        // Persistencia correcta
        $lote->refresh();
        $this->assertEquals(200.0, (float) $lote->wac_huevos_inventario);
        $this->assertEquals(600.0, (float) $lote->wac_costo_inventario);
        $this->assertEquals(3.0, (float) $lote->wac_costo_por_huevo);
        $this->assertEquals('devolucion', $lote->wac_motivo_ultima_actualizacion);
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

    /**
     * Escenario B end-to-end: agotamiento total seguido de devolución.
     *
     * Este test valida el fix completo del refactor NULL vs agotado-con-memoria.
     * Si la lógica anterior (wacNoInicializado comparando == 0.0) estuviera
     * activa, el paso 3 retornaría null y el costo de la devolución se perdería.
     */
    public function test_ciclo_agotamiento_total_seguido_de_devolucion_reactiva_lote_con_costo_correcto(): void
    {
        $lote = Lote::factory()->create();

        // 1) Compra: 500 huevos a L4.00 c/u (inv=2000, huevos=500, unit=4.00)
        $this->wac->aplicarCompra($lote, huevosFacturados: 500.0, costoCompra: 2000.0);
        $lote->refresh();
        $this->assertEquals(4.0, (float) $lote->wac_costo_por_huevo);

        // 2) Venta que AGOTA totalmente el lote (huevos=0, costo_unit preservado)
        $this->wac->aplicarVenta($lote, huevosFacturadosConsumidos: 500.0);
        $lote->refresh();
        $this->assertEquals(0.0, (float) $lote->wac_huevos_inventario, 'Lote agotado');
        $this->assertEquals(0.0, (float) $lote->wac_costo_inventario, 'Inventario en 0');
        $this->assertEquals(4.0, (float) $lote->wac_costo_por_huevo, 'costo_unit PRESERVADO (invariante WAC)');

        // 3) Devolución posterior al agotamiento (ej: reversión de reempaque).
        // ANTES del refactor: wacNoInicializado retornaba true (inv=0 && huevos=0)
        // y esta devolución se OMITÍA, dejando el lote con huevos reintegrados
        // en el modelo pero sin costo reflejado en WAC.
        // DESPUÉS del refactor: se reintegra usando el costo_unit=4.00 preservado.
        $delta = $this->wac->aplicarDevolucion($lote, huevosFacturadosDevueltos: 100.0);

        $this->assertNotNull(
            $delta,
            'Devolución sobre lote agotado-con-memoria DEBE procesarse (no omitirse)'
        );
        $lote->refresh();
        $this->assertEquals(100.0, (float) $lote->wac_huevos_inventario, '100 huevos reintegrados');
        $this->assertEquals(400.0, (float) $lote->wac_costo_inventario, '100 × 4.00 = 400 L reintegrados');
        $this->assertEquals(4.0, (float) $lote->wac_costo_por_huevo, 'costo_unit mantiene 4.00');
    }

    /**
     * Escenario A end-to-end: reactivación por nueva compra tras agotamiento.
     *
     * Valida que la memoria del costo_unit anterior NO contamina el WAC del
     * nuevo ciclo cuando llega una compra sobre un lote agotado.
     */
    public function test_ciclo_agotamiento_total_seguido_de_nueva_compra_reinicia_wac(): void
    {
        $lote = Lote::factory()->create();

        // 1) Primera compra: unit=4.00
        $this->wac->aplicarCompra($lote, huevosFacturados: 500.0, costoCompra: 2000.0);

        // 2) Venta total — agotado con memoria unit=4.00
        $this->wac->aplicarVenta($lote, huevosFacturadosConsumidos: 500.0);
        $lote->refresh();
        $this->assertEquals(4.0, (float) $lote->wac_costo_por_huevo, 'Precondición: costo_unit=4.00 preservado');

        // 3) Nueva compra a costo distinto — debe establecer NUEVO WAC (unit=2.00),
        // NO mezclar con la memoria vieja de 4.00
        $this->wac->aplicarCompra($lote, huevosFacturados: 1000.0, costoCompra: 2000.0);
        $lote->refresh();

        $this->assertEquals(2.0, (float) $lote->wac_costo_por_huevo, 'Nuevo WAC desde cero: 2000/1000 = 2.00');
        $this->assertEquals(2000.0, (float) $lote->wac_costo_inventario);
        $this->assertEquals(1000.0, (float) $lote->wac_huevos_inventario);
    }
}
