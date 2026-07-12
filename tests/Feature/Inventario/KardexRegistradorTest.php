<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Enums\MovimientoInventarioTipo;
use App\Models\Bodega;
use App\Models\BodegaProducto;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Services\Inventario\RegistradorMovimientos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use LogicException;
use Tests\TestCase;

/**
 * Tests del núcleo del Kardex: RegistradorMovimientos + inmutabilidad del modelo.
 *
 * Scope:
 *   1. Asiento a nivel lote: saldo_despues, valor, unidad, denormalizados.
 *   2. Asiento a nivel bodega (empacado/lácteos).
 *   3. Referencia polimórfica al documento origen.
 *   4. Kill-switch (kardex.habilitado=false) no registra y no rompe.
 *   5. Modo no estricto: un fallo del Kardex NO revienta la operación.
 *   6. Modo estricto: el fallo se relanza (transacción del caller revierte).
 *   7. Inmutabilidad: update y delete lanzan LogicException.
 *   8. Delta cero no ensucia el libro.
 */
class KardexRegistradorTest extends TestCase
{
    use RefreshDatabase;

    private RegistradorMovimientos $registrador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrador = app(RegistradorMovimientos::class);
        Config::set('inventario.kardex.habilitado', true);
        Config::set('inventario.kardex.estricto', false);
    }

    // =================================================================
    // NIVEL LOTE
    // =================================================================

    public function test_asienta_movimiento_de_lote_con_saldo_y_valor(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();
        $user = User::factory()->create();

        $mov = $this->registrador->registrarLote(
            lote:          $lote,
            tipo:          MovimientoInventarioTipo::SalidaReempaque,
            delta:         -450.0,
            costoUnitario: 2.605,
            descripcion:   'Reempaque automático — Venta V01-0001',
            contexto:      ['reempaque_id' => 99],
            userId:        $user->id,
        );

        $this->assertNotNull($mov);
        $this->assertSame(MovimientoInventario::NIVEL_LOTE, $mov->nivel);
        $this->assertSame(MovimientoInventario::UNIDAD_HUEVOS, $mov->unidad);
        $this->assertSame($lote->id, $mov->lote_id);
        $this->assertNull($mov->bodega_producto_id);

        // Denormalizados para filtros sin joins
        $this->assertSame($lote->producto_id, $mov->producto_id);
        $this->assertSame($lote->bodega_id, $mov->bodega_id);

        // Saldo del lote al momento (el factory dejó 3000)
        $this->assertEquals(-450.0, (float) $mov->delta);
        $this->assertEquals(3000.0, (float) $mov->saldo_despues);

        // Valor = abs(delta) × costo
        $this->assertEqualsWithDelta(1172.25, (float) $mov->valor, 0.01);

        $this->assertSame($user->id, $mov->created_by);
        $this->assertTrue($mov->esSalida());
        $this->assertEquals(-15.0, $mov->cartones_equiv);
    }

    public function test_asienta_movimiento_de_bodega_para_producto_terminado(): void
    {
        $bp = BodegaProducto::factory()->create([
            'stock'                 => 120.0,
            'costo_promedio_actual' => 45.50,
        ]);

        $mov = $this->registrador->registrarBodega(
            bodegaProducto: $bp,
            tipo:           MovimientoInventarioTipo::Venta,
            delta:          -10.0,
            costoUnitario:  45.50,
            descripcion:    'Venta de lácteos',
        );

        $this->assertNotNull($mov);
        $this->assertSame(MovimientoInventario::NIVEL_BODEGA, $mov->nivel);
        $this->assertSame(MovimientoInventario::UNIDAD_UNIDADES, $mov->unidad);
        $this->assertSame($bp->id, $mov->bodega_producto_id);
        $this->assertNull($mov->lote_id);
        $this->assertEquals(120.0, (float) $mov->saldo_despues);
        $this->assertEqualsWithDelta(455.00, (float) $mov->valor, 0.01);

        // cartones_equiv solo aplica a nivel lote
        $this->assertNull($mov->cartones_equiv);
    }

    public function test_referencia_polimorfica_apunta_al_documento_origen(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();
        $otroLote = Lote::factory()->conCompra(600.0, 1500.0)->create();

        // Usamos otro Lote como "documento" — cualquier Model sirve para el morph
        $mov = $this->registrador->registrarLote(
            lote:       $lote,
            tipo:       MovimientoInventarioTipo::Otro,
            delta:      -30.0,
            referencia: $otroLote,
        );

        $this->assertNotNull($mov);
        $this->assertSame($otroLote->getMorphClass(), $mov->referencia_type);
        $this->assertEquals($otroLote->id, $mov->referencia_id);
        $this->assertTrue($mov->referencia()->first()->is($otroLote));
    }

    // =================================================================
    // FLAGS
    // =================================================================

    public function test_kill_switch_apagado_no_registra_nada(): void
    {
        Config::set('inventario.kardex.habilitado', false);

        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();

        $mov = $this->registrador->registrarLote(
            lote:  $lote,
            tipo:  MovimientoInventarioTipo::Merma,
            delta: -30.0,
        );

        $this->assertNull($mov);
        $this->assertSame(0, MovimientoInventario::count());
    }

    public function test_modo_no_estricto_no_revienta_ante_fallo_del_kardex(): void
    {
        Config::set('inventario.kardex.estricto', false);

        // Lote NO persistido con FKs inexistentes → el insert del movimiento
        // viola la FK y falla. En modo no estricto eso se loguea y retorna null.
        $loteInvalido = Lote::factory()->make([
            'id'          => 999999,
            'producto_id' => 999999,
            'bodega_id'   => 999999,
        ]);

        $mov = $this->registrador->registrarLote(
            lote:  $loteInvalido,
            tipo:  MovimientoInventarioTipo::Merma,
            delta: -30.0,
        );

        $this->assertNull($mov);
        $this->assertSame(0, MovimientoInventario::count());
    }

    public function test_modo_estricto_relanza_el_fallo(): void
    {
        Config::set('inventario.kardex.estricto', true);

        $loteInvalido = Lote::factory()->make([
            'id'          => 999999,
            'producto_id' => 999999,
            'bodega_id'   => 999999,
        ]);

        $this->expectException(\Throwable::class);

        $this->registrador->registrarLote(
            lote:  $loteInvalido,
            tipo:  MovimientoInventarioTipo::Merma,
            delta: -30.0,
        );
    }

    public function test_delta_cero_no_ensucia_el_libro(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();

        $mov = $this->registrador->registrarLote(
            lote:  $lote,
            tipo:  MovimientoInventarioTipo::Otro,
            delta: 0.0,
        );

        $this->assertNull($mov);
        $this->assertSame(0, MovimientoInventario::count());
    }

    // =================================================================
    // INMUTABILIDAD
    // =================================================================

    public function test_los_movimientos_no_se_pueden_editar(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();

        $mov = $this->registrador->registrarLote(
            lote:  $lote,
            tipo:  MovimientoInventarioTipo::Merma,
            delta: -30.0,
        );

        $this->expectException(LogicException::class);
        $mov->update(['delta' => -999.0]);
    }

    public function test_los_movimientos_no_se_pueden_borrar(): void
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();

        $mov = $this->registrador->registrarLote(
            lote:  $lote,
            tipo:  MovimientoInventarioTipo::Merma,
            delta: -30.0,
        );

        $this->expectException(LogicException::class);
        $mov->delete();
    }
}
