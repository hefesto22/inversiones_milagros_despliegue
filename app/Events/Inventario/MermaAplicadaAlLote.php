<?php

declare(strict_types=1);

namespace App\Events\Inventario;

use App\Models\Lote;
use App\Models\Merma;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara DENTRO de la transacción de Lote::registrarMerma() después de
 * persistir el cambio en el lote y crear el registro en tabla mermas.
 *
 * Semántica WAC de una merma:
 *   - La parte "cubiertoBuffer" (huevos de regalo dañados) NO afecta el WAC
 *     porque esos huevos no tienen costo asociado.
 *   - La parte "perdidaReal" (huevos facturados dañados) SÍ afecta el WAC:
 *     delta_costo = perdidaReal × wac_costo_por_huevo_actual
 *     El wac_costo_por_huevo se preserva (es una salida, no entrada).
 */
final readonly class MermaAplicadaAlLote
{
    use Dispatchable;

    /**
     * @param Lote   $lote                Lote afectado
     * @param Merma  $merma               Registro de merma recién creado
     * @param float  $huevosCubiertoBuffer Huevos de merma absorbidos por el buffer de regalo (sin costo)
     * @param float  $huevosPerdidaReal    Huevos facturados efectivamente perdidos (afectan WAC)
     */
    public function __construct(
        public Lote  $lote,
        public Merma $merma,
        public float $huevosCubiertoBuffer,
        public float $huevosPerdidaReal,
    ) {}
}
