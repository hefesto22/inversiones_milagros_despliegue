<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Enums\MovimientoInventarioTipo;
use App\Models\BodegaProducto;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests de los comandos del Kardex:
 *   - kardex:inicializar → apertura idempotente con saldo_inicial
 *   - kardex:verificar   → guardián que detecta mutaciones fuera de eventos
 */
class KardexComandosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('inventario.kardex.habilitado', true);
        Config::set('inventario.kardex.estricto', true);
    }

    // =================================================================
    // kardex:inicializar
    // =================================================================

    public function test_inicializar_asienta_saldo_inicial_de_lotes_y_bodega(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();
        $bp   = BodegaProducto::factory()->create([
            'stock'                 => 80.0,
            'costo_promedio_actual' => 45.50,
        ]);
        // Contenedores vacíos NO se asientan
        $loteVacio = Lote::factory()->create(); // remanente 0
        $bpVacio   = BodegaProducto::factory()->create(['stock' => 0]);

        $this->artisan('kardex:inicializar')->assertExitCode(0);

        $movLote = MovimientoInventario::deLote($lote->id)->first();
        $this->assertNotNull($movLote);
        $this->assertSame(MovimientoInventarioTipo::SaldoInicial, $movLote->tipo);
        $this->assertEquals(3000.0, (float) $movLote->delta);
        $this->assertEquals(3000.0, (float) $movLote->saldo_despues);
        $this->assertEqualsWithDelta(2.605, (float) $movLote->costo_unitario, 0.001);

        $movBp = MovimientoInventario::deBodegaProducto($bp->id)->first();
        $this->assertNotNull($movBp);
        $this->assertSame(MovimientoInventarioTipo::SaldoInicial, $movBp->tipo);
        $this->assertEquals(80.0, (float) $movBp->delta);

        // Los vacíos no ensucian el libro
        $this->assertSame(0, MovimientoInventario::deLote($loteVacio->id)->count());
        $this->assertSame(0, MovimientoInventario::deBodegaProducto($bpVacio->id)->count());
    }

    public function test_inicializar_es_idempotente(): void
    {
        Lote::factory()->conCompra(3000.0, 7815.0)->create();
        BodegaProducto::factory()->create(['stock' => 50.0, 'costo_promedio_actual' => 40.0]);

        $this->artisan('kardex:inicializar')->assertExitCode(0);
        $totalPrimeraCorrida = MovimientoInventario::count();

        $this->artisan('kardex:inicializar')->assertExitCode(0);

        // Segunda corrida: cero movimientos nuevos
        $this->assertSame($totalPrimeraCorrida, MovimientoInventario::count());
    }

    public function test_inicializar_no_pisa_contenedores_con_historia(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();

        // El lote ya operó (tiene un movimiento por evento)
        $lote->reducirRemanente(300.0, 0.0);
        $this->assertSame(1, MovimientoInventario::deLote($lote->id)->count());

        $this->artisan('kardex:inicializar')->assertExitCode(0);

        // No se agregó un saldo_inicial encima de la historia existente
        $this->assertSame(1, MovimientoInventario::deLote($lote->id)->count());
        $this->assertSame(
            0,
            MovimientoInventario::deLote($lote->id)
                ->deTipo(MovimientoInventarioTipo::SaldoInicial)->count()
        );
    }

    public function test_inicializar_dry_run_no_escribe_nada(): void
    {
        Lote::factory()->conCompra(3000.0, 7815.0)->create();

        $this->artisan('kardex:inicializar --dry-run')->assertExitCode(0);

        $this->assertSame(0, MovimientoInventario::count());
    }

    // =================================================================
    // kardex:verificar
    // =================================================================

    public function test_verificar_cuadra_cuando_todo_paso_por_eventos(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();
        BodegaProducto::factory()->create(['stock' => 50.0, 'costo_promedio_actual' => 40.0]);

        $this->artisan('kardex:inicializar')->assertExitCode(0);

        // Operación normal por eventos: el libro se actualiza solo
        $lote->reducirRemanente(300.0, 0.0);

        $this->artisan('kardex:verificar')->assertExitCode(0);
    }

    public function test_verificar_detecta_mutacion_que_esquivo_los_eventos(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();

        $this->artisan('kardex:inicializar')->assertExitCode(0);

        // Mutación por FUERA de los eventos (el equivalente a un SQL manual):
        // el libro dice 3000, la realidad dirá 2500.
        DB::table('lotes')->where('id', $lote->id)
            ->update(['cantidad_huevos_remanente' => 2500]);

        $this->artisan('kardex:verificar')->assertExitCode(1);
    }

    public function test_verificar_detecta_contenedor_con_stock_sin_asientos(): void
    {
        // Un lote con stock que jamás pasó por el Kardex (ni apertura ni eventos)
        Lote::factory()->conCompra(3000.0, 7815.0)->create();

        $this->artisan('kardex:verificar')->assertExitCode(1);
    }
}
