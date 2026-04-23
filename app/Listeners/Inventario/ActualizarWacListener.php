<?php

declare(strict_types=1);

namespace App\Listeners\Inventario;

use App\Events\Inventario\CompraAplicadaAlLote;
use App\Events\Inventario\DevolucionAplicadaAlLote;
use App\Events\Inventario\MermaAplicadaAlLote;
use App\Events\Inventario\VentaAplicadaAlLote;
use App\Services\Inventario\WacService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Listener síncrono que consume los 4 eventos de dominio del inventario y
 * delega el cálculo WAC al WacService, persistiendo las columnas wac_* del lote.
 *
 * Diseño — síncrono, no ShouldQueue:
 *   El listener se ejecuta dentro de la transacción del caller (Lote::agregarCompra,
 *   etc.). Esto garantiza atomicidad: si el WAC falla, rollback de la operación
 *   legacy completa. La alternativa asíncrona (queue) rompería consistencia y
 *   complicaría los tests de dual-write.
 *
 * Diseño — try/catch defensivo:
 *   Un bug en WacService NUNCA debe tumbar una venta/compra en producción.
 *   Capturamos cualquier Throwable, logueamos con contexto rico, y dejamos que
 *   la operación legacy continúe. Durante Fase 2 (shadow) esto significa que
 *   podemos detectar y corregir bugs WAC sin impacto al usuario. En Fase 5
 *   (cuando el WAC sea fuente de verdad) se revisará la política.
 *
 * Diseño — kill-switch con early return:
 *   config('inventario.wac.shadow_mode') controla el dual-write. Si está
 *   apagado, el listener retorna inmediatamente sin tocar el WacService ni la
 *   BD. Permite rollback instantáneo vía .env sin redeploy.
 */
final class ActualizarWacListener
{
    public function __construct(
        private readonly WacService $wacService,
    ) {}

    /**
     * Compra — entrada con costo al WAC del lote.
     */
    public function handleCompra(CompraAplicadaAlLote $event): void
    {
        if (! $this->shadowModeActivo()) {
            return;
        }

        try {
            // Escenario edge: compra de solo regalo (no modifica WAC).
            if ($event->huevosFacturados <= 0) {
                $this->logSalto($event->lote->id, 'compra', 'compra de solo regalo (sin huevos facturados)');
                return;
            }

            $delta = $this->wacService->aplicarCompra(
                lote:              $event->lote,
                huevosFacturados:  $event->huevosFacturados,
                costoCompra:       $event->costoCompra,
                contextoAuditoria: [
                    'compra_id'         => $event->compraId,
                    'compra_detalle_id' => $event->compraDetalleId,
                    'proveedor_id'      => $event->proveedorId,
                    'huevos_regalo'     => $event->huevosRegalo,
                ],
            );

            $this->logDelta($delta->toArray());
        } catch (Throwable $e) {
            $this->logError('compra', $event->lote->id, $e, [
                'compra_id'          => $event->compraId,
                'compra_detalle_id'  => $event->compraDetalleId,
                'huevos_facturados'  => $event->huevosFacturados,
                'costo_compra'       => $event->costoCompra,
            ]);
        }
    }

    /**
     * Venta — salida del WAC, preserva costo unitario.
     */
    public function handleVenta(VentaAplicadaAlLote $event): void
    {
        if (! $this->shadowModeActivo()) {
            return;
        }

        try {
            // Escenario edge: venta de solo regalo (no modifica WAC).
            if ($event->huevosFacturadosConsumidos <= 0) {
                $this->logSalto($event->lote->id, 'venta', 'venta de solo regalo (sin huevos facturados)');
                return;
            }

            $delta = $this->wacService->aplicarVenta(
                lote:                       $event->lote,
                huevosFacturadosConsumidos: $event->huevosFacturadosConsumidos,
                contextoAuditoria:          array_merge($event->contexto, [
                    'huevos_regalo_consumidos' => $event->huevosRegaloConsumidos,
                ]),
            );

            // $delta es null si el WAC aún no está inicializado (pre-backfill).
            // WacService ya logueó un warning con hint accionable. Aquí solo
            // logueamos el delta cuando sí se aplicó.
            if ($delta !== null) {
                $this->logDelta($delta->toArray());
            }
        } catch (Throwable $e) {
            $this->logError('venta', $event->lote->id, $e, array_merge($event->contexto, [
                'huevos_facturados_consumidos' => $event->huevosFacturadosConsumidos,
            ]));
        }
    }

    /**
     * Merma — solo la parte "pérdida real" (facturada) afecta WAC.
     * La parte cubierta por buffer de regalo no tiene costo asociado.
     */
    public function handleMerma(MermaAplicadaAlLote $event): void
    {
        if (! $this->shadowModeActivo()) {
            return;
        }

        try {
            // Merma completamente absorbida por buffer de regalo — no afecta WAC.
            if ($event->huevosPerdidaReal <= 0) {
                $this->logSalto(
                    $event->lote->id,
                    'merma',
                    'merma absorbida por buffer de regalo',
                    ['huevos_cubierto_buffer' => $event->huevosCubiertoBuffer]
                );
                return;
            }

            $delta = $this->wacService->aplicarMerma(
                lote:              $event->lote,
                huevosPerdidaReal: $event->huevosPerdidaReal,
                contextoAuditoria: [
                    'merma_id'                => $event->merma->id,
                    'motivo_merma'            => $event->merma->motivo ?? null,
                    'huevos_cubierto_buffer'  => $event->huevosCubiertoBuffer,
                ],
            );

            if ($delta !== null) {
                $this->logDelta($delta->toArray());
            }
        } catch (Throwable $e) {
            $this->logError('merma', $event->lote->id, $e, [
                'merma_id'              => $event->merma->id ?? null,
                'huevos_perdida_real'   => $event->huevosPerdidaReal,
                'huevos_cubierto_buffer' => $event->huevosCubiertoBuffer,
            ]);
        }
    }

    /**
     * Devolución — reintegro al WAC al costo unitario actual del lote.
     */
    public function handleDevolucion(DevolucionAplicadaAlLote $event): void
    {
        if (! $this->shadowModeActivo()) {
            return;
        }

        try {
            // Devolución de solo regalo — no afecta WAC.
            if ($event->huevosFacturadosDevueltos <= 0) {
                $this->logSalto(
                    $event->lote->id,
                    'devolucion',
                    'devolución de solo regalo (sin huevos facturados)',
                    ['huevos_regalo_devueltos' => $event->huevosRegaloDevueltos]
                );
                return;
            }

            $delta = $this->wacService->aplicarDevolucion(
                lote:                      $event->lote,
                huevosFacturadosDevueltos: $event->huevosFacturadosDevueltos,
                contextoAuditoria:         array_merge($event->contexto, [
                    'huevos_regalo_devueltos' => $event->huevosRegaloDevueltos,
                ]),
            );

            if ($delta !== null) {
                $this->logDelta($delta->toArray());
            }
        } catch (Throwable $e) {
            $this->logError('devolucion', $event->lote->id, $e, array_merge($event->contexto, [
                'huevos_facturados_devueltos' => $event->huevosFacturadosDevueltos,
            ]));
        }
    }

    // =================================================================
    // HELPERS PRIVADOS
    // =================================================================

    private function shadowModeActivo(): bool
    {
        return (bool) config('inventario.wac.shadow_mode', false);
    }

    /**
     * Log estructurado de una aplicación WAC exitosa.
     * Usa nivel 'info' para no saturar logs en producción — los deltas son
     * observables vía agregación cuando se necesite.
     */
    private function logDelta(array $deltaArray): void
    {
        Log::info('WAC shadow write aplicado', $deltaArray);
    }

    /**
     * Log de escenarios que no ameritan tocar WAC pero son relevantes para
     * auditoría (ej: compra de solo regalo, merma absorbida por buffer).
     */
    private function logSalto(int $loteId, string $motivo, string $razon, array $extra = []): void
    {
        Log::info("WAC shadow write omitido", array_merge([
            'lote_id' => $loteId,
            'motivo'  => $motivo,
            'razon'   => $razon,
        ], $extra));
    }

    /**
     * Log de errores en la escritura WAC. Nivel 'error' para que el alerting
     * de producción lo capture. Incluye stack trace completo.
     *
     * Crítico: los errores NO se re-lanzan — el caller legacy no debe romperse
     * nunca por un problema en el dual-write WAC.
     */
    private function logError(string $motivo, int $loteId, Throwable $e, array $contexto): void
    {
        Log::error("WacService falló en shadow write ({$motivo})", [
            'lote_id'   => $loteId,
            'motivo'    => $motivo,
            'contexto'  => $contexto,
            'exception' => [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ],
        ]);
    }
}
