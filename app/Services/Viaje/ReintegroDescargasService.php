<?php

declare(strict_types=1);

namespace App\Services\Viaje;

use App\Application\Services\ReempaqueService;
use App\Models\BodegaProducto;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\ReempaqueProducto;
use App\Models\Viaje;
use App\Models\ViajeCarga;
use App\Models\ViajeDescarga;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reintegro de descargas de viaje al inventario.
 *
 * ÚNICO punto de verdad del destino del producto que regresa del camión.
 * Usado por:
 *   - Viaje::cerrar() → procesarReintegroDescargas()          (cierre del viaje)
 *   - DescargasRelationManager (acción manual de reingreso)   (antes del cierre)
 *
 * Regla de negocio (solicitada por el cliente, 2026-07-12):
 *
 *   1. Producto BASE de lote (categoría auto-referenciada, cartón 1x30 —
 *      Huevo Grande/Mediano/Pequeño/PW/Extra Grande): los cartones completos
 *      NO vendidos regresan AL LOTE, no a bodega_producto. El cartón base es
 *      físicamente idéntico al del lote, así que se revierte el reempaque
 *      automático de la carga (LIFO — ReempaqueService::revertirReempaqueParcial),
 *      lo que reintegra los huevos al costo WAC actual del lote ("según lo
 *      existente"). Cualquier excedente que no esté registrado en el reempaque
 *      (porción legacy que vino de bodega) se devuelve directo al lote único.
 *      Esto preserva el invariante: bodega_producto.stock de huevo base = 0.
 *
 *   2. Producto DERIVADO (OPOA 1x30 / 1x15, categoría con origen distinto) y
 *      productos sin lote: ya fueron reempacados físicamente a otra
 *      presentación — regresan al stock de bodega_producto con promedio
 *      ponderado, como siempre.
 *
 *   3. Fracciones de cartón (sueltos) siguen yendo al lote único del producto.
 *
 * Idempotencia: viaje_descargas.procesado_reingreso marca la descarga ya
 * procesada. Ambos caminos (manual y cierre) la respetan y la marcan, lo que
 * elimina el doble reintegro que ocurría al reingresar manualmente y luego
 * cerrar el viaje.
 *
 * Kardex/WAC: no se registra nada a mano aquí — devolverHuevos() dispara
 * DevolucionAplicadaAlLote (WAC + Kardex del lote) y actualizarCostoPromedio()
 * dispara el movimiento de bodega, igual que el resto del sistema.
 */
final class ReintegroDescargasService
{
    public function __construct(
        private readonly ReempaqueService $reempaqueService,
    ) {}

    /**
     * Procesar todas las descargas pendientes de reintegro de un viaje.
     * Llamado desde Viaje::cerrar() dentro de su transacción.
     *
     * @return array<int, string> Mensajes de resumen de los movimientos aplicados
     */
    public function procesarReintegrosPendientes(Viaje $viaje): array
    {
        $mensajes = [];

        $descargas = $viaje->descargas()
            ->where('reingresa_stock', true)
            ->where('procesado_reingreso', false)
            ->with(['producto.categoria', 'producto.unidad'])
            ->get();

        foreach ($descargas as $descarga) {
            $mensajes = array_merge($mensajes, $this->procesarReintegro($viaje, $descarga));
        }

        return $mensajes;
    }

    /**
     * Procesar el reintegro de UNA descarga (acción manual o cierre).
     *
     * Devuelve los mensajes de los movimientos aplicados; array vacío si la
     * descarga no aplica (ya procesada, no reingresa, o producto dañado/vencido).
     *
     * @return array<int, string>
     */
    public function procesarReintegro(Viaje $viaje, ViajeDescarga $descarga): array
    {
        if (
            ! $descarga->reingresa_stock
            || ! $descarga->estaEnBuenEstado()
            || $descarga->procesado_reingreso
        ) {
            return [];
        }

        // Eager load explícito: Model::shouldBeStrict() está activo fuera de
        // producción y este método también se invoca con modelos sin relaciones.
        $descarga->loadMissing(['producto.categoria', 'producto.unidad']);

        return DB::transaction(function () use ($viaje, $descarga) {
            $mensajes = [];

            $producto = $descarga->producto;
            $unidadesPorBulto = (int) ($producto->unidades_por_bulto ?? 1);

            $cantidadTotal = (float) $descarga->cantidad;
            $cartonesCompletos = floor($cantidadTotal);
            $fraccion = $cantidadTotal - $cartonesCompletos;

            // Nota: el sistema mantiene UNA carga (consolidada) por producto
            // por viaje — mismo supuesto que el resto de los flujos de viaje.
            $carga = $viaje->cargas()
                ->where('producto_id', $descarga->producto_id)
                ->first();

            $costoParaBodega = $carga
                ? (float) ($carga->costo_bodega_original ?? $carga->costo_unitario ?? $descarga->costo_unitario)
                : (float) $descarga->costo_unitario;

            // ── 1. Cartones completos ──────────────────────────────────────
            if ($cartonesCompletos > 0) {
                if ($this->esProductoBaseDeLote($producto)) {
                    $mensajes = array_merge(
                        $mensajes,
                        $this->reintegrarBaseAlLote($viaje, $descarga, $carga, $cartonesCompletos)
                    );
                } else {
                    $this->reintegrarABodega($viaje, $descarga->producto_id, $cartonesCompletos, $costoParaBodega);
                    $mensajes[] = "{$cartonesCompletos} unidades al stock de bodega";
                }
            }

            // ── 2. Fracción de cartón (sueltos) ────────────────────────────
            if ($fraccion > 0.0001) {
                if ($unidadesPorBulto > 1) {
                    $huevosSueltos = (int) round($fraccion * $unidadesPorBulto);

                    if ($huevosSueltos > 0) {
                        $this->reintegrarSueltosAlLoteUnico(
                            $viaje,
                            (int) $descarga->producto_id,
                            $huevosSueltos,
                            $unidadesPorBulto
                        );
                        $mensajes[] = "{$huevosSueltos} unidades sueltas al lote único";
                    }
                } else {
                    // Producto sin subunidades: la fracción va al stock normal
                    $this->reintegrarABodega($viaje, $descarga->producto_id, $fraccion, $costoParaBodega);
                    $mensajes[] = number_format($fraccion, 4).' unidades al stock de bodega';
                }
            }

            $descarga->procesado_reingreso = true;
            $descarga->save();

            Log::info('ReintegroDescargasService: descarga reintegrada', [
                'viaje_id' => $viaje->id,
                'descarga_id' => $descarga->id,
                'producto_id' => $descarga->producto_id,
                'cantidad' => $cantidadTotal,
                'movimientos' => $mensajes,
            ]);

            return $mensajes;
        });
    }

    /**
     * Un producto es "base de lote" cuando su categoría es auto-referenciada
     * (Huevo Mediano → origen Huevo Mediano) y su presentación es el cartón
     * 1x30 — es decir, el cartón es físicamente idéntico al que vive en el
     * lote y puede regresar a él sin des-reempacar nada.
     *
     * Los OPOA (categoría derivada: Opoa Huevo Mediano → origen Huevo Mediano)
     * y los 1x15 NO son base: ya fueron reempacados a otra presentación.
     */
    public function esProductoBaseDeLote(Producto $producto): bool
    {
        $producto->loadMissing('categoria');
        $categoria = $producto->categoria;

        if (! $categoria || ! $categoria->esAutoReferenciada()) {
            return false;
        }

        return $this->reempaqueService->getHuevosPorUnidad($producto) === 30;
    }

    /**
     * Reintegrar huevos sueltos (o cartones sin reempaque revertible) al
     * LOTE ÚNICO del producto en la bodega de origen del viaje.
     *
     * Delegado a los helpers oficiales de Lote (los mismos que usan compras,
     * reempaques y ventas): obtenerOCrearLoteUnico + devolverHuevos, que
     * dispara DevolucionAplicadaAlLote para WAC Perpetuo y Kardex.
     */
    public function reintegrarSueltosAlLoteUnico(
        Viaje $viaje,
        int $productoId,
        int $cantidadHuevos,
        int $unidadesPorBulto
    ): void {
        $lote = Lote::obtenerOCrearLoteUnico(
            productoId: $productoId,
            bodegaId: (int) $viaje->bodega_origen_id,
            huevosPorCarton: $unidadesPorBulto,
            createdBy: Auth::id(),
        );

        $lote->devolverHuevos(
            cantidadHuevos: (float) $cantidadHuevos,
            huevosRegaloDevueltos: 0.0,
            contexto: [
                'kardex_tipo' => 'retorno_viaje',
                'kardex_descripcion' => "Retorno de viaje #{$viaje->id} — reintegro al lote único",
                'kardex_referencia_type' => $viaje->getMorphClass(),
                'kardex_referencia_id' => $viaje->id,
            ],
        );
    }

    /**
     * Reintegrar cartones completos de producto BASE al lote.
     *
     * Camino principal: revertir el reempaque automático de la carga (LIFO),
     * que devuelve los huevos a los lotes exactos de donde salieron, al costo
     * WAC actual del lote. Se aplica un CAP a lo que el reempaque aún tiene
     * registrado (reempaque_productos.cantidad) porque revertirReempaqueParcial
     * ignora silenciosamente el excedente — ese excedente (porción legacy que
     * vino de bodega) se devuelve directo al lote único para mantener el
     * invariante "huevo base vive en el lote, bodega en 0".
     *
     * @return array<int, string>
     */
    private function reintegrarBaseAlLote(
        Viaje $viaje,
        ViajeDescarga $descarga,
        ?ViajeCarga $carga,
        float $cartonesCompletos
    ): array {
        $mensajes = [];
        $pendientes = $cartonesCompletos;

        if ($carga && $carga->reempaque_id) {
            $revertible = (float) (ReempaqueProducto::where('reempaque_id', $carga->reempaque_id)
                ->where('producto_id', $descarga->producto_id)
                ->value('cantidad') ?? 0.0);

            $aRevertir = min($pendientes, floor($revertible));

            if ($aRevertir > 0) {
                $this->reempaqueService->revertirReempaqueParcial(
                    (int) $carga->reempaque_id,
                    (int) $descarga->producto_id,
                    $aRevertir,
                );

                $pendientes -= $aRevertir;
                $mensajes[] = "{$aRevertir} cartones al lote (reversión de reempaque)";
            }
        }

        if ($pendientes > 0) {
            $unidadesPorBulto = max(1, (int) ($descarga->producto->unidades_por_bulto ?? 30));
            $huevos = (int) round($pendientes * $unidadesPorBulto);

            $this->reintegrarSueltosAlLoteUnico(
                $viaje,
                (int) $descarga->producto_id,
                $huevos,
                $unidadesPorBulto
            );

            $mensajes[] = "{$pendientes} cartones al lote único (sin reempaque revertible)";
        }

        return $mensajes;
    }

    /**
     * Reintegrar unidades a bodega_producto con promedio ponderado.
     * actualizarCostoPromedio() ya calcula el promedio con 4 decimales y
     * registra el movimiento de Kardex vía StockBodegaMovido.
     */
    private function reintegrarABodega(
        Viaje $viaje,
        int $productoId,
        float $cantidad,
        float $costoUnitario
    ): void {
        $bodegaProducto = BodegaProducto::firstOrCreate(
            [
                'bodega_id' => $viaje->bodega_origen_id,
                'producto_id' => $productoId,
            ],
            [
                'stock' => 0,
                'stock_reservado' => 0,
                'stock_minimo' => 0,
                'costo_promedio_actual' => $costoUnitario,
                'activo' => true,
            ]
        );

        $bodegaProducto->actualizarCostoPromedio($cantidad, $costoUnitario, [
            'kardex_tipo' => 'retorno_viaje',
            'kardex_descripcion' => "Retorno de viaje #{$viaje->id}",
            'kardex_referencia_type' => $viaje->getMorphClass(),
            'kardex_referencia_id' => $viaje->id,
        ]);
    }
}
