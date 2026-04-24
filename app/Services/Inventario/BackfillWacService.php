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
 * Fase 3 del refactor WAC Perpetuo — backfill del costo del inventario que QUEDA en stock.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * ESTRATEGIA — FIFO-inverso sobre el remanente
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * En Huevería el producto rota físicamente en FIFO (los huevos más viejos se
 * venden primero). Por lo tanto, el remanente en stock de un lote corresponde,
 * en la práctica, a las compras MÁS RECIENTES. Ese es el principio detrás del
 * algoritmo:
 *
 *   1. Partimos del objetivo: huevos facturados disponibles en el lote
 *      (remanente - buffer_regalo_disponible).
 *   2. Recorremos historial_compras_lote en orden DESC (compra más reciente
 *      primero), acumulando huevos facturados y costo.
 *   3. Cuando una compra cruza el boundary del objetivo, la prorrateamos para
 *      tomar solo la porción necesaria.
 *   4. El WAC del remanente es Σcosto_usado / Σhuevos_usados sobre las compras
 *      recorridas (no sobre TODAS las compras del ciclo).
 *
 * Esto corrige el bug que Mauricio señaló: compras viejas a precio alto que
 * YA SE VENDIERON no deben seguir inflando el costo del inventario actual.
 *
 * Ejemplo concreto (compras en orden cronológico):
 *   • Compra 1: 1000 cartones × 85 L = 85,000 L (vieja, ya vendida)
 *   • Compra 2:  500 cartones × 75 L = 37,500 L
 *   • Compra 3:  500 cartones × 70 L = 35,000 L (reciente — lo que queda en stock)
 *
 *   Promedio global  (Σcosto/Σhuevos): (157,500 / 60,000) × 30 ≈ 78.75 L/cartón
 *   FIFO-inverso sobre remanente 500: 35,000 / 15,000 × 30    = 70.00 L/cartón ✓
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * MERMAS — por qué NO se restan del cálculo
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Las mermas (perdida_real > 0) ya están reflejadas en
 * cantidad_huevos_remanente → el objetivo ya viene "post-merma". Conceptualmente
 * las mermas "se pagaron" con las compras más viejas (mismo principio FIFO), por
 * lo que no añadimos costo extra al WAC del remanente. El walk DESC solo cubre
 * huevos que físicamente siguen en stock.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * CÁLCULO DEL ESTADO WAC
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 *   target                         = remanente - buffer_regalo_disponible
 *   (huevos_usados, costo_usado)   = walk DESC sobre historial_compras_lote
 *                                    hasta acumular target huevos facturados
 *   wac_costo_por_huevo            = costo_usado / huevos_usados
 *   wac_huevos_inventario          = huevos_usados (= target dentro de tolerancia)
 *   wac_costo_inventario           = costo_usado
 *   wac_costo_por_carton_facturado = wac_costo_por_huevo × huevos_por_carton
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * CICLO ACTIVO DEL LOTE (resetearParaNuevaCompra)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Lote::resetearParaNuevaCompra() zeroa huevos_facturados_acumulados y
 * costo_total_acumulado cuando un lote se agota, pero historial_compras_lote
 * conserva el histórico completo. El walk FIFO-inverso cubre este caso
 * naturalmente: como solo acumula hasta cubrir el remanente actual, nunca
 * alcanza compras anteriores al último reset (el remanente post-reset refleja
 * solo compras posteriores al reset).
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * LOTES AGOTADOS — SEMILLA LEGACY (buildResultAgotado)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Un lote sin saldo facturado disponible (remanente=0 o 100% buffer regalo)
 * NO se salta — se procesa con semilla desde costo_por_huevo legacy:
 *   wac_costo_inventario          = 0.0 (no hay stock)
 *   wac_huevos_inventario         = 0.0 (no hay stock)
 *   wac_costo_por_huevo           = legacy.costo_por_huevo (SEMILLA preservada)
 *   wac_motivo_ultima_actualizacion = 'backfill_agotado'
 *
 * Razón: un lote agotado puede reactivarse por (a) reversión parcial de
 * reempaque, (b) devolución de cliente, (c) nueva compra. Los casos (a) y (b)
 * disparan Lote::devolverHuevos que invoca WacService::aplicarDevolucion, la
 * cual REQUIERE wac_costo_por_huevo > 0 para valorar la entrada. Si dejáramos
 * el lote con wac_*=NULL, la devolución quedaría silenciosamente omitida y
 * generaría inconsistencia de costos.
 *
 * Lotes agotados sin legacy.costo_por_huevo (<=0) se saltan con motivo
 * explícito — son lotes huérfanos sin datos útiles para sembrar.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * INCONSISTENCIA
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Si al agotar todas las compras el acumulado de huevos no cubre el target,
 * estamos ante un lote con datos anómalos (filas borradas del historial,
 * ajustes manuales a remanente/buffer, o huevos_por_carton cambiado
 * retroactivamente). Se reporta como divergencia anómala sin valores WAC.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * CLASIFICACIÓN DE DIVERGENCIA vs COSTO LEGACY
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Referencia: docs/AUDITORIA_VALUACION_2026-04-22.md documenta que el bug
 * legacy SOBREVALORA. El FIFO-inverso, en un mercado con precios de compra
 * tendencialmente estables o a la baja (patrón real del dominio), produce
 * WAC ≤ legacy en la mayoría de los casos.
 *
 *   - ninguna  : lote sin saldo facturado / no se evalúa divergencia
 *   - ruido    : |diferencia_por_carton| ≤ tolerancia (redondeo)
 *   - esperada : WAC < legacy con diferencia hasta 15% del legacy
 *                (rango del bug documentado)
 *   - anomala  : WAC > legacy (en dominio con precios estables o a la baja
 *                esto es fuera de patrón) O diferencia > 15% del legacy
 *
 * Divergencias anómalas requieren --force del operador para aplicarse.
 */
final class BackfillWacService
{
    /** Tolerancia de matching FIFO-inverso contra el remanente (en huevos) */
    private const TOLERANCIA_HUEVOS_FIFO = 0.01;

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

            // Cargar compras en orden cronológico DESC (más reciente primero).
            // El walk FIFO-inverso parte de la compra más reciente hacia atrás.
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

            // Objetivo del walk: huevos facturados que siguen físicamente en stock.
            // Si no hay saldo facturado (lote agotado o 100% regalo), no hay inventario
            // físico que valuar — pero SÍ debemos sembrar wac_costo_por_huevo con el
            // costo legacy para que devoluciones posteriores puedan valorarse.
            $target = $this->obtenerHuevosFacturadosDisponibles($lote);

            if ($target <= self::TOLERANCIA_HUEVOS_FIFO) {
                return $this->buildResultAgotado($lote, $huevosPorCarton);
            }

            // FIFO-inverso: walk DESC acumulando huevos hasta cubrir el target.
            $walk = $this->calcularFifoInversoSobreRemanente(
                $compras,
                $target,
                $huevosPorCarton
            );

            if ($walk === null) {
                // Historial no cubre el remanente — datos anómalos
                return $this->buildResultInconsistencia($lote, $compras, $huevosPorCarton, $target);
            }

            $sumaHuevosUsados = $walk['total_huevos'];
            $sumaCostoUsado   = $walk['total_costo'];

            $wacCostoPorHuevo = $sumaHuevosUsados > 0
                ? round($sumaCostoUsado / $sumaHuevosUsados, self::DEC_COSTO_UNIT)
                : 0.0;

            // wacHuevosInventario = huevos efectivamente cubiertos por el walk
            // (≈ target dentro de tolerancia, pero usamos el acumulado real del
            // walk para preservar consistencia exacta con wacCostoInventario).
            $wacHuevosInventario = round($sumaHuevosUsados, self::DEC_HUEVOS);

            // wacCostoInventario = costo exacto acumulado en el walk
            // (sin pasar por costoPorHuevo redondeado — evita ruido de redondeo
            // tipo 7000/3000 → 2.333333 × 3000 = 6999.999).
            $wacCostoInventario = round($sumaCostoUsado, self::DEC_COSTO_TOTAL);

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
                comprasConsideradas:        $walk['count'],
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
            // Motivo viene del DTO: 'backfill' para FIFO-inverso estándar,
            // 'backfill_agotado' cuando se sembró con costo legacy sobre un lote sin stock.
            $lote->wac_motivo_ultima_actualizacion = $result->motivoPersistencia;
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
     * Walk FIFO-inverso sobre historial_compras_lote: acumula huevos desde la
     * compra más reciente hacia atrás hasta cubrir $target. Si una compra
     * cruza el boundary, se prorratea para tomar solo la porción necesaria.
     *
     * Retorna agregados {total_huevos, total_costo, count} listos para
     * calcular el WAC, o null si las compras no alcanzan a cubrir el target
     * (inconsistencia con el remanente).
     *
     * Complejidad: O(n) sobre el número de compras del lote. En Huevería
     * un lote tiene < 20 compras típicamente, por lo que no hay problema
     * de escalado — y el caller ya paginó lotes en queryLotes().
     *
     * @param  Collection<int, HistorialCompraLote>  $comprasDesc  Orden DESC por created_at
     * @param  float                                  $target       Huevos facturados a valuar
     * @param  int                                    $huevosPorCarton
     * @return array{total_huevos: float, total_costo: float, count: int}|null
     */
    private function calcularFifoInversoSobreRemanente(
        Collection $comprasDesc,
        float $target,
        int $huevosPorCarton,
    ): ?array {
        $acumuladoHuevos = 0.0;
        $acumuladoCosto  = 0.0;
        $count           = 0;

        foreach ($comprasDesc as $compra) {
            $huevosEsta = ((float) $compra->cartones_facturados) * $huevosPorCarton;

            // Compra de solo regalo (0 cartones facturados) no contribuye al WAC.
            if ($huevosEsta <= 0.0) {
                continue;
            }

            $faltante = $target - $acumuladoHuevos;

            // Objetivo ya cubierto — terminamos (no tocamos compras más viejas).
            if ($faltante <= self::TOLERANCIA_HUEVOS_FIFO) {
                break;
            }

            $costoEsta = (float) $compra->costo_compra;

            if ($huevosEsta <= $faltante + self::TOLERANCIA_HUEVOS_FIFO) {
                // La compra completa cabe dentro del faltante — se toma íntegra.
                $acumuladoHuevos += $huevosEsta;
                $acumuladoCosto  += $costoEsta;
            } else {
                // Cruza el boundary — prorrateo por ratio.
                // Ejemplo: faltante=1500, huevosEsta=3000, costoEsta=7000
                //   ratio = 0.5 → huevos_usados=1500, costo_usado=3500
                $ratio = $faltante / $huevosEsta;
                $acumuladoHuevos += $faltante;
                $acumuladoCosto  += $costoEsta * $ratio;
            }

            $count++;
        }

        // Si agotamos todas las compras y no cubrimos el target, hay inconsistencia.
        if (abs($acumuladoHuevos - $target) > self::TOLERANCIA_HUEVOS_FIFO) {
            return null;
        }

        return [
            'total_huevos' => $acumuladoHuevos,
            'total_costo'  => $acumuladoCosto,
            'count'        => $count,
        ];
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
     * Construye un resultado "procesado" para un lote agotado (remanente=0 o
     * 100% buffer de regalo). Siembra wac_costo_por_huevo con el costo legacy
     * para que devoluciones posteriores (reversión de reempaque, devolución
     * de cliente) puedan valorarse correctamente al reactivar el lote.
     *
     * Diseño:
     *   - wac_costo_inventario         = 0.0 (no hay stock físico)
     *   - wac_huevos_inventario        = 0.0 (no hay stock físico)
     *   - wac_costo_por_huevo          = legacy.costo_por_huevo (SEMILLA)
     *   - wac_costo_por_carton         = semilla × huevos_por_carton (consistencia)
     *   - motivoPersistencia           = 'backfill_agotado' (trazable en auditoría)
     *   - clasificacion                = ninguna (no hay base legacy contra la cual comparar)
     *
     * Caso borde — lote agotado sin costo legacy (<=0):
     *   Típicamente implica un lote huérfano sin datos útiles (ej: lote 100%
     *   regalo, lote con compras de 0 cartones facturados, lote creado
     *   manualmente). Se salta con motivo explícito — una eventual devolución
     *   sobre este lote quedará correctamente como "omitido" en el listener
     *   WAC, sin sembrar valores incorrectos.
     */
    private function buildResultAgotado(Lote $lote, int $huevosPorCarton): BackfillLoteResult
    {
        $costoPorHuevoLegacy  = (float) ($lote->costo_por_huevo ?? 0);
        $costoPorCartonLegacy = (float) ($lote->costo_por_carton_facturado ?? 0);

        if ($costoPorHuevoLegacy <= 0) {
            return BackfillLoteResult::saltado(
                $lote->id,
                'lote agotado sin costo_por_huevo legacy (datos insuficientes para sembrar WAC)'
            );
        }

        $semillaCostoPorCarton = round(
            $costoPorHuevoLegacy * $huevosPorCarton,
            self::DEC_COSTO_CARTON
        );

        return new BackfillLoteResult(
            loteId:                     $lote->id,
            estado:                     BackfillLoteResult::ESTADO_PROCESADO,
            motivoSalto:                null,
            clasificacionDivergencia:   BackfillLoteResult::CLASIF_NINGUNA,
            detalleDivergencia:         sprintf(
                'Lote agotado — se siembra wac_costo_por_huevo con el costo legacy '
                . '(%.6f L/huevo, %.4f L/cartón) para habilitar devoluciones posteriores.',
                $costoPorHuevoLegacy,
                $semillaCostoPorCarton
            ),
            wacCostoInventario:         0.0,
            wacHuevosInventario:        0.0,
            wacCostoPorHuevo:           round($costoPorHuevoLegacy, self::DEC_COSTO_UNIT),
            wacCostoPorCartonFacturado: $semillaCostoPorCarton,
            costoPorHuevoLegacy:        $costoPorHuevoLegacy,
            costoPorCartonLegacy:       $costoPorCartonLegacy,
            diferenciaPorCarton:        0.0,
            comprasConsideradas:        0, // No se recorrió historial, solo se usó semilla legacy
            errorMensaje:               null,
            motivoPersistencia:         'backfill_agotado',
        );
    }

    /**
     * Construye un resultado "anomala" cuando el walk FIFO-inverso agota
     * todas las compras sin cubrir el remanente a valuar. El historial no
     * alcanza para explicar el saldo físico del lote.
     *
     * @param Collection<int, HistorialCompraLote> $compras
     */
    private function buildResultInconsistencia(
        Lote $lote,
        Collection $compras,
        int $huevosPorCarton,
        float $target,
    ): BackfillLoteResult {
        $totalEnHistorial = $compras->sum(fn($c) => ((float) $c->cartones_facturados) * $huevosPorCarton);
        $costoPorHuevoLegacy  = (float) ($lote->costo_por_huevo ?? 0);
        $costoPorCartonLegacy = (float) ($lote->costo_por_carton_facturado ?? 0);

        $detalle = sprintf(
            'Inconsistencia FIFO-inverso: el remanente a valuar (%s huevos facturados) '
            . 'excede la capacidad total del historial_compras_lote (%s huevos). Posibles '
            . 'causas: filas borradas del historial, ajustes manuales al remanente o al '
            . 'buffer de regalo, o huevos_por_carton cambiado retroactivamente.',
            number_format($target, 4, '.', ''),
            number_format($totalEnHistorial, 4, '.', ''),
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
