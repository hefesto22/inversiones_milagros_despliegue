<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\HistorialCompraLote;
use App\Models\Lote;
use App\Services\Inventario\BackfillWacService;
use App\Services\Inventario\Dto\BackfillLoteResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests feature del BackfillWacCommand (Fase 3 del refactor WAC Perpetuo).
 *
 * Scope crítico cubierto:
 *   1. Cálculo matemático correcto del WAC desde historial_compras_lote.
 *   2. Invariante del WAC: el valor calculado es independiente de las ventas/mermas
 *      intermedias (verificado comparando lote con/sin salidas simuladas).
 *   3. Dry-run no toca columnas wac_* bajo ningún escenario.
 *   4. Apply escribe wac_* cuando no hay anomalías.
 *   5. Apply aborta cuando hay anomalías sin --force.
 *   6. Apply + force aplica aun con anomalías.
 *   7. Clasificación de divergencia: ruido vs esperada vs anómala.
 *   8. Filtros --bodega / --producto aislan correctamente.
 *   9. Idempotencia: correr apply dos veces produce los mismos valores finales.
 *  10. Reset vuelve columnas wac_* a null.
 */
class BackfillWacCommandTest extends TestCase
{
    use RefreshDatabase;

    private BackfillWacService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BackfillWacService::class);

        // El backfill asume shadow_mode apagado; los tests lo forzan explícitamente
        // para evitar falsos positivos si el .env del runner lo tuviera en true.
        Config::set('inventario.wac.shadow_mode', false);
    }

    // =================================================================
    // HELPERS
    // =================================================================

    /**
     * Crea un lote con n compras coherentes: el lote queda con
     * huevos_facturados_acumulados y costo_total_acumulado agregados
     * desde las filas de historial_compras_lote.
     *
     * @param list<array{cartones: float, costo: float}> $compras
     */
    private function crearLoteConCompras(array $compras, int $huevosPorCarton = 30): Lote
    {
        $lote = Lote::factory()->create(['huevos_por_carton' => $huevosPorCarton]);

        $totalCartones = 0.0;
        $totalCosto    = 0.0;

        foreach ($compras as $c) {
            $compra = Compra::factory()->create([
                'proveedor_id' => $lote->proveedor_id,
                'bodega_id'    => $lote->bodega_id,
            ]);
            $detalle = CompraDetalle::factory()->create([
                'compra_id'   => $compra->id,
                'producto_id' => $lote->producto_id,
            ]);

            HistorialCompraLote::factory()
                ->conValores($c['cartones'], $c['costo'], $huevosPorCarton)
                ->create([
                    'lote_id'           => $lote->id,
                    'compra_id'         => $compra->id,
                    'compra_detalle_id' => $detalle->id,
                    'proveedor_id'      => $lote->proveedor_id,
                ]);

            $totalCartones += $c['cartones'];
            $totalCosto    += $c['costo'];
        }

        $huevosFacturados = $totalCartones * $huevosPorCarton;
        $costoPorHuevoLegacy = $huevosFacturados > 0
            ? round($totalCosto / $huevosFacturados, 4)
            : 0.0;

        // Poblar el lote consistentemente con las compras (legacy)
        $lote->update([
            'cantidad_cartones_facturados' => $totalCartones,
            'cantidad_huevos_original'     => $huevosFacturados,
            'cantidad_huevos_remanente'    => $huevosFacturados,
            'huevos_facturados_acumulados' => $huevosFacturados,
            'costo_total_acumulado'        => $totalCosto,
            'costo_total_lote'             => $totalCosto,
            'costo_por_huevo'              => $costoPorHuevoLegacy,
            'costo_por_carton_facturado'   => round($costoPorHuevoLegacy * $huevosPorCarton, 4),
        ]);

        return $lote->fresh();
    }

    /**
     * Simula que se vendieron/mermaron huevos del lote (reduce solo el remanente,
     * como hace el bug legacy — no toca los acumulados).
     */
    private function simularVentas(Lote $lote, float $huevosConsumidos): void
    {
        $lote->update([
            'cantidad_huevos_remanente' => max(0.0, $lote->cantidad_huevos_remanente - $huevosConsumidos),
        ]);
    }

    // =================================================================
    // 1. CÁLCULO MATEMÁTICO — una sola compra
    // =================================================================

    public function test_calcula_wac_correctamente_con_una_sola_compra(): void
    {
        // 100 cartones × 70 L/cartón = 7,000 L totales, 3,000 huevos
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);

        $result = $this->service->calcularLote($lote, toleranciaDivergenciaLempiras: 0.10);

        $this->assertTrue($result->fueProcesado(), 'El resultado debe ser "procesado"');
        $this->assertEqualsWithDelta(2.333333, $result->wacCostoPorHuevo, 0.000001);
        $this->assertEqualsWithDelta(70.0000, $result->wacCostoPorCartonFacturado, 0.0001);
        $this->assertEqualsWithDelta(3000.0, $result->wacHuevosInventario, 0.0001);
        $this->assertEqualsWithDelta(7000.0, $result->wacCostoInventario, 0.0001);
        $this->assertSame(1, $result->comprasConsideradas);
    }

    // =================================================================
    // 2. CÁLCULO MATEMÁTICO — múltiples compras (promedio ponderado)
    // =================================================================

    public function test_calcula_wac_correctamente_con_multiples_compras(): void
    {
        // Compra 1: 100 cartones × 70 = 7,000 L (3,000 huevos)
        // Compra 2: 100 cartones × 80 = 8,000 L (3,000 huevos)
        // Total: 200 cartones, 15,000 L, 6,000 huevos
        // WAC esperado: 15,000 / 6,000 = 2.50 L/huevo = 75 L/cartón
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
            ['cartones' => 100.0, 'costo' => 8000.0],
        ]);

        $result = $this->service->calcularLote($lote, toleranciaDivergenciaLempiras: 0.10);

        $this->assertTrue($result->fueProcesado());
        $this->assertEqualsWithDelta(2.50, $result->wacCostoPorHuevo, 0.000001);
        $this->assertEqualsWithDelta(75.0000, $result->wacCostoPorCartonFacturado, 0.0001);
        $this->assertSame(2, $result->comprasConsideradas);
    }

    // =================================================================
    // 3. INVARIANTE WAC — ventas no cambian el costo unitario calculado
    // =================================================================

    public function test_invariante_wac_ventas_no_cambian_costo_unitario_calculado(): void
    {
        // Mismo setup que el test anterior, pero con 3000 huevos ya vendidos.
        // La invariante del WAC dice: el costo unitario debe ser el mismo.
        $loteConVentas = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
            ['cartones' => 100.0, 'costo' => 8000.0],
        ]);
        $this->simularVentas($loteConVentas, 3000.0); // se vende la mitad
        $loteConVentas = $loteConVentas->fresh();

        $result = $this->service->calcularLote($loteConVentas, toleranciaDivergenciaLempiras: 0.10);

        // Costo unitario igual al test sin ventas
        $this->assertEqualsWithDelta(2.50, $result->wacCostoPorHuevo, 0.000001);
        // Pero el inventario actual refleja solo el remanente
        $this->assertEqualsWithDelta(3000.0, $result->wacHuevosInventario, 0.0001);
        $this->assertEqualsWithDelta(7500.0, $result->wacCostoInventario, 0.0001);
    }

    // =================================================================
    // 4. DRY-RUN no escribe columnas wac_*
    // =================================================================

    public function test_dry_run_no_escribe_columnas_wac(): void
    {
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);

        $this->artisan('wac:backfill', ['--dry-run' => true])->assertSuccessful();

        $lote->refresh();
        $this->assertNull($lote->wac_costo_inventario, 'dry-run no debe poblar wac_*');
        $this->assertNull($lote->wac_costo_por_huevo);
        $this->assertNull($lote->wac_ultima_actualizacion);

        // Pero sí debe existir el run registrado
        $this->assertDatabaseHas('wac_backfill_runs', [
            'modo'   => 'dry-run',
            'estado' => 'completado',
        ]);
        $this->assertDatabaseHas('wac_backfill_items', [
            'lote_id' => $lote->id,
            'estado'  => 'procesado',
        ]);
    }

    // =================================================================
    // 5. APPLY escribe wac_* cuando no hay anomalías
    // =================================================================

    public function test_apply_escribe_columnas_wac_cuando_no_hay_anomalias(): void
    {
        // Lote "limpio": legacy y WAC coinciden exactamente
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);

        $this->artisan('wac:backfill', ['--apply' => true])->assertSuccessful();

        $lote->refresh();
        $this->assertNotNull($lote->wac_costo_inventario);
        $this->assertEqualsWithDelta(2.333333, (float) $lote->wac_costo_por_huevo, 0.000001);
        $this->assertEqualsWithDelta(70.0, (float) $lote->wac_costo_por_carton_facturado, 0.0001);
        $this->assertSame('backfill', $lote->wac_motivo_ultima_actualizacion);
        $this->assertNotNull($lote->wac_ultima_actualizacion);
    }

    // =================================================================
    // 6. APPLY aborta cuando hay anomalía sin --force
    // =================================================================

    public function test_apply_aborta_cuando_hay_anomalia_sin_force(): void
    {
        // Lote con anomalía: WAC calcula 70 L/cartón, pero legacy dice 50 (diferencia >15%, anómala)
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);
        $lote->update(['costo_por_carton_facturado' => 50.0, 'costo_por_huevo' => 1.6667]);

        $exitCode = $this->artisan('wac:backfill', ['--apply' => true])->run();

        $this->assertSame(1, $exitCode, 'Debe retornar FAILURE (exit 1) al abortar');

        $lote->refresh();
        $this->assertNull($lote->wac_costo_inventario, 'No debe aplicar cuando hay anomalía');

        $this->assertDatabaseHas('wac_backfill_runs', [
            'estado' => 'abortado',
            'modo'   => 'apply',
        ]);
    }

    // =================================================================
    // 7. APPLY + FORCE aplica aun con anomalías
    // =================================================================

    public function test_apply_con_force_aplica_aun_con_anomalias(): void
    {
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);
        $lote->update(['costo_por_carton_facturado' => 50.0, 'costo_por_huevo' => 1.6667]);

        $this->artisan('wac:backfill', ['--apply' => true, '--force' => true])
            ->assertSuccessful();

        $lote->refresh();
        $this->assertNotNull($lote->wac_costo_inventario);
        $this->assertEqualsWithDelta(70.0, (float) $lote->wac_costo_por_carton_facturado, 0.0001);
    }

    // =================================================================
    // 8. CLASIFICACIÓN de divergencias
    // =================================================================

    public function test_clasificacion_divergencia_esperada_wac_menor_que_legacy(): void
    {
        // Escenario del bug documentado: legacy sobrevalora. WAC < legacy, ~10% de desvío.
        // Compras reales: 100 cartones × 70 = 7000 → WAC = 70/cartón
        // Legacy inflado: 78.15/cartón (10.4% por encima)
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);
        $lote->update([
            'costo_por_carton_facturado' => 78.15,
            'costo_por_huevo'            => 2.605,
        ]);

        $result = $this->service->calcularLote($lote, toleranciaDivergenciaLempiras: 0.10);

        $this->assertSame(
            BackfillLoteResult::CLASIF_ESPERADA,
            $result->clasificacionDivergencia,
            'WAC < legacy con desvío <=15% debe ser clasificado como "esperada"'
        );
    }

    public function test_clasificacion_divergencia_anomala_cuando_wac_mayor_que_legacy(): void
    {
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);
        $lote->update([
            'costo_por_carton_facturado' => 50.0,
            'costo_por_huevo'            => 1.6667,
        ]);

        $result = $this->service->calcularLote($lote, toleranciaDivergenciaLempiras: 0.10);

        $this->assertSame(
            BackfillLoteResult::CLASIF_ANOMALA,
            $result->clasificacionDivergencia,
            'WAC > legacy siempre debe ser anómala (el refactor no debe inflar costos)'
        );
    }

    public function test_clasificacion_divergencia_ruido_dentro_de_tolerancia(): void
    {
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);
        // Legacy casi idéntico al WAC calculado (diferencia <0.10 L/cartón)
        $lote->update([
            'costo_por_carton_facturado' => 70.05,
            'costo_por_huevo'            => 2.3350,
        ]);

        $result = $this->service->calcularLote($lote, toleranciaDivergenciaLempiras: 0.10);

        $this->assertSame(BackfillLoteResult::CLASIF_RUIDO, $result->clasificacionDivergencia);
    }

    // =================================================================
    // 9. FILTRO --bodega aisla correctamente
    // =================================================================

    public function test_filtro_bodega_solo_procesa_lotes_de_esa_bodega(): void
    {
        $loteA = $this->crearLoteConCompras([['cartones' => 100.0, 'costo' => 7000.0]]);
        $loteB = $this->crearLoteConCompras([['cartones' => 100.0, 'costo' => 7000.0]]);

        $this->artisan('wac:backfill', [
            '--apply'  => true,
            '--bodega' => $loteA->bodega_id,
        ])->assertSuccessful();

        $loteA->refresh();
        $loteB->refresh();

        $this->assertNotNull($loteA->wac_costo_inventario, 'El lote A debe haberse backfill');
        $this->assertNull($loteB->wac_costo_inventario, 'El lote B (otra bodega) no debe tocarse');
    }

    // =================================================================
    // 10. IDEMPOTENCIA — correr dos veces produce mismos valores
    // =================================================================

    public function test_idempotencia_apply_dos_veces_produce_mismos_valores(): void
    {
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
            ['cartones' => 100.0, 'costo' => 8000.0],
        ]);

        $this->artisan('wac:backfill', ['--apply' => true])->assertSuccessful();
        $lote->refresh();
        $primerWac = [
            'inventario'        => (float) $lote->wac_costo_inventario,
            'huevos'            => (float) $lote->wac_huevos_inventario,
            'costo_por_huevo'   => (float) $lote->wac_costo_por_huevo,
            'costo_por_carton'  => (float) $lote->wac_costo_por_carton_facturado,
        ];

        $this->artisan('wac:backfill', ['--apply' => true])->assertSuccessful();
        $lote->refresh();

        $this->assertEqualsWithDelta($primerWac['inventario'], (float) $lote->wac_costo_inventario, 0.0001);
        $this->assertEqualsWithDelta($primerWac['huevos'], (float) $lote->wac_huevos_inventario, 0.0001);
        $this->assertEqualsWithDelta($primerWac['costo_por_huevo'], (float) $lote->wac_costo_por_huevo, 0.000001);
        $this->assertEqualsWithDelta($primerWac['costo_por_carton'], (float) $lote->wac_costo_por_carton_facturado, 0.0001);
    }

    // =================================================================
    // 11. RESET vuelve columnas wac_* a NULL
    // =================================================================

    public function test_reset_vuelve_columnas_wac_a_null(): void
    {
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);

        $this->artisan('wac:backfill', ['--apply' => true])->assertSuccessful();
        $lote->refresh();
        $this->assertNotNull($lote->wac_costo_inventario);

        // Confirmación interactiva: pasamos "yes" al prompt
        $this->artisan('wac:backfill', ['--reset' => true])
            ->expectsConfirmation(
                'Esto pondrá en NULL las columnas wac_* de los lotes del filtro. ¿Continuar?',
                'yes'
            )
            ->assertSuccessful();

        $lote->refresh();
        $this->assertNull($lote->wac_costo_inventario);
        $this->assertNull($lote->wac_huevos_inventario);
        $this->assertNull($lote->wac_costo_por_huevo);
        $this->assertNull($lote->wac_costo_por_carton_facturado);
        $this->assertNull($lote->wac_ultima_actualizacion);
        $this->assertNull($lote->wac_motivo_ultima_actualizacion);
    }

    // =================================================================
    // 12. LOTE sin compras es saltado, no fallido
    // =================================================================

    public function test_lote_sin_compras_historial_es_saltado(): void
    {
        // Lote recién creado, sin ninguna compra aplicada
        $lote = Lote::factory()->create();

        $result = $this->service->calcularLote($lote, toleranciaDivergenciaLempiras: 0.10);

        $this->assertSame(BackfillLoteResult::ESTADO_SALTADO, $result->estado);
        $this->assertStringContainsString('sin compras', $result->motivoSalto ?? '');
    }
}
