<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Inventario\ReconciliarWacVsLegacyJob;
use App\Services\Inventario\ReconciliadorWacService;
use Illuminate\Console\Command;

/**
 * Invocación manual del reconciliador WAC vs legacy (Fase 4).
 *
 * Dos modos:
 *
 *   wac:reconciliar           → despacha el job a la cola de Horizon.
 *                                Retorna inmediatamente. El log aparece cuando
 *                                el worker procese el job.
 *
 *   wac:reconciliar --sync    → ejecuta la reconciliación en foreground.
 *                                Imprime tabla de resultados y retorna FAILURE
 *                                si hay divergencias anómalas — útil para
 *                                scripts de CI/verificación manual.
 *
 * El scheduler nocturno (routes/console.php → dailyAt 03:00) despacha el job
 * sin flags; este comando cubre los casos de ejecución ad-hoc.
 */
final class ReconciliarWacCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wac:reconciliar
                            {--sync : Ejecutar en foreground y mostrar resumen en terminal}';

    /**
     * @var string
     */
    protected $description = 'Fase 4 WAC: compara costo WAC vs legacy y loguea divergencias';

    public function handle(ReconciliadorWacService $service): int
    {
        if ($this->option('sync')) {
            return $this->ejecutarSincrono($service);
        }

        return $this->despacharJob();
    }

    private function ejecutarSincrono(ReconciliadorWacService $service): int
    {
        $this->info('Iniciando reconciliación WAC vs legacy (modo síncrono)...');

        $resumen = $service->reconciliar();

        if ($resumen->deshabilitadoPorFlag) {
            $this->warn('⚠️  El flag INVENTARIO_WAC_LOG_DIVERGENCES está apagado. Run omitido.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("Run UUID: {$resumen->runUuid}");
        $this->line("Duración: {$resumen->duracionSegundos()}s");
        $this->newLine();

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Lotes evaluados',      (string) $resumen->totalLotesEvaluados],
                ['Clase: ninguna',       (string) $resumen->divergenciasNinguna],
                ['Clase: ruido',         (string) $resumen->divergenciasRuido],
                ['Clase: esperada',      (string) $resumen->divergenciasEsperadas],
                ['Clase: anómala',       (string) $resumen->divergenciasAnomalas],
            ]
        );

        if ($resumen->tieneAnomalias()) {
            $this->error(sprintf(
                '❌ %d lotes con divergencia anómala. Revisar logs (canal default) con run_uuid=%s',
                $resumen->divergenciasAnomalas,
                $resumen->runUuid
            ));

            if (! empty($resumen->lotesConAnomalia)) {
                $this->line('Primeros lotes anómalos: ' . implode(', ', array_slice($resumen->lotesConAnomalia, 0, 20)));
            }

            return self::FAILURE;
        }

        $this->info('✅ Reconciliación completada sin divergencias anómalas.');
        return self::SUCCESS;
    }

    private function despacharJob(): int
    {
        ReconciliarWacVsLegacyJob::dispatch();

        $this->info('✅ Job despachado a la cola. Monitorear progreso en Horizon.');
        $this->line('   Tags: inventario, wac, reconciliacion, fase-4');
        $this->line('   Usa --sync para ver el resultado en terminal.');

        return self::SUCCESS;
    }
}
