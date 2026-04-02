<?php

namespace App\Console\Commands;

use App\Models\Liquidacion;
use App\Models\User;
use App\Models\Viaje;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LiquidarComisionesHistorico extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'comisiones:liquidar-historico
                            {--dry-run : Simular sin aplicar cambios}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera liquidaciones pagadas para todas las comisiones históricas pendientes, agrupadas por chofer y mes (comando de una sola vez)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('🔄 Liquidación histórica de comisiones pendientes');
        $this->info('   Este comando se ejecuta una sola vez para limpiar el histórico.');

        if ($dryRun) {
            $this->warn('   ⚠️  MODO SIMULACIÓN - No se aplicarán cambios');
        }

        $this->newLine();

        // Obtener todos los viajes cerrados con comisión no pagada
        // que NO estén ya en alguna liquidación
        $viajesPendientes = Viaje::where('estado', Viaje::ESTADO_CERRADO)
            ->where('comision_pagada', false)
            ->whereDoesntHave('liquidacionViajes')
            ->orderBy('fecha_salida')
            ->get();

        if ($viajesPendientes->isEmpty()) {
            $this->info('✅ No hay comisiones históricas pendientes.');
            return self::SUCCESS;
        }

        $this->info("   Viajes pendientes encontrados: {$viajesPendientes->count()}");

        // Agrupar por chofer y por mes (año-mes)
        $agrupados = $viajesPendientes->groupBy(function ($viaje) {
            return $viaje->chofer_id . '|' . $viaje->fecha_salida->format('Y-m');
        });

        $this->info("   Liquidaciones a generar: {$agrupados->count()}");
        $this->newLine();

        $totalLiquidaciones = 0;
        $totalPagado = 0;

        foreach ($agrupados as $clave => $viajes) {
            [$choferId, $yearMonth] = explode('|', $clave);
            $resultado = $this->liquidarGrupo((int) $choferId, $yearMonth, $viajes, $dryRun);

            if ($resultado) {
                $totalLiquidaciones++;
                $totalPagado += $resultado['total_pagar'];
            }
        }

        // Resumen final
        $this->newLine();
        $this->info('═══════════════════════════════════════════');
        $this->info("✅ Liquidaciones históricas generadas: {$totalLiquidaciones}");
        $this->info("💰 Total histórico registrado: L " . number_format($totalPagado, 2));
        $this->info('═══════════════════════════════════════════');

        if (!$dryRun) {
            Log::info('Liquidación histórica completada', [
                'liquidaciones' => $totalLiquidaciones,
                'total_pagado' => $totalPagado,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Crear liquidación pagada para un grupo chofer+mes
     */
    private function liquidarGrupo(int $choferId, string $yearMonth, $viajes, bool $dryRun): ?array
    {
        $chofer = User::find($choferId);

        if (!$chofer) {
            $this->error("   ❌ Chofer ID {$choferId} no encontrado");
            return null;
        }

        $fechaInicio = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $fechaFin = $fechaInicio->copy()->endOfMonth();
        $mesLabel = $fechaInicio->translatedFormat('F Y');

        $totalComisiones = $viajes->sum('comision_ganada');
        $totalCobros = $viajes->sum('cobros_devoluciones');
        $neto = $totalComisiones - $totalCobros;

        $this->info("   👤 {$chofer->name} — {$mesLabel}");
        $this->info("      Viajes: {$viajes->count()} | Comisiones: L " . number_format($totalComisiones, 2)
            . " | Cobros: L " . number_format($totalCobros, 2)
            . " | Neto: L " . number_format($neto, 2));

        if ($dryRun) {
            $this->warn("      ⏭️  Simulado (dry-run)");
            return [
                'chofer' => $chofer->name,
                'mes' => $mesLabel,
                'viajes' => $viajes->count(),
                'total_pagar' => $neto,
            ];
        }

        try {
            return DB::transaction(function () use ($chofer, $choferId, $viajes, $fechaInicio, $fechaFin, $mesLabel) {
                // 1. Crear liquidación
                $liquidacion = Liquidacion::create([
                    'chofer_id' => $choferId,
                    'tipo_periodo' => Liquidacion::PERIODO_MENSUAL,
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                    'estado' => Liquidacion::ESTADO_BORRADOR,
                    'created_by' => null,
                ]);

                // 2. Agregar viajes
                foreach ($viajes as $viaje) {
                    $liquidacion->agregarViaje($viaje);
                }

                // 3. Marcar como aprobada y pagada (ya fue pagado en efectivo)
                $liquidacion->estado = Liquidacion::ESTADO_PAGADA;
                $liquidacion->aprobado_por = null;
                $liquidacion->fecha_pago = $fechaFin; // Último día del mes correspondiente
                $liquidacion->metodo_pago = 'efectivo';
                $liquidacion->referencia_pago = "Registro histórico - {$mesLabel}";
                $liquidacion->pagado_por = null;
                $liquidacion->save();

                // 4. Registrar movimiento en cuenta del chofer
                $cuenta = $chofer->getOrCreateCuenta();
                if ($liquidacion->total_pagar > 0) {
                    $cuenta->pagarLiquidacion(
                        $liquidacion->total_pagar,
                        $liquidacion->id,
                        "Registro histórico {$liquidacion->numero_liquidacion} - {$mesLabel}"
                    );
                }

                // 5. Marcar viajes como pagados
                Viaje::whereIn('id', $viajes->pluck('id'))
                    ->update([
                        'comision_pagada' => true,
                        'fecha_pago_comision' => $fechaFin,
                    ]);

                $this->info("      ✅ {$liquidacion->numero_liquidacion} - L " . number_format($liquidacion->total_pagar, 2));

                return [
                    'chofer' => $chofer->name,
                    'mes' => $mesLabel,
                    'liquidacion' => $liquidacion->numero_liquidacion,
                    'viajes' => $viajes->count(),
                    'total_pagar' => (float) $liquidacion->total_pagar,
                ];
            });
        } catch (\Throwable $e) {
            $this->error("      ❌ Error: {$e->getMessage()}");
            Log::error("Liquidación histórica falló para {$chofer->name} - {$mesLabel}", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
