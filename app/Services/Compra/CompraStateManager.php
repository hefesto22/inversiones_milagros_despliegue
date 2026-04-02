<?php

namespace App\Services\Compra;

use App\Enums\CompraEstado;
use App\Models\Compra;
use App\Models\BodegaProducto;
use App\Models\HistorialCompraLote;
use App\Models\Lote;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Máquina de estados para el flujo de compras.
 *
 * Centraliza transiciones válidas, side-effects (inventario, reversiones),
 * y guards de seguridad. Sigue SRP: la UI solo pide transicionar,
 * este servicio decide si es válido y ejecuta los efectos correspondientes.
 *
 * Para agregar un estado nuevo: extender el enum, el mapa TRANSITIONS,
 * y categorizar en ESTADOS_RECIBIDOS / ESTADOS_REQUIEREN_INVENTARIO.
 */
class CompraStateManager
{
    /**
     * Mapa de transiciones permitidas.
     *
     * Flujo principal:
     *   Borrador → Ordenada → (estados intermedios) → RecibidaPagada
     *   Cualquier no-terminal → Cancelada
     *
     * RESTRICCIÓN: Borrador NO puede saltar directamente a estados "recibidos"
     * porque eso bypassea el procesamiento de inventario.
     */
    private const TRANSITIONS = [
        'borrador' => [
            'ordenada',
            'por_recibir_pagada',
            'por_recibir_pendiente_pago',
            'cancelada',
        ],
        'ordenada' => [
            'recibida_pagada',
            'recibida_pendiente_pago',
            'por_recibir_pagada',
            'por_recibir_pendiente_pago',
            'cancelada',
        ],
        'recibida_pendiente_pago' => [
            'recibida_pagada',
            'cancelada',
        ],
        'por_recibir_pagada' => [
            'recibida_pagada',
            'cancelada',
        ],
        'por_recibir_pendiente_pago' => [
            'recibida_pendiente_pago',
            'recibida_pagada',
            'por_recibir_pagada',
            'cancelada',
        ],
        // Estados finales: no tienen transiciones de salida
        'recibida_pagada' => [],
        'cancelada' => [],
    ];

    /**
     * Estados que implican que el inventario ya fue procesado.
     * Se usa para determinar si cancelar requiere reversión.
     */
    private const ESTADOS_CON_INVENTARIO = [
        'recibida_pagada',
        'recibida_pendiente_pago',
    ];

    /**
     * Estados que requieren procesamiento de inventario al entrar.
     * La UI debe llamar procesarInventarioDesdeCompra() antes de transicionar a estos.
     */
    private const ESTADOS_REQUIEREN_INVENTARIO = [
        'recibida_pagada',
        'recibida_pendiente_pago',
    ];

    // ========================================
    // CONSULTAS DE ESTADO
    // ========================================

    /**
     * Verifica si la transición de un estado a otro es válida.
     */
    public static function puedeTransicionar(CompraEstado $desde, CompraEstado $hacia): bool
    {
        $permitidos = self::TRANSITIONS[$desde->value] ?? [];

        return in_array($hacia->value, $permitidos, true);
    }

    /**
     * Obtiene los estados a los que se puede transicionar desde el estado actual.
     *
     * @return CompraEstado[]
     */
    public static function transicionesDisponibles(CompraEstado $estadoActual): array
    {
        $permitidos = self::TRANSITIONS[$estadoActual->value] ?? [];

        return array_map(
            fn (string $value) => CompraEstado::from($value),
            $permitidos
        );
    }

    /**
     * Obtiene las opciones de transición como array asociativo
     * listo para un Filament Select.
     *
     * @return array<string, string>
     */
    public static function opcionesTransicion(CompraEstado $estadoActual): array
    {
        $opciones = [];

        foreach (self::transicionesDisponibles($estadoActual) as $estado) {
            $opciones[$estado->value] = $estado->label();
        }

        return $opciones;
    }

    /**
     * Verifica si el estado actual es un estado final (sin salida).
     */
    public static function esEstadoFinal(CompraEstado $estado): bool
    {
        return empty(self::TRANSITIONS[$estado->value] ?? []);
    }

    /**
     * Verifica si la compra puede ser cancelada desde su estado actual.
     */
    public static function puedeCancelarse(CompraEstado $estado): bool
    {
        return self::puedeTransicionar($estado, CompraEstado::Cancelada);
    }

    /**
     * Verifica si un estado implica que el inventario ya fue procesado.
     */
    public static function tieneInventarioProcesado(CompraEstado $estado): bool
    {
        return in_array($estado->value, self::ESTADOS_CON_INVENTARIO, true);
    }

    /**
     * Verifica si transicionar a un estado requiere procesamiento de inventario previo.
     */
    public static function requiereProcesamientoInventario(CompraEstado $estado): bool
    {
        return in_array($estado->value, self::ESTADOS_REQUIEREN_INVENTARIO, true);
    }

    // ========================================
    // TRANSICIÓN PRINCIPAL
    // ========================================

    /**
     * Transiciona una compra a un nuevo estado.
     * Solo cambia el estado — NO ejecuta side-effects de inventario.
     * La UI es responsable de llamar procesarInventario/revertirInventario
     * según corresponda ANTES de llamar este método.
     *
     * @throws InvalidArgumentException Si la transición no es válida.
     */
    public static function transicionar(Compra $compra, CompraEstado $nuevoEstado): Compra
    {
        $estadoActual = $compra->estado;

        if (!self::puedeTransicionar($estadoActual, $nuevoEstado)) {
            Log::warning('Intento de transición inválida en compra', [
                'compra_id' => $compra->id,
                'estado_actual' => $estadoActual->value,
                'estado_destino' => $nuevoEstado->value,
                'usuario_id' => Auth::id(),
            ]);

            throw new InvalidArgumentException(
                "Transición no válida: {$estadoActual->label()} → {$nuevoEstado->label()}. " .
                "Transiciones permitidas desde '{$estadoActual->label()}': " .
                implode(', ', array_map(fn ($e) => $e->label(), self::transicionesDisponibles($estadoActual)))
            );
        }

        $compra->estado = $nuevoEstado;
        $compra->save();

        Log::info('Transición de estado en compra', [
            'compra_id' => $compra->id,
            'desde' => $estadoActual->value,
            'hacia' => $nuevoEstado->value,
            'usuario_id' => Auth::id(),
        ]);

        return $compra;
    }

    // ========================================
    // REVERSIÓN DE INVENTARIO
    // ========================================

    /**
     * Revierte el inventario procesado por una compra.
     * Se usa al cancelar una compra que ya fue recibida.
     *
     * Estrategia:
     * - Para lotes (cartones): resta huevos del lote usando historial_compras_lote
     * - Para BodegaProducto (no-cartón): resta stock usando detalles de compra
     * - Si el lote ya vendió parte del inventario, lanza excepción con detalle
     *
     * DEBE ejecutarse dentro de una DB::transaction().
     *
     * @throws \RuntimeException Si no se puede revertir (inventario ya fue usado)
     */
    public static function revertirInventario(Compra $compra): array
    {
        $resultado = [
            'lotes_revertidos' => 0,
            'productos_revertidos' => 0,
            'advertencias' => [],
        ];

        // 1) Revertir lotes (cartones) usando historial
        $historial = HistorialCompraLote::where('compra_id', $compra->id)->get();

        foreach ($historial as $entrada) {
            $lote = Lote::where('id', $entrada->lote_id)->lockForUpdate()->first();

            if (!$lote) {
                $resultado['advertencias'][] = "Lote ID {$entrada->lote_id} no encontrado, posiblemente ya eliminado.";
                continue;
            }

            $huevosARestar = (float) $entrada->huevos_agregados;

            // Guard: verificar que el lote tenga suficientes huevos para revertir
            if ($lote->cantidad_huevos_remanente < $huevosARestar) {
                $vendidos = $huevosARestar - $lote->cantidad_huevos_remanente;
                throw new \RuntimeException(
                    "No se puede cancelar: el lote '{$lote->numero_lote}' ya consumió " .
                    number_format($vendidos, 0) . " huevos de esta compra " .
                    "(por ventas, mermas o reempaques). " .
                    "Disponible: " . number_format($lote->cantidad_huevos_remanente, 0) .
                    ", necesario revertir: " . number_format($huevosARestar, 0) . "."
                );
            }

            // Revertir acumuladores del lote
            $lote->cantidad_cartones_facturados -= (float) $entrada->cartones_facturados;
            $lote->cantidad_cartones_regalo -= (float) $entrada->cartones_regalo;
            $lote->cantidad_cartones_recibidos -= ((float) $entrada->cartones_facturados + (float) $entrada->cartones_regalo);
            $lote->cantidad_huevos_original -= $huevosARestar;
            $lote->cantidad_huevos_remanente -= $huevosARestar;
            $lote->huevos_facturados_acumulados -= ((float) $entrada->cartones_facturados * ($lote->huevos_por_carton ?? 30));
            $lote->huevos_regalo_acumulados -= ((float) $entrada->cartones_regalo * ($lote->huevos_por_carton ?? 30));
            $lote->costo_total_acumulado -= (float) $entrada->costo_compra;
            $lote->costo_total_lote -= (float) $entrada->costo_compra;

            // Recalcular costos promedio
            if ($lote->cantidad_cartones_facturados > 0) {
                $lote->costo_por_carton_facturado = round(
                    $lote->costo_total_acumulado / $lote->cantidad_cartones_facturados,
                    4
                );
                $lote->costo_por_huevo = ($lote->huevos_por_carton ?? 30) > 0
                    ? round($lote->costo_por_carton_facturado / ($lote->huevos_por_carton ?? 30), 4)
                    : 0;
            } else {
                $lote->costo_por_carton_facturado = 0;
                $lote->costo_por_huevo = 0;
            }

            // Si el lote quedó en 0, marcarlo como agotado
            if ($lote->cantidad_huevos_remanente <= 0) {
                $lote->estado = \App\Enums\LoteEstado::Agotado;
            }

            $lote->save();

            // Eliminar la entrada del historial
            $entrada->delete();

            $resultado['lotes_revertidos']++;

            Log::info('Lote: compra revertida', [
                'lote_id' => $lote->id,
                'compra_id' => $compra->id,
                'huevos_revertidos' => $huevosARestar,
                'remanente_despues' => $lote->cantidad_huevos_remanente,
            ]);
        }

        // 2) Revertir BodegaProducto (productos no-cartón)
        //    Solo podemos revertir si sabemos la bodega de destino
        $bodegaId = $compra->bodega_id;

        if ($bodegaId) {
            foreach ($compra->detalles as $detalle) {
                // Si ya fue procesado como lote, el historial ya lo manejó
                $yaRevertidoEnLote = $historial->contains('compra_detalle_id', $detalle->id);
                if ($yaRevertidoEnLote) {
                    continue;
                }

                $cantidadFacturada = $detalle->cantidad_facturada ?? $detalle->cantidad ?? 0;
                $cantidadRegalo = $detalle->cantidad_regalo ?? 0;
                $cantidadRecibida = $cantidadFacturada + $cantidadRegalo;

                if ($cantidadRecibida <= 0) {
                    continue;
                }

                $bodegaProducto = BodegaProducto::where('bodega_id', $bodegaId)
                    ->where('producto_id', $detalle->producto_id)
                    ->lockForUpdate()
                    ->first();

                if (!$bodegaProducto) {
                    $resultado['advertencias'][] = "BodegaProducto no encontrado para producto {$detalle->producto_id} en bodega {$bodegaId}.";
                    continue;
                }

                if ($bodegaProducto->stock < $cantidadRecibida) {
                    $producto = \App\Models\Producto::find($detalle->producto_id);
                    $nombre = $producto ? $producto->nombre : "ID {$detalle->producto_id}";
                    throw new \RuntimeException(
                        "No se puede cancelar: el producto '{$nombre}' tiene stock {$bodegaProducto->stock} " .
                        "pero se necesita revertir {$cantidadRecibida} unidades."
                    );
                }

                $bodegaProducto->stock -= $cantidadRecibida;
                $bodegaProducto->save();
                $resultado['productos_revertidos']++;

                Log::info('BodegaProducto: stock revertido por cancelación', [
                    'compra_id' => $compra->id,
                    'producto_id' => $detalle->producto_id,
                    'bodega_id' => $bodegaId,
                    'cantidad_revertida' => $cantidadRecibida,
                    'stock_resultante' => $bodegaProducto->stock,
                ]);
            }
        }

        return $resultado;
    }

    // ========================================
    // GUARD DE IDEMPOTENCIA
    // ========================================

    /**
     * Verifica si la compra ya tiene inventario procesado
     * consultando el historial de compras en lotes.
     * Previene doble-procesamiento por race conditions.
     */
    public static function yaFueProcesadoInventario(Compra $compra): bool
    {
        return HistorialCompraLote::where('compra_id', $compra->id)->exists();
    }
}
