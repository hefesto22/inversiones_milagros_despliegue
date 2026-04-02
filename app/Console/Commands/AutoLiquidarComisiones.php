<?php

namespace App\Console\Commands;

use App\Models\Liquidacion;
use App\Models\User;
use App\Models\Viaje;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoLiquidarComisiones extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'comisiones:auto-liquidar
                            {--mes= : Mes a liquidar (1-12). Default: mes anterior}
                            {--anio= : Año a liquidar. Default: año actual}
                            {--chofer= : ID de chofer específico (opcional)}
                            {--dry-run : Simular sin aplicar cambios}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera y paga automáticamente las liquidaciones de comisiones del mes anterior para todos los choferes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Determinar periodo
        $periodo = $this->resolverPeriodo();
        $fechaInicio = $periodo['inicio'];
        $fechaFin = $periodo['fin'];
        $mesLabel = $fechaInicio->translatedFormat('F Y');

        $this->info("🔄 Auto-liquidación de comisiones: {$mesLabel}");
        $this->info("   Período: {$fechaInicio->format('d/m/Y')} - {$fechaFin->format('d/m/Y')}");

        if ($dryRun) {
            $this->warn('   ⚠️  MODO SIMULACIÓN - No se aplicarán cambios');
        }

        // Obtener choferes con viajes pendientes en el periodo
        $query = Viaje::where('estado', Viaje::ESTADO_CERRADO)
            ->where('comision_pagada', false)
            ->whereBetween('fecha_salida', [$fechaInicio, $fechaFin]);

        if ($choferId = $this->option('chofer')) {
            $query->where('chofer_id', $choferId);
        }

        $choferesConViajes = $query
            ->select('chofer_id')
            ->distinct()
            ->pluck('chofer_id');

        if ($choferesConViajes->isEmpty()) {
            $this->info('✅ No hay comisiones pendientes para el período.');
            Log::info("Auto-liquidación {$mesLabel}: sin comisiones pendientes.");
            return self::SUCCESS;
        }

        $this->info("   Choferes con comisiones pendientes: {$choferesConViajes->count()}");
        $this->newLine();

        $totalLiquidaciones = 0;
        $totalPagado = 0;
        $resumen = [];

        foreach ($choferesConViajes as $choferId) {
            $resultado = $this->liquidarChofer($choferId, $fechaInicio, $fechaFin, $dryRun);

            if ($resultado) {
                $totalLiquidaciones++;
                $totalPagado += $resultado['total_pagar'];
                $resumen[] = $resultado;
            }
        }

        // Resumen final
        $this->newLine();
        $this->info('═══════════════════════════════════════════');
        $this->info("✅ Liquidaciones generadas: {$totalLiquidaciones}");
        $this->info("💰 Total pagado: L " . number_format($totalPagado, 2));
        $this->info('═══════════════════════════════════════════');

        if (!$dryRun) {
            Log::info("Auto-liquidación {$mesLabel} completada", [
                'liquidaciones' => $totalLiquidaciones,
                'total_pagado' => $totalPagado,
                'resumen' => $resumen,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Crear liquidación y marcar como pagada para un chofer
     */
    private function liquidarChofer(int $choferId, Carbon $fechaInicio, Carbon $fechaFin, bool $dryRun): ?array
    {
        $chofer = User::find($choferId);

        if (!$chofer) {
            $this->error("   ❌ Chofer ID {$choferId} no encontrado");
            return null;
        }

        // Viajes cerrados no pagados del periodo
        $viajes = Viaje::where('chofer_id', $choferId)
            ->where('estado', Viaje::ESTADO_CERRADO)
            ->where('comision_pagada', false)
            ->whereBetween('fecha_salida', [$fechaInicio, $fechaFin])
            ->orderBy('fecha_salida')
            ->get();

        if ($viajes->isEmpty()) {
            return null;
        }

        $totalComisiones = $viajes->sum('comision_ganada');
        $totalCobros = $viajes->sum('cobros_devoluciones');
        $neto = $totalComisiones - $totalCobros;

        $this->info("   👤 {$chofer->name}");
        $this->info("      Viajes: {$viajes->count()} | Comisiones: L " . number_format($totalComisiones, 2)
            . " | Cobros: L " . number_format($totalCobros, 2)
            . " | Neto: L " . number_format($neto, 2));

        if ($dryRun) {
            $this->warn("      ⏭️  Simulado (dry-run)");
            return [
                'chofer' => $chofer->name,
                'viajes' => $viajes->count(),
                'total_comisiones' => $totalComisiones,
                'total_cobros' => $totalCobros,
                'total_pagar' => $neto,
            ];
        }

        try {
            return DB::transaction(function () use ($chofer, $choferId, $viajes, $fechaInicio, $fechaFin) {
                // 1. Crear liquidación
                $liquidacion = Liquidacion::create([
                    'chofer_id' => $choferId,
                    'tipo_periodo' => Liquidacion::PERIODO_MENSUAL,
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                    'estado' => Liquidacion::ESTADO_BORRADOR,
                    'created_by' => null, // Sistema automático
                ]);

                // 2. Agregar cada viaje a la liquidación
                foreach ($viajes as $viaje) {
                    $liquidacion->agregarViaje($viaje);
                }

                // 3. Aprobar
                $liquidacion->estado = Liquidacion::ESTADO_APROBADA;
                $liquidacion->aprobado_por = null; // Sistema automático
                $liquidacion->save();

                // 4. Pagar en efectivo
                //    No podemos usar pagar() directamente porque requiere Auth::id()
                //    Replicamos la lógica para el contexto automático
                $liquidacion->estado = Liquidacion::ESTADO_PAGADA;
                $liquidacion->fecha_pago = $fechaFin; // Fecha del último día del mes
                $liquidacion->metodo_pago = 'efectivo';
                $liquidacion->referencia_pago = 'Auto-liquidación mensual';
                $liquidacion->pagado_por = null; // Sistema automático
                $liquidacion->save();

                // 5. Registrar movimiento en cuenta del chofer
                $cuenta = $chofer->getOrCreateCuenta();
                if ($liquidacion->total_pagar > 0) {
                    $cuenta->pagarLiquidacion(
                        $liquidacion->total_pagar,
                        $liquidacion->id,
                        "Auto-liquidación {$liquidacion->numero_liquidacion}"
                    );
                }

                // 6. Marcar viajes como comision_pagada = true
                Viaje::whereIn('id', $viajes->pluck('id'))
                    ->update([
                        'comision_pagada' => true,
                        'fecha_pago_comision' => $fechaFin,
                    ]);

                $this->info("      ✅ {$liquidacion->numero_liquidacion} - L " . number_format($liquidacion->total_pagar, 2));

                return [
                    'chofer' => $chofer->name,
                    'liquidacion' => $liquidacion->numero_liquidacion,
                    'viajes' => $viajes->count(),
                    'total_comisiones' => (float) $liquidacion->total_comisiones,
                    'total_cobros' => (float) $liquidacion->total_cobros,
                    'total_pagar' => (float) $liquidacion->total_pagar,
                ];
            });
        } catch (\Throwable $e) {
            $this->error("      ❌ Error: {$e->getMessage()}");
            Log::error("Auto-liquidación falló para chofer {$chofer->name}", [
                'chofer_id' => $choferId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Resolver el periodo a liquidar
     */
    private function resolverPeriodo(): array
    {
        $mes = $this->option('mes');
        $anio = $this->option('anio') ?? now()->year;

        if ($mes) {
            $inicio = Carbon::create($anio, $mes, 1)->startOfDay();
        } else {
            // Por defecto: mes anterior
            $inicio = now()->subMonth()->startOfMonth()->startOfDay();
        }

        return [
            'inicio' => $inicio,
            'fin' => $inicio->copy()->endOfMonth()->endOfDay(),
        ];
    }
}
