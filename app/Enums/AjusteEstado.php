<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado del ciclo de vida de un Ajuste de Inventario.
 *
 * Flujo normal:
 *   Borrador → PendienteAprobacion (si supera umbral) → Aprobado → Aplicado
 *
 * Flujo bypass (si delta < umbral):
 *   Borrador → Aplicado (sin pasar por aprobación)
 *
 * Flujo de rechazo:
 *   Borrador → PendienteAprobacion → Rechazado (terminal)
 *
 * Una vez en Aplicado, el ajuste es INMUTABLE — no se puede borrar ni editar.
 * Para corregir, se crea un nuevo ajuste de tipo AjusteCorreccion.
 */
enum AjusteEstado: string
{
    case Borrador             = 'borrador';
    case PendienteAprobacion  = 'pendiente_aprobacion';
    case Aprobado             = 'aprobado';
    case Rechazado            = 'rechazado';
    case Aplicado             = 'aplicado';

    public function label(): string
    {
        return match ($this) {
            self::Borrador            => 'Borrador',
            self::PendienteAprobacion => 'Pendiente de aprobación',
            self::Aprobado            => 'Aprobado',
            self::Rechazado           => 'Rechazado',
            self::Aplicado            => 'Aplicado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Borrador            => 'gray',
            self::PendienteAprobacion => 'warning',
            self::Aprobado            => 'info',
            self::Rechazado           => 'danger',
            self::Aplicado            => 'success',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Borrador            => 'heroicon-o-pencil-square',
            self::PendienteAprobacion => 'heroicon-o-clock',
            self::Aprobado            => 'heroicon-o-check-circle',
            self::Rechazado           => 'heroicon-o-x-circle',
            self::Aplicado            => 'heroicon-o-check-badge',
        };
    }

    /**
     * Si el ajuste todavía puede modificarse.
     */
    public function esModificable(): bool
    {
        return $this === self::Borrador;
    }

    /**
     * Si el ajuste ya está terminado (no admite más cambios).
     */
    public function esTerminal(): bool
    {
        return in_array($this, [self::Rechazado, self::Aplicado], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(
            array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases()),
            'label',
            'value'
        );
    }
}
