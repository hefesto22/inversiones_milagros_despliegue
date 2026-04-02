<?php

namespace App\Enums;

/**
 * Estados posibles de un Lote.
 */
enum LoteEstado: string
{
    case Disponible = 'disponible';
    case Agotado = 'agotado';

    public function label(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Agotado => 'Agotado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Disponible => 'success',
            self::Agotado => 'gray',
        };
    }

    /**
     * Opciones para Filament Select/Radio.
     *
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
