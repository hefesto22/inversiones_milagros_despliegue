<?php

namespace App\Enums;

/**
 * Estados posibles de una Compra.
 *
 * Reemplaza las cadenas hardcodeadas ('borrador', 'ordenada', etc.)
 * dispersas en modelos, Resources y queries.
 */
enum CompraEstado: string
{
    case Borrador = 'borrador';
    case Ordenada = 'ordenada';
    case RecibidaPagada = 'recibida_pagada';
    case RecibidaPendientePago = 'recibida_pendiente_pago';
    case PorRecibirPagada = 'por_recibir_pagada';
    case PorRecibirPendientePago = 'por_recibir_pendiente_pago';
    case Cancelada = 'cancelada';

    /**
     * Etiqueta legible para UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Ordenada => 'Ordenada',
            self::RecibidaPagada => 'Recibida y Pagada',
            self::RecibidaPendientePago => 'Recibida - Pendiente de Pago',
            self::PorRecibirPagada => 'Por Recibir - Pagada',
            self::PorRecibirPendientePago => 'Por Recibir - Pendiente de Pago',
            self::Cancelada => 'Cancelada',
        };
    }

    /**
     * Color para badges de Filament.
     */
    public function color(): string
    {
        return match ($this) {
            self::Borrador => 'gray',
            self::Ordenada => 'info',
            self::RecibidaPagada => 'success',
            self::RecibidaPendientePago => 'warning',
            self::PorRecibirPagada => 'primary',
            self::PorRecibirPendientePago => 'danger',
            self::Cancelada => 'danger',
        };
    }

    /**
     * Estados que indican que la compra tiene deuda pendiente.
     *
     * @return self[]
     */
    public static function conDeudaPendiente(): array
    {
        return [
            self::RecibidaPendientePago,
            self::PorRecibirPendientePago,
        ];
    }

    /**
     * Estados que indican que la compra ya fue recibida.
     *
     * @return self[]
     */
    public static function recibidas(): array
    {
        return [
            self::RecibidaPagada,
            self::RecibidaPendientePago,
        ];
    }

    /**
     * Estados activos (excluye borrador y cancelada).
     *
     * @return self[]
     */
    public static function activas(): array
    {
        return array_filter(self::cases(), fn (self $e) => !in_array($e, [self::Borrador, self::Cancelada]));
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
