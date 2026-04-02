<?php

namespace App\Enums;

/**
 * Motivos posibles de una Merma.
 */
enum MermaMotivo: string
{
    case Rotos = 'rotos';
    case Podridos = 'podridos';
    case Vencidos = 'vencidos';
    case DanadosTransporte = 'dañados_transporte';
    case Otros = 'otros';

    public function label(): string
    {
        return match ($this) {
            self::Rotos => 'Rotos',
            self::Podridos => 'Podridos',
            self::Vencidos => 'Vencidos',
            self::DanadosTransporte => 'Dañados en Transporte',
            self::Otros => 'Otros',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Rotos => 'danger',
            self::Podridos => 'warning',
            self::Vencidos => 'gray',
            self::DanadosTransporte => 'info',
            self::Otros => 'primary',
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
