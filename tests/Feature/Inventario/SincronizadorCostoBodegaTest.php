<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\Bodega;
use App\Models\BodegaProducto;
use App\Models\Categoria;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Unidad;
use App\Services\Inventario\SincronizadorCostoBodega;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests del SincronizadorCostoBodega.
 *
 * Regla de negocio crítica: el costo de bodega_producto (que leen la página de
 * Productos y el formulario de Ventas) debe reflejar el costo VIGENTE del lote,
 * no un valor histórico congelado. Cubre:
 *
 *   1. Proyección 1x30: costo_por_huevo(lote) × 30 → bodega_producto.
 *   2. Derivación 1x15 sin lote propio: costo_por_huevo(lote hermano) × 15.
 *   3. Recalcula precio_venta_sugerido tras corregir el costo.
 *   4. Respeta config read_source (legacy hoy, wac en Fase 5).
 *   5. Aislamiento por bodega: un lote en bodega A no afecta la bodega B.
 */
class SincronizadorCostoBodegaTest extends TestCase
{
    use RefreshDatabase;

    private SincronizadorCostoBodega $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sync = app(SincronizadorCostoBodega::class);
        Config::set('inventario.wac.read_source', 'legacy');
    }

    /**
     * Crea un escenario marrón: categoría con producto 1x30 (con lote) y 1x15.
     *
     * @return array{categoria:Categoria, bodega:Bodega, p30:Producto, p15:Producto}
     */
    private function escenarioMarron(): array
    {
        $categoria = Categoria::factory()->create();
        $bodega    = Bodega::factory()->create();

        $unidad30 = Unidad::factory()->create(['nombre' => '1x30']);
        $unidad15 = Unidad::factory()->create(['nombre' => '1x15']);

        $p30 = Producto::factory()->create([
            'categoria_id'    => $categoria->id,
            'unidad_id'       => $unidad30->id,
            'margen_ganancia' => 5.00,
            'tipo_margen'     => 'monto',
            'aplica_isv'      => false,
        ]);

        $p15 = Producto::factory()->create([
            'categoria_id'    => $categoria->id,
            'unidad_id'       => $unidad15->id,
            'margen_ganancia' => 5.00,
            'tipo_margen'     => 'monto',
            'aplica_isv'      => false,
        ]);

        // Lote del 1x30 con costo_por_huevo = 1.6667 (6000 huevos / L10,000 => L50/cartón).
        Lote::factory()->conCompra(6000.0, 10000.0)->create([
            'producto_id' => $p30->id,
            'bodega_id'   => $bodega->id,
        ]);

        return compact('categoria', 'bodega', 'p30', 'p15');
    }

    #[Test]
    public function proyecta_el_costo_del_1x30_desde_su_lote(): void
    {
        ['bodega' => $bodega, 'p30' => $p30] = $this->escenarioMarron();

        // Costo viejo y desfasado, como en producción (L90 en vez de L50).
        BodegaProducto::factory()->create([
            'producto_id'           => $p30->id,
            'bodega_id'             => $bodega->id,
            'costo_promedio_actual' => 90.0000,
            'precio_venta_sugerido' => 92.7000,
        ]);

        $actualizado = $this->sync->sincronizar($p30, (int) $bodega->id);

        $this->assertTrue($actualizado);

        $bp = BodegaProducto::where('producto_id', $p30->id)->first();

        // 1.6667 × 30 ≈ 50.00 (tolerancia por redondeo de la columna decimal(12,4)).
        $this->assertEqualsWithDelta(50.00, (float) $bp->costo_promedio_actual, 0.01);
        // Precio recalculado: costo + margen L5 (sin tope) ≈ 55.00.
        $this->assertEqualsWithDelta(55.00, (float) $bp->precio_venta_sugerido, 0.01);
    }

    #[Test]
    public function deriva_el_costo_del_1x15_desde_el_lote_hermano_de_su_categoria(): void
    {
        ['bodega' => $bodega, 'p15' => $p15] = $this->escenarioMarron();

        BodegaProducto::factory()->create([
            'producto_id'           => $p15->id,
            'bodega_id'             => $bodega->id,
            'costo_promedio_actual' => 45.0000, // costo viejo del stock reempacado
            'precio_venta_sugerido' => 46.3500,
        ]);

        $actualizado = $this->sync->sincronizar($p15, (int) $bodega->id);

        $this->assertTrue($actualizado);

        $bp = BodegaProducto::where('producto_id', $p15->id)->first();

        // 1.6667 × 15 ≈ 25.00 (derivado del lote 1x30 de la misma categoría).
        $this->assertEqualsWithDelta(25.00, (float) $bp->costo_promedio_actual, 0.01);
    }

    #[Test]
    public function respeta_read_source_wac_cuando_esta_configurado(): void
    {
        ['bodega' => $bodega, 'p30' => $p30] = $this->escenarioMarron();

        // El lote legacy es 1.6667; poblamos wac con un costo distinto para
        // probar que el switch de fuente realmente cambia la lectura.
        Lote::where('producto_id', $p30->id)->update([
            'wac_costo_por_huevo'       => 2.000000,
            'wac_huevos_inventario'     => 6000.0,
            'wac_costo_inventario'      => 12000.0,
        ]);

        Config::set('inventario.wac.read_source', 'wac');

        $this->sync->sincronizar($p30, (int) $bodega->id);

        $bp = BodegaProducto::where('producto_id', $p30->id)->first();

        // 2.00 × 30 = 60.00 (usó wac_costo_por_huevo, no el legacy 1.6667).
        $this->assertEqualsWithDelta(60.00, (float) $bp->costo_promedio_actual, 0.01);
    }

    #[Test]
    public function no_cruza_costos_entre_bodegas(): void
    {
        ['p30' => $p30] = $this->escenarioMarron();

        $otraBodega = Bodega::factory()->create();

        // El producto existe en otra bodega SIN lote: no debe derivar costo.
        $costo = $this->sync->costoPorHuevoVigente($p30, (int) $otraBodega->id);

        $this->assertNull($costo);
        $this->assertFalse($this->sync->sincronizar($p30, (int) $otraBodega->id));
    }
}
