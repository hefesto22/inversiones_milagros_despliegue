<?php

namespace App\Domain\Sales\ValueObjects;

/**
 * Enum para estado de pago de una Venta
 *
 * Estados:
 * - PENDIENTE: Sin pagos registrados
 * - PARCIAL: Algunos pagos registrados pero no completo
 * - PAGADO: Pagado en su totalidad
 */
enum PaymentStatus: string
{
    case PENDIENTE = 'pendiente';
    case PARCIAL = 'parcial';
    case PAGADO = 'pagado';

    /**
     * ¿Está completamente pagada?
     */
    public function estaPagada(): bool
    {
        return $this === self::PAGADO;
    }

    /**
     * ¿Tiene pagos pendientes?
     */
    public function tienePendiente(): bool
    {
        return $this !== self::PAGADO;
    }

    /**
     * Label en español
     */
    public function label(): string
    {
        return match($this) {
            self::PENDIENTE => 'Pendiente',
            self::PARCIAL => 'Parcial',
            self::PAGADO => 'Pagado',
        };
    }

    /**
     * Color para UI
     */
    public function color(): string
    {
        return match($this) {
            self::PENDIENTE => 'warning',
            self::PARCIAL => 'info',
            self::PAGADO => 'success',
        };
    }
}
