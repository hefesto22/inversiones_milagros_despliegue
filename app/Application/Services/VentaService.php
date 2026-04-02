<?php

namespace App\Application\Services;

use App\Application\DTOs\VentaDTO;
use App\Application\DTOs\VentaDetalleDTO;
use App\Application\DTOs\VentaPagoDTO;
use App\Models\Venta;
use App\Models\BodegaProducto;
use App\Models\Producto;
use App\Application\Services\ReempaqueService;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Sales\ValueObjects\VentaState;
use App\Domain\Sales\ValueObjects\PaymentStatus;
use App\Domain\Sales\Services\VentaDomainService;
use App\Infrastructure\Transaction\TransactionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Exceptions\VentaCantBeCompletedException;
use App\Exceptions\InsufficientStockException;
use InvalidArgumentException;

/**
 * Servicio de aplicación para gestión de Ventas
 *
 * Responsabilidades:
 * - Orquestar la lógica de negocio
 * - Garantizar transaccionalidad
 * - Validar mediante domain services
 * - Persistir cambios
 */
final class VentaService
{
    public function __construct(
        private readonly TransactionManager $transactionManager,
        private readonly VentaDomainService $domainService,
        private readonly ReempaqueService $reempaqueService,
    ) {}

    /**
     * Completar una venta (pasar de borrador a completada)
     *
     * @param bool $autoRegistrarPago Si true (default), ventas de contado se marcan como pagadas.
     *                                 Si false, solo se procesa stock y se deja en estado completada/pendiente
     *                                 para que el pago se registre por separado.
     *
     * @throws VentaCantBeCompletedException
     * @throws InsufficientStockException
     * @throws InvalidArgumentException
     */
    public function completarVenta(int $ventaId, bool $autoRegistrarPago = true): VentaDTO
    {
        return $this->transactionManager->execute(function () use ($ventaId, $autoRegistrarPago) {
            $venta = Venta::with(['detalles.producto', 'cliente', 'bodega'])->findOrFail($ventaId);
            $ventaDTO = VentaDTO::fromModel($venta);

            // 1. Validar que pueda completarse
            $this->domainService->validarPuedeCompletarse($ventaDTO);

            // 2. Generar número de venta si no existe
            if (!$venta->numero_venta) {
                $venta->numero_venta = $this->generarNumeroVenta($venta);
            }

            // 3. Validar y descontar stock (bodega primero, luego lote si aplica)
            foreach ($venta->detalles as $detalle) {
                $producto = $detalle->producto;
                $cantidadSolicitada = floatval($detalle->cantidad);

                // Usar ReempaqueService como única fuente de verdad para stock
                $stockInfo = $this->reempaqueService->calcularStockDisponible($detalle->producto_id, $venta->bodega_id);

                $bodegaProducto = BodegaProducto::where('bodega_id', $venta->bodega_id)
                    ->where('producto_id', $detalle->producto_id)
                    ->lockForUpdate()
                    ->first();

                if ($stockInfo['usa_lotes']) {
                    if ($stockInfo['stock_total'] < $cantidadSolicitada) {
                        throw new InsufficientStockException(
                            "Stock insuficiente de {$producto->nombre}. " .
                            "Disponible: {$stockInfo['stock_total']} (bodega: {$stockInfo['stock_en_bodega']}, lote: {$stockInfo['stock_desde_lote']}), " .
                            "Requerido: {$cantidadSolicitada}"
                        );
                    }

                    $stockEnBodega = floatval($bodegaProducto->stock ?? 0);
                    $costoEnBodegaOriginal = floatval($bodegaProducto->costo_promedio_actual ?? 0);

                    // Primero de bodega, el resto de lote
                    $tomarDeBodega = min($stockEnBodega, $cantidadSolicitada);
                    $tomarDeLote = max(0, $cantidadSolicitada - $tomarDeBodega);

                    $reempaqueId = null;
                    $costoDelReempaque = 0;

                    // Ejecutar reempaque automático si necesita tomar de lotes
                    if ($tomarDeLote > 0) {
                        $resultadoReempaque = $this->reempaqueService->ejecutarReempaqueAutomatico(
                            $detalle->producto_id,
                            $venta->bodega_id,
                            $tomarDeLote,
                            "Venta #{$venta->numero_venta}"
                        );
                        $reempaqueId = $resultadoReempaque['reempaque_id'];
                        $costoDelReempaque = $resultadoReempaque['costo_unitario'];
                    }

                    // Calcular costo promedio ponderado
                    $valorDeBodega = $tomarDeBodega * $costoEnBodegaOriginal;
                    $valorDeLote = $tomarDeLote * $costoDelReempaque;
                    $valorTotal = $valorDeBodega + $valorDeLote;
                    $costoUnitarioCorrecto = $cantidadSolicitada > 0
                        ? round($valorTotal / $cantidadSolicitada, 4) : 0;

                    // Descontar de bodega
                    if ($tomarDeBodega > 0 && $bodegaProducto) {
                        $bodegaProducto->reducirStock($tomarDeBodega);
                    }

                    // Guardar campos de origen en el detalle
                    $detalle->cantidad_de_bodega = $tomarDeBodega;
                    $detalle->cantidad_de_lote = $tomarDeLote;
                    $detalle->reempaque_id = $reempaqueId;
                    $detalle->costo_bodega_original = $costoEnBodegaOriginal;
                    $detalle->costo_unitario_lote = $costoDelReempaque;
                    $detalle->costo_unitario = $costoUnitarioCorrecto;
                    $detalle->save();

                    Log::info("Venta #{$venta->numero_venta}: Deducción con lotes", [
                        'producto' => $producto->nombre,
                        'de_bodega' => $tomarDeBodega,
                        'de_lote' => $tomarDeLote,
                        'costo_bodega' => $costoEnBodegaOriginal,
                        'costo_lote' => $costoDelReempaque,
                        'costo_final' => $costoUnitarioCorrecto,
                        'reempaque_id' => $reempaqueId,
                    ]);
                } else {
                    // Producto sin lotes — flujo original simple
                    if (!$bodegaProducto) {
                        throw new InsufficientStockException(
                            "Producto {$producto->nombre} no existe en bodega {$venta->bodega->nombre}"
                        );
                    }

                    if (!$bodegaProducto->tieneSuficienteStock($cantidadSolicitada)) {
                        throw new InsufficientStockException(
                            "Stock insuficiente de {$producto->nombre}. " .
                            "Disponible: {$bodegaProducto->getStockDisponible()}, Requerido: {$cantidadSolicitada}"
                        );
                    }

                    // Descontar stock y guardar campos de origen
                    $bodegaProducto->reducirStock($cantidadSolicitada);

                    $detalle->cantidad_de_bodega = $cantidadSolicitada;
                    $detalle->cantidad_de_lote = 0;
                    $detalle->costo_bodega_original = floatval($bodegaProducto->costo_promedio_actual ?? 0);
                    $detalle->save();
                }
            }

            // 4. Actualizar historial del cliente
            foreach ($venta->detalles as $detalle) {
                $venta->cliente->actualizarUltimoPrecio(
                    $detalle->producto_id,
                    $detalle->precio_unitario,
                    $detalle->precio_con_isv,
                    $detalle->cantidad
                );
            }

            // 5. Actualizar estado según tipo de pago
            if ($ventaDTO->tipoPago === 'credito') {
                $venta->estado = VentaState::PENDIENTE_PAGO->value;
                $venta->estado_pago = PaymentStatus::PENDIENTE->value;
                $venta->saldo_pendiente = $venta->total;

                // Calcular fecha de vencimiento
                if ($venta->cliente->dias_credito > 0) {
                    $venta->fecha_vencimiento = now()->addDays($venta->cliente->dias_credito);
                }

                // Agregar deuda al cliente
                $venta->cliente->agregarDeuda($venta->total);
            } elseif ($autoRegistrarPago) {
                // Contado con auto-pago: marcar como pagada inmediatamente
                // (flujo normal desde ViewVenta / Procesar Venta)
                $venta->estado = VentaState::PAGADA->value;
                $venta->estado_pago = PaymentStatus::PAGADO->value;
                $venta->monto_pagado = $venta->total;
                $venta->saldo_pendiente = 0;
            } else {
                // Contado sin auto-pago: solo completar stock, el pago se registra por separado
                // (flujo desde lista: Registrar Pago parcial en borrador)
                $venta->estado = VentaState::PENDIENTE_PAGO->value;
                $venta->estado_pago = PaymentStatus::PENDIENTE->value;
                $venta->saldo_pendiente = $venta->total;
            }

            $venta->updated_by = Auth::id();
            $venta->save();

            return VentaDTO::fromModel($venta);
        });
    }

    /**
     * Registrar un pago en una venta
     *
     * @throws InvalidArgumentException
     */
    public function registrarPago(
        int $ventaId,
        float $monto,
        string $metodoPago = 'efectivo',
        ?string $referencia = null,
        ?string $nota = null,
    ): VentaDTO {
        if ($monto <= 0) {
            throw new InvalidArgumentException("Monto debe ser mayor a 0");
        }

        if (!in_array($metodoPago, ['efectivo', 'transferencia', 'tarjeta', 'cheque'])) {
            throw new InvalidArgumentException("Método de pago no válido: {$metodoPago}");
        }

        return $this->transactionManager->execute(function () use (
            $ventaId, $monto, $metodoPago, $referencia, $nota
        ) {
            $venta = Venta::with('cliente')->lockForUpdate()->findOrFail($ventaId);
            $ventaDTO = VentaDTO::fromModel($venta);

            // Validar que pueda pagarse
            if ($ventaDTO->estado === VentaState::CANCELADA) {
                throw new InvalidArgumentException("No se puede pagar una venta cancelada");
            }

            // Crear registro de pago
            $venta->pagos()->create([
                'monto' => $monto,
                'metodo_pago' => $metodoPago,
                'referencia' => $referencia,
                'nota' => $nota,
                'created_by' => Auth::id(),
            ]);

            // Actualizar saldos
            $nuevoMontoPagado = (float)$venta->monto_pagado + $monto;
            $nuevoSaldoPendiente = $venta->total - $nuevoMontoPagado;

            // Ajustar si paga más de lo debido
            if ($nuevoSaldoPendiente < 0) {
                $nuevoSaldoPendiente = 0;
                $nuevoMontoPagado = $venta->total;
            }

            $venta->monto_pagado = $nuevoMontoPagado;
            $venta->saldo_pendiente = $nuevoSaldoPendiente;

            // Actualizar estado de pago
            $venta->estado_pago = match(true) {
                $nuevoSaldoPendiente <= 0 => PaymentStatus::PAGADO->value,
                $nuevoMontoPagado > 0 => PaymentStatus::PARCIAL->value,
                default => PaymentStatus::PENDIENTE->value,
            };

            // Transicionar estado principal cuando se completan los pagos
            if ($nuevoSaldoPendiente <= 0 && $venta->estado === VentaState::PENDIENTE_PAGO->value) {
                $venta->estado = VentaState::PAGADA->value;
            }

            $venta->updated_by = Auth::id();
            $venta->save();

            // Si es crédito, actualizar deuda del cliente
            if ($venta->tipo_pago === 'credito') {
                $venta->cliente->registrarPago($monto);
            }

            return VentaDTO::fromModel($venta);
        });
    }

    /**
     * Cancelar una venta
     *
     * @throws InvalidArgumentException
     */
    public function cancelarVenta(
        int $ventaId,
        ?string $motivo = null
    ): VentaDTO {
        return $this->transactionManager->execute(function () use ($ventaId, $motivo) {
            $venta = Venta::with(['detalles.producto', 'cliente', 'bodega'])->lockForUpdate()->findOrFail($ventaId);
            $ventaDTO = VentaDTO::fromModel($venta);

            // Validar que pueda cancelarse
            if (!$ventaDTO->estado->puedeCancelarse()) {
                throw new InvalidArgumentException(
                    "Venta en estado {$ventaDTO->estado->label()} no puede cancelarse"
                );
            }

            // Si ya fue completada, devolver stock respetando origen
            if ($ventaDTO->estado->esCompletada()) {
                foreach ($venta->detalles as $detalle) {
                    $cantidadDeLote = floatval($detalle->cantidad_de_lote ?? 0);
                    $cantidadDeBodega = floatval($detalle->cantidad_de_bodega ?? 0);

                    // Fallback legacy: si no hay campos de origen, todo es bodega
                    if ($cantidadDeBodega == 0 && $cantidadDeLote == 0) {
                        $cantidadDeBodega = floatval($detalle->cantidad);
                    }

                    // 1. Revertir reempaque si hubo (devolver huevos a lotes)
                    if ($cantidadDeLote > 0 && $detalle->reempaque_id) {
                        $reempaque = \App\Models\Reempaque::find($detalle->reempaque_id);

                        if ($reempaque && !$reempaque->estaInactivo()) {
                            $this->reempaqueService->revertirReempaqueParcial(
                                $detalle->reempaque_id,
                                $detalle->producto_id,
                                $cantidadDeLote
                            );
                        }
                    }

                    // 2. Devolver unidades de bodega con costo original
                    if ($cantidadDeBodega > 0) {
                        $bodegaProducto = BodegaProducto::where('bodega_id', $venta->bodega_id)
                            ->where('producto_id', $detalle->producto_id)
                            ->lockForUpdate()
                            ->first();

                        if ($bodegaProducto) {
                            $costoOriginal = floatval($detalle->costo_bodega_original ?? $detalle->costo_unitario ?? 0);
                            $bodegaProducto->actualizarCostoPromedio($cantidadDeBodega, $costoOriginal);
                        }
                    }
                }

                // Si fue a crédito, eliminar la deuda
                if ($venta->tipo_pago === 'credito' && $venta->saldo_pendiente > 0) {
                    $venta->cliente->registrarPago($venta->saldo_pendiente);
                }
            }

            // Actualizar estado
            $venta->estado = VentaState::CANCELADA->value;
            $venta->nota = $venta->nota
                ? $venta->nota . "\n[CANCELADA] " . ($motivo ?? 'Sin motivo')
                : "[CANCELADA] " . ($motivo ?? 'Sin motivo');
            $venta->updated_by = Auth::id();
            $venta->save();

            return VentaDTO::fromModel($venta);
        });
    }

    /**
     * Recalcular totales desde los detalles
     */
    public function recalcularTotales(int $ventaId): VentaDTO
    {
        return $this->transactionManager->execute(function () use ($ventaId) {
            $venta = Venta::with('detalles')->findOrFail($ventaId);

            $subtotal = (float)$venta->detalles()->sum('subtotal');
            $totalISV = (float)$venta->detalles()->sum('total_isv');
            $descuento = $venta->descuento;

            $totalBruto = $subtotal + $totalISV;
            $total = $totalBruto - $descuento;

            $venta->subtotal = $subtotal;
            $venta->total_isv = $totalISV;
            $venta->total = $total;
            $venta->saldo_pendiente = $total - $venta->monto_pagado;
            $venta->updated_by = Auth::id();
            $venta->save();

            return VentaDTO::fromModel($venta);
        });
    }

    /**
     * Obtener venta con toda la información cargada
     */
    public function obtenerVenta(int $ventaId): VentaDTO
    {
        $venta = Venta::with([
            'cliente',
            'bodega',
            'detalles.producto.unidad',
            'pagos.creador',
            'creador',
            'actualizador',
        ])->findOrFail($ventaId);

        return VentaDTO::fromModel($venta);
    }

    /**
     * Generar número de venta único
     */
    private function generarNumeroVenta(Venta $venta): string
    {
        $prefijo = 'V';
        $bodegaCodigo = str_pad($venta->bodega_id, 2, '0', STR_PAD_LEFT);
        $fecha = now()->format('ymd');
        $clave = "{$prefijo}{$bodegaCodigo}-{$fecha}";

        $ultimaVenta = Venta::where('numero_venta', 'like', $clave . '%')
            ->orderBy('numero_venta', 'desc')
            ->lockForUpdate()
            ->first();

        if ($ultimaVenta) {
            $ultimoNumero = (int) substr($ultimaVenta->numero_venta, -4);
            $siguiente = $ultimoNumero + 1;
        } else {
            $siguiente = 1;
        }

        return $clave . '-' . str_pad($siguiente, 4, '0', STR_PAD_LEFT);
    }
}
