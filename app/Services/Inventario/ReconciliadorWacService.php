<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\Lote;
use App\Services\Inventario\Dto\ClasificacionDivergencia;
use App\Services\Inventario\Dto\ReconciliacionResumen;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fase 4 del refactor WAC Perpetuo — reconciliación nocturna WAC vs legacy.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * PROPÓSITO
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Durante Fase 2-4 convivimos con dual-write: cada operación (compra/venta/merma/
 * devolución) escribe tanto en columnas legacy como en wac_*. Si el listener
 * tiene un bug o se salta un edge case, las columnas pueden divergir en silencio.
 *
 * Este servicio detecta esa divergencia — NO corrige. Solo clasifica y loguea.
 * Ejecutado nocturnamente (vía job + scheduler) alimenta los logs con una señal
 * operativa observable desde Horizon/log aggregator antes de que Fase 5 cambie
 * la fuente de lectura a wac_*.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * DIFERENCIAS CON BackfillWacService
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 *   Aspecto    | Backfill (Fase 3)             | Reconciliador (Fase 4)
 *   -----------|-------------------------------|--------------------------------
 *   Input      | historial_compras_lote        | Columnas ya persistidas
 *   Recálculo  | Sí — FIFO-inverso             | No — lee y compara
 *   Efecto     | Escribe wac_*                 | Solo loguea
 *   Frecuencia | Una vez (o reprocess puntual) | Nightly
 *   Target     | Todos los lotes               | Solo lotes con AMBOS costos
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * KILL-SWITCH
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Respeta `config('inventario.wac.log_divergences')`. Si está apagado, early
 * return con un resumen marcado `deshabilitadoPorFlag=true` — útil para tests
 * de paridad y para que el scheduler no truene si se apagó la Fase 4 vía .env.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * MEMORIA O(1) VÍA cursor()
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * El iterador cursor() de Eloquent hidrata un lote por vez en lugar de cargar
 * toda la colección en memoria. Con 50k+ lotes eventuales en Huevería a 2-3
 * años vista, get() sería O(n) de memoria — cursor() mantiene constante.
 *
 * Se hace un select() explícito de las 7 columnas necesarias (FK a productos/
 * bodegas NO se leen) para minimizar el payload por fila.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * REUTILIZACIÓN DEL CLASIFICADOR
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Usa el mismo ClasificadorDivergenciaWac que consume BackfillWacService. Eso
 * garantiza que las reglas (umbral 15%, tolerancia en Lempiras, signo esperado)
 * NO pueden divergir entre backfill y reconciliación — ambos consultan la única
 * fuente de verdad.
 */
final class ReconciliadorWacService
{
    /** Decimales de redondeo de la diferencia (alineado con persistencia) */
    private const DEC_DIFERENCIA = 4;

    public function __construct(
        private readonly ClasificadorDivergenciaWac $clasificador,
    ) {}

    /**
     * Ejecuta una corrida completa de reconciliación.
     *
     * Retorna siempre un resumen — nunca lanza excepción por falta de datos.
     * Un bug por lote individual no debe tumbar el run completo: se captura
     * implícitamente al clasificar contra valores casteados a float (cualquier
     * cosa no comparable caerá en clase 'ninguna' o 'anomala' con detalle).
     */
    public function reconciliar(): ReconciliacionResumen
    {
        $resumen = new ReconciliacionResumen(
            runUuid:    (string) Str::uuid(),
            iniciadoEn: Carbon::now(),
        );

        if (! $this->logDivergenciasActivo()) {
            $resumen->deshabilitadoPorFlag = true;
            $resumen->finalizar();

            Log::info('Reconciliación WAC omitida — log_divergences está apagado', [
                'run_uuid' => $resumen->runUuid,
            ]);

            return $resumen;
        }

        $tolerancia = (float) config('inventario.wac.divergence_tolerance_lempiras', 0.10);

        // Query intencionalmente restringida a lotes con AMBOS costos inicializados:
        //   - wac_costo_por_huevo IS NOT NULL  → shadow_mode escribió al menos una vez
        //   - costo_por_huevo > 0              → legacy tiene base comparable
        // Lotes que no cumplen ambas condiciones no se reconcilian (ya sea porque el
        // backfill no los alcanzó, o porque son lotes huérfanos sin datos útiles).
        Lote::query()
            ->select([
                'id',
                'wac_costo_por_huevo',
                'wac_costo_por_carton_facturado',
                'costo_por_huevo',
                'costo_por_carton_facturado',
            ])
            ->whereNotNull('wac_costo_por_huevo')
            ->where('costo_por_huevo', '>', 0)
            ->orderBy('id')
            ->cursor()
            ->each(function (Lote $lote) use ($resumen, $tolerancia): void {
                $this->reconciliarLote($lote, $resumen, $tolerancia);
            });

        $resumen->finalizar();

        Log::info('Reconciliación WAC completada', $resumen->toArray());

        return $resumen;
    }

    /**
     * Clasifica un lote individual y, si es reportable, emite log.
     */
    private function reconciliarLote(
        Lote $lote,
        ReconciliacionResumen $resumen,
        float $tolerancia,
    ): void {
        $wacCarton    = (float) ($lote->wac_costo_por_carton_facturado ?? 0);
        $legacyCarton = (float) ($lote->costo_por_carton_facturado ?? 0);
        $diferencia   = round($wacCarton - $legacyCarton, self::DEC_DIFERENCIA);

        $clasif = $this->clasificador->clasificar(
            $wacCarton,
            $legacyCarton,
            $diferencia,
            $tolerancia,
        );

        if ($clasif->esDivergenciaReportable()) {
            $context = [
                'run_uuid'       => $resumen->runUuid,
                'lote_id'        => $lote->id,
                'clasificacion'  => $clasif->clasificacion,
                'wac_carton'     => $wacCarton,
                'legacy_carton'  => $legacyCarton,
                'diferencia_l'   => $diferencia,
                'detalle'        => $clasif->detalle,
            ];

            // Severidad: anomala → warning (dispara alertas), esperada → info (esperado por el bug documentado).
            if ($clasif->esAnomala()) {
                Log::warning('WAC divergencia ANÓMALA detectada', $context);
            } else {
                Log::info('WAC divergencia esperada detectada', $context);
            }
        }

        $resumen->registrar($lote->id, $clasif);
    }

    private function logDivergenciasActivo(): bool
    {
        return (bool) config('inventario.wac.log_divergences', false);
    }
}
