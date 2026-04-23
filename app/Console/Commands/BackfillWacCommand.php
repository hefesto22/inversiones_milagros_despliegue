<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lote;
use App\Services\Inventario\BackfillWacService;
use App\Services\Inventario\Dto\BackfillLoteResult;
use App\Services\Inventario\Dto\BackfillRunSummary;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 3 del refactor WAC Perpetuo — backfill de columnas wac_* en lotes históricos.
 *
 * Orquesta la iteración sobre lotes, delega el cálculo a BackfillWacService,
 * persiste resultados en wac_backfill_runs/items, aplica escrituras solo si el
 * modo es --apply y el run no tiene anomalías no autorizadas.
 *
 * Precondición operativa crítica:
 *   Este command debe correr con INVENTARIO_WAC_SHADOW_MODE=false. El diseño
 *   del sistema (ver config/inventario.php y docblock de ActualizarWacListener)
 *   asume que primero se hace backfill, luego se activa shadow. Correr con
 *   shadow_mode=true simultáneamente puede producir race conditions.
 *
 * Uso típico:
 *   1. php artisan wac:backfill --dry-run                   → reporte sin tocar BD
 *   2. Revisar divergencias clasificadas como anómalas       → decidir si hay bug real
 *   3. php artisan wac:backfill --apply                      → si todo limpio
 *   4. php artisan wac:backfill --apply --force              → si hay anómalas aceptadas
 *
 * Otros modos:
 *   --bodega=X       → filtrar por bodega
 *   --producto=X     → filtrar por producto
 *   --reset          → pone wac_* a null en los lotes tocados (rollback)
 */
final class BackfillWacCommand extends Command
{
    protected $signature = 'wac:backfill
                            {--dry-run : Simular sin escribir columnas wac_* (default si no se pasa --apply)}
                            {--apply : Escribir columnas wac_* en lotes sin anomalías}
                            {--force : Permitir aplicar aun cuando haya divergencias anómalas}
                            {--bodega= : Filtrar por bodega_id}
                            {--producto= : Filtrar por producto_id}
                            {--reset : Poner wac_* a null en los lotes del filtro (rollback)}
                            {--notas= : Notas del operador para wac_backfill_runs.notas}';

    protected $description = 'Fase 3 WAC: reconstruye columnas wac_* de lotes desde historial_compras_lote. '
        . 'Siempre ejecutar primero con --dry-run, revisar divergencias, luego --apply.';

    public function handle(BackfillWacService $service): int
    {
        // ---------- Validación de flags ----------
        $reset    = (bool) $this->option('reset');
        $apply    = (bool) $this->option('apply');
        $dryRun   = (bool) $this->option('dry-run');
        $force    = (bool) $this->option('force');
        $bodegaId = $this->optionAsInt('bodega');
        $producto = $this->optionAsInt('producto');
        $notas    = $this->option('notas');

        if ($reset && ($apply || $dryRun)) {
            $this->error('--reset no puede combinarse con --apply ni --dry-run. Correr --reset solo.');
            return self::INVALID;
        }

        // Default: si no pasan nada, asumir dry-run.
        if (! $apply && ! $reset) {
            $dryRun = true;
        }

        if ($force && ! $apply) {
            $this->error('--force solo tiene sentido con --apply.');
            return self::INVALID;
        }

        $modo = $reset ? 'reset' : ($apply ? 'apply' : 'dry-run');

        if (! $this->advertirShadowMode()) {
            return self::FAILURE;
        }

        // ---------- Modo reset ----------
        if ($reset) {
            return $this->ejecutarReset($service, $bodegaId, $producto);
        }

        // ---------- Tolerancia (leída del config — misma que usa Fase 4) ----------
        $tolerancia = (float) config(
            'inventario.wac.divergence_tolerance_lempiras',
            0.10
        );

        // ---------- Inicializar run ----------
        $runUuid = $service->nuevoRunUuid();
        $runId   = $this->crearRegistroRun(
            runUuid:   $runUuid,
            modo:      $apply ? 'apply' : 'dry-run',
            bodegaId:  $bodegaId,
            producto:  $producto,
            force:     $force,
            notas:     $notas,
        );

        $summary = $service->nuevoSummary(
            runUuid:   $runUuid,
            modo:      $apply ? 'apply' : 'dry-run',
            bodegaId:  $bodegaId,
            productoId: $producto,
            force:     $force,
        );

        $this->renderCabecera($modo, $bodegaId, $producto, $force, $tolerancia, $runUuid);

        // ---------- Iteración ----------
        $lotesIdsYaProcesados = $this->lotesIdsEnRunsExitosos();
        $query = $service->queryLotes($bodegaId, $producto);
        $summary->totalLotes = (clone $query)->count();

        if ($summary->totalLotes === 0) {
            $this->warn('No hay lotes que coincidan con los filtros. Nada que hacer.');
            $this->finalizarRegistroRun($runId, $summary, 'completado');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($summary->totalLotes);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
        $bar->setMessage('iniciando…');
        $bar->start();

        $lotesParaAplicar = [];

        // cursor() para O(1) memoria en tablas grandes
        foreach ($query->cursor() as $lote) {
            try {
                // Reanudación: saltar lotes procesados en runs previos exitosos
                // (pero solo si estamos en dry-run; en apply puede ser deseable reprocesar)
                if (! $apply && isset($lotesIdsYaProcesados[$lote->id])) {
                    $result = BackfillLoteResult::saltado(
                        $lote->id,
                        'ya procesado en run previo exitoso'
                    );
                } else {
                    $result = $service->calcularLote($lote, $tolerancia);
                }

                $this->persistirItem($runId, $result);
                $summary->registrar($result);

                // Un lote entra a la lista de aplicar si:
                //  (a) fue procesado exitosamente, Y
                //  (b) no es anómalo — O el operador pasó --force para aceptar anomalías.
                // La decisión final de abortar todo el run por anomalías no autorizadas
                // se toma después del loop via $summary->tieneAnomaliasNoAutorizadas().
                if ($apply && $result->fueProcesado() && (! $result->esAnomala() || $force)) {
                    $lotesParaAplicar[] = [$lote, $result];
                }

                $bar->setMessage($this->mensajeBarra($result));
            } catch (Throwable $e) {
                $fallido = BackfillLoteResult::fallido(
                    $lote->id,
                    sprintf('%s: %s', get_class($e), $e->getMessage())
                );
                $this->persistirItem($runId, $fallido);
                $summary->registrar($fallido);
                Log::error('BackfillWacCommand: fallo en lote', [
                    'run_uuid'  => $runUuid,
                    'lote_id'   => $lote->id,
                    'exception' => $e->getMessage(),
                ]);
                $bar->setMessage("lote {$lote->id} FALLIDO");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // ---------- Decisión de aplicar ----------
        if ($apply) {
            if ($summary->tieneAnomaliasNoAutorizadas()) {
                $this->error(sprintf(
                    'ABORTADO: %d divergencia(s) anómala(s) detectadas. Revisá el run y re-ejecutá con --force si querés aplicar de todos modos.',
                    $summary->divergenciasAnomalas
                ));
                $this->finalizarRegistroRun($runId, $summary, 'abortado');
                $this->renderResumen($summary);
                return self::FAILURE;
            }

            $this->info(sprintf('Aplicando wac_* en %d lote(s)...', count($lotesParaAplicar)));
            foreach ($lotesParaAplicar as [$lote, $result]) {
                $service->aplicarResultado($lote, $result);
            }
            $this->info('Aplicación completa.');
        }

        $this->finalizarRegistroRun($runId, $summary, 'completado');
        $this->renderResumen($summary);

        return self::SUCCESS;
    }

    // =================================================================
    // MODO RESET
    // =================================================================

    private function ejecutarReset(
        BackfillWacService $service,
        ?int $bodegaId,
        ?int $producto,
    ): int {
        if (! $this->confirm(
            'Esto pondrá en NULL las columnas wac_* de los lotes del filtro. ¿Continuar?',
            false
        )) {
            $this->warn('Abortado por el operador.');
            return self::SUCCESS;
        }

        $query = $service->queryLotes($bodegaId, $producto);
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No hay lotes que coincidan con los filtros.');
            return self::SUCCESS;
        }

        $this->info("Reseteando wac_* en {$total} lote(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($query->cursor() as $lote) {
            $service->resetearWac($lote);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Reset completado.');

        return self::SUCCESS;
    }

    // =================================================================
    // HELPERS DE PERSISTENCIA
    // =================================================================

    private function crearRegistroRun(
        string $runUuid,
        string $modo,
        ?int $bodegaId,
        ?int $producto,
        bool $force,
        ?string $notas,
    ): int {
        return (int) DB::table('wac_backfill_runs')->insertGetId([
            'run_uuid'           => $runUuid,
            'modo'               => $modo,
            'estado'             => 'en_curso',
            'bodega_id_filtro'   => $bodegaId,
            'producto_id_filtro' => $producto,
            'force_aplicado'     => $force,
            'iniciado_en'        => Carbon::now(),
            'ejecutado_por'      => auth()->id(),
            'notas'              => $notas,
            'created_at'         => Carbon::now(),
            'updated_at'         => Carbon::now(),
        ]);
    }

    private function persistirItem(int $runId, BackfillLoteResult $result): void
    {
        DB::table('wac_backfill_items')->insert(array_merge(
            ['wac_backfill_run_id' => $runId],
            $result->toPersistenceArray(),
            [
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        ));
    }

    private function finalizarRegistroRun(int $runId, BackfillRunSummary $summary, string $estado): void
    {
        $iniciado = DB::table('wac_backfill_runs')->where('id', $runId)->value('iniciado_en');
        $duracion = $iniciado
            ? (int) abs(Carbon::now()->diffInSeconds(Carbon::parse($iniciado)))
            : null;

        DB::table('wac_backfill_runs')->where('id', $runId)->update([
            'estado'                 => $estado,
            'total_lotes'            => $summary->totalLotes,
            'lotes_procesados'       => $summary->lotesProcesados,
            'lotes_saltados'         => $summary->lotesSaltados,
            'lotes_fallidos'         => $summary->lotesFallidos,
            'divergencias_ruido'     => $summary->divergenciasRuido,
            'divergencias_esperadas' => $summary->divergenciasEsperadas,
            'divergencias_anomalas'  => $summary->divergenciasAnomalas,
            'finalizado_en'          => Carbon::now(),
            'duracion_segundos'      => $duracion,
            'updated_at'             => Carbon::now(),
        ]);
    }

    /**
     * @return array<int, true>  Set de lote_id procesados en runs previos completados
     */
    private function lotesIdsEnRunsExitosos(): array
    {
        $ids = DB::table('wac_backfill_items')
            ->join('wac_backfill_runs', 'wac_backfill_runs.id', '=', 'wac_backfill_items.wac_backfill_run_id')
            ->where('wac_backfill_runs.estado', 'completado')
            ->where('wac_backfill_items.estado', 'procesado')
            ->pluck('wac_backfill_items.lote_id')
            ->all();

        return array_fill_keys($ids, true);
    }

    // =================================================================
    // HELPERS DE RENDER
    // =================================================================

    /**
     * Advierte si el shadow_mode está activo y pide confirmación para continuar.
     *
     * @return bool true si puede continuar, false si el operador abortó.
     */
    private function advertirShadowMode(): bool
    {
        if (! (bool) config('inventario.wac.shadow_mode', false)) {
            return true;
        }

        $this->warn('⚠  INVENTARIO_WAC_SHADOW_MODE está ACTIVO.');
        $this->warn('   El backfill debería correr con shadow_mode=false para evitar race conditions.');
        $this->warn('   Pausá el servicio o desactivá el flag antes de continuar.');

        if (! $this->confirm('¿Continuar de todas formas?', false)) {
            $this->warn('Abortado por el operador.');
            return false;
        }

        return true;
    }

    private function renderCabecera(
        string $modo,
        ?int $bodegaId,
        ?int $producto,
        bool $force,
        float $tolerancia,
        string $runUuid,
    ): void {
        $this->info('═══════════════════════════════════════════════════════════════════');
        $this->info('  WAC Backfill — Fase 3');
        $this->info('═══════════════════════════════════════════════════════════════════');
        $this->line(sprintf('  Modo            : %s', strtoupper($modo)));
        $this->line(sprintf('  Bodega filtro   : %s', $bodegaId ?? 'todas'));
        $this->line(sprintf('  Producto filtro : %s', $producto ?? 'todos'));
        $this->line(sprintf('  Force anomalías : %s', $force ? 'SÍ' : 'no'));
        $this->line(sprintf('  Tolerancia      : %.4f L/cartón', $tolerancia));
        $this->line(sprintf('  Run UUID        : %s', $runUuid));
        $this->info('═══════════════════════════════════════════════════════════════════');
        $this->newLine();
    }

    private function mensajeBarra(BackfillLoteResult $r): string
    {
        $tag = match (true) {
            $r->estado === BackfillLoteResult::ESTADO_FALLIDO => 'FAIL',
            $r->estado === BackfillLoteResult::ESTADO_SALTADO => 'skip',
            $r->clasificacionDivergencia === BackfillLoteResult::CLASIF_ANOMALA => 'ANOM',
            $r->clasificacionDivergencia === BackfillLoteResult::CLASIF_ESPERADA => 'esp',
            $r->clasificacionDivergencia === BackfillLoteResult::CLASIF_RUIDO => 'ruid',
            default => 'ok',
        };

        return "lote {$r->loteId} [{$tag}]";
    }

    private function renderResumen(BackfillRunSummary $s): void
    {
        $this->newLine();
        $this->info('───────────────────  RESUMEN DEL RUN  ───────────────────');
        $this->line(sprintf('  UUID                  : %s', $s->runUuid));
        $this->line(sprintf('  Modo                  : %s', $s->modo));
        $this->line(sprintf('  Total lotes           : %d', $s->totalLotes));
        $this->line(sprintf('  Procesados            : %d', $s->lotesProcesados));
        $this->line(sprintf('  Saltados              : %d', $s->lotesSaltados));
        $this->line(sprintf('  Fallidos              : %d', $s->lotesFallidos));
        $this->line('');
        $this->line(sprintf('  Divergencias ruido    : %d', $s->divergenciasRuido));
        $this->line(sprintf('  Divergencias esperadas: %d', $s->divergenciasEsperadas));
        $this->line(sprintf('  Divergencias anómalas : %d', $s->divergenciasAnomalas));
        $this->info('──────────────────────────────────────────────────────────');

        if (! empty($s->lotesConAnomalia)) {
            $this->newLine();
            $this->warn('Lotes con divergencia anómala (revisar en wac_backfill_items):');
            $this->line('  ' . implode(', ', $s->lotesConAnomalia));
        }

        $this->newLine();
        $this->line(sprintf(
            '  Ver detalle: SELECT * FROM wac_backfill_items WHERE wac_backfill_run_id = '
            . '(SELECT id FROM wac_backfill_runs WHERE run_uuid = \'%s\');',
            $s->runUuid
        ));
    }

    private function optionAsInt(string $name): ?int
    {
        $val = $this->option($name);
        return $val === null || $val === '' ? null : (int) $val;
    }
}
