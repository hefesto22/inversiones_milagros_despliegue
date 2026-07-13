<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\BodegaProducto;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Reempaque;
use App\Models\Viaje;
use App\Models\ViajeCarga;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de operaciones sobre cargas de viaje.
 *
 * Extraído de CargasRelationManager (2026-07-13) para que la lógica de
 * AUMENTAR una carga existente viva en un solo lugar, testeable, y sea
 * compartida por:
 *   - EditAction del CargasRelationManager (aumentar cantidad total en
 *     Planificado/Cargando)
 *   - Acción "Recargar" del CargasRelationManager (modo Recargando: el
 *     usuario indica cuántas unidades SUMAR, no la nueva cantidad total)
 *
 * Reglas del aumento (idénticas al comportamiento probado en producción):
 *   1. Se toma primero del stock libre en bodega_producto; lo que falte
 *      sale del lote vía reempaque automático.
 *   2. Reempaque CONSOLIDADO: si la carga ya tenía reempaque y necesita
 *      más del lote, se revierte el anterior (los huevos regresan al lote)
 *      y se crea UNO nuevo por el total (anterior + nuevo). Así cada carga
 *      mantiene un solo reempaque_id y no quedan reempaques huérfanos.
 *   3. El costo unitario de la carga se recalcula como promedio ponderado
 *      entre la porción de bodega (costo_bodega_original) y la del lote
 *      (costo del reempaque consolidado).
 *
 * El servicio NO envía notificaciones — lanza excepciones con mensajes
 * listos para mostrar y la capa Filament decide cómo presentarlos.
 */
final class CargaViajeService
{
    public function __construct(
        private readonly ReempaqueService $reempaqueService,
    ) {}

    /**
     * Aumentar la cantidad de una carga existente.
     *
     * @param  array  $atributosExtra  Campos adicionales a persistir junto al
     *                                 aumento (EditAction pasa su $data completo;
     *                                 la acción Recargar no pasa ninguno).
     * @return array{carga: ViajeCarga, tomar_de_bodega: float, tomar_de_lote: float, costo_unitario: float, reempaque_numero: ?string}
     *
     * @throws \InvalidArgumentException si la nueva cantidad no es mayor a la actual
     * @throws \RuntimeException con mensaje "Stock insuficiente..." si no alcanza
     */
    public function aumentarCantidad(
        Viaje $viaje,
        ViajeCarga $carga,
        float $cantidadNueva,
        array $atributosExtra = []
    ): array {
        return DB::transaction(function () use ($viaje, $carga, $cantidadNueva, $atributosExtra) {
            $producto = Producto::with('categoria.categoriaOrigen', 'unidad')->find($carga->producto_id);
            $categoria = $producto?->categoria;
            $usaLotes = (bool) ($categoria && $categoria->categoria_origen_id);

            $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                ->where('producto_id', $carga->producto_id)
                ->lockForUpdate()
                ->first();

            $cantidadAnterior = (float) $carga->cantidad;
            $diferencia = $cantidadNueva - $cantidadAnterior;

            if ($diferencia <= 0) {
                throw new \InvalidArgumentException(
                    "La nueva cantidad ({$cantidadNueva}) debe ser mayor a la actual ({$cantidadAnterior})."
                );
            }

            $stockActual = (float) ($bodegaProducto->stock ?? 0);

            $data = $atributosExtra;
            $data['cantidad'] = $cantidadNueva;

            if (! $usaLotes) {
                // ── PRODUCTO SIN LOTES: solo bodega ─────────────────────────
                if ($stockActual < $diferencia) {
                    throw new \RuntimeException(
                        "Stock insuficiente. Necesita: {$diferencia}, Disponible: {$stockActual}"
                    );
                }

                $data['cantidad_de_bodega'] = $cantidadNueva;
                $data['cantidad_de_lote'] = 0;
                $data['costo_unitario_lote'] = 0;

                $carga->update($data);
                $bodegaProducto->reducirStock($diferencia, true, [
                    'kardex_tipo' => 'carga_viaje',
                    'kardex_descripcion' => "Carga al viaje #{$viaje->id}",
                    'kardex_referencia_type' => $viaje->getMorphClass(),
                    'kardex_referencia_id' => $viaje->id,
                ]);

                return [
                    'carga' => $carga,
                    'tomar_de_bodega' => $diferencia,
                    'tomar_de_lote' => 0.0,
                    'costo_unitario' => (float) $carga->costo_unitario,
                    'reempaque_numero' => null,
                ];
            }

            // ── PRODUCTO QUE USA LOTES: bodega primero, luego lote ──────────
            $tomarDeBodega = min($stockActual, $diferencia);
            $tomarDeLote = $diferencia - $tomarDeBodega;

            $huevosPorUnidad = $this->reempaqueService->getHuevosPorUnidad($producto);
            $cantidadDeLoteAnterior = (float) ($carga->cantidad_de_lote ?? 0);

            if ($tomarDeLote > 0) {
                // Validar stock para el TOTAL consolidado (anterior + nuevo),
                // considerando que la reversión del reempaque anterior devuelve
                // huevos al lote, así que estarán disponibles.
                $totalLoteConsolidadoValidacion = $cantidadDeLoteAnterior + $tomarDeLote;
                $huevosNecesarios = intval($totalLoteConsolidadoValidacion) * $huevosPorUnidad;

                $huevosQueSeRecuperan = intval($cantidadDeLoteAnterior) * $huevosPorUnidad;
                $totalHuevosEnLotes = Lote::where('bodega_id', $viaje->bodega_origen_id)
                    ->whereHas('producto', fn ($q) => $q->where('categoria_id', $categoria->categoria_origen_id))
                    ->where('estado', 'disponible')
                    ->where('cantidad_huevos_remanente', '>', 0)
                    ->sum('cantidad_huevos_remanente');
                $totalHuevosDisponibles = $totalHuevosEnLotes + $huevosQueSeRecuperan;

                if ($totalHuevosDisponibles < $huevosNecesarios) {
                    $unidadesDisponibles = floor($totalHuevosDisponibles / $huevosPorUnidad)
                        - intval($cantidadDeLoteAnterior) + $stockActual;

                    throw new \RuntimeException(
                        "Stock insuficiente. Disponible: {$unidadesDisponibles} unidades adicionales. Necesita: {$diferencia}"
                    );
                }
            }

            // ── REEMPAQUE CONSOLIDADO ──
            $cantidadDeBodegaAnterior = (float) ($carga->cantidad_de_bodega ?? 0);
            $costoBodegaOriginal = (float) ($carga->costo_bodega_original ?? 0);

            $nuevaCantidadDeBodega = $cantidadDeBodegaAnterior + $tomarDeBodega;
            $totalLoteConsolidado = $cantidadDeLoteAnterior + $tomarDeLote;

            $nuevoReempaqueId = null;
            $reempaqueNumero = null;
            $costoLoteConsolidado = 0.0;

            if ($tomarDeLote > 0 && $totalLoteConsolidado > 0) {
                // 1. Revertir reempaque anterior si existe (devuelve huevos al lote)
                if ($carga->reempaque_id && $cantidadDeLoteAnterior > 0) {
                    $reempaqueAnterior = Reempaque::find($carga->reempaque_id);
                    if ($reempaqueAnterior && ! $reempaqueAnterior->estaInactivo()) {
                        $this->reempaqueService->revertirReempaqueParcial(
                            (int) $carga->reempaque_id,
                            (int) $carga->producto_id,
                            $cantidadDeLoteAnterior
                        );
                    }
                }

                // 2. Crear reempaque consolidado (anterior + nuevo)
                $etiquetaOrigen = $cantidadDeLoteAnterior > 0
                    ? "Viaje #{$viaje->id} (recarga consolidada)"
                    : "Viaje #{$viaje->id}";

                $resultado = $this->reempaqueService->ejecutarReempaqueAutomatico(
                    (int) $carga->producto_id,
                    (int) $viaje->bodega_origen_id,
                    (int) $totalLoteConsolidado,
                    $etiquetaOrigen
                );
                $nuevoReempaqueId = $resultado['reempaque_id'];
                $reempaqueNumero = $resultado['reempaque_numero'];
                $costoLoteConsolidado = (float) $resultado['costo_unitario'];
            } elseif ($cantidadDeLoteAnterior > 0) {
                // Solo aumenta bodega, lote anterior se mantiene intacto
                $costoLoteConsolidado = (float) ($carga->costo_unitario_lote ?? 0);
            }

            // Descontar de bodega si aplica
            if ($tomarDeBodega > 0) {
                $bodegaProducto->reducirStock($tomarDeBodega, true, [
                    'kardex_tipo' => 'carga_viaje',
                    'kardex_descripcion' => "Carga al viaje #{$viaje->id}",
                    'kardex_referencia_type' => $viaje->getMorphClass(),
                    'kardex_referencia_id' => $viaje->id,
                ]);
            }

            // Costo promedio ponderado total de la carga
            $valorBodegaTotal = $nuevaCantidadDeBodega * $costoBodegaOriginal;
            $valorLoteTotal = $totalLoteConsolidado * $costoLoteConsolidado;
            $costoNuevo = $cantidadNueva > 0
                ? round(($valorBodegaTotal + $valorLoteTotal) / $cantidadNueva, 4)
                : (float) $carga->costo_unitario;

            $data['costo_unitario'] = $costoNuevo;
            $data['subtotal_costo'] = round($costoNuevo * $cantidadNueva, 2);
            $data['cantidad_de_bodega'] = $nuevaCantidadDeBodega;
            $data['cantidad_de_lote'] = $totalLoteConsolidado;
            $data['costo_unitario_lote'] = $costoLoteConsolidado;

            if ($nuevoReempaqueId) {
                $data['reempaque_id'] = $nuevoReempaqueId;
            }

            $carga->update($data);

            Log::info('CargaViajeService: cantidad aumentada', [
                'viaje_id' => $viaje->id,
                'carga_id' => $carga->id,
                'producto_id' => $carga->producto_id,
                'cantidad_anterior' => $cantidadAnterior,
                'cantidad_nueva' => $cantidadNueva,
                'tomar_de_bodega' => $tomarDeBodega,
                'tomar_de_lote' => $tomarDeLote,
                'reempaque_id' => $nuevoReempaqueId,
            ]);

            return [
                'carga' => $carga,
                'tomar_de_bodega' => (float) $tomarDeBodega,
                'tomar_de_lote' => (float) $tomarDeLote,
                'costo_unitario' => $costoNuevo,
                'reempaque_numero' => $reempaqueNumero,
            ];
        });
    }

    /**
     * Reducir la cantidad de una carga existente (bajar producto del camión).
     *
     * Inverso de aumentarCantidad(). Usado por:
     *   - EditAction del CargasRelationManager (reducir cantidad total en
     *     Planificado/Cargando)
     *   - Acción "Bajar" del modo Recargando (deshacer una recarga
     *     equivocada: el usuario indica cuántas unidades BAJA del camión)
     *
     * Reglas (idénticas al comportamiento probado de la reducción del Edit):
     *   1. LIFO: primero se devuelve al lote (reversión parcial del reempaque
     *      de la carga — los huevos reingresan al costo WAC actual del lote),
     *      y el resto a bodega_producto con el costo_bodega_original.
     *   2. No se puede bajar producto ya vendido/mermado/devuelto: la nueva
     *      cantidad no puede ser menor que lo consumido.
     *   3. Para quitar el producto COMPLETO (cantidad 0) se usa la acción
     *      Eliminar/Borrar, no esta reducción.
     *
     * @param  array  $atributosExtra  Campos adicionales a persistir (EditAction
     *                                 pasa su $data completo; "Bajar" no pasa ninguno).
     * @return array{carga: ViajeCarga, devolver_al_lote: float, devolver_a_bodega: float, costo_unitario: float}
     *
     * @throws \InvalidArgumentException si la nueva cantidad no es menor a la
     *                                   actual, o si quedaría en cero o negativa
     * @throws \RuntimeException si la nueva cantidad es menor a lo ya consumido
     *                           (vendido + merma + devuelto)
     */
    public function reducirCantidad(
        Viaje $viaje,
        ViajeCarga $carga,
        float $cantidadNueva,
        array $atributosExtra = []
    ): array {
        return DB::transaction(function () use ($viaje, $carga, $cantidadNueva, $atributosExtra) {
            $producto = Producto::with('categoria.categoriaOrigen', 'unidad')->find($carga->producto_id);
            $categoria = $producto?->categoria;
            $usaLotes = (bool) ($categoria && $categoria->categoria_origen_id);

            $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                ->where('producto_id', $carga->producto_id)
                ->lockForUpdate()
                ->first();

            $cantidadAnterior = (float) $carga->cantidad;
            $cantidadADevolver = $cantidadAnterior - $cantidadNueva;

            if ($cantidadADevolver <= 0) {
                throw new \InvalidArgumentException(
                    "La nueva cantidad ({$cantidadNueva}) debe ser menor a la actual ({$cantidadAnterior})."
                );
            }

            if ($cantidadNueva <= 0) {
                throw new \InvalidArgumentException(
                    'Para quitar el producto completo del camión use la acción Borrar/Eliminar.'
                );
            }

            $consumido = (float) ($carga->cantidad_vendida ?? 0)
                + (float) ($carga->cantidad_merma ?? 0)
                + (float) ($carga->cantidad_devuelta ?? 0);

            if ($cantidadNueva < $consumido) {
                $maxBajar = max(0, $cantidadAnterior - $consumido);
                throw new \RuntimeException(
                    "No puede bajar más de lo disponible en el camión. Ya consumido (vendido/merma/devuelto): {$consumido}. Máximo a bajar: {$maxBajar}."
                );
            }

            $cantidadDeLoteActual = (float) ($carga->cantidad_de_lote ?? 0);
            $cantidadDeBodegaActual = (float) ($carga->cantidad_de_bodega ?? 0);
            $costoLoteActual = (float) ($carga->costo_unitario_lote ?? 0);
            $costoBodegaOriginal = (float) ($carga->costo_bodega_original ?? $carga->costo_unitario ?? 0);

            $devolverAlLote = 0.0;
            $devolverABodega = 0.0;

            if ($usaLotes && $cantidadDeLoteActual > 0) {
                // LIFO: primero devolver al lote
                $devolverAlLote = min($cantidadADevolver, $cantidadDeLoteActual);
                $devolverABodega = max(0, $cantidadADevolver - $devolverAlLote);

                // Revertir reempaque parcialmente (devolver huevos al lote)
                if ($devolverAlLote > 0 && $carga->reempaque_id) {
                    $this->reempaqueService->revertirReempaqueParcial(
                        (int) $carga->reempaque_id,
                        (int) $carga->producto_id,
                        $devolverAlLote
                    );
                }

                // Devolver a bodega si sobra
                if ($devolverABodega > 0 && $bodegaProducto) {
                    $this->reempaqueService->devolverStockABodega($bodegaProducto, $devolverABodega, $costoBodegaOriginal, [
                        'kardex_tipo' => 'retorno_viaje',
                        'kardex_descripcion' => "Reducción de carga del viaje #{$viaje->id}",
                        'kardex_referencia_type' => $viaje->getMorphClass(),
                        'kardex_referencia_id' => $viaje->id,
                    ]);
                }
            } else {
                // Sin lotes: todo va a bodega
                $devolverABodega = $cantidadADevolver;

                if ($bodegaProducto) {
                    $this->reempaqueService->devolverStockABodega($bodegaProducto, $devolverABodega, $costoBodegaOriginal, [
                        'kardex_tipo' => 'retorno_viaje',
                        'kardex_descripcion' => "Reducción de carga del viaje #{$viaje->id}",
                        'kardex_referencia_type' => $viaje->getMorphClass(),
                        'kardex_referencia_id' => $viaje->id,
                    ]);
                }
            }

            // Actualizar campos de origen en la carga
            $nuevaCantidadDeLote = max(0, $cantidadDeLoteActual - $devolverAlLote);
            $nuevaCantidadDeBodega = max(0, $cantidadDeBodegaActual - $devolverABodega);

            $data = $atributosExtra;
            $data['cantidad'] = $cantidadNueva;
            $data['cantidad_de_lote'] = $nuevaCantidadDeLote;
            $data['cantidad_de_bodega'] = $nuevaCantidadDeBodega;
            $data['costo_unitario_lote'] = $costoLoteActual; // se mantiene

            // Recalcular costo unitario promedio ponderado
            $valorBodega = $nuevaCantidadDeBodega * $costoBodegaOriginal;
            $valorLote = $nuevaCantidadDeLote * $costoLoteActual;
            $costoNuevo = $cantidadNueva > 0
                ? round(($valorBodega + $valorLote) / $cantidadNueva, 4)
                : 0.0;

            $data['costo_unitario'] = $costoNuevo;
            $data['subtotal_costo'] = round($costoNuevo * $cantidadNueva, 2);

            $carga->update($data);

            Log::info('CargaViajeService: cantidad reducida', [
                'viaje_id' => $viaje->id,
                'carga_id' => $carga->id,
                'producto_id' => $carga->producto_id,
                'cantidad_anterior' => $cantidadAnterior,
                'cantidad_nueva' => $cantidadNueva,
                'devolver_al_lote' => $devolverAlLote,
                'devolver_a_bodega' => $devolverABodega,
            ]);

            return [
                'carga' => $carga,
                'devolver_al_lote' => (float) $devolverAlLote,
                'devolver_a_bodega' => (float) $devolverABodega,
                'costo_unitario' => $costoNuevo,
            ];
        });
    }

    /**
     * Máximo que se puede BAJAR del camión de una carga: lo disponible
     * (cargado − vendido − merma − devuelto). Bajar más significaría
     * quitar producto que ya no está físicamente en el camión.
     */
    public function maximoParaBajar(ViajeCarga $carga): float
    {
        return max(0.0, (float) $carga->getCantidadDisponible());
    }

    /**
     * Stock ADICIONAL disponible para aumentar/recargar una carga:
     * lo libre en bodega + lo convertible desde lotes (para productos
     * que usan lotes). No incluye lo ya cargado en el camión.
     *
     * @return array{bodega: float, lote: float, total: float}
     */
    public function stockAdicionalDisponible(Viaje $viaje, ViajeCarga $carga): array
    {
        $producto = Producto::with('categoria.categoriaOrigen', 'unidad')->find($carga->producto_id);
        $categoria = $producto?->categoria;
        $usaLotes = (bool) ($categoria && $categoria->categoria_origen_id);

        $stockEnBodega = (float) (BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
            ->where('producto_id', $carga->producto_id)
            ->value('stock') ?? 0);

        $stockDesdeLote = 0.0;

        if ($usaLotes) {
            $huevosPorUnidad = $this->reempaqueService->getHuevosPorUnidad($producto);

            $totalHuevosEnLotes = Lote::where('bodega_id', $viaje->bodega_origen_id)
                ->whereHas('producto', fn ($q) => $q->where('categoria_id', $categoria->categoria_origen_id))
                ->where('estado', 'disponible')
                ->where('cantidad_huevos_remanente', '>', 0)
                ->sum('cantidad_huevos_remanente');

            $stockDesdeLote = floor($totalHuevosEnLotes / $huevosPorUnidad);
        }

        return [
            'bodega' => $stockEnBodega,
            'lote' => $stockDesdeLote,
            'total' => $stockEnBodega + $stockDesdeLote,
        ];
    }
}
