<?php

declare(strict_types=1);

namespace App\Services\Inventario\Dto;

/**
 * Resumen agregado de un run del BackfillWacCommand.
 *
 * Construido incrementalmente durante la corrida y devuelto por
 * BackfillWacService::ejecutarRun() al terminar. Usado por:
 *   - BackfillWacCommand para render del reporte final en terminal
 *   - Tests para assertar contadores y estado global
 *   - Consultas posteriores vía la tabla wac_backfill_runs
 */
final class BackfillRunSummary
{
    public int $totalLotes           = 0;
    public int $lotesProcesados      = 0;
    public int $lotesSaltados        = 0;
    public int $lotesFallidos        = 0;
    public int $divergenciasRuido    = 0;
    public int $divergenciasEsperadas = 0;
    public int $divergenciasAnomalas = 0;

    /**
     * @var list<int>
     */
    public array $lotesConAnomalia = [];

    public function __construct(
        public readonly string $runUuid,
        public readonly string $modo,
        public readonly ?int   $bodegaIdFiltro,
        public readonly ?int   $productoIdFiltro,
        public readonly bool   $forceAplicado,
    ) {}

    public function registrar(BackfillLoteResult $result): void
    {
        match ($result->estado) {
            BackfillLoteResult::ESTADO_PROCESADO => $this->lotesProcesados++,
            BackfillLoteResult::ESTADO_SALTADO   => $this->lotesSaltados++,
            BackfillLoteResult::ESTADO_FALLIDO   => $this->lotesFallidos++,
        };

        match ($result->clasificacionDivergencia) {
            BackfillLoteResult::CLASIF_RUIDO    => $this->divergenciasRuido++,
            BackfillLoteResult::CLASIF_ESPERADA => $this->divergenciasEsperadas++,
            BackfillLoteResult::CLASIF_ANOMALA  => $this->divergenciasAnomalas++,
            BackfillLoteResult::CLASIF_NINGUNA  => null,
        };

        if ($result->esAnomala()) {
            $this->lotesConAnomalia[] = $result->loteId;
        }
    }

    public function tieneAnomaliasNoAutorizadas(): bool
    {
        return $this->divergenciasAnomalas > 0 && ! $this->forceAplicado;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_uuid'                => $this->runUuid,
            'modo'                    => $this->modo,
            'bodega_id_filtro'        => $this->bodegaIdFiltro,
            'producto_id_filtro'      => $this->productoIdFiltro,
            'force_aplicado'          => $this->forceAplicado,
            'total_lotes'             => $this->totalLotes,
            'lotes_procesados'        => $this->lotesProcesados,
            'lotes_saltados'          => $this->lotesSaltados,
            'lotes_fallidos'          => $this->lotesFallidos,
            'divergencias_ruido'      => $this->divergenciasRuido,
            'divergencias_esperadas'  => $this->divergenciasEsperadas,
            'divergencias_anomalas'   => $this->divergenciasAnomalas,
            'lotes_con_anomalia'      => $this->lotesConAnomalia,
        ];
    }
}
