<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\BodegaProducto;
use App\Models\Producto;
use App\Models\Reempaque;
use App\Models\Viaje;
use App\Models\ViajeCarga;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transferencia de carga entre camiones (viajes).
 *
 * CASO DE NEGOCIO (2026-07-13): a veces un camión le pasa producto a otro
 * en la calle (p. ej. a uno se le acabó el huevo mediano y el otro anda
 * sobrado). La transferencia debe quedar VISIBLE y los costos correctos.
 *
 * DISEÑO — "bajar de A + subir a B" en una sola transacción:
 * En lugar de mover la fila de carga (lo que obligaría a partir reempaques
 * y campos de origen a mano), se reutiliza la maquinaria ya probada:
 *
 *   1. CargaViajeService::reducirCantidad() baja las unidades del camión
 *      origen — LIFO: regresan al lote (reversión del reempaque al costo
 *      WAC actual) o a bodega al costo original. Si se transfiere TODO y
 *      no hay consumos, la carga origen se retira completa.
 *   2. CargaViajeService::aumentarCantidad() las sube al camión destino —
 *      bodega primero, luego lote vía reempaque consolidado, costo
 *      promedio ponderado. Si el destino ya lleva el producto, se
 *      consolida en su carga; si no, se crea una nueva copiando los
 *      precios de venta de la carga origen.
 *
 * El viaje redondo por el inventario preserva el costo (se reintegra y se
 * toma al mismo WAC vigente) y deja rastro completo en el Kardex (salida
 * del viaje A → lote/bodega → entrada al viaje B). Además se anota
 * [TRANSFERENCIA] en las observaciones de AMBOS viajes.
 *
 * COMISIONES: no requiere cambios — las comisiones se calculan por las
 * ventas de cada viaje con SU chofer, así que lo vendido tras el traslado
 * comisiona automáticamente al chofer del camión destino.
 *
 * El servicio NO envía notificaciones — lanza excepciones con mensajes
 * listos para mostrar y la capa Filament decide cómo presentarlos.
 */
final class TransferenciaCargaService
{
    /** Estados del viaje ORIGEN en los que se permite transferir. */
    public const ESTADOS_ORIGEN = [
        Viaje::ESTADO_EN_RUTA,
        Viaje::ESTADO_RECARGANDO,
    ];

    /** Estados del viaje DESTINO que pueden recibir carga. */
    public const ESTADOS_DESTINO = [
        Viaje::ESTADO_PLANIFICADO,
        Viaje::ESTADO_CARGANDO,
        Viaje::ESTADO_EN_RUTA,
        Viaje::ESTADO_RECARGANDO,
    ];

    public function __construct(
        private readonly CargaViajeService $cargaViajeService,
        private readonly ReempaqueService $reempaqueService,
    ) {}

    /**
     * Viajes que pueden recibir carga desde $origen: activos (ver
     * ESTADOS_DESTINO), de la MISMA bodega y distintos al origen.
     *
     * @return Collection<int, string> id => "VJ-... — Chofer — Estado"
     */
    public function destinosDisponibles(Viaje $origen): Collection
    {
        return Viaje::query()
            ->where('bodega_origen_id', $origen->bodega_origen_id)
            ->whereKeyNot($origen->getKey())
            ->whereIn('estado', self::ESTADOS_DESTINO)
            ->with(['chofer', 'camion'])
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (Viaje $viaje) => [
                $viaje->id => trim(
                    ($viaje->numero_viaje ?? "Viaje #{$viaje->id}")
                    .' — '.($viaje->chofer?->name ?? 'Sin chofer')
                    .' — '.ucfirst(str_replace('_', ' ', (string) $viaje->estado))
                ),
            ]);
    }

    /**
     * Transferir $cantidad unidades de la carga del viaje origen al destino.
     *
     * @return array{cantidad: float, transferencia_total: bool, carga_destino: ViajeCarga}
     *
     * @throws \InvalidArgumentException datos inválidos (mismo viaje, otra bodega, cantidad <= 0)
     * @throws \RuntimeException estado no permitido o cantidad mayor a lo disponible
     */
    public function transferir(Viaje $origen, ViajeCarga $carga, Viaje $destino, float $cantidad): array
    {
        return DB::transaction(function () use ($origen, $carga, $destino, $cantidad) {
            $this->validar($origen, $carga, $destino, $cantidad);

            $origen->loadMissing('chofer');
            $destino->loadMissing('chofer');
            $producto = Producto::find($carga->producto_id);

            $cantidadAnterior = (float) $carga->cantidad;
            $cantidadRestante = $cantidadAnterior - $cantidad;
            $transferenciaTotal = $cantidadRestante <= 0.0001;

            // Snapshot para replicar la carga en el destino
            $productoId = (int) $carga->producto_id;
            $unidadId = $carga->unidad_id;
            $precioSugerido = $carga->precio_venta_sugerido;
            $precioMinimo = $carga->precio_venta_minimo;

            // ── 1. BAJAR del camión origen ─────────────────────────────────
            // Las unidades regresan al lote (LIFO, costo WAC actual) o a
            // bodega (costo original) — de donde salieron.
            if ($transferenciaTotal) {
                $this->retirarCargaCompleta($origen, $carga);
            } else {
                $this->cargaViajeService->reducirCantidad($origen, $carga, $cantidadRestante);
            }

            // ── 2. SUBIR al camión destino ─────────────────────────────────
            $cargaDestino = $destino->cargas()
                ->where('producto_id', $productoId)
                ->lockForUpdate()
                ->first();

            if (! $cargaDestino) {
                $cargaDestino = $this->crearCargaEsqueleto($destino, $productoId, $unidadId, $precioSugerido, $precioMinimo);
            }

            $this->cargaViajeService->aumentarCantidad(
                $destino,
                $cargaDestino,
                (float) $cargaDestino->cantidad + $cantidad
            );

            // ── 3. Trazabilidad visible en ambos viajes ────────────────────
            $nota = '[TRANSFERENCIA '.now()->format('d/m/Y H:i').'] '
                .rtrim(rtrim(number_format($cantidad, 2, '.', ''), '0'), '.')
                .' x '.($producto?->nombre ?? "producto #{$productoId}")
                .": viaje #{$origen->id} (".($origen->chofer?->name ?? 'sin chofer').')'
                ." → viaje #{$destino->id} (".($destino->chofer?->name ?? 'sin chofer').')';

            $this->registrarNota($origen, $nota);
            $this->registrarNota($destino, $nota);

            Log::info('TransferenciaCargaService: carga transferida', [
                'viaje_origen_id' => $origen->id,
                'viaje_destino_id' => $destino->id,
                'producto_id' => $productoId,
                'cantidad' => $cantidad,
                'transferencia_total' => $transferenciaTotal,
                'carga_destino_id' => $cargaDestino->id,
            ]);

            return [
                'cantidad' => $cantidad,
                'transferencia_total' => $transferenciaTotal,
                'carga_destino' => $cargaDestino,
            ];
        });
    }

    private function validar(Viaje $origen, ViajeCarga $carga, Viaje $destino, float $cantidad): void
    {
        if ($origen->getKey() === $destino->getKey()) {
            throw new \InvalidArgumentException('El viaje destino debe ser distinto al viaje origen.');
        }

        if ((int) $destino->bodega_origen_id !== (int) $origen->bodega_origen_id) {
            throw new \InvalidArgumentException('Solo se puede transferir carga entre viajes de la misma bodega.');
        }

        if ((int) $carga->viaje_id !== (int) $origen->getKey()) {
            throw new \InvalidArgumentException('La carga no pertenece al viaje origen.');
        }

        if (! in_array($origen->estado, self::ESTADOS_ORIGEN, true)) {
            throw new \RuntimeException('Solo se puede transferir carga de un viaje En Ruta o Recargando.');
        }

        if (! in_array($destino->estado, self::ESTADOS_DESTINO, true)) {
            throw new \RuntimeException("El viaje destino no puede recibir carga (estado: {$destino->estado}).");
        }

        if ($cantidad <= 0) {
            throw new \InvalidArgumentException('Indique cuántas unidades va a transferir (mayor a cero).');
        }

        $disponible = max(0, (float) $carga->getCantidadDisponible());

        if ($cantidad > $disponible + 0.0001) {
            throw new \RuntimeException(
                "Solo hay {$disponible} unidades disponibles en el camión. Lo ya vendido/mermado/devuelto no se puede transferir."
            );
        }
    }

    /**
     * Retirar la carga COMPLETA del viaje origen (transferencia total, sin
     * consumos): revierte el reempaque al lote, devuelve la porción de
     * bodega a bodega y elimina la fila. Espejo del DeleteAction del
     * CargasRelationManager.
     */
    private function retirarCargaCompleta(Viaje $viaje, ViajeCarga $carga): void
    {
        $cantidadDeLote = (float) ($carga->cantidad_de_lote ?? 0);
        $cantidadDeBodega = (float) ($carga->cantidad_de_bodega ?? 0);

        // Fallback legacy: sin campos de origen, todo se trata como bodega
        if ($cantidadDeBodega == 0.0 && $cantidadDeLote == 0.0) {
            $cantidadDeBodega = (float) $carga->cantidad;
        }

        if ($cantidadDeLote > 0 && $carga->reempaque_id) {
            $reempaque = Reempaque::find($carga->reempaque_id);

            if ($reempaque && ! $reempaque->estaInactivo()) {
                $this->reempaqueService->revertirReempaqueParcial(
                    (int) $carga->reempaque_id,
                    (int) $carga->producto_id,
                    $cantidadDeLote
                );
            }
        }

        if ($cantidadDeBodega > 0) {
            $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                ->where('producto_id', $carga->producto_id)
                ->lockForUpdate()
                ->first();

            $costoOriginal = (float) ($carga->costo_bodega_original ?? $carga->costo_unitario ?? 0);

            if ($bodegaProducto) {
                $this->reempaqueService->devolverStockABodega($bodegaProducto, $cantidadDeBodega, $costoOriginal, [
                    'kardex_tipo' => 'retorno_viaje',
                    'kardex_descripcion' => "Transferencia de carga desde el viaje #{$viaje->id}",
                    'kardex_referencia_type' => $viaje->getMorphClass(),
                    'kardex_referencia_id' => $viaje->id,
                ]);
            } else {
                $bp = BodegaProducto::create([
                    'bodega_id' => $viaje->bodega_origen_id,
                    'producto_id' => $carga->producto_id,
                    'stock' => $cantidadDeBodega,
                    'costo_promedio_actual' => round($costoOriginal, 4),
                    'stock_minimo' => 0,
                    'activo' => true,
                ]);
                $bp->actualizarPrecioVentaSegunCosto();
                $bp->save();
            }
        }

        $carga->delete();
    }

    /**
     * Crear la carga "esqueleto" (cantidad 0) en el viaje destino cuando el
     * producto aún no está cargado allí. CargaViajeService::aumentarCantidad()
     * hace después el trabajo real (bodega primero → lote vía reempaque,
     * costo promedio ponderado y subtotales). Copia los precios de venta de
     * la carga origen para que el traslado no cambie el precio al cliente.
     */
    private function crearCargaEsqueleto(
        Viaje $destino,
        int $productoId,
        ?int $unidadId,
        $precioSugerido,
        $precioMinimo
    ): ViajeCarga {
        $costoBodegaActual = (float) (BodegaProducto::where('bodega_id', $destino->bodega_origen_id)
            ->where('producto_id', $productoId)
            ->value('costo_promedio_actual') ?? 0);

        return ViajeCarga::create([
            'viaje_id' => $destino->id,
            'reempaque_id' => null,
            'producto_id' => $productoId,
            'unidad_id' => $unidadId,
            'cantidad' => 0,
            'costo_unitario' => 0,
            'costo_bodega_original' => $costoBodegaActual,
            'cantidad_de_bodega' => 0,
            'cantidad_de_lote' => 0,
            'costo_unitario_lote' => 0,
            'precio_venta_sugerido' => $precioSugerido,
            'precio_venta_minimo' => $precioMinimo,
        ]);
    }

    private function registrarNota(Viaje $viaje, string $nota): void
    {
        $viaje->observaciones = $viaje->observaciones
            ? $viaje->observaciones."\n".$nota
            : $nota;
        $viaje->save();
    }
}
