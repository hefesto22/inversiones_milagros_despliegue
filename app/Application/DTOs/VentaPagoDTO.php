<?php

namespace App\Application\DTOs;

use App\Models\VentaPago;
use App\Domain\Shared\ValueObjects\Money;

/**
 * DTO para pago de venta
 */
final class VentaPagoDTO
{
    private function __construct(
        public readonly int $id,
        public readonly int $ventaId,
        public readonly Money $monto,
        public readonly string $metodoPago,
        public readonly ?string $referencia = null,
        public readonly ?string $nota = null,
        public readonly ?int $creadoPor = null,
        public readonly ?\DateTimeImmutable $creadoEn = null,
    ) {}

    /**
     * Crear desde modelo
     */
    public static function fromModel(VentaPago $pago): self
    {
        return new self(
            id: $pago->id,
            ventaId: $pago->venta_id,
            monto: Money::make($pago->monto),
            metodoPago: $pago->metodo_pago,
            referencia: $pago->referencia,
            nota: $pago->nota,
            creadoPor: $pago->created_by,
            creadoEn: $pago->created_at
                ? \DateTimeImmutable::createFromInterface($pago->created_at)
                : null,
        );
    }

    /**
     * Obtener etiqueta del método de pago
     */
    public function getMetodoPagoLabel(): string
    {
        return match($this->metodoPago) {
            'efectivo' => 'Efectivo',
            'transferencia' => 'Transferencia',
            'tarjeta' => 'Tarjeta',
            'cheque' => 'Cheque',
            default => $this->metodoPago,
        };
    }

    /**
     * Convertir a array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'venta_id' => $this->ventaId,
            'monto' => $this->monto->getAmount(),
            'metodo_pago' => $this->metodoPago,
            'referencia' => $this->referencia,
            'nota' => $this->nota,
            'created_by' => $this->creadoPor,
            'created_at' => $this->creadoEn?->format('Y-m-d H:i:s'),
        ];
    }
}
