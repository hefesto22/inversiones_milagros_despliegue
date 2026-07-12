<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Motivos por los cuales se aplica un Ajuste de Inventario.
 *
 * Distinto de MermaMotivo (que es para pérdidas físicas reales).
 * Un ajuste captura diferencias entre saldo en sistema y físico real,
 * resultantes de operación cotidiana no documentada en tiempo real.
 */
enum AjusteMotivo: string
{
    case ConteoFisicoDiferencia    = 'conteo_fisico_diferencia';
    case ClasificacionIncorrecta   = 'clasificacion_incorrecta';
    case ReempaqueNoDocumentado    = 'reempaque_no_documentado';
    case ReclasificacionCalidad    = 'reclasificacion_calidad';
    case RoturaNoDocumentada       = 'rotura_no_documentada';
    case CorreccionCapturaErronea  = 'correccion_captura_erronea';
    case Otro                      = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::ConteoFisicoDiferencia   => 'Diferencia por conteo físico',
            self::ClasificacionIncorrecta  => 'Clasificación incorrecta al contar',
            self::ReempaqueNoDocumentado   => 'Reempaque físico no documentado',
            self::ReclasificacionCalidad   => 'Reclasificación por calidad',
            self::RoturaNoDocumentada      => 'Rotura no asentada',
            self::CorreccionCapturaErronea => 'Corrección de captura errónea',
            self::Otro                     => 'Otro',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ConteoFisicoDiferencia   => 'info',
            self::ClasificacionIncorrecta  => 'warning',
            self::ReempaqueNoDocumentado   => 'warning',
            self::ReclasificacionCalidad   => 'primary',
            self::RoturaNoDocumentada      => 'danger',
            self::CorreccionCapturaErronea => 'gray',
            self::Otro                     => 'gray',
        };
    }

    /**
     * Si este motivo es válido para una merma residual (vs reclasificación).
     */
    public function aplicaAMerma(): bool
    {
        return match ($this) {
            self::ConteoFisicoDiferencia,
            self::RoturaNoDocumentada,
            self::Otro                   => true,
            default                      => false,
        };
    }

    /**
     * Si este motivo es válido para una reclasificación entre productos.
     */
    public function aplicaAReclasificacion(): bool
    {
        return match ($this) {
            self::ClasificacionIncorrecta,
            self::ReempaqueNoDocumentado,
            self::ReclasificacionCalidad,
            self::CorreccionCapturaErronea => true,
            default                        => false,
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
