<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\LoteEstado;
use App\Models\BodegaProducto;
use App\Models\Lote;
use App\Models\Producto;
use Illuminate\Support\Facades\Log;

/**
 * Proyecta el costo vigente del lote sobre bodega_producto.costo_promedio_actual.
 *
 * PROBLEMA QUE RESUELVE
 * ---------------------
 * Las compras de huevos (cartones) se procesan en ViewCompra::agregarALoteUnico,
 * que actualiza únicamente el lote (costo_por_huevo / wac_*). Nunca tocan
 * bodega_producto.costo_promedio_actual. Pero la página de Productos y el
 * formulario de Ventas (VentaResource) leen su costo y precio sugerido de
 * bodega_producto. Resultado: el costo mostrado y el precio sugerido quedan
 * congelados en un valor viejo (ej. L90/cartón cuando el lote real es L50).
 *
 * Este service cierra esa fuga: toma el costo/huevo vigente del lote y lo
 * proyecta sobre bodega_producto, recalculando el precio de venta sugerido.
 *
 * FUENTE DE VERDAD — read_source
 * ------------------------------
 * El refactor WAC Perpetuo está en shadow_mode (read_source=legacy). Por eso
 * leemos la columna de costo según config('inventario.wac.read_source'):
 *   - 'legacy' (default hoy) → lotes.costo_por_huevo
 *   - 'wac'    (Fase 5)      → lotes.wac_costo_por_huevo
 * Cuando se flipee a 'wac' este service sigue funcionando sin cambios.
 *
 * PRODUCTOS 1x15 SIN LOTE PROPIO
 * ------------------------------
 * El 1x15 no tiene lote; su stock entra por reempaque del 1x30. Para reflejar
 * el costo de reposición ACTUAL (no el costo histórico del stock viejo), se
 * deriva del lote del producto cartón de la MISMA categoría:
 *   costo_1x15 = costo_por_huevo(lote_carton) * 15
 */
final class SincronizadorCostoBodega
{
    /**
     * Recalcula el costo/huevo vigente de un producto en una bodega.
     *
     * Para productos cartón usa sus propios lotes disponibles. Para productos
     * sin lote propio (1x15) cae al lote cartón de su misma categoría. Devuelve
     * null si no hay ningún lote disponible del cual derivar el costo (en ese
     * caso el llamador debe respetar el costo existente, p.ej. el de reempaque).
     */
    public function costoPorHuevoVigente(Producto $producto, int $bodegaId): ?float
    {
        $columnaCosto = $this->columnaCostoSegunFuente();

        // 1. Lotes propios del producto (caso cartón 1x30).
        $costo = $this->costoPonderadoDesdeLotes(
            Lote::query()
                ->where('producto_id', $producto->id)
                ->where('bodega_id', $bodegaId),
            $columnaCosto
        );

        if ($costo !== null) {
            return $costo;
        }

        // 2. Sin lote propio (caso 1x15): derivar del lote cartón de su categoría.
        $idsCategoria = Producto::query()
            ->where('categoria_id', $producto->categoria_id)
            ->pluck('id');

        return $this->costoPonderadoDesdeLotes(
            Lote::query()
                ->whereIn('producto_id', $idsCategoria)
                ->where('bodega_id', $bodegaId),
            $columnaCosto
        );
    }

    /**
     * Sincroniza bodega_producto de un solo producto+bodega.
     *
     * @return bool true si actualizó; false si no había lote del cual derivar.
     */
    public function sincronizar(Producto $producto, int $bodegaId): bool
    {
        $costoPorHuevo = $this->costoPorHuevoVigente($producto, $bodegaId);

        if ($costoPorHuevo === null || $costoPorHuevo <= 0) {
            return false;
        }

        $huevosPorUnidad = $this->huevosPorUnidad($producto);
        $nuevoCosto = round($costoPorHuevo * $huevosPorUnidad, 4);

        $bodegaProducto = BodegaProducto::firstOrNew([
            'bodega_id'   => $bodegaId,
            'producto_id' => $producto->id,
        ]);

        $costoAnterior = (float) ($bodegaProducto->costo_promedio_actual ?? 0);

        // Evita escrituras y logs cuando no cambia nada relevante.
        if (! $bodegaProducto->exists || abs($costoAnterior - $nuevoCosto) >= 0.0001) {
            // Inyecta la relación ya cargada para que actualizarPrecioVentaSegunCosto
            // no dispare un lazy load (preventLazyLoading está activo).
            $bodegaProducto->setRelation('producto', $producto);

            $bodegaProducto->costo_promedio_actual = $nuevoCosto;
            $bodegaProducto->actualizarPrecioVentaSegunCosto();
            $bodegaProducto->save();

            Log::info('SincronizadorCostoBodega: costo proyectado desde lote', [
                'producto_id'           => $producto->id,
                'bodega_id'             => $bodegaId,
                'costo_anterior'        => $costoAnterior,
                'costo_nuevo'           => $nuevoCosto,
                'costo_por_huevo'       => $costoPorHuevo,
                'huevos_por_unidad'     => $huevosPorUnidad,
                'precio_venta_sugerido' => $bodegaProducto->precio_venta_sugerido,
                'read_source'           => $this->fuenteLectura(),
            ]);
        }

        return true;
    }

    /**
     * Sincroniza todos los productos de una categoría en una bodega.
     *
     * Se usa tras una compra de cartones: actualizar el lote 1x30 debe refrescar
     * tanto el 1x30 como el 1x15 (que deriva su costo del mismo lote).
     *
     * @return int Cantidad de productos efectivamente actualizados.
     */
    public function sincronizarCategoria(int $categoriaId, int $bodegaId): int
    {
        $actualizados = 0;

        Producto::query()
            ->with('unidad')
            ->where('categoria_id', $categoriaId)
            ->where('activo', true)
            ->each(function (Producto $producto) use ($bodegaId, &$actualizados): void {
                if ($this->sincronizar($producto, $bodegaId)) {
                    $actualizados++;
                }
            });

        return $actualizados;
    }

    /**
     * Reconciliación masiva: recorre todas las combinaciones producto+bodega que
     * tienen lote y reproyecta su costo. Operación idempotente y re-ejecutable.
     *
     * @return array{revisados:int, actualizados:int} Resumen de la corrida.
     */
    public function reconciliarTodo(): array
    {
        $revisados = 0;
        $actualizados = 0;

        // Solo bodegas que tienen al menos un lote: ahí vive el costo de huevos.
        Lote::query()
            ->select('producto_id', 'bodega_id')
            ->distinct()
            ->get()
            ->each(function ($fila) use (&$revisados, &$actualizados): void {
                $producto = Producto::find($fila->producto_id);
                if (! $producto) {
                    return;
                }

                // Reproyecta el producto del lote y sus hermanos de categoría (1x15).
                $actualizados += $this->sincronizarCategoria(
                    (int) $producto->categoria_id,
                    (int) $fila->bodega_id
                );
                $revisados++;
            });

        return [
            'revisados'    => $revisados,
            'actualizados' => $actualizados,
        ];
    }

    /**
     * Costo por huevo ponderado por remanente sobre un conjunto de lotes
     * disponibles. Devuelve null si no hay lotes con stock y costo válido.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Lote>  $query
     */
    private function costoPonderadoDesdeLotes($query, string $columnaCosto): ?float
    {
        $lotes = $query
            ->where('estado', LoteEstado::Disponible)
            ->where('cantidad_huevos_remanente', '>', 0)
            ->get(['cantidad_huevos_remanente', $columnaCosto]);

        $sumaHuevos = 0.0;
        $sumaValor = 0.0;

        foreach ($lotes as $lote) {
            $costo = (float) ($lote->{$columnaCosto} ?? 0);
            $huevos = (float) $lote->cantidad_huevos_remanente;

            if ($costo <= 0 || $huevos <= 0) {
                continue;
            }

            $sumaHuevos += $huevos;
            $sumaValor += $costo * $huevos;
        }

        if ($sumaHuevos <= 0) {
            return null;
        }

        return round($sumaValor / $sumaHuevos, 6);
    }

    /**
     * Huevos por unidad de venta del producto. Sigue la convención existente del
     * sistema (CreateReempaque): el nombre de la unidad "1x15" => 15, resto => 30.
     */
    private function huevosPorUnidad(Producto $producto): int
    {
        // loadMissing es un eager load explícito: seguro bajo preventLazyLoading.
        $producto->loadMissing('unidad');
        $nombreUnidad = (string) ($producto->unidad->nombre ?? '');

        return str_contains($nombreUnidad, '15') ? 15 : 30;
    }

    private function columnaCostoSegunFuente(): string
    {
        return $this->fuenteLectura() === 'wac'
            ? 'wac_costo_por_huevo'
            : 'costo_por_huevo';
    }

    private function fuenteLectura(): string
    {
        return (string) config('inventario.wac.read_source', 'legacy');
    }
}
