<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ChoferCuenta;
use App\Models\ChoferCuentaMovimiento;
use App\Models\Liquidacion;
use App\Models\Viaje;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recalcula las comisiones de un periodo ya cerrado con la configuracion
 * vigente y deja consistente todo lo que cuelga de ellas.
 *
 * Nacio del cambio de regla del 2026-09-01 (se elimino la comision reducida):
 * los viajes de agosto ya estaban cerrados y liquidados con la tarifa vieja,
 * asi que el Estado de Resultados de agosto no cuadraba con lo que el jefe
 * espera pagar.
 *
 * Lo que toca, en este orden:
 *   1. viaje_comision_detalle  - borra y regenera con Viaje::calcularComisiones()
 *   2. viajes                  - comision_ganada y neto_chofer
 *   3. chofer_cuenta_movimientos - el movimiento tipo 'comision' de cada viaje
 *   4. chofer_cuenta_movimientos - reencadena saldo_anterior/saldo_nuevo del chofer
 *   5. chofer_cuenta           - saldo y totales historicos
 *   6. liquidacion_viajes / liquidaciones - totales del periodo
 *
 * SIMULA POR DEFECTO. Sin --aplicar hace todo el trabajo dentro de una
 * transaccion y la revierte, para poder ver el antes/despues real sin escribir.
 */
class RecalcularComisionesCommand extends Command
{
    protected $signature = 'comisiones:recalcular
                            {--desde= : Fecha inicial del periodo (Y-m-d)}
                            {--hasta= : Fecha final del periodo (Y-m-d)}
                            {--chofer= : Limitar a un chofer (user_id)}
                            {--aplicar : Escribir los cambios. Sin esta bandera solo simula}';

    protected $description = 'Recalcula comisiones de viajes cerrados en un periodo con la configuracion vigente y deja consistentes saldos y liquidaciones. Simula por defecto.';

    /** Tipos de movimiento que SUMAN al saldo del chofer; el resto resta. */
    private const TIPOS_QUE_SUMAN = [
        ChoferCuentaMovimiento::TIPO_COMISION,
        ChoferCuentaMovimiento::TIPO_AJUSTE_FAVOR,
    ];

    private const TIPOS_COBRO = [
        ChoferCuentaMovimiento::TIPO_COBRO_DEVOLUCION,
        ChoferCuentaMovimiento::TIPO_COBRO_MERMA,
        ChoferCuentaMovimiento::TIPO_COBRO_FALTANTE,
    ];

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        if (! $this->option('desde') || ! $this->option('hasta')) {
            $this->error('Faltan --desde y --hasta (formato Y-m-d).');

            return self::FAILURE;
        }

        try {
            $desde = Carbon::parse((string) $this->option('desde'))->startOfDay();
            $hasta = Carbon::parse((string) $this->option('hasta'))->endOfDay();
        } catch (\Throwable $e) {
            $this->error('Fechas invalidas: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($hasta->lessThan($desde)) {
            $this->error('--hasta no puede ser anterior a --desde.');

            return self::FAILURE;
        }

        $choferId = $this->option('chofer') ? (int) $this->option('chofer') : null;

        // El Estado de Resultados agrupa por created_at del movimiento, que se
        // crea al cerrar el viaje: por eso el criterio es cerrado_en.
        $viajes = Viaje::query()
            ->where('estado', Viaje::ESTADO_CERRADO)
            ->whereBetween('cerrado_en', [$desde, $hasta])
            ->when($choferId, fn ($q) => $q->where('chofer_id', $choferId))
            ->with('chofer')
            ->orderBy('cerrado_en')
            ->orderBy('id')
            ->get();

        if ($viajes->isEmpty()) {
            $this->warn('No hay viajes cerrados en ese periodo.');

            return self::SUCCESS;
        }

        $this->info($aplicar ? '🚀 APLICANDO cambios' : '🔍 SIMULANDO (no se escribe nada). Use --aplicar para escribir.');
        $this->line(sprintf('Periodo: %s a %s | %d viajes cerrados',
            $desde->format('d/m/Y'), $hasta->format('d/m/Y'), $viajes->count()));
        $this->newLine();

        $filasViaje = [];
        $porChofer = [];
        $cuentas = [];
        $liquidaciones = [];

        $revertir = new class extends \RuntimeException {};

        try {
            DB::transaction(function () use ($viajes, $aplicar, $revertir, &$filasViaje, &$porChofer, &$cuentas, &$liquidaciones) {
                $afectados = [];

                foreach ($viajes as $viaje) {
                    $antes = (float) $viaje->comision_ganada;

                    $viaje->calcularComisiones();

                    $despues = (float) $viaje->comision_ganada;

                    $this->sincronizarMovimientoComision($viaje, $despues);

                    $afectados[$viaje->chofer_id] = true;

                    $nombre = $viaje->chofer?->name ?? "Chofer #{$viaje->chofer_id}";
                    $porChofer[$nombre] ??= ['antes' => 0.0, 'despues' => 0.0, 'viajes' => 0];
                    $porChofer[$nombre]['antes'] += $antes;
                    $porChofer[$nombre]['despues'] += $despues;
                    $porChofer[$nombre]['viajes']++;

                    if (round($antes, 2) !== round($despues, 2)) {
                        $filasViaje[] = [
                            $viaje->numero_viaje ?? "#{$viaje->id}",
                            $nombre,
                            $viaje->cerrado_en?->format('d/m/Y') ?? '-',
                            number_format($antes, 2),
                            number_format($despues, 2),
                            number_format($despues - $antes, 2),
                        ];
                    }
                }

                foreach (array_keys($afectados) as $userId) {
                    $cuentas[] = $this->reencadenarSaldos((int) $userId);
                }

                $liquidaciones = $this->actualizarLiquidaciones($viajes);

                if (! $aplicar) {
                    throw $revertir;
                }
            });
        } catch (\RuntimeException $e) {
            if ($e !== $revertir) {
                throw $e;
            }
        }

        $this->mostrarResultados($filasViaje, $porChofer, $cuentas, $liquidaciones);

        if ($aplicar) {
            Log::info('comisiones:recalcular aplicado', [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
                'chofer' => $choferId,
                'viajes' => $viajes->count(),
                'por_chofer' => $porChofer,
                'cuentas' => $cuentas,
                'liquidaciones' => $liquidaciones,
            ]);
            $this->newLine();
            $this->info('✅ Cambios aplicados y registrados en el log.');
        } else {
            $this->newLine();
            $this->warn('⚠️  Simulacion revertida. Nada se escribio en la base.');
        }

        return self::SUCCESS;
    }

    /**
     * Deja el movimiento contable tipo 'comision' del viaje alineado con la
     * comision recalculada. Los saldos se arreglan despues, al reencadenar.
     */
    private function sincronizarMovimientoComision(Viaje $viaje, float $monto): void
    {
        $movimiento = ChoferCuentaMovimiento::query()
            ->where('viaje_id', $viaje->id)
            ->where('tipo', ChoferCuentaMovimiento::TIPO_COMISION)
            ->orderBy('id')
            ->first();

        if ($monto <= 0) {
            $movimiento?->delete();

            return;
        }

        if ($movimiento) {
            $movimiento->monto = $monto;
            $movimiento->save();

            return;
        }

        // El viaje no habia generado comision (quedo en 0 con la tarifa vieja).
        // Se crea el movimiento con la fecha de cierre para que caiga en el
        // periodo correcto del Estado de Resultados.
        $nuevo = ChoferCuentaMovimiento::create([
            'user_id' => $viaje->chofer_id,
            'tipo' => ChoferCuentaMovimiento::TIPO_COMISION,
            'monto' => $monto,
            'saldo_anterior' => 0,
            'saldo_nuevo' => 0,
            'viaje_id' => $viaje->id,
            'concepto' => sprintf('Comisión viaje #%d - %s',
                $viaje->id,
                $viaje->fecha_salida?->format('d/m/Y') ?? ''),
        ]);

        if ($viaje->cerrado_en) {
            $nuevo->forceFill(['created_at' => $viaje->cerrado_en])->save();
        }
    }

    /**
     * Recorre TODOS los movimientos del chofer en orden de id (que es el orden
     * real en que se registraron; created_at trae fechas retroactivas de la
     * carga historica) y recalcula la cadena de saldos.
     *
     * @return array{chofer: string, saldo_antes: float, saldo_despues: float, movimientos_tocados: int}
     */
    private function reencadenarSaldos(int $userId): array
    {
        $cuenta = ChoferCuenta::where('user_id', $userId)->first();

        $movimientos = ChoferCuentaMovimiento::where('user_id', $userId)
            ->orderBy('id')
            ->get();

        $saldoAntes = (float) ($cuenta?->saldo ?? 0);

        if ($movimientos->isEmpty()) {
            return [
                'chofer' => $cuenta?->chofer?->name ?? "Chofer #{$userId}",
                'saldo_antes' => $saldoAntes,
                'saldo_despues' => $saldoAntes,
                'movimientos_tocados' => 0,
            ];
        }

        $saldo = (float) $movimientos->first()->saldo_anterior;
        $tocados = 0;

        foreach ($movimientos as $movimiento) {
            $signo = in_array($movimiento->tipo, self::TIPOS_QUE_SUMAN, true) ? 1 : -1;
            $nuevo = round($saldo + $signo * (float) $movimiento->monto, 2);

            if (round((float) $movimiento->saldo_anterior, 2) !== round($saldo, 2)
                || round((float) $movimiento->saldo_nuevo, 2) !== $nuevo) {
                $movimiento->saldo_anterior = $saldo;
                $movimiento->saldo_nuevo = $nuevo;
                $movimiento->save();
                $tocados++;
            }

            $saldo = $nuevo;
        }

        if ($cuenta) {
            $cuenta->saldo = $saldo;
            $cuenta->total_comisiones_historico = (float) ChoferCuentaMovimiento::where('user_id', $userId)
                ->where('tipo', ChoferCuentaMovimiento::TIPO_COMISION)->sum('monto');
            $cuenta->total_cobros_historico = (float) ChoferCuentaMovimiento::where('user_id', $userId)
                ->whereIn('tipo', self::TIPOS_COBRO)->sum('monto');
            $cuenta->total_pagado_historico = (float) ChoferCuentaMovimiento::where('user_id', $userId)
                ->where('tipo', ChoferCuentaMovimiento::TIPO_PAGO_LIQUIDACION)->sum('monto');
            $cuenta->save();
        }

        return [
            'chofer' => $cuenta?->chofer?->name ?? "Chofer #{$userId}",
            'saldo_antes' => $saldoAntes,
            'saldo_despues' => $saldo,
            'movimientos_tocados' => $tocados,
        ];
    }

    /**
     * Actualiza las liquidaciones que contienen los viajes recalculados.
     *
     * Se escribe `liquidacion_viajes` por query builder, NO por Eloquent, a
     * proposito y por dos razones:
     *
     *  1. LiquidacionViaje::boot() tiene un hook saved() que llama a
     *     Liquidacion::recalcular(). Ese metodo pisa `saldo_anterior` con el
     *     saldo ACTUAL de la cuenta del chofer y recalcula `total_ventas`
     *     contra la tabla `ventas` (bodega), que no aplica a ventas de ruta.
     *     Sobre una liquidacion historica ya emitida y pagada eso es
     *     corrupcion silenciosa: perderiamos el saldo con que se emitio.
     *  2. Ese mismo hook accede a $liquidacionViaje->liquidacion sin eager
     *     load y revienta con LazyLoadingViolationException, porque
     *     Model::shouldBeStrict() esta activo fuera de produccion.
     *
     * El query builder no dispara eventos de modelo, asi que los totales de la
     * liquidacion se recalculan aqui abajo de forma explicita y controlada,
     * conservando el saldo_anterior original.
     *
     * @param  \Illuminate\Support\Collection<int, Viaje>  $viajes
     * @return array<int, array{numero: string, antes: float, despues: float}>
     */
    private function actualizarLiquidaciones($viajes): array
    {
        $viajeIds = $viajes->pluck('id')->all();
        $porId = $viajes->keyBy('id');

        $lineas = DB::table('liquidacion_viajes')
            ->whereIn('viaje_id', $viajeIds)
            ->get(['id', 'viaje_id', 'liquidacion_id']);

        $ahora = now();

        foreach ($lineas as $linea) {
            $viaje = $porId->get($linea->viaje_id);

            if (! $viaje) {
                continue;
            }

            DB::table('liquidacion_viajes')
                ->where('id', $linea->id)
                ->update([
                    'comision_viaje' => $viaje->comision_ganada,
                    'cobros_viaje' => $viaje->cobros_devoluciones,
                    'neto_viaje' => $viaje->neto_chofer,
                    'updated_at' => $ahora,
                ]);
        }

        $resultado = [];

        foreach (Liquidacion::whereIn('id', $lineas->pluck('liquidacion_id')->unique())->get() as $liquidacion) {
            $antes = (float) $liquidacion->total_comisiones;

            $liquidacion->total_viajes = $liquidacion->viajes()->count();
            $liquidacion->total_comisiones = (float) $liquidacion->viajes()->sum('comision_viaje');
            $liquidacion->total_cobros = (float) $liquidacion->viajes()->sum('cobros_viaje');
            $liquidacion->total_pagar = (float) $liquidacion->total_comisiones
                - (float) $liquidacion->total_cobros
                + (float) $liquidacion->saldo_anterior;
            $liquidacion->save();

            $resultado[] = [
                'numero' => (string) $liquidacion->numero_liquidacion,
                'antes' => $antes,
                'despues' => (float) $liquidacion->total_comisiones,
            ];
        }

        return $resultado;
    }

    /**
     * @param  array<int, array<int, string|null>>  $filasViaje
     * @param  array<string, array{antes: float, despues: float, viajes: int}>  $porChofer
     * @param  array<int, array<string, mixed>>  $cuentas
     * @param  array<int, array<string, mixed>>  $liquidaciones
     */
    private function mostrarResultados(array $filasViaje, array $porChofer, array $cuentas, array $liquidaciones): void
    {
        if ($filasViaje !== []) {
            $this->line('<comment>Viajes cuya comision cambia:</comment>');
            $this->table(['Viaje', 'Chofer', 'Cerrado', 'Antes', 'Despues', 'Dif.'], $filasViaje);
        } else {
            $this->info('Ningun viaje cambia de comision.');
        }

        $this->newLine();
        $this->line('<comment>Total por chofer en el periodo:</comment>');
        $filas = [];
        $totalAntes = $totalDespues = 0.0;

        foreach ($porChofer as $nombre => $datos) {
            $filas[] = [
                $nombre,
                $datos['viajes'],
                number_format($datos['antes'], 2),
                number_format($datos['despues'], 2),
                number_format($datos['despues'] - $datos['antes'], 2),
            ];
            $totalAntes += $datos['antes'];
            $totalDespues += $datos['despues'];
        }

        $filas[] = ['TOTAL', '', number_format($totalAntes, 2), number_format($totalDespues, 2), number_format($totalDespues - $totalAntes, 2)];
        $this->table(['Chofer', 'Viajes', 'Antes', 'Despues', 'Dif.'], $filas);

        if ($cuentas !== []) {
            $this->newLine();
            $this->line('<comment>Cuentas de chofer:</comment>');
            $this->table(
                ['Chofer', 'Saldo antes', 'Saldo despues', 'Movs. reencadenados'],
                array_map(fn (array $c) => [
                    $c['chofer'],
                    number_format((float) $c['saldo_antes'], 2),
                    number_format((float) $c['saldo_despues'], 2),
                    $c['movimientos_tocados'],
                ], $cuentas),
            );
        }

        if ($liquidaciones !== []) {
            $this->newLine();
            $this->line('<comment>Liquidaciones del periodo:</comment>');
            $this->table(
                ['Liquidacion', 'Comisiones antes', 'Comisiones despues'],
                array_map(fn (array $l) => [
                    $l['numero'],
                    number_format((float) $l['antes'], 2),
                    number_format((float) $l['despues'], 2),
                ], $liquidaciones),
            );
        }
    }
}
