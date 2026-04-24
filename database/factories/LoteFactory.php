<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LoteEstado;
use App\Models\Bodega;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para App\Models\Lote con estados específicos para tests del refactor WAC.
 *
 * Estados disponibles:
 *   - default: lote vacío (sin compra aplicada), wac_* en NULL.
 *              Representa tanto "lote jamás tocado" como "lote pre-Fase 3 backfill".
 *              Útil para probar que salidas/devoluciones sobre wac=NULL se omiten.
 *   - wacInicializado(): lote con columnas wac_* pobladas, simulando post-backfill.
 *   - wacAgotadoConMemoria(): lote con inv=0, huevos=0, pero costo_unit > 0.
 *              Representa agotamiento total por ventas/mermas donde se preservó
 *              el costo_unit (invariante WAC). Habilita probar devoluciones
 *              posteriores al agotamiento.
 *   - conCompra(huevos, costo): lote con compra legacy aplicada, SIN WAC (pre-shadow).
 *
 * NOTA (2026-04-23 — Fase 3 refactor NULL vs agotado-con-memoria):
 *   Se eliminó el state `wacNoInicializado()` que poblaba wac_* = 0. Era
 *   engañoso porque conflateaba "jamás inicializado" (NULL) con "agotado"
 *   (0). El default factory ya deja wac_* = NULL, que es la representación
 *   correcta de "no inicializado" bajo la nueva semántica.
 *
 * @extends Factory<Lote>
 */
class LoteFactory extends Factory
{
    protected $model = Lote::class;

    public function definition(): array
    {
        return [
            'numero_lote'                    => 'LU-TEST-'.fake()->unique()->numerify('########'),
            'producto_id'                    => Producto::factory(),
            'proveedor_id'                   => Proveedor::factory(),
            'bodega_id'                      => Bodega::factory(),
            'huevos_por_carton'              => 30,

            // Cantidades y stock en cero (lote recién creado, sin compra)
            'cantidad_cartones_facturados'   => 0,
            'cantidad_cartones_regalo'       => 0,
            'cantidad_cartones_recibidos'    => 0,
            'cantidad_huevos_original'       => 0,
            'cantidad_huevos_remanente'      => 0,
            'huevos_facturados_acumulados'   => 0,
            'huevos_regalo_acumulados'       => 0,
            'huevos_regalo_consumidos'       => 0,
            'merma_total_acumulada'          => 0,

            // Costos legacy en cero
            'costo_total_acumulado'          => 0,
            'costo_total_lote'               => 0,
            'costo_por_carton_facturado'     => 0,
            'costo_por_huevo'                => 0,

            // Columnas WAC todas en NULL (default, estado pre-backfill de Fase 3)
            'wac_costo_inventario'           => null,
            'wac_huevos_inventario'          => null,
            'wac_costo_por_huevo'            => null,
            'wac_costo_por_carton_facturado' => null,
            'wac_ultima_actualizacion'       => null,
            'wac_motivo_ultima_actualizacion'=> null,

            'estado'                         => LoteEstado::Disponible,
        ];
    }

    /**
     * Lote con WAC inicializado — simula estado post-backfill de Fase 3.
     *
     * Parámetros ajustan el estado del WAC sin tocar el legacy. Útil para probar
     * ventas, mermas y devoluciones sin tener que aplicar una compra completa.
     */
    public function wacInicializado(
        float $huevos = 30000.0,
        float $costoInventario = 78150.0,
        int   $huevosPorCarton = 30,
    ): static {
        return $this->state(function () use ($huevos, $costoInventario, $huevosPorCarton) {
            $costoPorHuevo    = $huevos > 0 ? round($costoInventario / $huevos, 6) : 0.0;
            $costoPorCarton   = round($costoPorHuevo * $huevosPorCarton, 4);

            return [
                'huevos_por_carton'              => $huevosPorCarton,

                // También poblamos cantidad_huevos_remanente para que reducirRemanente
                // no lance excepción de stock insuficiente.
                'cantidad_huevos_remanente'      => $huevos,
                'huevos_facturados_acumulados'   => $huevos,

                'wac_costo_inventario'           => $costoInventario,
                'wac_huevos_inventario'          => $huevos,
                'wac_costo_por_huevo'            => $costoPorHuevo,
                'wac_costo_por_carton_facturado' => $costoPorCarton,
                'wac_ultima_actualizacion'       => now(),
                'wac_motivo_ultima_actualizacion'=> 'backfill',
            ];
        });
    }

    /**
     * Lote agotado-con-memoria: sin stock físico pero preservando el costo_unit
     * del último ciclo activo. Emerge naturalmente después de ventas/mermas
     * que consumieron todo el remanente — WacService::aplicarSalida preserva
     * costo_unit cuando huevos_despues = 0 (invariante WAC).
     *
     * Diferencia semántica con wac_*=NULL:
     *   - NULL                        → nunca se aplicó WAC al lote
     *   - inv=0, huevos=0, unit>0     → lote agotado, puede reactivarse por
     *                                    devolución (reempaque o cliente) usando
     *                                    el costo_unit preservado.
     *
     * @param float $costoUnitMemoria Costo unitario WAC preservado tras agotamiento
     */
    public function wacAgotadoConMemoria(float $costoUnitMemoria = 2.605, int $huevosPorCarton = 30): static
    {
        return $this->state(fn () => [
            'huevos_por_carton'              => $huevosPorCarton,
            'estado'                         => LoteEstado::Agotado,
            'cantidad_huevos_remanente'      => 0,
            // WAC en estado post-agotamiento: numerador y denominador en 0,
            // costo_unit preservado (típico resultado de aplicarSalida que vacía el lote)
            'wac_costo_inventario'           => 0.0,
            'wac_huevos_inventario'          => 0.0,
            'wac_costo_por_huevo'            => round($costoUnitMemoria, 6),
            'wac_costo_por_carton_facturado' => round($costoUnitMemoria * $huevosPorCarton, 4),
            'wac_ultima_actualizacion'       => now(),
            'wac_motivo_ultima_actualizacion'=> 'venta',
        ]);
    }

    /**
     * Lote con una "compra" legacy aplicada — stock disponible y costos poblados.
     * NO toca las columnas wac_* (quedan en NULL, estado pre-shadow).
     *
     * Útil para probar que ventas/mermas sobre un lote pre-backfill no revientan.
     */
    public function conCompra(float $huevosFacturados = 3000.0, float $costoCompra = 7815.0): static
    {
        return $this->state(function () use ($huevosFacturados, $costoCompra) {
            $huevosPorCarton = 30;
            $cartones        = $huevosFacturados / $huevosPorCarton;
            $costoPorCarton  = $cartones > 0 ? round($costoCompra / $cartones, 4) : 0.0;
            $costoPorHuevo   = $huevosPorCarton > 0 ? round($costoPorCarton / $huevosPorCarton, 4) : 0.0;

            return [
                'cantidad_cartones_facturados' => $cartones,
                'cantidad_cartones_recibidos'  => $cartones,
                'cantidad_huevos_original'     => $huevosFacturados,
                'cantidad_huevos_remanente'    => $huevosFacturados,
                'huevos_facturados_acumulados' => $huevosFacturados,
                'costo_total_acumulado'        => $costoCompra,
                'costo_total_lote'             => $costoCompra,
                'costo_por_carton_facturado'   => $costoPorCarton,
                'costo_por_huevo'              => $costoPorHuevo,
            ];
        });
    }

    public function agotado(): static
    {
        return $this->state(fn () => [
            'cantidad_huevos_remanente' => 0,
            'estado'                    => LoteEstado::Agotado,
        ]);
    }
}
