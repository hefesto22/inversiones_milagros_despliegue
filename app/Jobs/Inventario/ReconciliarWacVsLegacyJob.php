<?php

declare(strict_types=1);

namespace App\Jobs\Inventario;

use App\Services\Inventario\ReconciliadorWacService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 4 del refactor WAC Perpetuo — job wrapper de la reconciliación nocturna.
 *
 * El job solo orquesta: delega el trabajo real al ReconciliadorWacService que
 * es donde vive toda la lógica. Aquí solo se define la política de ejecución
 * en cola (tries, timeout, unicidad, tags para Horizon).
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * POLÍTICA — tries=1
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Intencional: NO se reintenta el job completo en caso de fallo.
 *   (a) Un run parcial ya logueó las divergencias que alcanzó a evaluar; retentar
 *       desde cero duplicaría entradas en el log.
 *   (b) El siguiente schedule (mañana a las 03:00) es un retry de facto con
 *       datos frescos.
 *   (c) El servicio no lanza excepción por lotes individuales; solo por bug
 *       catastrófico (DB caída, memoria, etc.) — retentar el mismo run no
 *       va a ayudar.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * POLÍTICA — ShouldBeUnique
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Garantiza que nunca corran dos reconciliaciones en paralelo. Si el scheduler
 * dispara a las 03:00 y un run manual (wac:reconciliar) sigue corriendo, el
 * segundo job se descarta. El uniqueFor() de 1800s (30 min) es el tope: si
 * un run queda colgado más tiempo, el lock expira y el siguiente puede entrar.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * TIMEOUT — 600s (10 minutos)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Con cursor() iterando sobre lotes, 10 min alcanza holgadamente para decenas
 * de miles de registros. Si un día esto se acerca al límite, es señal de
 * escalado — hay que paginar por rango de IDs o particionar el job.
 */
final class ReconciliarWacVsLegacyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Número de intentos — 1 (no reintenta; ver docstring).
     */
    public int $tries = 1;

    /**
     * Timeout máximo en segundos para la ejecución del job.
     */
    public int $timeout = 600;

    /**
     * Duración del lock de unicidad en segundos (30 min).
     */
    public int $uniqueFor = 1800;

    /**
     * Identificador para ShouldBeUnique — uno solo por tipo de job.
     */
    public function uniqueId(): string
    {
        return 'wac-reconciliacion-global';
    }

    /**
     * Tags expuestos en Horizon para filtrar/buscar runs.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['inventario', 'wac', 'reconciliacion', 'fase-4'];
    }

    public function handle(ReconciliadorWacService $service): void
    {
        $resumen = $service->reconciliar();

        // Señal explícita al log de Horizon: si hubo anomalías, dejamos traza
        // para que un filtro de "jobs con problemas" las pesque aunque el job
        // terminó exitosamente en términos de ejecución.
        if ($resumen->tieneAnomalias()) {
            Log::warning(
                sprintf(
                    'Reconciliación WAC %s terminó con %d lotes anómalos',
                    $resumen->runUuid,
                    $resumen->divergenciasAnomalas
                ),
                ['lotes' => $resumen->lotesConAnomalia]
            );
        }
    }

    /**
     * Hook de Laravel cuando el job falla irrecuperablemente (excepciones no
     * capturadas). Loguea con contexto para debugging post-mortem.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Job ReconciliarWacVsLegacyJob falló', [
            'exception' => get_class($exception),
            'message'   => $exception->getMessage(),
            'file'      => $exception->getFile(),
            'line'      => $exception->getLine(),
        ]);
    }
}
