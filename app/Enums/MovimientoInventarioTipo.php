<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de movimiento del Kardex de Inventario.
 *
 * Cada caso responde "¿por qué se movió el inventario?" y determina el color
 * del badge en las pantallas del Kardex. El signo (entra/sale) NO vive aquí —
 * lo lleva el campo delta del movimiento (± explícito).
 */
enum MovimientoInventarioTipo: string
{
    /** Apertura del Kardex: saldo existente al momento de estrenar el libro */
    case SaldoInicial = 'saldo_inicial';

    // --- Entradas ---
    case Compra = 'compra';
    case EntradaReempaque = 'entrada_reempaque';
    case DevolucionReempaque = 'devolucion_reempaque';
    case RetornoViaje = 'retorno_viaje';
    case CancelacionVenta = 'cancelacion_venta';
    case AjusteEntrada = 'ajuste_entrada';

    // --- Salidas ---
    case SalidaReempaque = 'salida_reempaque';
    case Venta = 'venta';
    case CargaViaje = 'carga_viaje';
    case Merma = 'merma';
    case AjusteSalida = 'ajuste_salida';
    case CancelacionCompra = 'cancelacion_compra';

    // --- Ambidireccional ---
    case AjusteCorreccion = 'ajuste_correccion';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::SaldoInicial        => 'Saldo inicial',
            self::Compra              => 'Compra',
            self::EntradaReempaque    => 'Entrada por reempaque',
            self::DevolucionReempaque => 'Devolución de reempaque',
            self::RetornoViaje        => 'Retorno de viaje',
            self::CancelacionVenta    => 'Cancelación de venta',
            self::AjusteEntrada       => 'Ajuste — entrada',
            self::SalidaReempaque     => 'Salida a reempaque',
            self::Venta               => 'Venta',
            self::CargaViaje          => 'Carga a viaje',
            self::Merma               => 'Merma',
            self::AjusteSalida        => 'Ajuste — salida',
            self::CancelacionCompra   => 'Cancelación de compra',
            self::AjusteCorreccion    => 'Ajuste — corrección',
            self::Otro                => 'Otro',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SaldoInicial        => 'gray',
            self::Compra,
            self::EntradaReempaque,
            self::DevolucionReempaque,
            self::RetornoViaje,
            self::CancelacionVenta,
            self::AjusteEntrada       => 'success',
            self::SalidaReempaque,
            self::Venta,
            self::CargaViaje          => 'info',
            self::Merma,
            self::CancelacionCompra,
            self::AjusteSalida        => 'danger',
            self::AjusteCorreccion    => 'warning',
            self::Otro                => 'gray',
        };
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
