<?php

declare(strict_types=1);

namespace App\Events\Inventario;

use App\Models\AjusteInventario;
use App\Models\Lote;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara DENTRO de la transacción del AjusteInventarioService cuando un
 * ajuste tipo SalidaReclasificacion se aplica al lote origen.
 *
 * Semántica WAC:
 *   - Una salida de reclasificación decrementa el lote origen, preservando
 *     el costo unitario del lote (invariante WAC en salidas).
 *   - El listener WAC recalcula:
 *       costo_saliente    = huevos_salida * costo_unit_actual
 *       nuevo_numerador   = numerador - costo_saliente
 *       nuevo_denominador = denominador - huevos_salida
 *       nuevo_costo_unit  = costo_unit_actual (INVARIANTE)
 *
 * Distinto de VentaAplicadaAlLote: no hay cliente ni venta real, el contexto
 * identifica el ajuste de origen y el lote destino vía contextoAuditoria.
 */
final readonly class AjusteSalidaAplicadaAlLote
{
    use Dispatchable;

    /**
     * @param Lote              $lote              Lote origen del que salen los huevos
     * @param AjusteInventario  $ajuste            Registro del ajuste recién aplicado
     * @param float             $huevosSalientes   Cantidad de huevos que salen (siempre > 0)
     * @param array             $contextoAuditoria Metadatos para el log WAC
     */
    public function __construct(
        public Lote             $lote,
        public AjusteInventario $ajuste,
        public float            $huevosSalientes,
        public array            $contextoAuditoria = [],
    ) {}
}
