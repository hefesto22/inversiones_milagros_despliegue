<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BodegaProducto;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Guardián del Kardex — verifica que el libro y el stock real cuadren.
 *
 * Para cada contenedor (lote / bodega_producto) compara el `saldo_despues`
 * de su ÚLTIMO movimiento contra el stock real actual. Si alguien movió
 * inventario por fuera de los eventos (SQL manual, bug, writer no cableado),
 * la diferencia aparece aquí — el descuadre se detecta en horas, no en el
 * siguiente conteo físico.
 *
 * Clases de hallazgo:
 *   - descuadre:    último saldo del libro ≠ stock real (tolerancia 0.001)
 *   - sin_asientos: contenedor con stock > 0 que no tiene ningún movimiento
 *                   (nació o se movió sin pasar por el Kardex)
 *
 * Corre en el schedule nocturno (03:30, después del reconciliador WAC) y
 * puede correrse manualmente en cualquier momento. Exit code 1 si hay
 * divergencias — útil para alerting.
 */
class KardexVerificarCommand extends Command
{
    private const TOLERANCIA = 0.001;

    protected $signature = 'kardex:verificar';

    protected $description = 'Verifica que el último saldo del Kardex cuadre con el stock real de cada lote y producto';

    public function handle(): int
    {
        $divergencias = [];

        // ── Nivel LOTE ──────────────────────────────────────────────────
        $ultimosLote = MovimientoInventario::query()
            ->selectRaw('MAX(id) as id')
            ->whereNotNull('lote_id')
            ->groupBy('lote_id')
            ->pluck('id');

        $ultimoPorLote = MovimientoInventario::whereIn('id', $ultimosLote)
            ->get()
            ->keyBy('lote_id');

        Lote::query()
            ->where('cantidad_huevos_remanente', '>', 0)
            ->orWhereIn('id', $ultimoPorLote->keys())
            ->orderBy('id')
            ->chunkById(200, function ($lotes) use ($ultimoPorLote, &$divergencias) {
                foreach ($lotes as $lote) {
                    $ultimo = $ultimoPorLote->get($lote->id);
                    $stockReal = (float) $lote->cantidad_huevos_remanente;

                    if ($ultimo === null) {
                        $divergencias[] = [
                            'contenedor' => "Lote {$lote->numero_lote} (#{$lote->id})",
                            'clase'      => 'sin_asientos',
                            'libro'      => '—',
                            'real'       => $stockReal,
                            'diferencia' => $stockReal,
                        ];
                        continue;
                    }

                    $saldoLibro = (float) $ultimo->saldo_despues;

                    if (abs($saldoLibro - $stockReal) > self::TOLERANCIA) {
                        $divergencias[] = [
                            'contenedor' => "Lote {$lote->numero_lote} (#{$lote->id})",
                            'clase'      => 'descuadre',
                            'libro'      => $saldoLibro,
                            'real'       => $stockReal,
                            'diferencia' => round($stockReal - $saldoLibro, 3),
                        ];
                    }
                }
            });

        // ── Nivel BODEGA ────────────────────────────────────────────────
        $ultimosBp = MovimientoInventario::query()
            ->selectRaw('MAX(id) as id')
            ->whereNotNull('bodega_producto_id')
            ->groupBy('bodega_producto_id')
            ->pluck('id');

        $ultimoPorBp = MovimientoInventario::whereIn('id', $ultimosBp)
            ->get()
            ->keyBy('bodega_producto_id');

        BodegaProducto::query()
            ->where('stock', '>', 0)
            ->orWhereIn('id', $ultimoPorBp->keys())
            ->with('producto:id,nombre')
            ->orderBy('id')
            ->chunkById(200, function ($bps) use ($ultimoPorBp, &$divergencias) {
                foreach ($bps as $bp) {
                    $ultimo = $ultimoPorBp->get($bp->id);
                    $stockReal = (float) $bp->stock;
                    $nombre = $bp->producto?->nombre ?? "producto {$bp->producto_id}";

                    if ($ultimo === null) {
                        $divergencias[] = [
                            'contenedor' => "Bodega #{$bp->bodega_id} · {$nombre} (bp #{$bp->id})",
                            'clase'      => 'sin_asientos',
                            'libro'      => '—',
                            'real'       => $stockReal,
                            'diferencia' => $stockReal,
                        ];
                        continue;
                    }

                    $saldoLibro = (float) $ultimo->saldo_despues;

                    if (abs($saldoLibro - $stockReal) > self::TOLERANCIA) {
                        $divergencias[] = [
                            'contenedor' => "Bodega #{$bp->bodega_id} · {$nombre} (bp #{$bp->id})",
                            'clase'      => 'descuadre',
                            'libro'      => $saldoLibro,
                            'real'       => $stockReal,
                            'diferencia' => round($stockReal - $saldoLibro, 3),
                        ];
                    }
                }
            });

        // ── Reporte ─────────────────────────────────────────────────────
        if ($divergencias === []) {
            $this->info('✅ Kardex verificado: el libro cuadra con el stock real en todos los contenedores.');
            Log::info('Kardex verificado sin divergencias');

            return self::SUCCESS;
        }

        $this->error('❌ ' . count($divergencias) . ' divergencia(s) entre el Kardex y el stock real:');
        $this->table(
            ['Contenedor', 'Clase', 'Saldo libro', 'Stock real', 'Diferencia'],
            $divergencias
        );

        foreach ($divergencias as $d) {
            Log::warning('Kardex: divergencia detectada', $d + [
                'hint' => 'Algo movió inventario sin pasar por los eventos del Kardex '
                    . '(SQL manual, writer no cableado o bug). Investigar y asentar '
                    . 'la corrección desde el módulo de Ajuste.',
            ]);
        }

        return self::FAILURE;
    }
}
