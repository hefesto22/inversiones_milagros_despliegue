<?php

declare(strict_types=1);

namespace App\Services\Inventario\Dto;

/**
 * Resultado del cálculo de backfill WAC para un lote individual.
 *
 * Producido por BackfillWacService::calcularLote() y consumido por:
 *   - BackfillWacCommand para render en terminal y persistencia en wac_backfill_items
 *   - Tests para assertar valores exactos y clasificación
 *
 * Inmutable por diseño — representa un snapshot puntual del cálculo.
 *
 * Clasificación de divergencia contra el costo legacy:
 *   - ninguna   → no aplica (lote sin compras en el ciclo activo)
 *   - ruido     → |diferencia_por_carton| ≤ tolerancia (redondeo esperado)
 *   - esperada  → dentro de patrones conocidos del bug legacy documentado
 *                 en docs/AUDITORIA_VALUACION_2026-04-22.md
 *   - anomala   → fuera de tolerancia y fuera de patrones conocidos;
 *                 requiere --force para aplicarse
 */
final readonly class BackfillLoteResult
{
    public const ESTADO_PROCESADO = 'procesado';
    public const ESTADO_SALTADO   = 'saltado';
    public const ESTADO_FALLIDO   = 'fallido';

    public const CLASIF_NINGUNA  = 'ninguna';
    public const CLASIF_RUIDO    = 'ruido';
    public const CLASIF_ESPERADA = 'esperada';
    public const CLASIF_ANOMALA  = 'anomala';

    /**
     * @param int    $loteId
     * @param string $estado                        Uno de ESTADO_*
     * @param ?string $motivoSalto                  Si estado=saltado: razón humana
     * @param string $clasificacionDivergencia      Uno de CLASIF_*
     * @param ?string $detalleDivergencia           Explicación numérica si aplica
     * @param ?float $wacCostoInventario            Valor calculado (null si saltado/fallido)
     * @param ?float $wacHuevosInventario
     * @param ?float $wacCostoPorHuevo
     * @param ?float $wacCostoPorCartonFacturado
     * @param ?float $costoPorHuevoLegacy           Snapshot del valor legacy al momento de cálculo
     * @param ?float $costoPorCartonLegacy
     * @param ?float $diferenciaPorCarton           wacCostoPorCartonFacturado - costoPorCartonLegacy
     * @param int    $comprasConsideradas           # filas de historial_compras_lote usadas
     * @param ?string $errorMensaje                 Si estado=fallido
     */
    public function __construct(
        public int     $loteId,
        public string  $estado,
        public ?string $motivoSalto,
        public string  $clasificacionDivergencia,
        public ?string $detalleDivergencia,
        public ?float  $wacCostoInventario,
        public ?float  $wacHuevosInventario,
        public ?float  $wacCostoPorHuevo,
        public ?float  $wacCostoPorCartonFacturado,
        public ?float  $costoPorHuevoLegacy,
        public ?float  $costoPorCartonLegacy,
        public ?float  $diferenciaPorCarton,
        public int     $comprasConsideradas,
        public ?string $errorMensaje = null,
    ) {}

    public function fueProcesado(): bool
    {
        return $this->estado === self::ESTADO_PROCESADO;
    }

    public function esAnomala(): bool
    {
        return $this->clasificacionDivergencia === self::CLASIF_ANOMALA;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toPersistenceArray(): array
    {
        return [
            'lote_id'                         => $this->loteId,
            'estado'                          => $this->estado,
            'motivo_salto'                    => $this->motivoSalto,
            'clasificacion_divergencia'       => $this->clasificacionDivergencia,
            'detalle_divergencia'             => $this->detalleDivergencia,
            'wac_costo_inventario_calculado'  => $this->wacCostoInventario,
            'wac_huevos_inventario_calculado' => $this->wacHuevosInventario,
            'wac_costo_por_huevo_calculado'   => $this->wacCostoPorHuevo,
            'wac_costo_por_carton_calculado'  => $this->wacCostoPorCartonFacturado,
            'costo_por_huevo_legacy'          => $this->costoPorHuevoLegacy,
            'costo_por_carton_legacy'         => $this->costoPorCartonLegacy,
            'diferencia_por_carton'           => $this->diferenciaPorCarton,
            'compras_consideradas'            => $this->comprasConsideradas,
            'error_mensaje'                   => $this->errorMensaje,
        ];
    }

    public static function saltado(int $loteId, string $motivo): self
    {
        return new self(
            loteId:                     $loteId,
            estado:                     self::ESTADO_SALTADO,
            motivoSalto:                $motivo,
            clasificacionDivergencia:   self::CLASIF_NINGUNA,
            detalleDivergencia:         null,
            wacCostoInventario:         null,
            wacHuevosInventario:        null,
            wacCostoPorHuevo:           null,
            wacCostoPorCartonFacturado: null,
            costoPorHuevoLegacy:        null,
            costoPorCartonLegacy:       null,
            diferenciaPorCarton:        null,
            comprasConsideradas:        0,
        );
    }

    public static function fallido(int $loteId, string $mensajeError): self
    {
        return new self(
            loteId:                     $loteId,
            estado:                     self::ESTADO_FALLIDO,
            motivoSalto:                null,
            clasificacionDivergencia:   self::CLASIF_NINGUNA,
            detalleDivergencia:         null,
            wacCostoInventario:         null,
            wacHuevosInventario:        null,
            wacCostoPorHuevo:           null,
            wacCostoPorCartonFacturado: null,
            costoPorHuevoLegacy:        null,
            costoPorCartonLegacy:       null,
            diferenciaPorCarton:        null,
            comprasConsideradas:        0,
            errorMensaje:               $mensajeError,
        );
    }
}
