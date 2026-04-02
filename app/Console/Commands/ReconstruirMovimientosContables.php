<?php

namespace App\Console\Commands;

use App\Models\ChoferCuenta;
use App\Models\ChoferCuentaMovimiento;
use App\Models\Viaje;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconstruirMovimientosContables extends Command
{
    protected $signature = 'comisiones:reconstruir-movimientos
                            {--dry-run : Simular sin aplicar cambios}';

    protected $description = 'Reconstruye los movimientos contables (comision, cobro_devolucion, cobro_merma, cobro_faltante) para viajes cerrados que no los tienen. Usa la fecha del viaje para created_at (base devengado).';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info($dryRun ? '🔍 MODO DRY-RUN (sin cambios)' : '🚀 EJECUTANDO reconstrucción de movimientos');
        $this->newLine();

        // Buscar viajes cerrados que NO tienen movimiento tipo 'comision' en ChoferCuentaMovimiento
        $viajes = Viaje::where('estado', Viaje::ESTADO_CERRADO)
            ->where('comision_ganada', '>', 0)
            ->whereDoesntHave('movimientosContables', function ($q) {
                $q->where('tipo', 'comision');
            })
            ->with(['chofer.cuenta', 'descargas', 'mermas'])
            ->orderBy('fecha_salida')
            ->get();

        if ($viajes->isEmpty()) {
            $this->info('✅ No hay viajes pendientes de reconstrucción.');
            return self::SUCCESS;
        }

        $this->info("📋 Encontrados {$viajes->count()} viajes cerrados sin movimientos contables:");
        $this->newLine();

        $totalComisiones = 0;
        $totalCobros = 0;
        $movimientosCreados = 0;

        foreach ($viajes as $viaje) {
            $cuenta = $viaje->chofer?->cuenta;

            if (!$cuenta) {
                $this->warn("  ⚠ Viaje #{$viaje->id} - Chofer sin cuenta, omitido");
                continue;
            }

            $choferNombre = $viaje->chofer->name;
            $fecha = $viaje->fecha_salida->format('d/m/Y');

            $this->line("  Viaje #{$viaje->id} | {$choferNombre} | {$fecha}");
            $this->line("    Comisión: L " . number_format($viaje->comision_ganada, 2));

            if (!$dryRun) {
                DB::transaction(function () use ($viaje, $cuenta, &$totalComisiones, &$totalCobros, &$movimientosCreados) {
                    // 1. Registrar comisión
                    $mov = $cuenta->agregarComision(
                        $viaje->comision_ganada,
                        $viaje->id,
                        "Comisión viaje #{$viaje->id} - {$viaje->fecha_salida->format('d/m/Y')}"
                    );
                    // Forzar created_at a la fecha del viaje (base devengado)
                    $mov->created_at = $viaje->fecha_salida->endOfDay();
                    $mov->save();
                    $movimientosCreados++;
                    $totalComisiones += $viaje->comision_ganada;

                    // 2. Cobros por devoluciones
                    $cobrosDescargas = $viaje->descargas()->where('cobrar_chofer', true)->sum('monto_cobrar');
                    if ($cobrosDescargas > 0) {
                        $mov = $cuenta->cobrarDevolucion(
                            $cobrosDescargas,
                            $viaje->id,
                            "Cobro devoluciones viaje #{$viaje->id}"
                        );
                        $mov->created_at = $viaje->fecha_salida->endOfDay();
                        $mov->save();
                        $movimientosCreados++;
                        $totalCobros += $cobrosDescargas;
                    }

                    // 3. Cobros por mermas
                    $cobrosMermas = $viaje->mermas()->where('cobrar_chofer', true)->sum('monto_cobrar');
                    if ($cobrosMermas > 0) {
                        $mov = $cuenta->cobrarMerma(
                            $cobrosMermas,
                            $viaje->id,
                            "Cobro mermas viaje #{$viaje->id}"
                        );
                        $mov->created_at = $viaje->fecha_salida->endOfDay();
                        $mov->save();
                        $movimientosCreados++;
                        $totalCobros += $cobrosMermas;
                    }

                    // 4. Faltante de efectivo
                    if ($viaje->diferencia_efectivo < 0) {
                        $faltante = abs($viaje->diferencia_efectivo);
                        $mov = $cuenta->cobrarFaltante(
                            $faltante,
                            $viaje->id,
                            "Faltante efectivo viaje #{$viaje->id}"
                        );
                        $mov->created_at = $viaje->fecha_salida->endOfDay();
                        $mov->save();
                        $movimientosCreados++;
                        $totalCobros += $faltante;
                    }
                });
            } else {
                $totalComisiones += $viaje->comision_ganada;

                $cobrosDescargas = $viaje->descargas()->where('cobrar_chofer', true)->sum('monto_cobrar');
                $cobrosMermas = $viaje->mermas()->where('cobrar_chofer', true)->sum('monto_cobrar');
                $faltante = $viaje->diferencia_efectivo < 0 ? abs($viaje->diferencia_efectivo) : 0;

                $cobrosViaje = $cobrosDescargas + $cobrosMermas + $faltante;
                $totalCobros += $cobrosViaje;

                if ($cobrosViaje > 0) {
                    $this->line("    Cobros: L " . number_format($cobrosViaje, 2));
                }
            }
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info("Total comisiones:     L " . number_format($totalComisiones, 2));
        $this->info("Total cobros:         L " . number_format($totalCobros, 2));

        if (!$dryRun) {
            $this->info("Movimientos creados:  {$movimientosCreados}");
            $this->newLine();
            $this->info('✅ Reconstrucción completada.');
            Log::info("Movimientos contables reconstruidos: {$movimientosCreados} movimientos, L " . number_format($totalComisiones, 2) . " en comisiones");
        } else {
            $this->newLine();
            $this->info('ℹ️  Ejecute sin --dry-run para aplicar cambios.');
        }

        return self::SUCCESS;
    }
}
