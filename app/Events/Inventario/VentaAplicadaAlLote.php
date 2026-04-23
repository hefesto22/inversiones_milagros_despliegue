<?php

declare(strict_types=1);

namespace App\Events\Inventario;

use App\Models\Lote;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara DENTRO de la transacción de Lote::reducirRemanente() cuando el
 * motivo es una venta (directa o via reempaque).
 *
 * NOTA: el modelo legacy no distingue entre reducción por venta vs merma a
 * nivel de método — ambas llaman a reducirRemanente(). La clasificación
 * ocurre en el caller (VentaService, MermaService, etc.) que instancia el
 * evento apropiado.
 *
 * El WAC se calcula en el listener usando wac_costo_por_huevo actual del lote:
 *   delta_costo = huevosFacturadosConsumidos × lote.wac_costo_por_huevo
 *
 * Por definición del promedio ponderado perpetuo, la salida NO cambia el
 * wac_costo_por_huevo (solo cambia al entrar inventario nuevo con precio distinto).
 */
final readonly class VentaAplicadaAlLote
{
    use Dispatchable;

    /**
     * @param Lote       $lote                           Lote afectado (ya con remanente reducido)
     * @param float      $huevosFacturadosConsumidos     Huevos facturados retirados del lote
     * @param float      $huevosRegaloConsumidos         Huevos de regalo retirados (no afectan WAC)
     * @param array      $contexto                       ['venta_detalle_id' => ...] u otro ID de origen
     */
    public function __construct(
        public Lote  $lote,
        public float $huevosFacturadosConsumidos,
        public float $huevosRegaloConsumidos,
        public array $contexto = [],
    ) {}
}
