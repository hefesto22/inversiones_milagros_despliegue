<?php

declare(strict_types=1);

namespace App\Events\Inventario;

use App\Models\Lote;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara DENTRO de la transacción de Lote::devolverHuevos() cuando se
 * restituyen huevos al lote (reversión parcial de reempaque o devolución
 * de cliente).
 *
 * Diseño pragmático para Fase 2 (shadow mode):
 *   La devolución usa el wac_costo_por_huevo ACTUAL del lote al momento de
 *   devolver — no el costo original al que salieron los huevos. Esto significa:
 *
 *   - Escenario feliz (WAC estable): compra → venta inmediata → devolución
 *     inmediata deja el WAC exactamente como estaba antes. ✓
 *
 *   - Escenario borde (WAC cambió entre venta y devolución, porque hubo una
 *     nueva compra con precio distinto): la devolución reintegra al WAC actual,
 *     que refleja correctamente el costo promedio de hoy. Es la política
 *     esperable del promedio ponderado perpetuo.
 *
 * Si en Fase 4 observamos divergencias causadas por este supuesto, se refina
 * a rastrear el costo original en la línea de venta/reempaque.
 */
final readonly class DevolucionAplicadaAlLote
{
    use Dispatchable;

    /**
     * @param Lote   $lote                         Lote receptor de la devolución
     * @param float  $huevosFacturadosDevueltos    Huevos con costo reintegrados al lote
     * @param float  $huevosRegaloDevueltos        Huevos de regalo reintegrados (no afectan WAC)
     * @param array  $contexto                     ['reempaque_id' => ..., 'venta_detalle_id' => ...]
     */
    public function __construct(
        public Lote  $lote,
        public float $huevosFacturadosDevueltos,
        public float $huevosRegaloDevueltos,
        public array $contexto = [],
    ) {}
}
