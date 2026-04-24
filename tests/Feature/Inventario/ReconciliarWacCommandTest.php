<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Jobs\Inventario\ReconciliarWacVsLegacyJob;
use App\Models\Lote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests feature del ReconciliarWacCommand (Fase 4 del refactor WAC Perpetuo).
 *
 * El command tiene dos modos:
 *   - Default (sin --sync): despacha el job a la cola y retorna SUCCESS.
 *   - --sync: ejecuta el servicio en foreground, imprime la tabla de
 *     resultados y retorna FAILURE (exit 1) si hay divergencias anomalas.
 *
 * Scope cubierto:
 *   1. Default despacha el job (Queue::fake verifica el push).
 *   2. Default NO ejecuta el servicio — incluso con un lote anomalo en BD,
 *      retorna SUCCESS porque el job no corrio todavia.
 *   3. --sync con flag apagado: SUCCESS + mensaje "esta apagado" en stdout.
 *   4. --sync sin lotes anomalos: SUCCESS + mensaje de confirmacion.
 *   5. --sync con lote anomalo: FAILURE (exit 1) + mensaje de error con
 *      referencia a logs y lista de IDs.
 */
class ReconciliarWacCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('inventario.wac.log_divergences', true);
        Config::set('inventario.wac.divergence_tolerance_lempiras', 0.10);
    }

    private function crearLoteConCostos(
        ?float $wacCostoPorHuevo,
        ?float $wacCostoPorCarton,
        float  $legacyCostoPorHuevo,
        float  $legacyCostoPorCarton,
    ): Lote {
        // forceFill() porque las columnas wac_* no estan en $fillable del modelo
        // (solo el WacService deberia escribirlas en produccion). Ver nota extensa
        // en ReconciliadorWacServiceTest::crearLoteConCostos.
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
    // 1. DEFAULT (sin --sync) despacha el job a la cola
    // =================================================================

    public function test_default_despacha_el_job_a_la_cola_y_retorna_success(): void
    {
        Queue::fake();

        $exitCode = $this->artisan('wac:reconciliar')
            ->expectsOutputToContain('Job despachado')
            ->run();

        $this->assertSame(0, $exitCode);
        Queue::assertPushed(ReconciliarWacVsLegacyJob::class);
    }

    public function test_default_no_ejecuta_servicio_en_foreground_incluso_con_lote_anomalo(): void
    {
        // Precondicion: un lote anomalo en BD. Si el default corriera el servicio
        // en foreground, retornaria FAILURE. Pero solo despacha → SUCCESS.
        Queue::fake();
        $this->crearLoteConCostos(2.6667, 80.00, 2.3333, 70.00);

        $exitCode = $this->artisan('wac:reconciliar')->run();

        $this->assertSame(0, $exitCode, 'Default solo despacha, no ejecuta');
        Queue::assertPushed(ReconciliarWacVsLegacyJob::class);
    }

    // =================================================================
    // 2. --SYNC con flag apagado
    // =================================================================

    public function test_sync_con_flag_apagado_retorna_success_con_mensaje_de_warning(): void
    {
        Config::set('inventario.wac.log_divergences', false);

        $exitCode = $this->artisan('wac:reconciliar', ['--sync' => true])
            ->expectsOutputToContain('está apagado')
            ->run();

        $this->assertSame(
            0,
            $exitCode,
            'Flag apagado NO es un error — es un no-op deliberado'
        );
    }

    // =================================================================
    // 3. --SYNC sin anomalias
    // =================================================================

    public function test_sync_sin_anomalias_retorna_success(): void
    {
        // Mezcla valida: ruido + esperada. Ninguna anomala.
        $this->crearLoteConCostos(2.3343, 70.03, 2.3333, 70.00);
        $this->crearLoteConCostos(2.3333, 70.00, 2.6050, 78.15);

        $exitCode = $this->artisan('wac:reconciliar', ['--sync' => true])
            ->expectsOutputToContain('sin divergencias anómalas')
            ->run();

        $this->assertSame(0, $exitCode);
    }

    // =================================================================
    // 4. --SYNC con anomalias
    // =================================================================

    public function test_sync_con_anomalias_retorna_failure_con_mensaje_y_referencia_a_logs(): void
    {
        $loteAnomalo = $this->crearLoteConCostos(2.6667, 80.00, 2.3333, 70.00);

        $exitCode = $this->artisan('wac:reconciliar', ['--sync' => true])
            ->expectsOutputToContain('lotes con divergencia anómala')
            ->expectsOutputToContain((string) $loteAnomalo->id)
            ->run();

        $this->assertSame(
            1,
            $exitCode,
            'FAILURE permite que scripts de CI/monitoreo detecten el problema'
        );
    }
}
