<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BodegaProducto;
use App\Models\Lote;
use App\Models\Producto;
use App\Services\Inventario\SincronizadorCostoBodega;
use Illuminate\Console\Command;

/**
 * Reconciliación one-time del costo en bodega_producto contra el lote.
 *
 * Corrige el desfase histórico donde bodega_producto.costo_promedio_actual de
 * los productos huevo quedó congelado en un costo viejo (porque las compras de
 * cartones solo actualizaban el lote, nunca bodega_producto). Reproyecta el
 * costo/precio vigente desde el lote para que la página de Productos y el
 * formulario de Ventas muestren el costo real.
 *
 * Idempotente y re-ejecutable. Usar --dry-run para previsualizar sin escribir.
 */
final class SincronizarCostoBodegaCommand extends Command
{
    protected $signature = 'inventario:sync-costo-bodega
                            {--dry-run : Solo mostrar qué cambiaría, sin escribir en BD}';

    protected $description = 'Reproyecta el costo/precio de bodega_producto desde el lote (corrige costos huevo desfasados)';

    public function handle(SincronizadorCostoBodega $sincronizador): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'DRY-RUN: previsualizando cambios (no se escribe en BD)...'
            : 'Reproyectando costos de bodega_producto desde lotes...');

        // Combinaciones producto+bodega que tienen lote (ahí vive el costo huevo).
        $combinaciones = Lote::query()
            ->select('producto_id', 'bodega_id')
            ->distinct()
            ->get();

        $filas = [];
        $categoriasVistas = [];

        foreach ($combinaciones as $combo) {
            $productoLote = Producto::find($combo->producto_id);
            if (! $productoLote) {
                continue;
            }

            $claveCategoria = $productoLote->categoria_id . ':' . $combo->bodega_id;
            if (isset($categoriasVistas[$claveCategoria])) {
                continue;
            }
            $categoriasVistas[$claveCategoria] = true;

            // Todos los productos huevo de esta categoría (1x30 + 1x15).
            $productos = Producto::query()
                ->with('unidad')
                ->where('categoria_id', $productoLote->categoria_id)
                ->get();

            foreach ($productos as $producto) {
                $costoPorHuevo = $sincronizador->costoPorHuevoVigente($producto, (int) $combo->bodega_id);
                if ($costoPorHuevo === null || $costoPorHuevo <= 0) {
                    continue;
                }

                $huevosPorUnidad = str_contains((string) ($producto->unidad->nombre ?? ''), '15') ? 15 : 30;
                $costoNuevo = round($costoPorHuevo * $huevosPorUnidad, 4);

                $bp = BodegaProducto::where('producto_id', $producto->id)
                    ->where('bodega_id', $combo->bodega_id)
                    ->first();
                $costoAnterior = (float) ($bp->costo_promedio_actual ?? 0);

                if (abs($costoAnterior - $costoNuevo) < 0.0001) {
                    continue;
                }

                $filas[] = [
                    $producto->id,
                    $producto->nombre,
                    $combo->bodega_id,
                    number_format($costoAnterior, 2),
                    number_format($costoNuevo, 2),
                ];

                if (! $dryRun) {
                    $sincronizador->sincronizar($producto, (int) $combo->bodega_id);
                }
            }
        }

        if (empty($filas)) {
            $this->info('Todo en orden: no hay costos desfasados que corregir.');

            return self::SUCCESS;
        }

        $this->table(
            ['Producto ID', 'Nombre', 'Bodega', 'Costo antes', 'Costo después'],
            $filas
        );

        $this->info(($dryRun ? 'Se corregirían ' : 'Corregidos ') . count($filas) . ' registro(s).');

        if ($dryRun) {
            $this->comment('Re-ejecuta sin --dry-run para aplicar los cambios.');
        }

        return self::SUCCESS;
    }
}
