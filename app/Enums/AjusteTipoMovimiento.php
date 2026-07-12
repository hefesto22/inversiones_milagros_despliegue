<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de movimiento del ajuste de inventario.
 *
 * Una reclasificación entre productos genera DOS ajustes vinculados:
 *   - SalidaReclasificacion (sale del lote origen)
 *   - EntradaReclasificacion (entra al lote destino)
 *
 * Ambos se vinculan vía ajuste_pareja_id para trazabilidad bidireccional.
 *
 * Una merma residual o ajuste de corrección genera UN solo registro.
 */
enum AjusteTipoMovimiento: string
{
    case SalidaReclasificacion  = 'salida_reclasificacion';
    case EntradaReclasificacion = 'entrada_reclasificacion';
    case MermaResidual          = 'merma_residual';
    case AjusteCorreccion       = 'ajuste_correccion';

    public function label(): string
    {
        return match ($this) {
            self::SalidaReclasificacion  => 'Salida (reclasificación)',
            self::EntradaReclasificacion => 'Entrada (reclasificación)',
            self::MermaResidual          => 'Merma residual',
            self::AjusteCorreccion       => 'Ajuste de corrección',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SalidaReclasificacion  => 'danger',
            self::EntradaReclasificacion => 'success',
            self::MermaResidual          => 'warning',
            self::AjusteCorreccion       => 'info',
        };
    }

    /**
     * Si este tipo decrementa el lote (huevos salen).
     */
    public function esSalida(): bool
    {
        return match ($this) {
            self::SalidaReclasificacion,
            self::MermaResidual          => true,
            default                      => false,
        };
    }

    /**
     * Si este tipo requiere una pareja vinculada para ser válido.
     */
    public function requierePareja(): bool
    {
        return match ($this) {
            self::SalidaReclasificacion,
            self::EntradaReclasificacion => true,
            default                      => false,
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
