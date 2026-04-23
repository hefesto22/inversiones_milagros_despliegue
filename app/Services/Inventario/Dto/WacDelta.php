<?php

declare(strict_types=1);

namespace App\Services\Inventario\Dto;

/**
 * DTO inmutable que describe el resultado de aplicar un cambio WAC a un lote.
 *
 * Retornado por WacService::aplicar{Compra|Venta|Merma|Devolucion}() y usado por:
 *   - ActualizarWacListener para logging estructurado
 *   - Tests para assertar el delta exacto aplicado
 *   - Futuro ReconciliarWacVsLegacyJob (Fase 4) para detectar divergencias
 *
 * Características de diseño:
 *   - readonly: inmutable por construcción, se puede compartir sin defensive copies
 *   - final: no heredable, contrato estable de datos del dominio
 *   - toArray(): serialización para logs y tests
 */
final readonly class WacDelta
{
    /**
     * @param int    $loteId                          ID del lote afectado
     * @param string $motivo                          'compra'|'venta'|'merma'|'devolucion'|'backfill'
     * @param float  $deltaHuevos                     Cambio en huevos facturados (+ entrada, − salida)
     * @param float  $deltaCostoInventario            Cambio en costo inventario Lempiras (mismo signo que deltaHuevos)
     * @param float  $wacCostoInventarioAntes         Numerador antes de la operación
     * @param float  $wacCostoInventarioDespues       Numerador después de la operación
     * @param float  $wacHuevosInventarioAntes        Denominador antes (DECIMAL en BD, puede ser fraccional)
     * @param float  $wacHuevosInventarioDespues      Denominador después
     * @param float  $wacCostoPorHuevoAntes           Costo unitario WAC antes (6 decimales)
     * @param float  $wacCostoPorHuevoDespues         Costo unitario WAC después (6 decimales)
     * @param array  $contextoAuditoria               Metadatos para auditoría (compra_id, venta_detalle_id, merma_id, etc.)
     */
    public function __construct(
        public int   $loteId,
        public string $motivo,
        public float $deltaHuevos,
        public float $deltaCostoInventario,
        public float $wacCostoInventarioAntes,
        public float $wacCostoInventarioDespues,
        public float $wacHuevosInventarioAntes,
        public float $wacHuevosInventarioDespues,
        public float $wacCostoPorHuevoAntes,
        public float $wacCostoPorHuevoDespues,
        public array $contextoAuditoria = [],
    ) {}

    /**
     * Serialización plana para logs estructurados y assertions en tests.
     *
     * Ejemplo de uso:
     *   Log::info('WAC aplicado', $delta->toArray());
     */
    public function toArray(): array
    {
        return [
            'lote_id'                        => $this->loteId,
            'motivo'                         => $this->motivo,
            'delta_huevos'                   => $this->deltaHuevos,
            'delta_costo_inventario'         => $this->deltaCostoInventario,
            'wac_costo_inventario_antes'     => $this->wacCostoInventarioAntes,
            'wac_costo_inventario_despues'   => $this->wacCostoInventarioDespues,
            'wac_huevos_inventario_antes'    => $this->wacHuevosInventarioAntes,
            'wac_huevos_inventario_despues'  => $this->wacHuevosInventarioDespues,
            'wac_costo_por_huevo_antes'      => $this->wacCostoPorHuevoAntes,
            'wac_costo_por_huevo_despues'    => $this->wacCostoPorHuevoDespues,
            'contexto'                       => $this->contextoAuditoria,
        ];
    }

    /**
     * Retorna true si la operación NO modificó el wac_costo_por_huevo.
     *
     * Invariante matemático del WAC: ventas y mermas NO deben cambiar el costo
     * unitario (el promedio ponderado se preserva cuando salen huevos).
     * Si este método retorna false en una venta/merma, hay un bug en la aritmética.
     */
    public function costoPorHuevoPreservado(float $toleranciaDecimales = 0.000001): bool
    {
        return abs($this->wacCostoPorHuevoAntes - $this->wacCostoPorHuevoDespues) <= $toleranciaDecimales;
    }
}
