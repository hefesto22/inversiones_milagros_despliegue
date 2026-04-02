<?php

namespace App\Application\DTOs;

use App\Models\Venta;
use App\Domain\Sales\ValueObjects\VentaState;
use App\Domain\Sales\ValueObjects\PaymentStatus;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Support\Collection;

/**
 * Data Transfer Object para Venta
 *
 * Proporciona tipado fuerte y validación de datos
 * de transferencia entre capas de la aplicación.
 */
final class VentaDTO
{
    private function __construct(
        public readonly int $id,
        public readonly int $clienteId,
        public readonly int $bodegaId,
        public readonly ?string $numeroVenta,
        public readonly string $tipoPago,
        public readonly Money $subtotal,
        public readonly Money $totalISV,
        public readonly Money $descuento,
        public readonly Money $total,
        public readonly Money $montoPagado,
        public readonly Money $saldoPendiente,
        public readonly VentaState $estado,
        public readonly PaymentStatus $estadoPago,
        public readonly ?string $nota,
        public readonly Collection $detalles, // VentaDetalleDTO[]
        public readonly Collection $pagos, // VentaPagoDTO[]
        public readonly ?\DateTimeImmutable $fechaVencimiento = null,
    ) {}

    /**
     * Crear desde modelo Venta
     */
    public static function fromModel(Venta $venta): self
    {
        return new self(
            id: $venta->id,
            clienteId: $venta->cliente_id,
            bodegaId: $venta->bodega_id,
            numeroVenta: $venta->numero_venta,
            tipoPago: $venta->tipo_pago,
            subtotal: Money::make($venta->subtotal),
            totalISV: Money::make($venta->total_isv),
            descuento: Money::make($venta->descuento),
            total: Money::make($venta->total),
            montoPagado: Money::make($venta->monto_pagado),
            saldoPendiente: Money::make($venta->saldo_pendiente),
            estado: VentaState::from($venta->estado),
            estadoPago: PaymentStatus::from($venta->estado_pago),
            nota: $venta->nota,
            detalles: $venta->detalles->map(
                fn($d) => VentaDetalleDTO::fromModel($d)
            )->values(),
            pagos: $venta->pagos->map(
                fn($p) => VentaPagoDTO::fromModel($p)
            )->values(),
            fechaVencimiento: $venta->fecha_vencimiento
                ? \DateTimeImmutable::createFromInterface($venta->fecha_vencimiento)
                : null,
        );
    }

    /**
     * ¿Está completamente pagada?
     */
    public function estaPagada(): bool
    {
        return $this->estadoPago->estaPagada();
    }

    /**
     * ¿Tiene saldo pendiente?
     */
    public function tieneSaldoPendiente(): bool
    {
        return $this->saldoPendiente->isPositive();
    }

    /**
     * ¿Está vencida?
     */
    public function estaVencida(): bool
    {
        if ($this->estaPagada()) {
            return false;
        }

        if (!$this->fechaVencimiento) {
            return false;
        }

        return $this->fechaVencimiento < new \DateTimeImmutable();
    }

    /**
     * Días hasta vencimiento (positivo = futuro, negativo = pasado)
     */
    public function diasHastaVencimiento(): ?int
    {
        if (!$this->fechaVencimiento) {
            return null;
        }

        return (new \DateTimeImmutable())
            ->diff($this->fechaVencimiento)
            ->days;
    }

    /**
     * Convertir a array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cliente_id' => $this->clienteId,
            'bodega_id' => $this->bodegaId,
            'numero_venta' => $this->numeroVenta,
            'tipo_pago' => $this->tipoPago,
            'subtotal' => $this->subtotal->getAmount(),
            'total_isv' => $this->totalISV->getAmount(),
            'descuento' => $this->descuento->getAmount(),
            'total' => $this->total->getAmount(),
            'monto_pagado' => $this->montoPagado->getAmount(),
            'saldo_pendiente' => $this->saldoPendiente->getAmount(),
            'estado' => $this->estado->value,
            'estado_pago' => $this->estadoPago->value,
            'nota' => $this->nota,
            'fecha_vencimiento' => $this->fechaVencimiento?->format('Y-m-d'),
        ];
    }
}
