<?php

namespace App\Application\Services;

use App\Models\BodegaProducto;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Reempaque;
use App\Models\ReempaqueLote;
use App\Models\ReempaqueProducto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Servicio centralizado para operaciones de reempaque automático,
 * reversión LIFO y devolución de stock a bodega.
 *
 * Único punto de verdad para lógica de reempaque — usado por:
 * - CargasRelationManager (carga/edición/eliminación de viajes)
 * - VentaService (completar/cancelar ventas directas)
 * - Viaje::cancelar() (cancelación de viajes)
 *
 * Inyectable via constructor (DI) — no usa métodos estáticos.
 * Laravel lo resuelve automáticamente del container.
 */
final class ReempaqueService
{
    /**
     * Calcular stock disponible (bodega + lotes) para un producto.
     *
     * @return array{usa_lotes: bool, stock_en_bodega: float, stock_desde_lote: float, stock_total: float, costo_bodega: float, costo_lote: float, costo_promedio: float, huevos_por_unidad: int}
     */
    public function calcularStockDisponible(int $productoId, int $bodegaId): array
    {
        $producto = Producto::with('categoria.categoriaOrigen', 'unidad')->find($productoId);

        if (!$producto || !$producto->categoria || !$producto->categoria->usaLotes()) {
            $bp = BodegaProducto::where('bodega_id', $bodegaId)
                ->where('producto_id', $productoId)->first();

            return [
                'usa_lotes' => false,
                'stock_en_bodega' => floatval($bp->stock ?? 0),
                'stock_desde_lote' => 0,
                'stock_total' => floatval($bp->stock ?? 0),
                'costo_bodega' => floatval($bp->costo_promedio_actual ?? 0),
                'costo_lote' => 0,
                'costo_promedio' => floatval($bp->costo_promedio_actual ?? 0),
                'huevos_por_unidad' => 0,
            ];
        }

        $categoriaLoteId = $producto->categoria->categoria_origen_id;

        $bp = BodegaProducto::where('bodega_id', $bodegaId)
            ->where('producto_id', $productoId)->first();

        $stockEnBodega = floatval($bp->stock ?? 0);
        $costoEnBodega = floatval($bp->costo_promedio_actual ?? 0);

        $lotes = Lote::where('bodega_id', $bodegaId)
            ->whereHas('producto', fn ($q) => $q->where('categoria_id', $categoriaLoteId))
            ->where('estado', 'disponible')
            ->where('cantidad_huevos_remanente', '>=', 30)
            ->orderBy('created_at', 'asc')->get();

        $huevosPorUnidad = $this->getHuevosPorUnidad($producto);

        $totalHuevosEnLotes = $lotes->sum('cantidad_huevos_remanente');
        $stockDesdeLote = floor($totalHuevosEnLotes / $huevosPorUnidad);

        $costoTotalLotes = 0;
        $unidadesTotalesLote = 0;
        foreach ($lotes as $lote) {
            $unidadesEnLote = floor($lote->cantidad_huevos_remanente / $huevosPorUnidad);
            $costoPorCarton30 = floatval($lote->costo_por_carton_facturado ?? 0);
            $costoPorUnidad = ($huevosPorUnidad == 30)
                ? $costoPorCarton30
                : $costoPorCarton30 * ($huevosPorUnidad / 30);
            $costoTotalLotes += $unidadesEnLote * $costoPorUnidad;
            $unidadesTotalesLote += $unidadesEnLote;
        }
        $costoUnitarioLote = $unidadesTotalesLote > 0 ? $costoTotalLotes / $unidadesTotalesLote : 0;

        $stockTotal = $stockEnBodega + $stockDesdeLote;
        $valorTotal = ($stockEnBodega * $costoEnBodega) + ($stockDesdeLote * $costoUnitarioLote);
        $costoPromedio = $stockTotal > 0 ? round($valorTotal / $stockTotal, 4) : 0;

        return [
            'usa_lotes' => true,
            'stock_en_bodega' => $stockEnBodega,
            'stock_desde_lote' => $stockDesdeLote,
            'stock_total' => $stockTotal,
            'costo_bodega' => round($costoEnBodega, 4),
            'costo_lote' => round($costoUnitarioLote, 4),
            'costo_promedio' => $costoPromedio,
            'huevos_por_unidad' => $huevosPorUnidad,
        ];
    }

    /**
     * Ejecutar reempaque automático desde lotes (FIFO).
     *
     * @return array{costo_unitario: float, reempaque_id: int, reempaque_numero: string}
     * @throws \Exception
     */
    public function ejecutarReempaqueAutomatico(
        int $productoId,
        int $bodegaId,
        float $cantidad,
        ?string $origen = null
    ): array {
        if ($cantidad <= 0) {
            throw new \Exception("La cantidad para reempaque debe ser mayor a cero");
        }

        $producto = Producto::with('categoria.categoriaOrigen', 'unidad')->find($productoId);

        if (!$producto || !$producto->categoria || !$producto->categoria->categoria_origen_id) {
            throw new \Exception("Producto no válido para reempaque automático");
        }

        $categoriaLoteId = $producto->categoria->categoria_origen_id;
        $huevosPorUnidad = $this->getHuevosPorUnidad($producto);
        $huevosNecesarios = $cantidad * $huevosPorUnidad;

        $lotes = Lote::where('bodega_id', $bodegaId)
            ->whereHas('producto', fn ($q) => $q->where('categoria_id', $categoriaLoteId))
            ->where('estado', 'disponible')
            ->where('cantidad_huevos_remanente', '>', 0)
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->get();

        $totalDisponible = $lotes->sum('cantidad_huevos_remanente');
        if ($totalDisponible < $huevosNecesarios) {
            throw new \Exception(
                "Stock insuficiente en lotes. Necesario: {$huevosNecesarios} huevos, Disponible: {$totalDisponible}"
            );
        }

        $nota = $origen
            ? "Reempaque automatico - {$origen}"
            : "Reempaque automatico";

        $reempaque = Reempaque::create([
            'bodega_id' => $bodegaId,
            'tipo' => 'individual',
            'total_huevos_usados' => $huevosNecesarios,
            'merma' => 0,
            'huevos_utiles' => $huevosNecesarios,
            'costo_total' => 0,
            'costo_unitario_promedio' => 0,
            'cartones_30' => $huevosPorUnidad == 30 ? $cantidad : 0,
            'cartones_15' => $huevosPorUnidad == 15 ? $cantidad : 0,
            'huevos_sueltos' => 0,
            'estado' => 'completado',
            'nota' => $nota,
            'created_by' => Auth::id(),
        ]);

        $huevosRestantes = $huevosNecesarios;
        $costoTotal = 0;

        foreach ($lotes as $lote) {
            if ($huevosRestantes <= 0) break;

            $huevosAUsar = min($huevosRestantes, $lote->cantidad_huevos_remanente);
            $resultado = $lote->calcularConsumoHuevos($huevosAUsar);

            $huevosPorCarton = $lote->huevos_por_carton ?? 30;
            $cartonesFacturadosUsados = $resultado['huevos_facturados_usados'] / $huevosPorCarton;
            $cartonesRegaloUsados = $resultado['huevos_regalo_usados'] / $huevosPorCarton;
            $cartonesTotalesUsados = $cartonesFacturadosUsados + $cartonesRegaloUsados;

            ReempaqueLote::create([
                'reempaque_id' => $reempaque->id,
                'lote_id' => $lote->id,
                'cantidad_cartones_usados' => round($cartonesTotalesUsados, 3),
                'cantidad_huevos_usados' => $huevosAUsar,
                'cartones_facturados_usados' => round($cartonesFacturadosUsados, 3),
                'cartones_regalo_usados' => round($cartonesRegaloUsados, 3),
                'costo_parcial' => round($resultado['costo'], 4),
            ]);

            $lote->reducirRemanente($huevosAUsar, $resultado['huevos_regalo_usados']);
            $costoTotal += $resultado['costo'];
            $huevosRestantes -= $huevosAUsar;
        }

        $costoUnitarioPorHuevo = $huevosNecesarios > 0 ? $costoTotal / $huevosNecesarios : 0;
        $costoUnitarioProducto = $costoUnitarioPorHuevo * $huevosPorUnidad;

        $reempaque->update([
            'costo_total' => round($costoTotal, 4),
            'costo_unitario_promedio' => round($costoUnitarioPorHuevo, 4),
        ]);

        ReempaqueProducto::create([
            'reempaque_id' => $reempaque->id,
            'producto_id' => $productoId,
            'categoria_id' => $producto->categoria_id,
            'bodega_id' => $bodegaId,
            'cantidad' => $cantidad,
            'costo_unitario' => round($costoUnitarioProducto, 4),
            'costo_total' => round($costoTotal, 4),
            'agregado_a_stock' => false,
        ]);

        return [
            'costo_unitario' => round($costoUnitarioProducto, 4),
            'reempaque_id' => $reempaque->id,
            'reempaque_numero' => $reempaque->numero_reempaque,
        ];
    }

    /**
     * Revertir un reempaque parcialmente (LIFO).
     * Devuelve huevos a los lotes originales en orden inverso al consumo.
     */
    public function revertirReempaqueParcial(int $reempaqueId, int $productoId, float $unidadesADevolver): void
    {
        $reempaque = Reempaque::find($reempaqueId);
        if (!$reempaque) {
            Log::warning("ReempaqueService: Reempaque #{$reempaqueId} no encontrado para reversión");
            return;
        }

        $producto = Producto::with('unidad')->find($productoId);
        $huevosPorUnidad = $this->getHuevosPorUnidad($producto);
        $huevosADevolver = $unidadesADevolver * $huevosPorUnidad;

        $reempaqueLotes = ReempaqueLote::where('reempaque_id', $reempaqueId)
            ->where('cantidad_huevos_usados', '>', 0)
            ->orderBy('id', 'desc')
            ->get();

        $huevosRestantes = $huevosADevolver;
        $costoDevuelto = 0;

        foreach ($reempaqueLotes as $rl) {
            if ($huevosRestantes <= 0) break;

            $lote = Lote::find($rl->lote_id);
            if (!$lote) continue;

            $huevosEnEsteRL = floatval($rl->cantidad_huevos_usados);
            $huevosADevolverDeEsteRL = min($huevosRestantes, $huevosEnEsteRL);

            $huevosPorCarton = $lote->huevos_por_carton ?? 30;
            $totalFacturadosRL = floatval($rl->cartones_facturados_usados) * $huevosPorCarton;
            $totalRegaloRL = floatval($rl->cartones_regalo_usados) * $huevosPorCarton;
            $totalHuevosRL = $totalFacturadosRL + $totalRegaloRL;

            if ($totalHuevosRL > 0) {
                $proporcionRegalo = $totalRegaloRL / $totalHuevosRL;
            } else {
                $proporcionRegalo = 0;
            }

            $huevosRegaloDevueltos = round($huevosADevolverDeEsteRL * $proporcionRegalo);
            $huevosRegaloDevueltos = min($huevosRegaloDevueltos, $totalRegaloRL);
            $huevosFacturadosDevueltos = $huevosADevolverDeEsteRL - $huevosRegaloDevueltos;

            $costoPorHuevo = floatval($lote->costo_por_carton_facturado ?? 0) / $huevosPorCarton;
            $costoRevertido = $huevosFacturadosDevueltos * $costoPorHuevo;
            $costoDevuelto += $costoRevertido;

            $lote->devolverHuevos($huevosADevolverDeEsteRL, $huevosRegaloDevueltos);

            $cartonesFacturadosDevueltos = $huevosFacturadosDevueltos / $huevosPorCarton;
            $cartonesRegaloDevueltos = $huevosRegaloDevueltos / $huevosPorCarton;

            $rl->cantidad_huevos_usados = max(0, $huevosEnEsteRL - $huevosADevolverDeEsteRL);
            $rl->cartones_facturados_usados = max(0, floatval($rl->cartones_facturados_usados) - $cartonesFacturadosDevueltos);
            $rl->cartones_regalo_usados = max(0, floatval($rl->cartones_regalo_usados) - $cartonesRegaloDevueltos);
            $rl->cantidad_cartones_usados = $rl->cartones_facturados_usados + $rl->cartones_regalo_usados;
            $rl->costo_parcial = max(0, floatval($rl->costo_parcial) - round($costoRevertido, 4));
            $rl->save();

            $huevosRestantes -= $huevosADevolverDeEsteRL;

            Log::info("ReempaqueService: Reversión lote procesado", [
                'reempaque_id' => $reempaqueId,
                'lote_id' => $lote->id,
                'huevos_devueltos' => $huevosADevolverDeEsteRL,
            ]);
        }

        // Actualizar totales del Reempaque
        $reempaque->total_huevos_usados = max(0, floatval($reempaque->total_huevos_usados) - $huevosADevolver);
        $reempaque->huevos_utiles = max(0, floatval($reempaque->huevos_utiles) - $huevosADevolver);
        $reempaque->costo_total = max(0, floatval($reempaque->costo_total) - round($costoDevuelto, 4));

        if ($reempaque->total_huevos_usados > 0) {
            $reempaque->costo_unitario_promedio = round($reempaque->costo_total / $reempaque->total_huevos_usados, 4);
        } else {
            $reempaque->costo_unitario_promedio = 0;
        }

        if ($huevosPorUnidad == 30) {
            $reempaque->cartones_30 = max(0, ($reempaque->cartones_30 ?? 0) - $unidadesADevolver);
        } else {
            $reempaque->cartones_15 = max(0, ($reempaque->cartones_15 ?? 0) - $unidadesADevolver);
        }

        if ($reempaque->total_huevos_usados <= 0) {
            $reempaque->estado = 'revertido';
            $reempaque->nota = ($reempaque->nota ? $reempaque->nota . "\n\n" : '')
                . "REVERTIDO: Reversión completa.";
        }

        $reempaque->save();

        // Actualizar ReempaqueProducto
        $reempaqueProducto = ReempaqueProducto::where('reempaque_id', $reempaqueId)
            ->where('producto_id', $productoId)->first();

        if ($reempaqueProducto) {
            $nuevaCantidad = max(0, floatval($reempaqueProducto->cantidad) - $unidadesADevolver);
            $reempaqueProducto->cantidad = $nuevaCantidad;
            $reempaqueProducto->costo_total = max(0, floatval($reempaqueProducto->costo_total) - round($costoDevuelto, 4));

            if ($nuevaCantidad > 0) {
                $reempaqueProducto->costo_unitario = round($reempaqueProducto->costo_total / $nuevaCantidad, 4);
            } else {
                $reempaqueProducto->costo_unitario = 0;
            }

            $reempaqueProducto->save();
        }

        Log::info("ReempaqueService: Reversión parcial completada", [
            'reempaque_id' => $reempaqueId,
            'producto_id' => $productoId,
            'unidades_devueltas' => $unidadesADevolver,
            'costo_total_revertido' => $costoDevuelto,
        ]);
    }

    /**
     * Devolver stock a bodega con promedio ponderado correcto.
     * Recalcula el costo promedio entre lo existente y lo devuelto.
     */
    public function devolverStockABodega(
        BodegaProducto $bodegaProducto,
        float $cantidadDevolver,
        float $costoOriginal
    ): void {
        if ($cantidadDevolver <= 0) return;

        $stockActual = floatval($bodegaProducto->stock);
        $costoActual = floatval($bodegaProducto->costo_promedio_actual);
        $nuevoStock = $stockActual + $cantidadDevolver;

        if ($costoActual <= 0 || $stockActual <= 0) {
            $bodegaProducto->costo_promedio_actual = round($costoOriginal, 4);
        } else {
            $valorExistente = $stockActual * $costoActual;
            $valorDevuelto = $cantidadDevolver * $costoOriginal;
            $nuevoCosto = ($valorExistente + $valorDevuelto) / $nuevoStock;
            $bodegaProducto->costo_promedio_actual = round($nuevoCosto, 4);
        }

        $bodegaProducto->stock = $nuevoStock;
        $bodegaProducto->actualizarPrecioVentaSegunCosto();
        $bodegaProducto->save();

        Log::info("ReempaqueService: Stock devuelto a bodega", [
            'producto_id' => $bodegaProducto->producto_id,
            'stock_antes' => $stockActual,
            'stock_despues' => $nuevoStock,
            'cantidad_devuelta' => $cantidadDevolver,
            'costo_devuelto' => $costoOriginal,
        ]);
    }

    /**
     * Obtener display de stock para formularios.
     */
    public function getStockDisplay(float $stockEnBodega, float $stockDesdeLote): string
    {
        $stockTotal = $stockEnBodega + $stockDesdeLote;

        if ($stockEnBodega > 0 && $stockDesdeLote > 0) {
            return number_format($stockTotal, 0) . " ({$stockEnBodega} bodega + {$stockDesdeLote} lote)";
        } elseif ($stockEnBodega > 0) {
            return number_format($stockEnBodega, 0) . " (bodega)";
        } elseif ($stockDesdeLote > 0) {
            return number_format($stockDesdeLote, 0) . " (lote)";
        }

        return '0';
    }

    /**
     * Determinar cuántos huevos por unidad usa el producto.
     */
    public function getHuevosPorUnidad(?Producto $producto): int
    {
        if (!$producto) return 30;

        $producto->loadMissing('unidad');
        $unidad = $producto->unidad;

        if ($unidad) {
            $factor = floatval($unidad->factor ?? 1);
            if ($factor == 0.5) {
                return 15;
            }
            if (str_contains(strtolower($unidad->nombre ?? ''), '15')) {
                return 15;
            }
        }

        return 30;
    }
}
