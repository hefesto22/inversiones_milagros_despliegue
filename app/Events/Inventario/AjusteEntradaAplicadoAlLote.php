<?php

declare(strict_types=1);

namespace App\Events\Inventario;

use App\Models\AjusteInventario;
use App\Models\Lote;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara DENTRO de la transacción del AjusteInventarioService cuando un
 * ajuste tipo EntradaReclasificacion se aplica al lote destino.
 *
 * Semántica WAC:
 *   - Una entrada de reclasificación incrementa el lote destino con huevos
 *     valorados al costo unitario explícito (típicamente el costo del lote
 *     destino, para preservar su WAC sin alteración).
 *   - El listener WAC recalcula:
 *       nuevo_numerador   = numerador + (huevos * costo_unit_aplicado)
 *       nuevo_denominador = denominador + huevos
 *       nuevo_costo_unit  = nuevo_numerador / nuevo_denominador
 *
 * Distinto de CompraAplicadaAlLote: no hay proveedor ni compra real,
 * el contexto identifica el ajuste de origen vía contextoAuditoria.
 */
final readonly class AjusteEntradaAplicadoAlLote
{
    use Dispatchable;

    /**
     * @param Lote              $lote                  Lote destino al que entran los huevos
     * @param AjusteInventario  $ajuste                Registro del ajuste recién aplicado
     * @param float             $huevosEntrantes       Cantidad de huevos que entran (siempre > 0)
     * @param float             $costoUnitarioAplicado Costo por huevo aplicado al movimiento
     * @param array             $contextoAuditoria     Metadatos para el log WAC
     */
    public function __construct(
        public Lote             $lote,
        public AjusteInventario $ajuste,
        public float            $huevosEntrantes,
        public float            $costoUnitarioAplicado,
        public array            $contextoAuditoria = [],
    ) {}
}
