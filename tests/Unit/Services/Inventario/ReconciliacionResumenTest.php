<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Inventario;

use App\Services\Inventario\Dto\ClasificacionDivergencia;
use App\Services\Inventario\Dto\ReconciliacionResumen;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tests unitarios del DTO ReconciliacionResumen (Fase 4 del refactor WAC Perpetuo).
 *
 * DTO estadistico construido incrementalmente por el ReconciliadorWacService
 * durante el cursor() sobre lotes. Este test suite valida el contrato completo
 * sin necesidad de BD — es aritmetica de contadores pura.
 *
 * Scope cubierto:
 *   1. Estado inicial: todos los contadores en cero.
 *   2. registrar() incrementa total + contador de la clase correspondiente.
 *   3. Solo clase anomala acumula en lotesConAnomalia.
 *   4. maxLotesAnomalosATrackear limita el array sin afectar el contador.
 *   5. finalizar() es idempotente (preserva el primer timestamp).
 *   6. duracionSegundos() = 0 antes de finalizar, valor real despues.
 *   7. tieneAnomalias() refleja el contador.
 *   8. toArray() expone todos los campos y marca truncado cuando aplica.
 *   9. deshabilitadoPorFlag se propaga al array serializado.
 */
class ReconciliacionResumenTest extends TestCase
{
    private function nuevoResumen(int $maxAnomalos = 500): ReconciliacionResumen
    {
        return new ReconciliacionResumen(
            runUuid:                   'test-run-uuid',
            iniciadoEn:                Carbon::parse('2026-04-23 03:00:00.000'),
            maxLotesAnomalosATrackear: $maxAnomalos,
        );
    }

    // =================================================================
    // 1. Estado inicial
    // =================================================================

    public function test_contadores_arrancan_en_cero(): void
    {
        $resumen = $this->nuevoResumen();

        $this->assertSame(0, $resumen->totalLotesEvaluados);
        $this->assertSame(0, $resumen->divergenciasNinguna);
        $this->assertSame(0, $resumen->divergenciasRuido);
        $this->assertSame(0, $resumen->divergenciasEsperadas);
        $this->assertSame(0, $resumen->divergenciasAnomalas);
        $this->assertSame([], $resumen->lotesConAnomalia);
        $this->assertNull($resumen->finalizadoEn);
        $this->assertFalse($resumen->deshabilitadoPorFlag);
    }

    // =================================================================
    // 2. registrar() incrementa contador de la clase correcta
    // =================================================================

    public function test_registrar_ninguna_incrementa_total_y_ninguna(): void
    {
        $resumen = $this->nuevoResumen();
        $resumen->registrar(42, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_NINGUNA));

        $this->assertSame(1, $resumen->totalLotesEvaluados);
        $this->assertSame(1, $resumen->divergenciasNinguna);
        $this->assertSame(0, $resumen->divergenciasRuido);
        $this->assertSame(0, $resumen->divergenciasEsperadas);
        $this->assertSame(0, $resumen->divergenciasAnomalas);
        $this->assertSame([], $resumen->lotesConAnomalia);
    }

    public function test_registrar_ruido_incrementa_solo_ruido(): void
    {
        $resumen = $this->nuevoResumen();
        $resumen->registrar(7, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_RUIDO));

        $this->assertSame(1, $resumen->divergenciasRuido);
        $this->assertSame([], $resumen->lotesConAnomalia, 'Ruido no se acumula como anomalia');
    }

    public function test_registrar_esperada_incrementa_solo_esperada(): void
    {
        $resumen = $this->nuevoResumen();
        $resumen->registrar(11, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ESPERADA));

        $this->assertSame(1, $resumen->divergenciasEsperadas);
        $this->assertSame([], $resumen->lotesConAnomalia, 'Esperada no se acumula como anomalia');
    }

    // =================================================================
    // 3. Solo anomala acumula el ID en lotesConAnomalia
    // =================================================================

    public function test_registrar_anomala_acumula_el_id_en_lotesConAnomalia(): void
    {
        $resumen = $this->nuevoResumen();
        $resumen->registrar(101, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ANOMALA));
        $resumen->registrar(202, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ANOMALA));

        $this->assertSame(2, $resumen->divergenciasAnomalas);
        $this->assertSame([101, 202], $resumen->lotesConAnomalia);
    }

    // =================================================================
    // 4. maxLotesAnomalosATrackear limita el array sin afectar el contador
    // =================================================================

    public function test_max_lotes_anomalos_limita_el_array_sin_afectar_contador(): void
    {
        $resumen = $this->nuevoResumen(maxAnomalos: 2);

        $resumen->registrar(1, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ANOMALA));
        $resumen->registrar(2, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ANOMALA));
        $resumen->registrar(3, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ANOMALA));
        $resumen->registrar(4, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ANOMALA));

        $this->assertSame(4, $resumen->divergenciasAnomalas, 'Contador refleja el total real (4)');
        $this->assertCount(2, $resumen->lotesConAnomalia,    'Array limitado al tope configurado');
        $this->assertSame([1, 2], $resumen->lotesConAnomalia, 'Primeros N IDs; los demas se descartan');
    }

    // =================================================================
    // 5. finalizar() es idempotente
    // =================================================================

    public function test_finalizar_es_idempotente_no_sobreescribe_primer_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-23 03:01:00'));
        $resumen = $this->nuevoResumen();

        $resumen->finalizar();
        $primero = $resumen->finalizadoEn;

        // Un dia despues, segunda invocacion
        Carbon::setTestNow(Carbon::parse('2026-04-24 03:01:00'));
        $resumen->finalizar();

        $this->assertTrue(
            $primero->eq($resumen->finalizadoEn),
            'La segunda llamada NO debe pisar el timestamp original'
        );

        Carbon::setTestNow();
    }

    // =================================================================
    // 6. duracionSegundos()
    // =================================================================

    public function test_duracion_segundos_retorna_cero_sin_finalizar(): void
    {
        $resumen = $this->nuevoResumen();
        $this->assertSame(0.0, $resumen->duracionSegundos());
    }

    public function test_duracion_segundos_calcula_diferencia_cuando_finalizado(): void
    {
        $inicio = Carbon::parse('2026-04-23 03:00:00.000');
        $fin    = Carbon::parse('2026-04-23 03:00:05.500');

        $resumen = new ReconciliacionResumen(
            runUuid:    'test-run-uuid',
            iniciadoEn: $inicio,
        );

        Carbon::setTestNow($fin);
        $resumen->finalizar();

        $this->assertEqualsWithDelta(5.5, $resumen->duracionSegundos(), 0.001);

        Carbon::setTestNow();
    }

    // =================================================================
    // 7. tieneAnomalias()
    // =================================================================

    public function test_tiene_anomalias_false_sin_anomalas(): void
    {
        $resumen = $this->nuevoResumen();
        $resumen->registrar(1, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_RUIDO));
        $resumen->registrar(2, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ESPERADA));

        $this->assertFalse($resumen->tieneAnomalias(), 'Solo ruido + esperada → no hay anomalias');
    }

    public function test_tiene_anomalias_true_con_una_sola_anomala(): void
    {
        $resumen = $this->nuevoResumen();
        $resumen->registrar(1, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ANOMALA));

        $this->assertTrue($resumen->tieneAnomalias());
    }

    // =================================================================
    // 8. toArray() expone todo + marca truncado
    // =================================================================

    public function test_toArray_expone_todos_los_campos_esperados(): void
    {
        $resumen = $this->nuevoResumen();
        $resumen->registrar(1, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ANOMALA));
        $resumen->finalizar();

        $array = $resumen->toArray();

        $this->assertSame('test-run-uuid', $array['run_uuid']);
        $this->assertSame(1, $array['total_lotes_evaluados']);
        $this->assertSame(1, $array['divergencias_anomalas']);
        $this->assertSame([1], $array['lotes_con_anomalia']);
        $this->assertFalse($array['lotes_anomalos_truncado']);
        $this->assertArrayHasKey('iniciado_en', $array);
        $this->assertArrayHasKey('finalizado_en', $array);
        $this->assertArrayHasKey('duracion_segundos', $array);
    }

    public function test_toArray_marca_truncado_cuando_contador_excede_array(): void
    {
        $resumen = $this->nuevoResumen(maxAnomalos: 1);
        $resumen->registrar(1, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ANOMALA));
        $resumen->registrar(2, new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ANOMALA));

        $array = $resumen->toArray();

        $this->assertSame(2, $array['divergencias_anomalas']);
        $this->assertCount(1, $array['lotes_con_anomalia']);
        $this->assertTrue(
            $array['lotes_anomalos_truncado'],
            'La marca debe indicar al consumidor que el array esta truncado'
        );
    }

    // =================================================================
    // 9. deshabilitadoPorFlag
    // =================================================================

    public function test_deshabilitado_por_flag_se_propaga_al_array(): void
    {
        $resumen = $this->nuevoResumen();
        $resumen->deshabilitadoPorFlag = true;

        $this->assertTrue($resumen->toArray()['deshabilitado_por_flag']);
    }
}
