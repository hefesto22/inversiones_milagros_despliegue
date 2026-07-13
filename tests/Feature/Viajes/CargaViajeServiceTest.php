<?php

declare(strict_types=1);

namespace Tests\Feature\Viajes;

use App\Application\Services\CargaViajeService;
use App\Application\Services\ReempaqueService;
use App\Enums\LoteEstado;
use App\Models\Bodega;
use App\Models\BodegaProducto;
use App\Models\Camion;
use App\Models\Categoria;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\ReempaqueProducto;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Viaje;
use App\Models\ViajeCarga;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests de CargaViajeService: aumentarCantidad(), reducirCantidad(),
 * stockAdicionalDisponible() y maximoParaBajar().
 *
 * Este servicio es la lógica compartida entre el EditAction (aumentar o
 * reducir cantidad total en Planificado/Cargando) y las acciones "Recargar"
 * y "Bajar" del modo Recargando (2026-07-13), extraída de CargasRelationManager.
 *
 * Reglas cubiertas:
 *   - Aumento toma primero de bodega, luego del lote (reempaque automático).
 *   - Reempaque CONSOLIDADO: revierte el anterior y crea uno nuevo por el
 *     total, manteniendo UN reempaque_id por carga.
 *   - Reducción LIFO: primero devuelve al lote (reversión del reempaque al
 *     costo WAC actual), el resto a bodega con el costo original.
 *   - No se puede bajar producto ya vendido/mermado/devuelto.
 *   - Costo unitario promedio ponderado entre porción bodega y porción lote.
 *   - Stock insuficiente lanza RuntimeException sin tocar datos.
 *
 * Escenario base: lote de 3,000 huevos a L 7,500 (L 2.50/huevo, L 75/cartón).
 */
class CargaViajeServiceTest extends TestCase
{
    use RefreshDatabase;

    private static int $contadorCamion = 900;

    private Bodega $bodega;

    private Categoria $categoriaBase;

    private Producto $productoBase;

    private Lote $lote;

    protected function setUp(): void
    {
        parent::setUp();

        // Espejo de producción: dual-write WAC activo (default false en testing).
        Config::set('inventario.wac.shadow_mode', true);

        $this->actingAs(User::factory()->create());

        $this->bodega = Bodega::factory()->create();

        // Categoría base auto-referenciada (usa lotes)
        $this->categoriaBase = Categoria::factory()->create();
        $this->categoriaBase->update(['categoria_origen_id' => $this->categoriaBase->id]);
        $this->categoriaBase->refresh();

        // Unidad sin "15" ni dígitos en el nombre → 30 huevos por unidad
        $unidad30 = Unidad::factory()->create([
            'nombre' => 'carton_treinta_'.fake()->unique()->lexify('??????'),
        ]);

        $this->productoBase = Producto::factory()->create([
            'categoria_id' => $this->categoriaBase->id,
            'unidad_id' => $unidad30->id,
            'formato_empaque' => '1x30',
            'unidades_por_bulto' => 30,
        ]);

        $this->lote = Lote::factory()
            ->wacInicializado(huevos: 3000.0, costoInventario: 7500.0)
            ->create([
                'numero_lote' => "LU-B{$this->bodega->id}-P{$this->productoBase->id}",
                'producto_id' => $this->productoBase->id,
                'bodega_id' => $this->bodega->id,
                'estado' => LoteEstado::Disponible,
                'costo_por_huevo' => 2.5,
                'costo_por_carton_facturado' => 75.0,
            ]);
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function crearViajeMinimo(string $estado = 'recargando'): Viaje
    {
        $chofer = User::factory()->create();
        $admin = User::factory()->create();

        $n = str_pad((string) ++self::$contadorCamion, 6, '0', STR_PAD_LEFT);
        $camion = Camion::create([
            'codigo' => 'CAM-V-'.$n,
            'placa' => 'TSV-'.$n,
            'bodega_id' => $this->bodega->id,
            'activo' => true,
            'created_by' => $admin->id,
        ]);

        return Viaje::create([
            'camion_id' => $camion->id,
            'chofer_id' => $chofer->id,
            'bodega_origen_id' => $this->bodega->id,
            'fecha_salida' => now(),
            'estado' => $estado,
        ]);
    }

    /**
     * Cargar el camión desde el lote vía reempaque automático (mismo camino
     * que la carga real) y devolver la carga creada.
     */
    private function cargarDesdeLote(Viaje $viaje, Producto $producto, float $cantidad): ViajeCarga
    {
        $resultado = DB::transaction(fn () => app(ReempaqueService::class)
            ->ejecutarReempaqueAutomatico(
                $producto->id,
                $this->bodega->id,
                $cantidad,
                "Viaje #{$viaje->id} test"
            ));

        return ViajeCarga::create([
            'viaje_id' => $viaje->id,
            'reempaque_id' => $resultado['reempaque_id'],
            'producto_id' => $producto->id,
            'unidad_id' => $producto->unidad_id,
            'cantidad' => $cantidad,
            'costo_unitario' => $resultado['costo_unitario'],
            'costo_unitario_lote' => $resultado['costo_unitario'],
            'cantidad_de_bodega' => 0,
            'cantidad_de_lote' => $cantidad,
            'precio_venta_sugerido' => 100,
            'precio_venta_minimo' => 90,
        ]);
    }

    private function stockEnBodega(int $productoId): float
    {
        return (float) (BodegaProducto::where('bodega_id', $this->bodega->id)
            ->where('producto_id', $productoId)
            ->value('stock') ?? 0.0);
    }

    // =========================================================
    // Aumento desde LOTE (reempaque consolidado)
    // =========================================================

    public function test_recarga_desde_lote_consolida_reempaque_y_descuenta_del_lote(): void
    {
        $viaje = $this->crearViajeMinimo();

        // Carga inicial: 10 cartones (300 huevos) → remanente 2,700
        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 10);
        $reempaqueOriginal = $carga->reempaque_id;

        // Recargar +5 → nueva cantidad 15
        $resultado = app(CargaViajeService::class)->aumentarCantidad($viaje, $carga, 15);

        $carga->refresh();
        $this->lote->refresh();

        $this->assertSame(15.0, (float) $carga->cantidad);
        $this->assertSame(15.0, (float) $carga->cantidad_de_lote);
        $this->assertSame(0.0, (float) $carga->cantidad_de_bodega);

        // El lote quedó con 3,000 − 450 huevos (15 cartones netos):
        // la consolidación revierte los 300 y consume 450.
        $this->assertSame(2550.0, (float) $this->lote->cantidad_huevos_remanente);

        // Reempaque consolidado: uno nuevo, con las 15 unidades registradas
        $this->assertNotSame($reempaqueOriginal, $carga->reempaque_id, 'Debe existir un reempaque consolidado nuevo');
        $this->assertSame(
            15.0,
            (float) ReempaqueProducto::where('reempaque_id', $carga->reempaque_id)
                ->where('producto_id', $this->productoBase->id)
                ->value('cantidad')
        );

        // Costo: todo salió del lote a L 75/cartón
        $this->assertEqualsWithDelta(75.0, (float) $carga->costo_unitario, 0.01);
        $this->assertSame(5.0, $resultado['tomar_de_lote']);
        $this->assertSame(0.0, $resultado['tomar_de_bodega']);
    }

    public function test_recarga_toma_de_bodega_primero_y_luego_del_lote(): void
    {
        $viaje = $this->crearViajeMinimo();

        // Carga inicial de 10 desde lote → remanente 2,700
        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 10);

        // Stock libre en bodega: 3 unidades a L 60 (escenario legacy/excepcional)
        BodegaProducto::create([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->productoBase->id,
            'stock' => 3,
            'costo_promedio_actual' => 60,
            'stock_minimo' => 0,
            'activo' => true,
        ]);
        $carga->update(['costo_bodega_original' => 60]);

        // Recargar +5: 3 de bodega + 2 del lote
        $resultado = app(CargaViajeService::class)->aumentarCantidad($viaje, $carga, 15);

        $carga->refresh();
        $this->lote->refresh();

        $this->assertSame(3.0, $resultado['tomar_de_bodega']);
        $this->assertSame(2.0, $resultado['tomar_de_lote']);
        $this->assertSame(0.0, $this->stockEnBodega($this->productoBase->id), 'La bodega debe agotarse primero');
        $this->assertSame(3.0, (float) $carga->cantidad_de_bodega);
        $this->assertSame(12.0, (float) $carga->cantidad_de_lote);

        // Lote: 3,000 − (12 cartones consolidados × 30) = 2,640
        $this->assertSame(2640.0, (float) $this->lote->cantidad_huevos_remanente);

        // Costo ponderado: (3×60 + 12×75) / 15 = 72.00
        $this->assertEqualsWithDelta(72.0, (float) $carga->costo_unitario, 0.01);
    }

    // =========================================================
    // Producto sin lotes (solo bodega)
    // =========================================================

    public function test_recarga_de_producto_sin_lote_descuenta_de_bodega(): void
    {
        $categoriaNormal = Categoria::factory()->create();
        $productoNormal = Producto::factory()->create([
            'categoria_id' => $categoriaNormal->id,
            'unidades_por_bulto' => null,
        ]);

        BodegaProducto::create([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $productoNormal->id,
            'stock' => 10,
            'costo_promedio_actual' => 100,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $viaje = $this->crearViajeMinimo();

        $carga = ViajeCarga::create([
            'viaje_id' => $viaje->id,
            'producto_id' => $productoNormal->id,
            'unidad_id' => $productoNormal->unidad_id,
            'cantidad' => 5,
            'costo_unitario' => 100,
            'costo_bodega_original' => 100,
            'cantidad_de_bodega' => 5,
            'cantidad_de_lote' => 0,
            'precio_venta_sugerido' => 150,
            'precio_venta_minimo' => 140,
        ]);

        app(CargaViajeService::class)->aumentarCantidad($viaje, $carga, 8);

        $carga->refresh();

        $this->assertSame(8.0, (float) $carga->cantidad);
        $this->assertSame(8.0, (float) $carga->cantidad_de_bodega);
        $this->assertSame(7.0, $this->stockEnBodega($productoNormal->id), '10 − 3 recargadas = 7');
    }

    // =========================================================
    // Validaciones
    // =========================================================

    public function test_stock_insuficiente_lanza_excepcion_sin_tocar_datos(): void
    {
        $viaje = $this->crearViajeMinimo();

        // Carga inicial: 10 → remanente 2,700 (90 cartones disponibles en lote)
        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 10);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Stock insuficiente/');

        // Pedir 200 adicionales (solo hay 90 en lote + 10 recuperables)
        try {
            app(CargaViajeService::class)->aumentarCantidad($viaje, $carga, 210);
        } finally {
            $carga->refresh();
            $this->lote->refresh();

            $this->assertSame(10.0, (float) $carga->cantidad, 'La carga no debe cambiar');
            $this->assertSame(2700.0, (float) $this->lote->cantidad_huevos_remanente, 'El lote no debe cambiar');
        }
    }

    public function test_cantidad_no_mayor_a_la_actual_lanza_excepcion(): void
    {
        $viaje = $this->crearViajeMinimo();
        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 10);

        $this->expectException(\InvalidArgumentException::class);

        app(CargaViajeService::class)->aumentarCantidad($viaje, $carga, 10);
    }

    // =========================================================
    // Stock adicional disponible (dato del modal de recarga)
    // =========================================================

    public function test_stock_adicional_disponible_suma_bodega_y_lote(): void
    {
        $viaje = $this->crearViajeMinimo();

        // Carga inicial: 10 → quedan 2,700 huevos = 90 cartones en lote
        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 10);

        BodegaProducto::create([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->productoBase->id,
            'stock' => 4,
            'costo_promedio_actual' => 60,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $stock = app(CargaViajeService::class)->stockAdicionalDisponible($viaje, $carga);

        $this->assertSame(4.0, $stock['bodega']);
        $this->assertSame(90.0, $stock['lote']);
        $this->assertSame(94.0, $stock['total']);
    }

    // =========================================================
    // Reducción (acción "Bajar" / Edit al reducir)
    // =========================================================

    public function test_bajar_devuelve_al_lote_via_reversion_de_reempaque(): void
    {
        $viaje = $this->crearViajeMinimo();

        // Recarga equivocada: cargó 30 (900 huevos) → remanente 2,100
        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 30);

        // Baja 20 → quedan 10
        $resultado = app(CargaViajeService::class)->reducirCantidad($viaje, $carga, 10);

        $carga->refresh();
        $this->lote->refresh();

        $this->assertSame(10.0, (float) $carga->cantidad);
        $this->assertSame(10.0, (float) $carga->cantidad_de_lote);
        $this->assertSame(20.0, $resultado['devolver_al_lote']);
        $this->assertSame(0.0, $resultado['devolver_a_bodega']);

        // Los 600 huevos regresaron al lote: 2,100 + 600 = 2,700
        $this->assertSame(2700.0, (float) $this->lote->cantidad_huevos_remanente);

        // WAC acompaña: 3,000 − 900 + 600 = 2,700 al costo intacto
        $this->assertSame(2700.0, (float) $this->lote->wac_huevos_inventario);
        $this->assertEqualsWithDelta(2.5, (float) $this->lote->wac_costo_por_huevo, 0.0001);

        // El reempaque quedó con las 10 unidades que siguen en el camión
        $this->assertSame(
            10.0,
            (float) ReempaqueProducto::where('reempaque_id', $carga->reempaque_id)
                ->where('producto_id', $this->productoBase->id)
                ->value('cantidad')
        );

        // Nada fue a bodega (invariante huevo base)
        $this->assertSame(0.0, $this->stockEnBodega($this->productoBase->id));
    }

    public function test_bajar_lifo_devuelve_lote_primero_y_resto_a_bodega(): void
    {
        $viaje = $this->crearViajeMinimo();

        // Carga mixta: 3 de bodega (L 60) + 12 del lote — como la deja una recarga previa
        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 10);

        BodegaProducto::create([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->productoBase->id,
            'stock' => 3,
            'costo_promedio_actual' => 60,
            'stock_minimo' => 0,
            'activo' => true,
        ]);
        $carga->update(['costo_bodega_original' => 60]);
        app(CargaViajeService::class)->aumentarCantidad($viaje, $carga, 15);
        $carga->refresh();

        $this->assertSame(12.0, (float) $carga->cantidad_de_lote, 'Precondición: 12 del lote');
        $this->assertSame(3.0, (float) $carga->cantidad_de_bodega, 'Precondición: 3 de bodega');

        // Bajar 14: LIFO → 12 al lote + 2 a bodega
        $resultado = app(CargaViajeService::class)->reducirCantidad($viaje, $carga, 1);

        $carga->refresh();
        $this->lote->refresh();

        $this->assertSame(12.0, $resultado['devolver_al_lote']);
        $this->assertSame(2.0, $resultado['devolver_a_bodega']);
        $this->assertSame(1.0, (float) $carga->cantidad);
        $this->assertSame(0.0, (float) $carga->cantidad_de_lote);
        $this->assertSame(1.0, (float) $carga->cantidad_de_bodega);

        // Lote: 2,640 (tras consolidación de 12) + 360 devueltos = 3,000
        $this->assertSame(3000.0, (float) $this->lote->cantidad_huevos_remanente);

        // Bodega: quedó en 0 tras la recarga, recibe 2 de vuelta
        $this->assertSame(2.0, $this->stockEnBodega($this->productoBase->id));

        // Costo de la carga restante = porción bodega (1 × 60)
        $this->assertEqualsWithDelta(60.0, (float) $carga->costo_unitario, 0.01);
    }

    public function test_bajar_no_permite_quitar_lo_ya_vendido(): void
    {
        $viaje = $this->crearViajeMinimo();

        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 10);
        $carga->update(['cantidad_vendida' => 6]);

        // Máximo a bajar = 4 (10 − 6 vendidos)
        $this->assertSame(4.0, app(CargaViajeService::class)->maximoParaBajar($carga->refresh()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No puede bajar más de lo disponible/');

        // Intentar dejar la carga en 5 (bajaría 5 > 4 disponibles)
        try {
            app(CargaViajeService::class)->reducirCantidad($viaje, $carga, 5);
        } finally {
            $carga->refresh();
            $this->lote->refresh();

            $this->assertSame(10.0, (float) $carga->cantidad, 'La carga no debe cambiar');
            $this->assertSame(2700.0, (float) $this->lote->cantidad_huevos_remanente, 'El lote no debe cambiar');
        }
    }

    public function test_bajar_a_cero_lanza_excepcion_y_dirige_a_borrar(): void
    {
        $viaje = $this->crearViajeMinimo();
        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 10);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Borrar/');

        app(CargaViajeService::class)->reducirCantidad($viaje, $carga, 0);
    }

    public function test_bajar_producto_sin_lote_regresa_a_bodega_con_costo_original(): void
    {
        $categoriaNormal = Categoria::factory()->create();
        $productoNormal = Producto::factory()->create([
            'categoria_id' => $categoriaNormal->id,
            'unidades_por_bulto' => null,
        ]);

        BodegaProducto::create([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $productoNormal->id,
            'stock' => 0,
            'costo_promedio_actual' => 100,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $viaje = $this->crearViajeMinimo();

        $carga = ViajeCarga::create([
            'viaje_id' => $viaje->id,
            'producto_id' => $productoNormal->id,
            'unidad_id' => $productoNormal->unidad_id,
            'cantidad' => 8,
            'costo_unitario' => 100,
            'costo_bodega_original' => 100,
            'cantidad_de_bodega' => 8,
            'cantidad_de_lote' => 0,
            'precio_venta_sugerido' => 150,
            'precio_venta_minimo' => 140,
        ]);

        app(CargaViajeService::class)->reducirCantidad($viaje, $carga, 5);

        $carga->refresh();

        $this->assertSame(5.0, (float) $carga->cantidad);
        $this->assertSame(5.0, (float) $carga->cantidad_de_bodega);
        $this->assertSame(3.0, $this->stockEnBodega($productoNormal->id), 'Las 3 bajadas regresan a bodega');
    }
}
