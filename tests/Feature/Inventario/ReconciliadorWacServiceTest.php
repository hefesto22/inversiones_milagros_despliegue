<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\Lote;
use App\Services\Inventario\ReconciliadorWacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests feature del ReconciliadorWacService (Fase 4 del refactor WAC Perpetuo).
 *
 * A diferencia del clasificador (unit), este servicio toca la BD via cursor()
 * y tiene side-effect de logging. Los tests validan:
 *
 *   1. Kill-switch (log_divergences=false) — early return sin query.
 *   2. Filtro de la query — solo lotes con wac_costo_por_huevo NOT NULL
 *      y costo_por_huevo > 0 se evaluan.
 *   3. Cada clase de divergencia (ruido/esperada/anomala) se cuenta correctamente.
 *   4. Lotes anomalos emiten Log::warning con contexto (lote_id, clasificacion).
 *   5. Lotes esperados emiten Log::info, NO Log::warning (nivel importa para alerting).
 *   6. Resumen mixto: varios lotes en distintas clases se contabilizan correctamente.
 *
 * Nota de diseno:
 *   Los tests crean lotes via Lote::factory()->create() y luego sobreescriben
 *   las 4 columnas relevantes con update(). No usamos crearLoteConCompras del
 *   BackfillWacCommandTest porque aqui no nos importa el historial — solo las
 *   columnas ya persistidas (el reconciliador es puro lector de estado).
 */
class ReconciliadorWacServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReconciliadorWacService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReconciliadorWacService::class);

        // Default: flag ON para que los tests midan el comportamiento de
        // clasificacion en vez de solo el early-return.
        Config::set('inventario.wac.log_divergences', true);
        Config::set('inventario.wac.divergence_tolerance_lempiras', 0.10);
    }

    /**
     * Helper: crea un lote con wac_* y legacy_* especificos.
     *
     * wac_costo_por_huevo se pasa como nullable para poder testear el filtro
     * `whereNotNull` de la query.
     */
    private function crearLoteConCostos(
        ?float $wacCostoPorHuevo,
        ?float $wacCostoPorCarton,
        float  $legacyCostoPorHuevo,
        float  $legacyCostoPorCarton,
    ): Lote {
        // Usamos forceFill() porque wac_costo_por_huevo / wac_costo_por_carton_facturado
        // NO estan en $fillable del modelo Lote — y eso es correcto en produccion:
        // solo el WacService deberia escribir esas columnas, nunca mass assignment
        // externo. forceFill es la forma canonica de Laravel para bypassear el guard
        // intencionalmente en tests.
        $lote = Lote::factory()->create();
        $lote->forceFill([
            'wac_costo_por_huevo'            => $wacCostoPorHuevo,
            'wac_costo_por_carton_facturado' => $wacCostoPorCarton,
            'costo_por_huevo'                => $legacyCostoPorHuevo,
            'costo_por_carton_facturado'     => $legacyCostoPorCarton,
        ])->save();
        return $lote->fresh();
    }

    // =================================================================
    // 1. KILL-SWITCH
    // =================================================================

    public function test_flag_apagado_retorna_resumen_deshabilitado_sin_evaluar_lotes(): void
    {
        Config::set('inventario.wac.log_divergences', false);

        // Incluso creando un lote anomalo, no debe evaluarse.
        $this->crearLoteConCostos(2.6667, 80.0, 2.3333, 70.0);

        $resumen = $this->service->reconciliar();

        $this->assertTrue($resumen->deshabilitadoPorFlag);
        $this->assertSame(0, $resumen->totalLotesEvaluados);
        $this->assertSame(0, $resumen->divergenciasAnomalas);
        $this->assertNotNull($resumen->finalizadoEn, 'finalizar() igual se llama en el early-return');
    }

    // =================================================================
    // 2. FILTRO de la query (whereNotNull + where>0)
    // =================================================================

    public function test_lote_sin_wac_inicializado_no_se_evalua(): void
    {
        // wac_costo_por_huevo = NULL → excluido por whereNotNull
        $this->crearLoteConCostos(null, null, 2.3333, 70.00);

        $resumen = $this->service->reconciliar();

        $this->assertSame(0, $resumen->totalLotesEvaluados);
    }

    public function test_lote_con_legacy_cero_no_se_evalua(): void
    {
        // costo_por_huevo = 0 → excluido por where('costo_por_huevo', '>', 0)
        $this->crearLoteConCostos(2.3333, 70.0, 0.0, 0.0);

        $resumen = $this->service->reconciliar();

        $this->assertSame(0, $resumen->totalLotesEvaluados);
    }

    // =================================================================
    // 3. CLASIFICACION correcta por clase
    // =================================================================

    public function test_lote_con_ruido_se_cuenta_como_ruido(): void
    {
        // diff = 0.03 L → dentro de tolerancia 0.10 → ruido
        $this->crearLoteConCostos(
            wacCostoPorHuevo:     2.3343,
            wacCostoPorCarton:    70.03,
            legacyCostoPorHuevo:  2.3333,
            legacyCostoPorCarton: 70.00,
        );

        $resumen = $this->service->reconciliar();

        $this->assertSame(1, $resumen->totalLotesEvaluados);
        $this->assertSame(1, $resumen->divergenciasRuido);
        $this->assertSame(0, $resumen->divergenciasAnomalas);
        $this->assertSame([], $resumen->lotesConAnomalia);
    }

    public function test_lote_con_esperada_se_cuenta_como_esperada_sin_acumular_anomalia(): void
    {
        // WAC 70 vs Legacy 78.15 → diff -8.15 L, |-8.15|/78.15 = 10.43% <= 15%
        $this->crearLoteConCostos(
            wacCostoPorHuevo:     2.3333,
            wacCostoPorCarton:    70.00,
            legacyCostoPorHuevo:  2.6050,
            legacyCostoPorCarton: 78.15,
        );

        $resumen = $this->service->reconciliar();

        $this->assertSame(1, $resumen->divergenciasEsperadas);
        $this->assertSame(0, $resumen->divergenciasAnomalas);
        $this->assertSame([], $resumen->lotesConAnomalia);
    }

    public function test_lote_con_anomalia_se_cuenta_y_acumula_en_lotesConAnomalia(): void
    {
        // WAC 80 > Legacy 70 → anomala (WAC no debe inflar costo)
        $lote = $this->crearLoteConCostos(
            wacCostoPorHuevo:     2.6667,
            wacCostoPorCarton:    80.00,
            legacyCostoPorHuevo:  2.3333,
            legacyCostoPorCarton: 70.00,
        );

        $resumen = $this->service->reconciliar();

        $this->assertSame(1, $resumen->divergenciasAnomalas);
        $this->assertSame([$lote->id], $resumen->lotesConAnomalia);
        $this->assertTrue($resumen->tieneAnomalias());
    }

    // =================================================================
    // 4. LOGGING — severidad correcta por clase
    // =================================================================

    public function test_lote_anomalo_emite_log_warning_con_contexto_estructurado(): void
    {
        Log::spy();

        $lote = $this->crearLoteConCostos(
            wacCostoPorHuevo:     2.6667,
            wacCostoPorCarton:    80.00,
            legacyCostoPorHuevo:  2.3333,
            legacyCostoPorCarton: 70.00,
        );

        $this->service->reconciliar();

        // Debe emitirse un warning con el contexto identificable por el alerting.
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($lote): bool {
                return str_contains($message, 'ANÓMALA')
                    && ($context['lote_id']       ?? null) === $lote->id
                    && ($context['clasificacion'] ?? null) === 'anomala'
                    && array_key_exists('run_uuid', $context)
                    && array_key_exists('wac_carton', $context)
                    && array_key_exists('legacy_carton', $context)
                    && array_key_exists('diferencia_l', $context);
            })
            ->once();
    }

    public function test_lote_esperada_emite_log_info_y_no_warning(): void
    {
        Log::spy();

        $this->crearLoteConCostos(
            wacCostoPorHuevo:     2.3333,
            wacCostoPorCarton:    70.00,
            legacyCostoPorHuevo:  2.6050,
            legacyCostoPorCarton: 78.15,
        );

        $this->service->reconciliar();

        // Esperada NO escala a warning — no queremos alertas por el bug
        // documentado que justamente este refactor corrige.
        Log::shouldNotHaveReceived('warning');
        // Debe emitir al menos un info: uno por lote reportable + uno al cierre del run.
        Log::shouldHaveReceived('info')->atLeast()->times(1);
    }

    // =================================================================
    // 5. RUN MIXTO — varios lotes en distintas clases
    // =================================================================

    public function test_run_con_lotes_mixtos_cuenta_cada_clase_correctamente(): void
    {
        // Ruido
        $this->crearLoteConCostos(2.3343, 70.03, 2.3333, 70.00);
        // Esperada
        $this->crearLoteConCostos(2.3333, 70.00, 2.6050, 78.15);
        // Anomala
        $loteAnomalo = $this->crearLoteConCostos(2.6667, 80.00, 2.3333, 70.00);
        // Excluido por filtro (wac=null)
        $this->crearLoteConCostos(null, null, 2.3333, 70.00);
        // Excluido por filtro (legacy=0)
        $this->crearLoteConCostos(2.3333, 70.00, 0.0, 0.0);

        $resumen = $this->service->reconciliar();

        $this->assertSame(3, $resumen->totalLotesEvaluados, 'Los 2 excluidos no entran');
        $this->assertSame(1, $resumen->divergenciasRuido);
        $this->assertSame(1, $resumen->divergenciasEsperadas);
        $this->assertSame(1, $resumen->divergenciasAnomalas);
        $this->assertSame([$loteAnomalo->id], $resumen->lotesConAnomalia);
        $this->assertTrue($resumen->tieneAnomalias());
    }
}
