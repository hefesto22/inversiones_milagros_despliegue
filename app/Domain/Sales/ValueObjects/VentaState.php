<?php

namespace App\Domain\Sales\ValueObjects;

/**
 * Enum para estados de Venta
 *
 * Estados válidos:
 * - BORRADOR: Venta en construcción
 * - PENDIENTE_PAGO: Venta completada pero sin pagar
 * - PAGADA: Venta completada y pagada
 * - CANCELADA: Venta cancelada
 */
enum VentaState: string
{
    case BORRADOR = 'borrador';
    case PENDIENTE_PAGO = 'pendiente_pago';
    case PAGADA = 'pagada';
    case CANCELADA = 'cancelada';

    /**
     * ¿Puede completarse esta venta?
     */
    public function puedeCompletarse(): bool
    {
        return $this === self::BORRADOR;
    }

    /**
     * ¿Puede cancelarse esta venta?
     */
    public function puedeCancelarse(): bool
    {
        return $this !== self::CANCELADA;
    }

    /**
     * ¿Ha sido completada la venta?
     */
    public function esCompletada(): bool
    {
        return in_array($this, [self::PAGADA, self::PENDIENTE_PAGO]);
    }

    /**
     * Label en español
     */
    public function label(): string
    {
        return match($this) {
            self::BORRADOR => 'Borrador',
            self::PENDIENTE_PAGO => 'Pendiente de Pago',
            self::PAGADA => 'Pagada',
            self::CANCELADA => 'Cancelada',
        };
    }

    /**
     * Color para UI (Filament, etc)
     */
    public function color(): string
    {
        return match($this) {
            self::BORRADOR => 'gray',
            self::PENDIENTE_PAGO => 'warning',
            self::PAGADA => 'success',
            self::CANCELADA => 'danger',
        };
    }
}
