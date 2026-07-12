<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MovimientoInventarioTipo;
use App\Models\BodegaProducto;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Services\Inventario\RegistradorMovimientos;
use Illuminate\Console\Command;

/**
 * Apertura del Kardex de Inventario — asienta el movimiento `saldo_inicial`
 * de cada contenedor con existencias al momento de estrenar el libro.
 *
 * Decisión de negocio (2026-07-12): corte limpio — el Kardex arranca HOY con
 * lo que existe; no se reconstruye historia previa. Desde la apertura, todo
 * movimiento queda registrado por eventos con trazabilidad completa.
 *
 * IDEMPOTENTE: un contenedor (lote o bodega_producto) que ya tenga CUALQUIER
 * movimiento en el Kardex se salta — correr el comando dos veces no duplica
 * aperturas, y correrlo después de días de operación no pisa la historia.
 *
 * Valoración de la apertura:
 *   - Lotes (huevos):       costo_por_huevo_efectivo (respeta read_source WAC)
 *   - Bodega (empacado/lácteos): costo_promedio_actual
 */
class KardexInicializarCommand extends Command
{
    protected $signature = 'kardex:inicializar
                            {--dry-run : Muestra qué se asentaría sin escribir nada}';

    protected $description = 'Abre el Kardex de Inventario asentando el saldo inicial de lotes y stock de bodega existentes';

    public function handle(RegistradorMovimientos $registrador): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! config('inventario.kardex.habilitado', true)) {
            $this->error('El Kardex está deshabilitado (KARDEX_HABILITADO=false). Actívalo antes de inicializar.');
            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Apertura del Kardex — DRY RUN (no se escribe nada)…'
            : 'Apertura del Kardex — asentando saldos iniciales…');

        // ── Nivel LOTE (huevos) ─────────────────────────────────────────
        $lotesAsentados = 0;
        $lotesSaltados  = 0;

        Lote::where('cantidad_huevos_remanente', '>', 0)
            ->orderBy('id')
            ->chunkById(100, function ($lotes) use ($registrador, $dryRun, &$lotesAsentados, &$lotesSaltados) {
                foreach ($lotes as $lote) {
                    if (MovimientoInventario::deLote($lote->id)->exists()) {
                        $lotesSaltados++;
                        continue;
                    }

                    if (! $dryRun) {
                        $mov = $registrador->registrarLote(
                            lote:          $lote,
                            tipo:          MovimientoInventarioTipo::SaldoInicial,
                            delta:         (float) $lote->cantidad_huevos_remanente,
                            costoUnitario: (float) $lote->costo_por_huevo_efectivo ?: null,
                            descripcion:   'Apertura del Kardex — saldo inicial',
                        );

                        if ($mov === null) {
                            $this->warn("  ⚠ Lote {$lote->numero_lote}: no se pudo asentar (revisar log).");
                            continue;
                        }
                    }

                    $lotesAsentados++;
                }
            });

        // ── Nivel BODEGA (empacado / lácteos) ───────────────────────────
        $bpAsentados = 0;
        $bpSaltados  = 0;

        BodegaProducto::where('stock', '>', 0)
            ->orderBy('id')
            ->chunkById(100, function ($bps) use ($registrador, $dryRun, &$bpAsentados, &$bpSaltados) {
                foreach ($bps as $bp) {
                    if (MovimientoInventario::deBodegaProducto($bp->id)->exists()) {
                        $bpSaltados++;
                        continue;
                    }

                    if (! $dryRun) {
                        $mov = $registrador->registrarBodega(
                            bodegaProducto: $bp,
                            tipo:           MovimientoInventarioTipo::SaldoInicial,
                            delta:          (float) $bp->stock,
                            costoUnitario:  (float) $bp->costo_promedio_actual ?: null,
                            descripcion:    'Apertura del Kardex — saldo inicial',
                        );

                        if ($mov === null) {
                            $this->warn("  ⚠ BodegaProducto #{$bp->id}: no se pudo asentar (revisar log).");
                            continue;
                        }
                    }

                    $bpAsentados++;
                }
            });

        $this->table(['Contenedor', 'Asentados', 'Saltados (ya tenían historia)'], [
            ['Lotes (huevos)',            $lotesAsentados, $lotesSaltados],
            ['Bodega (empacado/lácteos)', $bpAsentados,    $bpSaltados],
        ]);

        $this->info($dryRun
            ? 'DRY RUN completado — nada se escribió.'
            : '✅ Kardex abierto. Desde ahora todo movimiento queda en el libro.');

        return self::SUCCESS;
    }
}
