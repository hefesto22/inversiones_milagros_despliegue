<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\HistorialCompraLote;
use App\Models\Lote;
use App\Services\Inventario\Dto\BackfillLoteResult;
use App\Services\Inventario\Dto\BackfillRunSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fase 3 del refactor WAC Perpetuo — lógica de backfill del costo promedio ponderado.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * ESTRATEGIA — "agregados + reconciliación con saldo", NO replay de salidas
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Por la invariante matemática del WAC Perpetuo (las salidas NO modifican el
 * costo unitario), el wac_costo_por_huevo final de un lote es matemáticamente
 * equivalente a:
 *
 *    Σ(costo_compra_i) / Σ(huevos_facturados_compra_i)
 *
 * sobre las compras "activas" del ciclo actual del lote — sin importar qué
 * ventas o mermas se intercalaron entre ellas.
 *
 * Esto evita tener que reproducir los movimientos de salida, que en Huevería
 * viven dispersos en venta_detalles, viaje_venta_detalles, mermas,
 * reempaque_lotes y devolucion_detalles — varios de ellos sin `lote_id`
 * directo (el vínculo pasa por bodega/reempaque/viaje).
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * IDENTIFICACIÓN DEL CICLO ACTIVO DEL LOTE
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Lote::resetearParaNuevaCompra() zeroa huevos_facturados_acumulados y
 * costo_total_acumulado cuando un lote se agota. historial_compras_lote, en
 * cambio, conserva el histórico completo.
 *
 * Para identificar las compras del ciclo activo (las que quedaron "vivas"
 * después del último reset), se recorren las compras en orden DESC y se
 * acumula huevos_facturados hasta que el acumulado coincide con
 * lote.huevos_facturados_acumulados (con tolerancia 0.01 huevos).
 *
 * Si recorriendo todas no se alcanza el total esperado, se reporta inconsistencia.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * CÁLCULO DEL ESTADO WAC
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 *   wac_costo_por_huevo            = Σcosto / Σhuevos_facturados  (compras activas)
 *   wac_huevos_inventario          = lote.getHuevosFacturadosDisponibles()
 *                                    (remanente - buffer_regalo_disponible)
 *   wac_costo_inventario           = wac_huevos_inventario × wac_costo_por_huevo
 *   wac_costo_por_carton_facturado = wac_costo_por_huevo × huevos_por_carton
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * CLASIFICACIÓN DE DIVERGENCIA vs COSTO LEGACY
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Referencia: docs/AUDITORIA_VALUACION_2026-04-22.md documenta que el bug
 * legacy SOBREVALORA (ej: reporta 78.15 cuando compras reales son 70–75).
 *
 *   - ninguna  : lote sin compras activas / saldo en 0 / no se evalúa divergencia
 *   - ruido    : |diferencia_por_carton| ≤ tolerancia (redondeo)
 *   - esperada : WAC < legacy con diferencia hasta 15% del legacy
 *                (rango del bug documentado)
 *   - anomala  : WAC > legacy (el refactor NO debería subir el costo) O
 *                diferencia > 15% del legacy (fuera de patrón conocido)
 *
 * Divergencias anómalas requieren --force del operador para aplicarse.
 */
final class BackfillWacService
{
    /** Tolerancia de reconciliación de huevos facturados (en huevos) */
    private const TOLERANCIA_HUEVOS_RECONCILIACION = 0.01;

    /** Umbral de divergencia esperada: hasta 15% del costo legacy */
    private const UMBRAL_ESPERADA_PCT = 0.15;

    /** Decimales de persistencia (consistentes con WacService) */
    private const DEC_COSTO_UNIT     = 6;
    private const DEC_COSTO_TOTAL    = 4;
    private const DEC_COSTO_CARTON   = 4;
    private const DEC_HUEVOS         = 4;

    // =================================================================
    // API PÚBLICA
    // =================================================================

    /**
     * Calcula el backfill para un lote individual. Función pura — NO escribe BD.
     *
     * @param  float  $toleranciaDivergenciaLempiras  Umbral de "ruido" en Lempiras por cartón
     */
    public function calcularLote(Lote $lote, float $toleranciaDivergenciaLempiras): BackfillLoteResult
    {
        try {
            $huevosPorCarton = (int) ($lote->huevos_por_carton ?? 30);

            // Cargar compras en orden cronológico DESC (más reciente primero)
            // para identificar el ciclo activo post-reset.
            $compras = HistorialCompraLote::query()
                ->where('lote_id', $lote->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get([
                    'id',
                    'cartones_facturados',
                    'costo_compra',
                    'created_at',
                ]);

            if ($compras->isEmpty()) {
                return BackfillLoteResult::saltado(
                    $lote->id,
                    'sin compras en historial_compras_lote'
                );
            }

            // Identificar compras del ciclo activo
            $activas = $this->identificarComprasActivas(
                $lote,
                $compras,
                $huevosPorCarton
            );

            if ($activas === null) {
                // No se pudo reconciliar — reportar anomalía con detalle
                return $this->buildResultInconsistencia($lote, $compras, $huevosPorCarton);
            }

            if ($activas->isEmpty()) {
                return BackfillLoteResult::saltado(
                    $lote->id,
                    'lote sin huevos facturados acumulados (posiblemente reseteado sin nueva compra)'
                );
            }

            // Σcosto / Σhuevos_facturados de las compras activas
            $sumaHuevosFacturados = 0.0;
            $sumaCosto            = 0.0;
            foreach ($activas as $c) {
                $sumaHuevosFacturados += ((float) $c->cartones_facturados) * $huevosPorCarton;
                $sumaCosto            += (float) $c->costo_compra;
            }

            $wacCostoPorHuevo = $sumaHuevosFacturados > 0
                ? round($sumaCosto / $sumaHuevosFacturados, self::DEC_COSTO_UNIT)
                : 0.0;

            // Saldo actual (huevos con costo en stock)
            $wacHuevosInventario = round(
                $this->obtenerHuevosFacturadosDisponibles($lote),
                self::DEC_HUEVOS
            );

            // wacCostoInventario se calcula como proporción directa del saldo sobre
            // el total facturado — NO como wacHuevosInventario × wacCostoPorHuevo —
            // para evitar el ruido de redondeo que introduce el costo unitario
            // (ej. 7000/3000 redondeado a 6 decimales = 2.333333, y 3000×2.333333 = 6999.999).
            // La división/multiplicación directa preserva el costo exacto del saldo.
            $wacCostoInventario = $sumaHuevosFacturados > 0
                ? round(
                    ($wacHuevosInventario / $sumaHuevosFacturados) * $sumaCosto,
                    self::DEC_COSTO_TOTAL
                )
                : 0.0;

            $wacCostoPorCartonFacturado = round(
                $wacCostoPorHuevo * $huevosPorCarton,
                self::DEC_COSTO_CARTON
            );

            // Clasificación de divergencia
            $costoPorHuevoLegacy  = (float) ($lote->costo_por_huevo ?? 0);
            $costoPorCartonLegacy = (float) ($lote->costo_por_carton_facturado ?? 0);
            $diferenciaCarton     = round(
                $wacCostoPorCartonFacturado - $costoPorCartonLegacy,
                self::DEC_COSTO_CARTON
            );

            [$clasificacion, $detalle] = $this->clasificarDivergencia(
                $wacCostoPorCartonFacturado,
                $costoPorCartonLegacy,
                $diferenciaCarton,
                $toleranciaDivergenciaLempiras,
            );

            return new BackfillLoteResult(
                loteId:                     $lote->id,
                estado:                     BackfillLoteResult::ESTADO_PROCESADO,
                motivoSalto:                null,
                clasificacionDivergencia:   $clasificacion,
                detalleDivergencia:         $detalle,
                wacCostoInventario:         $wacCostoInventario,
                wacHuevosInventario:        $wacHuevosInventario,
                wacCostoPorHuevo:           $wacCostoPorHuevo,
                wacCostoPorCartonFacturado: $wacCostoPorCartonFacturado,
                costoPorHuevoLegacy:        $costoPorHuevoLegacy,
                costoPorCartonLegacy:       $costoPorCartonLegacy,
                diferenciaPorCarton:        $diferenciaCarton,
                comprasConsideradas:        $activas->count(),
            );
        } catch (Throwable $e) {
            return BackfillLoteResult::fallido(
                $lote->id,
                sprintf('%s: %s (línea %d)', get_class($e), $e->getMessage(), $e->getLine())
            );
        }
    }

    /**
     * Aplica el resultado calculado al lote. Escribe columnas wac_* dentro de
     * una transacción corta con lock pesimista.
     */
    public function aplicarResultado(Lote $lote, BackfillLoteResult $result): void
    {
        if (! $result->fueProcesado()) {
            return;
        }

        DB::transaction(function () use ($lote, $result): void {
            // Lock + refresh defensivo — el backfill debería correr con shadow_mode
            // apagado, pero este lock protege contra operaciones concurrentes
            // manuales desde tinker u otras sesiones.
            Lote::where('id', $lote->id)->lockForUpdate()->first();
            $lote->refresh();

            $lote->wac_costo_inventario            = $result->wacCostoInventario;
            $lote->wac_huevos_inventario           = $result->wacHuevosInventario;
            $lote->wac_costo_por_huevo             = $result->wacCostoPorHuevo;
            $lote->wac_costo_por_carton_facturado  = $result->wacCostoPorCartonFacturado;
            $lote->wac_ultima_actualizacion        = Carbon::now();
            $lote->wac_motivo_ultima_actualizacion = 'backfill';
            $lote->save();
        });
    }

    /**
     * Resetea las columnas wac_* de un lote a null (para rollback de un run fallido).
     */
    public function resetearWac(Lote $lote): void
    {
        DB::transaction(function () use ($lote): void {
            Lote::where('id', $lote->id)->lockForUpdate()->first();
            $lote->refresh();

            $lote->wac_costo_inventario            = null;
            $lote->wac_huevos_inventario           = null;
            $lote->wac_costo_por_huevo             = null;
            $lote->wac_costo_por_carton_facturado  = null;
            $lote->wac_ultima_actualizacion        = null;
            $lote->wac_motivo_ultima_actualizacion = null;
            $lote->save();
        });
    }

    /**
     * Construye el query base de lotes a procesar según filtros.
     */
    public function queryLotes(?int $bodegaId, ?int $productoId): Builder
    {
        return Lote::query()
            ->when($bodegaId, fn($q) => $q->where('bodega_id', $bodegaId))
            ->when($productoId, fn($q) => $q->where('producto_id', $productoId))
            ->orderBy('id');
    }

    /**
     * Genera un UUID estable para un run.
     */
    public function nuevoRunUuid(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Construye un summary vacío para iniciar un run.
     */
    public function nuevoSummary(
        string $runUuid,
        string $modo,
        ?int $bodegaId,
        ?int $productoId,
        bool $force,
    ): BackfillRunSummary {
        return new BackfillRunSummary(
            runUuid:          $runUuid,
            modo:             $modo,
            bodegaIdFiltro:   $bodegaId,
            productoIdFiltro: $productoId,
            forceAplicado:    $force,
        );
    }

    // =================================================================
    // LÓGICA PRIVADA
    // =================================================================

    /**
     * Identifica las compras del ciclo activo del lote.
     *
     * Algoritmo: acumular huevos facturados desde la compra más reciente
     * hacia atrás hasta alcanzar lote.huevos_facturados_acumulados con
     * tolerancia de 0.01 huevos. Si no se logra reconciliar, retorna null
     * (caller reporta inconsistencia).
     *
     * @param  Collection<int, HistorialCompraLote>  $comprasDesc
     * @return Collection<int, HistorialCompraLote>|null
     */
    private function identificarComprasActivas(
        Lote $lote,
        Collection $comprasDesc,
        int $huevosPorCarton,
    ): ?Collection {
        $objetivo = (float) ($lote->huevos_facturados_acumulados ?? 0);

        if ($objetivo <= 0) {
            return collect();
        }

        $acumulado = 0.0;
        $activas   = collect();

        foreach ($comprasDesc as $compra) {
            $huevosEstaCompra = ((float) $compra->cartones_facturados) * $huevosPorCarton;
            $acumulado       += $huevosEstaCompra;
            $activas->push($compra);

            if (abs($acumulado - $objetivo) <= self::TOLERANCIA_HUEVOS_RECONCILIACION) {
                return $activas;
            }

            if ($acumulado > $objetivo + self::TOLERANCIA_HUEVOS_RECONCILIACION) {
                // Se pasó del objetivo — inconsistencia entre historial y agregado del lote.
                return null;
            }
        }

        // Se consumieron todas las compras y no se alcanzó el objetivo.
        if (abs($acumulado - $objetivo) <= self::TOLERANCIA_HUEVOS_RECONCILIACION) {
            return $activas;
        }

        return null;
    }

    /**
     * Clasifica la divergencia WAC vs legacy y construye un detalle legible.
     *
     * @return array{0: string, 1: ?string}  [clasificación, detalle]
     */
    private function clasificarDivergencia(
        float $wacCarton,
        float $legacyCarton,
        float $diferencia,
        float $tolerancia,
    ): array {
        if ($legacyCarton <= 0) {
            // Legacy en 0 o negativo — no hay base contra la cual comparar.
            if (abs($wacCarton) <= $tolerancia) {
                return [BackfillLoteResult::CLASIF_NINGUNA, null];
            }

            return [
                BackfillLoteResult::CLASIF_ANOMALA,
                "Legacy costo_por_carton_facturado <= 0 (valor: {$legacyCarton}) pero WAC calculado = {$wacCarton}",
            ];
        }

        if (abs($diferencia) <= $tolerancia) {
            return [
                BackfillLoteResult::CLASIF_RUIDO,
                sprintf(
                    'Diferencia %.4f L/cartón dentro de tolerancia %.4f L (ruido de redondeo)',
                    $diferencia,
                    $tolerancia
                ),
            ];
        }

        $porcentajeDesvio = abs($diferencia) / $legacyCarton;

        // Patrón esperado: WAC < legacy (bug legacy sobrevalora) con desvío ≤ 15%
        if ($diferencia < 0 && $porcentajeDesvio <= self::UMBRAL_ESPERADA_PCT) {
            return [
                BackfillLoteResult::CLASIF_ESPERADA,
                sprintf(
                    'WAC %.4f < legacy %.4f (diferencia %.4f L/cartón, %.2f%%). '
                    . 'Consistente con bug de sobrevaluación documentado en AUDITORIA_VALUACION_2026-04-22.md',
                    $wacCarton,
                    $legacyCarton,
                    $diferencia,
                    $porcentajeDesvio * 100
                ),
            ];
        }

        // Todo lo demás es anomalía: WAC > legacy (refactor no debería subir el costo),
        // o diferencia > 15% (fuera de patrón conocido)
        $razon = $diferencia > 0
            ? 'WAC > legacy — el refactor no debería inflar el costo'
            : sprintf('Desvío %.2f%% excede umbral esperado %.2f%%', $porcentajeDesvio * 100, self::UMBRAL_ESPERADA_PCT * 100);

        return [
            BackfillLoteResult::CLASIF_ANOMALA,
            sprintf(
                'WAC %.4f vs legacy %.4f (diferencia %.4f L/cartón, %.2f%%). %s',
                $wacCarton,
                $legacyCarton,
                $diferencia,
                $porcentajeDesvio * 100,
                $razon
            ),
        ];
    }

    /**
     * Construye un resultado "anomala" cuando no se puede reconciliar el
     * historial de compras con el agregado actual del lote.
     *
     * @param Collection<int, HistorialCompraLote> $compras
     */
    private function buildResultInconsistencia(Lote $lote, Collection $compras, int $huevosPorCarton): BackfillLoteResult
    {
        $totalEnHistorial = $compras->sum(fn($c) => ((float) $c->cartones_facturados) * $huevosPorCarton);
        $objetivo         = (float) ($lote->huevos_facturados_acumulados ?? 0);
        $costoPorHuevoLegacy  = (float) ($lote->costo_por_huevo ?? 0);
        $costoPorCartonLegacy = (float) ($lote->costo_por_carton_facturado ?? 0);

        $detalle = sprintf(
            'Inconsistencia reconciliando historial_compras_lote (%s huevos totales) con '
            . 'lote.huevos_facturados_acumulados (%s). Posibles causas: filas borradas del '
            . 'historial, ajustes manuales al lote, o huevos_por_carton cambiado retroactivamente.',
            number_format($totalEnHistorial, 4, '.', ''),
            number_format($objetivo, 4, '.', ''),
        );

        return new BackfillLoteResult(
            loteId:                     $lote->id,
            estado:                     BackfillLoteResult::ESTADO_PROCESADO,
            motivoSalto:                null,
            clasificacionDivergencia:   BackfillLoteResult::CLASIF_ANOMALA,
            detalleDivergencia:         $detalle,
            wacCostoInventario:         null,
            wacHuevosInventario:        null,
            wacCostoPorHuevo:           null,
            wacCostoPorCartonFacturado: null,
            costoPorHuevoLegacy:        $costoPorHuevoLegacy,
            costoPorCartonLegacy:       $costoPorCartonLegacy,
            diferenciaPorCarton:        null,
            comprasConsideradas:        $compras->count(),
        );
    }

    /**
     * Obtiene los huevos facturados disponibles del lote (remanente - buffer de regalo).
     *
     * Se calcula localmente en vez de llamar al método del modelo para evitar
     * dependencia indirecta en tests y mantener la función pura.
     */
    private function obtenerHuevosFacturadosDisponibles(Lote $lote): float
    {
        $remanente        = (float) ($lote->cantidad_huevos_remanente ?? 0);
        $regaloTotal      = (float) ($lote->huevos_regalo_acumulados ?? 0);
        $mermaTotal       = (float) ($lote->merma_total_acumulada ?? 0);
        $regaloConsumido  = (float) ($lote->huevos_regalo_consumidos ?? 0);

        $bufferRegalo = max(0.0, $regaloTotal - $mermaTotal - $regaloConsumido);

        return max(0.0, $remanente - $bufferRegalo);
    }
}
