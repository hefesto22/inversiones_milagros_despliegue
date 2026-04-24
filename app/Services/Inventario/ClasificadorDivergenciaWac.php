<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Services\Inventario\Dto\ClasificacionDivergencia;

/**
 * Servicio puro (stateless) que clasifica divergencias entre el costo
 * WAC calculado y el costo legacy persistido, bajo las reglas del refactor
 * WAC Perpetuo (docs/AUDITORIA_VALUACION_2026-04-22.md).
 *
 * Extracción:
 *   La lógica vivía como método privado en BackfillWacService. Con la Fase 4
 *   (ReconciliadorWacService) y los parity tests (Fase 5) aparecen segundos
 *   y terceros consumidores, lo que volvía la duplicación una fuente latente
 *   de divergencia entre los propios clasificadores. Este servicio es ahora
 *   la fuente única de verdad de las reglas.
 *
 * Reglas de clasificación:
 *   - ninguna   → costo legacy <= 0 y |WAC| dentro de tolerancia
 *                 (no hay base comparable y WAC tampoco reporta valor).
 *   - ruido     → |diferencia| ≤ tolerancia en Lempiras por cartón
 *                 (redondeo esperado, no alarma operativa).
 *   - esperada  → WAC < legacy con desvío ≤ 15% del legacy
 *                 (patrón del bug de sobrevaluación documentado en auditoría).
 *   - anomala   → WAC > legacy (refactor no debería inflar el costo) O
 *                 desvío > 15% O legacy <= 0 con WAC con valor.
 *                 Requiere atención manual — bloquea backfill sin --force,
 *                 dispara log de alarma en reconciliador.
 *
 * La clase es intencionalmente sin estado mutable — `clasificar()` es una
 * función pura. Inyectable vía container como singleton.
 */
final class ClasificadorDivergenciaWac
{
    /**
     * Umbral del desvío "esperado" contra el costo legacy, como fracción.
     *
     * Basado en el rango documentado del bug legacy (sobrevaluación típica
     * entre 5%-12% según AUDITORIA_VALUACION_2026-04-22.md). El 15% da
     * margen al ruido dentro del cual sabemos que el bug puede producir
     * desvíos legítimos, sin confundirlos con anomalías que ameritan
     * investigación manual.
     */
    public const UMBRAL_ESPERADA_PCT = 0.15;

    /** Decimales de presentación para el detalle legible */
    private const DEC_DETALLE = 4;

    /**
     * Clasifica una divergencia WAC vs legacy.
     *
     * @param  float  $wacCartonFacturado     wac_costo_por_carton_facturado calculado o persistido
     * @param  float  $legacyCartonFacturado  costo_por_carton_facturado legacy del lote
     * @param  float  $diferenciaCarton       wacCartonFacturado - legacyCartonFacturado (signo importa)
     * @param  float  $toleranciaLempiras     Umbral absoluto en Lempiras/cartón (ej: 0.10)
     */
    public function clasificar(
        float $wacCartonFacturado,
        float $legacyCartonFacturado,
        float $diferenciaCarton,
        float $toleranciaLempiras,
    ): ClasificacionDivergencia {
        // Caso borde: legacy sin valor comparable.
        if ($legacyCartonFacturado <= 0) {
            if (abs($wacCartonFacturado) <= $toleranciaLempiras) {
                return new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_NINGUNA);
            }

            return new ClasificacionDivergencia(
                ClasificacionDivergencia::CLASIF_ANOMALA,
                sprintf(
                    'Legacy costo_por_carton_facturado <= 0 (valor: %.' . self::DEC_DETALLE . 'f) '
                    . 'pero WAC calculado = %.' . self::DEC_DETALLE . 'f',
                    $legacyCartonFacturado,
                    $wacCartonFacturado,
                ),
            );
        }

        // Ruido de redondeo dentro de tolerancia.
        if (abs($diferenciaCarton) <= $toleranciaLempiras) {
            return new ClasificacionDivergencia(
                ClasificacionDivergencia::CLASIF_RUIDO,
                sprintf(
                    'Diferencia %.' . self::DEC_DETALLE . 'f L/cartón dentro de tolerancia %.' . self::DEC_DETALLE . 'f L (ruido de redondeo)',
                    $diferenciaCarton,
                    $toleranciaLempiras,
                ),
            );
        }

        $porcentajeDesvio = abs($diferenciaCarton) / $legacyCartonFacturado;

        // Patrón esperado del bug legacy: WAC < legacy con desvío ≤ 15%.
        if ($diferenciaCarton < 0 && $porcentajeDesvio <= self::UMBRAL_ESPERADA_PCT) {
            return new ClasificacionDivergencia(
                ClasificacionDivergencia::CLASIF_ESPERADA,
                sprintf(
                    'WAC %.' . self::DEC_DETALLE . 'f < legacy %.' . self::DEC_DETALLE . 'f '
                    . '(diferencia %.' . self::DEC_DETALLE . 'f L/cartón, %.2f%%). '
                    . 'Consistente con bug de sobrevaluación documentado en AUDITORIA_VALUACION_2026-04-22.md',
                    $wacCartonFacturado,
                    $legacyCartonFacturado,
                    $diferenciaCarton,
                    $porcentajeDesvio * 100,
                ),
            );
        }

        // Todo lo demás es anomalía.
        $razon = $diferenciaCarton > 0
            ? 'WAC > legacy — el refactor no debería inflar el costo'
            : sprintf(
                'Desvío %.2f%% excede umbral esperado %.2f%%',
                $porcentajeDesvio * 100,
                self::UMBRAL_ESPERADA_PCT * 100,
            );

        return new ClasificacionDivergencia(
            ClasificacionDivergencia::CLASIF_ANOMALA,
            sprintf(
                'WAC %.' . self::DEC_DETALLE . 'f vs legacy %.' . self::DEC_DETALLE . 'f '
                . '(diferencia %.' . self::DEC_DETALLE . 'f L/cartón, %.2f%%). %s',
                $wacCartonFacturado,
                $legacyCartonFacturado,
                $diferenciaCarton,
                $porcentajeDesvio * 100,
                $razon,
            ),
        );
    }
}
