<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\HistorialCompraLote;
use App\Models\Lote;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\HistorialCompraLote.
 *
 * Usado por los tests del BackfillWacCommand para construir historiales de
 * compras sobre un lote y verificar la reconstrucción del WAC.
 *
 * @extends Factory<HistorialCompraLote>
 */
class HistorialCompraLoteFactory extends Factory
{
    protected $model = HistorialCompraLote::class;

    public function definition(): array
    {
        $cartonesFacturados = fake()->randomFloat(2, 10, 500);
        $cartonesRegalo     = 0;
        $huevosPorCarton    = 30;
        $huevosFacturados   = $cartonesFacturados * $huevosPorCarton;
        $huevosRegalo       = $cartonesRegalo * $huevosPorCarton;
        $costoPorCarton     = fake()->randomFloat(4, 65, 85);
        $costoCompra        = round($cartonesFacturados * $costoPorCarton, 2);
        $costoPorHuevoCompra = $huevosFacturados > 0
            ? round($costoCompra / $huevosFacturados, 4)
            : 0.0;

        return [
            'lote_id'                   => Lote::factory(),
            'compra_id'                 => Compra::factory(),
            'compra_detalle_id'         => CompraDetalle::factory(),
            'proveedor_id'              => Proveedor::factory(),
            'cartones_facturados'       => $cartonesFacturados,
            'cartones_regalo'           => $cartonesRegalo,
            'huevos_agregados'          => $huevosFacturados + $huevosRegalo,
            'costo_compra'              => $costoCompra,
            'costo_por_huevo_compra'    => $costoPorHuevoCompra,
            'costo_promedio_resultante' => $costoPorHuevoCompra,
            'huevos_totales_resultante' => $huevosFacturados + $huevosRegalo,
        ];
    }

    /**
     * Compra con valores específicos — útil para tests deterministas.
     */
    public function conValores(
        float $cartonesFacturados,
        float $costoCompra,
        int $huevosPorCarton = 30,
    ): static {
        return $this->state(function () use ($cartonesFacturados, $costoCompra, $huevosPorCarton) {
            $huevos = $cartonesFacturados * $huevosPorCarton;
            $costoPorHuevo = $huevos > 0 ? round($costoCompra / $huevos, 4) : 0.0;

            return [
                'cartones_facturados'       => $cartonesFacturados,
                'cartones_regalo'           => 0,
                'huevos_agregados'          => $huevos,
                'costo_compra'              => $costoCompra,
                'costo_por_huevo_compra'    => $costoPorHuevo,
                'costo_promedio_resultante' => $costoPorHuevo,
                'huevos_totales_resultante' => $huevos,
            ];
        });
    }
}
