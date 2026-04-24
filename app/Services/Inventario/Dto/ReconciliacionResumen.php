<?php

declare(strict_types=1);

namespace App\Services\Inventario\Dto;

use Illuminate\Support\Carbon;

/**
 * Resumen agregado de un run del ReconciliadorWacService (Fase 4).
 *
 * Construido incrementalmente durante la corrida del job/comando de
 * reconciliación y devuelto al caller (Job, Command, tests) al terminar.
 *
 * Consumidores:
 *   - ReconciliarWacCommand  → render en terminal cuando se ejecuta con --sync
 *   - Log::info final        → emitido dentro del Service con el contenido de toArray()
 *   - Tests de Fase 4        → asertan contadores sobre fixtures específicos
 *
 * Diseño — mutabilidad intencional:
 *   Los contadores se actualizan via registrar() durante el cursor() del Service.
 *   Alternativas consideradas y descartadas:
 *     (a) inmutabilidad + with*() → explosión de copias por cada lote evaluado,
 *     (b) array interno           → pierde tipado estricto útil en el caller.
 *   Los campos readonly son solo la identidad del run; los contadores son estado.
 *
 * Diseño — duración medida aquí, no en el caller:
 *   finalizar() congela $finalizadoEn. El caller no tiene que acordarse de pasar
 *   timestamps — el resumen se auto-contiene y es serializable directo a log.
 */
final class ReconciliacionResumen
{
    /** Total de lotes evaluados (incluye clasificación=ninguna). */
    public int $totalLotesEvaluados = 0;

    /** Lotes cuya comparación no aplicó (legacy sin base comparable y WAC también en cero). */
    public int $divergenciasNinguna = 0;

    /** Lotes con diferencia dentro de la tolerancia (redondeo). */
    public int $divergenciasRuido = 0;

    /** Lotes con patrón consistente con el bug legacy de sobrevaluación. */
    public int $divergenciasEsperadas = 0;

    /** Lotes con divergencia fuera de patrón — requieren atención manual. */
    public int $divergenciasAnomalas = 0;

    /**
     * IDs de lotes clasificados como anómalos durante el run. Limitado por
     * `maxLotesAnomalosATrackear` para evitar consumo ilimitado de memoria
     * si algo catastrófico hace que TODOS los lotes diverjan.
     *
     * @var list<int>
     */
    public array $lotesConAnomalia = [];

    /** Timestamp de cierre del run, null hasta que se llame finalizar(). */
    public ?Carbon $finalizadoEn = null;

    /**
     * Si true, el Service hizo early-return por kill-switch apagado.
     * El resumen se retorna con contadores en 0 y esta bandera en true
     * para que el caller lo distinga de un run legítimamente sin lotes.
     */
    public bool $deshabilitadoPorFlag = false;

    /**
     * @param string  $runUuid                    UUID del run (stringificado en logs)
     * @param Carbon  $iniciadoEn                 Timestamp de arranque
     * @param int     $maxLotesAnomalosATrackear  Tope del array lotesConAnomalia (default 500)
     */
    public function __construct(
        public readonly string $runUuid,
        public readonly Carbon $iniciadoEn,
        public readonly int    $maxLotesAnomalosATrackear = 500,
    ) {}

    /**
     * Registra el resultado de la clasificación de UN lote en los contadores.
     */
    public function registrar(int $loteId, ClasificacionDivergencia $clasif): void
    {
        $this->totalLotesEvaluados++;

        match ($clasif->clasificacion) {
            ClasificacionDivergencia::CLASIF_NINGUNA  => $this->divergenciasNinguna++,
            ClasificacionDivergencia::CLASIF_RUIDO    => $this->divergenciasRuido++,
            ClasificacionDivergencia::CLASIF_ESPERADA => $this->divergenciasEsperadas++,
            ClasificacionDivergencia::CLASIF_ANOMALA  => $this->divergenciasAnomalas++,
        };

        if ($clasif->esAnomala() && count($this->lotesConAnomalia) < $this->maxLotesAnomalosATrackear) {
            $this->lotesConAnomalia[] = $loteId;
        }
    }

    /**
     * Congela el timestamp de cierre. Idempotente — si ya se llamó, no sobreescribe.
     */
    public function finalizar(): void
    {
        $this->finalizadoEn ??= Carbon::now();
    }

    /**
     * Duración del run en segundos (con decimales de milisegundos). Retorna 0.0
     * si nunca se llamó finalizar() — el caller debe llamarlo antes de medir.
     */
    public function duracionSegundos(): float
    {
        if ($this->finalizadoEn === null) {
            return 0.0;
        }

        return round($this->iniciadoEn->diffInMilliseconds($this->finalizadoEn) / 1000, 3);
    }

    /**
     * ¿Hay divergencias que ameritan alarma operativa?
     */
    public function tieneAnomalias(): bool
    {
        return $this->divergenciasAnomalas > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_uuid'                 => $this->runUuid,
            'iniciado_en'              => $this->iniciadoEn->toIso8601String(),
            'finalizado_en'            => $this->finalizadoEn?->toIso8601String(),
            'duracion_segundos'        => $this->duracionSegundos(),
            'deshabilitado_por_flag'   => $this->deshabilitadoPorFlag,
            'total_lotes_evaluados'    => $this->totalLotesEvaluados,
            'divergencias_ninguna'     => $this->divergenciasNinguna,
            'divergencias_ruido'       => $this->divergenciasRuido,
            'divergencias_esperadas'   => $this->divergenciasEsperadas,
            'divergencias_anomalas'    => $this->divergenciasAnomalas,
            'lotes_con_anomalia'       => $this->lotesConAnomalia,
            'lotes_anomalos_truncado'  => $this->divergenciasAnomalas > count($this->lotesConAnomalia),
        ];
    }
}
