<?php

declare(strict_types=1);

namespace App\Events\Inventario;

use App\Models\Lote;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara DENTRO de la transacción de Lote::agregarCompra() inmediatamente
 * después de persistir los cambios legacy y el registro en HistorialCompraLote.
 *
 * El listener ActualizarWacListener lo consume para escribir las columnas wac_*
 * (solo si INVENTARIO_WAC_SHADOW_MODE=true).
 *
 * Invariante: el lote ya fue guardado con sus valores legacy al momento del
 * dispatch. El listener puede confiar en que $lote->refresh() devuelve el
 * estado persistido más reciente.
 */
final readonly class CompraAplicadaAlLote
{
    use Dispatchable;

    /**
     * @param Lote  $lote               Lote afectado (ya guardado con cambios legacy)
     * @param int   $compraId           FK a compras.id
     * @param int   $compraDetalleId    FK a compra_detalles.id
     * @param int   $proveedorId        FK a proveedores.id
     * @param float $huevosFacturados   Huevos con costo añadidos (excluye regalo)
     * @param float $huevosRegalo       Huevos de regalo añadidos (sin costo, buffer de mermas)
     * @param float $costoCompra        Monto total en Lempiras pagado por los huevos facturados
     */
    public function __construct(
        public Lote  $lote,
        public int   $compraId,
        public int   $compraDetalleId,
        public int   $proveedorId,
        public float $huevosFacturados,
        public float $huevosRegalo,
        public float $costoCompra,
    ) {}
}
