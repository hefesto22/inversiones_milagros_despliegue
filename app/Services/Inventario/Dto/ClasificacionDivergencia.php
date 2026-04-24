<?php

declare(strict_types=1);

namespace App\Services\Inventario\Dto;

/**
 * Resultado puro de la clasificación de una divergencia WAC vs legacy.
 *
 * Producido por ClasificadorDivergenciaWac::clasificar() y consumido por:
 *   - BackfillWacService          → persistido en wac_backfill_items.clasificacion_divergencia
 *   - ReconciliadorWacService     → loguea con nivel según severidad
 *   - Tests de paridad            → asertan clasificación esperada por fixture
 *
 * Inmutable — representa un snapshot del juicio al momento de clasificar.
 *
 * Las constantes CLASIF_* son la fuente de verdad del vocabulario de
 * clasificación del refactor WAC. BackfillLoteResult las re-exporta por
 * compatibilidad con código existente, pero nuevos consumidores deben
 * referenciarlas desde acá.
 */
final readonly class ClasificacionDivergencia
{
    public const CLASIF_NINGUNA  = 'ninguna';
    public const CLASIF_RUIDO    = 'ruido';
    public const CLASIF_ESPERADA = 'esperada';
    public const CLASIF_ANOMALA  = 'anomala';

    /**
     * @param string  $clasificacion  Uno de CLASIF_*
     * @param ?string $detalle        Explicación numérica legible o null si CLASIF_NINGUNA sin contexto
     */
    public function __construct(
        public string  $clasificacion,
        public ?string $detalle = null,
    ) {}

    public function esRuido(): bool
    {
        return $this->clasificacion === self::CLASIF_RUIDO;
    }

    public function esEsperada(): bool
    {
        return $this->clasificacion === self::CLASIF_ESPERADA;
    }

    public function esAnomala(): bool
    {
        return $this->clasificacion === self::CLASIF_ANOMALA;
    }

    public function esDivergenciaReportable(): bool
    {
        // ninguna y ruido no ameritan alarma — ambas son "ok" desde el punto de
        // vista operativo (ninguna=sin base comparable, ruido=dentro de tolerancia).
        return $this->esEsperada() || $this->esAnomala();
    }
}
