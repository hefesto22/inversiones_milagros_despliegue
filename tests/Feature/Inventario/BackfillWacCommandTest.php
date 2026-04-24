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
 *   2. FIFO-inverso sobre remanente: el WAC refleja las compras MÁS RECIENTES
 *      que físicamente siguen en stock (no el promedio global de todas las
 *      compras del ciclo). Incluye prorateo en boundary e inconsistencias.
 *   3. Dry-run no toca columnas wac_* bajo ningún escenario.
 *   4. Apply escribe wac_* cuando no hay anomalías.
 *   5. Apply aborta cuando hay anomalías sin --force.
 *   6. Apply + force aplica aun con anomalías.
 *   7. Clasificación de divergencia: ruido vs esperada vs anómala.
 *   8. Filtros --bodega / --producto aislan correctamente.
 *   9. Idempotencia: correr apply dos veces produce los mismos valores finales.
 *  10. Reset vuelve columnas wac_* a null.
 *  11. Lote sin compras/sin saldo → saltado con motivo claro.
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
    // 3. FIFO-INVERSO — remanente refleja compras recientes, no promedio global
    // =================================================================

    public function test_fifo_inverso_remanente_refleja_compra_mas_reciente_no_promedio_global(): void
    {
        // Escenario del dominio real (descrito por el owner): compras a distintos
        // precios en el tiempo. Las más VIEJAS (caras) ya se vendieron físicamente
        // en FIFO; el remanente corresponde a las más RECIENTES (baratas). El WAC
        // debe reflejarlo — NO inflarse con costos de compras ya agotadas.
        //
        // Compra 1 (vieja):    100 cartones × 85 = 8,500 L (3,000 huevos)
        // Compra 2 (reciente): 100 cartones × 70 = 7,000 L (3,000 huevos)
        // Ventas: 3,000 huevos (físicamente los de la compra 1)
        // Remanente: 3,000 huevos = lo que queda de la compra 2
        //
        // Promedio global (algoritmo incorrecto):
        //   WAC = (8,500 + 7,000) / 6,000 = 2.5833/huevo = 77.50 L/cartón
        //   → INFLARÍA el valor del remanente con costos ya vendidos.
        //
        // FIFO-inverso sobre remanente:
        //   walk DESC toma solo la compra 2 (cubre los 3,000 huevos exactos)
        //   WAC = 7,000 / 3,000 = 2.3333/huevo = 70.00 L/cartón ✓
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 8500.0], // vieja (85/cartón)
            ['cartones' => 100.0, 'costo' => 7000.0], // reciente (70/cartón)
        ]);
        $this->simularVentas($lote, 3000.0); // se agota la compra vieja
        $lote = $lote->fresh();

        $result = $this->service->calcularLote($lote, toleranciaDivergenciaLempiras: 0.10);

        $this->assertTrue($result->fueProcesado(), 'Debe procesar correctamente');
        // El WAC viene de la compra 2 (reciente, barata) — NO del promedio global.
        $this->assertEqualsWithDelta(2.333333, $result->wacCostoPorHuevo, 0.000001);
        $this->assertEqualsWithDelta(70.0, $result->wacCostoPorCartonFacturado, 0.0001);
        $this->assertEqualsWithDelta(3000.0, $result->wacHuevosInventario, 0.0001);
        $this->assertEqualsWithDelta(7000.0, $result->wacCostoInventario, 0.0001);
        $this->assertSame(1, $result->comprasConsideradas, 'Solo la compra reciente está activa en stock');
    }

    // =================================================================
    // 3b. FIFO-INVERSO — prorateo cuando el remanente cruza un boundary
    // =================================================================

    public function test_fifo_inverso_prorratea_compra_cuando_remanente_cruza_boundary(): void
    {
        // Compra 1 (vieja):    100 cartones × 85 = 8,500 L (3,000 huevos)
        // Compra 2 (reciente): 100 cartones × 70 = 7,000 L (3,000 huevos)
        // Ventas: 1,500 huevos (solo la mitad de la compra vieja)
        // Remanente: 4,500 huevos = compra 2 completa + mitad de compra 1
        //
        // FIFO-inverso:
        //   - Compra 2 completa:    3,000 huevos, 7,000 L
        //   - Compra 1 prorrateada: 1,500 huevos, 8,500 × 0.5 = 4,250 L
        //   Total: 4,500 huevos, 11,250 L
        //   WAC = 11,250 / 4,500 = 2.50/huevo = 75.00 L/cartón
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 8500.0],
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);
        $this->simularVentas($lote, 1500.0);
        $lote = $lote->fresh();

        $result = $this->service->calcularLote($lote, toleranciaDivergenciaLempiras: 0.10);

        $this->assertTrue($result->fueProcesado());
        $this->assertEqualsWithDelta(2.50, $result->wacCostoPorHuevo, 0.000001);
        $this->assertEqualsWithDelta(75.0, $result->wacCostoPorCartonFacturado, 0.0001);
        $this->assertEqualsWithDelta(4500.0, $result->wacHuevosInventario, 0.0001);
        $this->assertEqualsWithDelta(11250.0, $result->wacCostoInventario, 0.0001);
        $this->assertSame(2, $result->comprasConsideradas, 'Ambas compras contribuyen (una prorrateada)');
    }

    // =================================================================
    // 3c. FIFO-INVERSO — remanente > historial disponible = inconsistencia
    // =================================================================

    public function test_fifo_inverso_remanente_mayor_que_historial_marca_inconsistencia(): void
    {
        // Lote con 3,000 huevos en historial (1 compra) pero remanente
        // artificialmente inflado a 4,000. Simula data anomalies: filas
        // borradas del historial, ajustes manuales, etc. El walk agota
        // todas las compras sin cubrir el target → inconsistencia.
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);
        $lote->update(['cantidad_huevos_remanente' => 4000.0]);
        $lote = $lote->fresh();

        $result = $this->service->calcularLote($lote, toleranciaDivergenciaLempiras: 0.10);

        $this->assertSame(
            BackfillLoteResult::CLASIF_ANOMALA,
            $result->clasificacionDivergencia,
            'Debe clasificarse como anómala'
        );
        $this->assertNull($result->wacCostoPorHuevo, 'No se escribe WAC sin historial suficiente');
        $this->assertNull($result->wacCostoInventario);
        $this->assertStringContainsString(
            'FIFO-inverso',
            $result->detalleDivergencia ?? '',
            'El detalle debe identificar el tipo de inconsistencia'
        );
    }

    // =================================================================
    // 3d. LOTE agotado (remanente=0) es PROCESADO con semilla legacy
    // =================================================================

    public function test_lote_agotado_es_procesado_con_semilla_legacy_para_devoluciones_futuras(): void
    {
        // Un lote sin saldo físico (todo vendido) NO se salta:
        // se siembra wac_costo_por_huevo con legacy.costo_por_huevo para que
        // una devolución posterior (reversión de reempaque o devolución de
        // cliente) pueda reactivar el lote con el costo correcto.
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);
        $lote->update(['cantidad_huevos_remanente' => 0.0]);
        $lote = $lote->fresh();

        // Legacy: costo_por_huevo = 7000/3000 = 2.3333, costo_por_carton = 70.00
        $legacyCostoPorHuevo  = (float) $lote->costo_por_huevo;
        $legacyCostoPorCarton = (float) $lote->costo_por_carton_facturado;
        $this->assertGreaterThan(0, $legacyCostoPorHuevo, 'Precondición: legacy poblado');

        $result = $this->service->calcularLote($lote, toleranciaDivergenciaLempiras: 0.10);

        $this->assertSame(
            BackfillLoteResult::ESTADO_PROCESADO,
            $result->estado,
            'Lote agotado con legacy válido debe procesarse (no saltarse)'
        );
        $this->assertSame(0.0, $result->wacCostoInventario, 'Sin stock físico → inv=0');
        $this->assertSame(0.0, $result->wacHuevosInventario, 'Sin stock físico → huevos=0');
        $this->assertEqualsWithDelta(
            round($legacyCostoPorHuevo, 6),
            $result->wacCostoPorHuevo,
            0.000001,
            'Semilla: wac_costo_por_huevo = legacy.costo_por_huevo'
        );
        $this->assertEqualsWithDelta(
            round($legacyCostoPorHuevo * 30, 4),
            $result->wacCostoPorCartonFacturado,
            0.0001,
            'Costo/cartón derivado de la semilla'
        );
        $this->assertSame(
            'backfill_agotado',
            $result->motivoPersistencia,
            'Motivo distingue semilla agotado del FIFO-inverso estándar'
        );
        $this->assertSame(BackfillLoteResult::CLASIF_NINGUNA, $result->clasificacionDivergencia);
    }

    public function test_lote_agotado_sin_costo_legacy_es_saltado(): void
    {
        // Caso borde: lote sin saldo físico Y sin costo legacy (<=0).
        // Típicamente un lote huérfano sin datos útiles — se salta explícitamente.
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);
        $lote->update([
            'cantidad_huevos_remanente'    => 0.0,
            'costo_por_huevo'              => 0.0, // legacy vacío
            'costo_por_carton_facturado'   => 0.0,
        ]);
        $lote = $lote->fresh();

        $result = $this->service->calcularLote($lote, toleranciaDivergenciaLempiras: 0.10);

        $this->assertSame(BackfillLoteResult::ESTADO_SALTADO, $result->estado);
        $this->assertStringContainsString('sin costo_por_huevo legacy', $result->motivoSalto ?? '');
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

    // =================================================================
    // 13. REPROCESS — caché de runs previos
    // =================================================================

    public function test_dry_run_default_salta_lotes_procesados_en_runs_previos(): void
    {
        // Caso: dos dry-run consecutivos. El segundo debe saltar el lote porque
        // ya fue procesado exitosamente en el primero (comportamiento de reanudación).
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);

        $this->artisan('wac:backfill', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('wac:backfill', ['--dry-run' => true])->assertSuccessful();

        // El segundo run debe haber marcado el lote como saltado con el motivo de caché.
        $runs = DB::table('wac_backfill_runs')
            ->where('modo', 'dry-run')
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $this->assertCount(2, $runs, 'Se deben registrar ambos runs');

        $itemSegundoRun = DB::table('wac_backfill_items')
            ->where('wac_backfill_run_id', $runs[1])
            ->where('lote_id', $lote->id)
            ->first();

        $this->assertNotNull($itemSegundoRun);
        $this->assertSame('saltado', $itemSegundoRun->estado);
        $this->assertStringContainsString(
            'ya procesado en run previo exitoso',
            (string) $itemSegundoRun->motivo_salto
        );
    }

    public function test_dry_run_con_reprocess_ignora_cache_y_recalcula(): void
    {
        // Caso crítico: cambia el algoritmo (o se tunean parámetros) → los runs
        // previos son inválidos. --reprocess debe forzar el recálculo sin borrar
        // historial.
        $lote = $this->crearLoteConCompras([
            ['cartones' => 100.0, 'costo' => 7000.0],
        ]);

        $this->artisan('wac:backfill', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('wac:backfill', ['--dry-run' => true, '--reprocess' => true])
            ->assertSuccessful();

        $runs = DB::table('wac_backfill_runs')
            ->where('modo', 'dry-run')
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $this->assertCount(2, $runs);

        $itemSegundoRun = DB::table('wac_backfill_items')
            ->where('wac_backfill_run_id', $runs[1])
            ->where('lote_id', $lote->id)
            ->first();

        $this->assertNotNull($itemSegundoRun);
        $this->assertSame(
            'procesado',
            $itemSegundoRun->estado,
            '--reprocess debe forzar el recálculo, NO saltar por caché'
        );

        // El historial del run anterior debe seguir intacto (auditoría).
        $this->assertDatabaseHas('wac_backfill_items', [
            'wac_backfill_run_id' => $runs[0],
            'lote_id'             => $lote->id,
            'estado'              => 'procesado',
        ]);
    }

    public function test_reprocess_combinado_con_reset_es_rechazado(): void
    {
        $exitCode = $this->artisan('wac:backfill', [
            '--reset'     => true,
            '--reprocess' => true,
        ])->run();

        $this->assertSame(
            2, // self::INVALID
            $exitCode,
            '--reset + --reprocess debe devolver INVALID'
        );
    }
}
