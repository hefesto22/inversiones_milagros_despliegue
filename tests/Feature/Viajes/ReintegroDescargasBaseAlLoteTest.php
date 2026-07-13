<?php

declare(strict_types=1);

namespace Tests\Feature\Viajes;

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
use App\Models\ViajeDescarga;
use App\Services\Viaje\ReintegroDescargasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests de la regla de negocio del reintegro de descargas (2026-07-12):
 *
 *   - Producto BASE 1x30 (categoría auto-referenciada — Huevo Grande/Mediano/
 *     Pequeño/PW/Extra Grande): los cartones NO vendidos regresan AL LOTE
 *     revirtiendo el reempaque automático de la carga (costo según WAC actual
 *     del lote). bodega_producto.stock de estos productos se mantiene en 0.
 *
 *   - Producto DERIVADO (OPOA 1x30 / 1x15, categoría con origen distinto) y
 *     productos sin lote: regresan al stock de bodega_producto, como siempre.
 *
 *   - Fracciones de cartón (sueltos): al lote único del producto.
 *
 *   - Idempotencia: viaje_descargas.procesado_reingreso evita el doble
 *     reintegro (reingreso manual + cierre del viaje).
 *
 * Escenario base: lote de 3,000 huevos a L 7,500 (L 2.50/huevo, L 75/cartón).
 */
class ReintegroDescargasBaseAlLoteTest extends TestCase
{
    use RefreshDatabase;

    private static int $contadorCamion = 500;

    private Bodega $bodega;

    private Categoria $categoriaBase;

    private Producto $productoBase;

    private Lote $lote;

    protected function setUp(): void
    {
        parent::setUp();

        // Espejo de producción: el dual-write WAC está activo. Sin este flag
        // el ActualizarWacListener se salta (kill-switch, default false en
        // testing) y las columnas wac_* no se moverían.
        Config::set('inventario.wac.shadow_mode', true);

        // Auth: reempaques y lotes registran created_by
        $this->actingAs(User::factory()->create());

        $this->bodega = Bodega::factory()->create();

        // Categoría BASE: auto-referenciada (origen = ella misma),
        // igual que Huevo Grande/Mediano/Pequeño en producción.
        $this->categoriaBase = Categoria::factory()->create();
        $this->categoriaBase->update(['categoria_origen_id' => $this->categoriaBase->id]);
        $this->categoriaBase->refresh();

        // Unidad SIN "15" en el nombre → getHuevosPorUnidad() = 30.
        // lexify usa solo letras para que el nombre nunca contenga dígitos.
        $unidad30 = Unidad::factory()->create([
            'nombre' => 'carton_treinta_'.fake()->unique()->lexify('??????'),
        ]);

        $this->productoBase = Producto::factory()->create([
            'categoria_id' => $this->categoriaBase->id,
            'unidad_id' => $unidad30->id,
            'formato_empaque' => '1x30',
            'unidades_por_bulto' => 30,
        ]);

        // Lote único del producto base: 3,000 huevos, L 7,500 (L 2.50/huevo).
        // Se pueblan costos legacy Y WAC para que el test sea estable sin
        // importar el valor de inventario.wac.read_source.
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

    private function crearViajeMinimo(string $estado = 'liquidando'): Viaje
    {
        $chofer = User::factory()->create();
        $admin = User::factory()->create();

        $n = str_pad((string) ++self::$contadorCamion, 6, '0', STR_PAD_LEFT);
        $camion = Camion::create([
            'codigo' => 'CAM-R-'.$n,
            'placa' => 'TSR-'.$n,
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
     * Cargar el camión con producto vía reempaque automático desde el lote
     * (mismo camino que CargasRelationManager) y devolver la carga creada.
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

    private function crearDescarga(Viaje $viaje, Producto $producto, float $cantidad, string $estado = 'bueno'): ViajeDescarga
    {
        return ViajeDescarga::create([
            'viaje_id' => $viaje->id,
            'producto_id' => $producto->id,
            'unidad_id' => $producto->unidad_id,
            'cantidad' => $cantidad,
            'costo_unitario' => 75,
            'estado_producto' => $estado,
            'reingresa_stock' => true,
            'cobrar_chofer' => false,
            'monto_cobrar' => 0,
        ]);
    }

    private function stockEnBodega(int $productoId): float
    {
        return (float) (BodegaProducto::where('bodega_id', $this->bodega->id)
            ->where('producto_id', $productoId)
            ->value('stock') ?? 0.0);
    }

    private function crearProductoOpoa(int $huevosPorUnidad = 30): Producto
    {
        // Categoría DERIVADA: origen = categoría base (como Opoa Huevo Mediano)
        $categoriaOpoa = Categoria::factory()->create([
            'categoria_origen_id' => $this->categoriaBase->id,
        ]);

        $nombreUnidad = $huevosPorUnidad === 15
            ? 'carton_15_'.fake()->unique()->lexify('??????')
            : 'carton_opoa_'.fake()->unique()->lexify('??????');

        $unidad = Unidad::factory()->create(['nombre' => $nombreUnidad]);

        return Producto::factory()->create([
            'categoria_id' => $categoriaOpoa->id,
            'unidad_id' => $unidad->id,
            'formato_empaque' => $huevosPorUnidad === 15 ? '1x15' : '1x30',
            'unidades_por_bulto' => $huevosPorUnidad,
        ]);
    }

    // =========================================================
    // Regla principal: base 1x30 → LOTE
    // =========================================================

    public function test_base_1x30_no_vendido_regresa_al_lote_y_no_a_bodega(): void
    {
        $viaje = $this->crearViajeMinimo();

        // Cargar 30 cartones (900 huevos) desde el lote
        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 30);

        $this->lote->refresh();
        $this->assertSame(2100.0, (float) $this->lote->cantidad_huevos_remanente, 'Precondición: la carga consumió 900 huevos');

        // Se vendieron 20; regresan 10
        $carga->update(['cantidad_vendida' => 20, 'cantidad_devuelta' => 10]);
        $descarga = $this->crearDescarga($viaje, $this->productoBase, 10);

        app(ReintegroDescargasService::class)->procesarReintegrosPendientes($viaje);

        $this->lote->refresh();
        $descarga->refresh();

        // Los 10 cartones (300 huevos) regresaron AL LOTE
        $this->assertSame(2400.0, (float) $this->lote->cantidad_huevos_remanente, 'Los 300 huevos devueltos deben estar en el lote');

        // WAC: cantidad reintegrada al costo actual — costo unitario intacto
        $this->assertSame(2400.0, (float) $this->lote->wac_huevos_inventario, 'El WAC debe reflejar los huevos devueltos');
        $this->assertEqualsWithDelta(2.5, (float) $this->lote->wac_costo_por_huevo, 0.0001, 'El costo por huevo WAC no debe cambiar');

        // bodega_producto.stock del producto base se mantiene en 0
        $this->assertSame(0.0, $this->stockEnBodega($this->productoBase->id), 'El huevo base vive en el lote: bodega debe quedar en 0');

        // El reempaque quedó con las 20 unidades realmente vendidas
        $this->assertSame(
            20.0,
            (float) ReempaqueProducto::where('reempaque_id', $carga->reempaque_id)
                ->where('producto_id', $this->productoBase->id)
                ->value('cantidad'),
            'El reempaque debe reflejar solo lo vendido'
        );

        $this->assertTrue($descarga->procesado_reingreso, 'La descarga debe quedar marcada como procesada');
    }

    public function test_fraccion_de_base_regresa_al_lote_como_sueltos(): void
    {
        $viaje = $this->crearViajeMinimo();

        // Cargar 5 cartones (150 huevos) → remanente 2,850
        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 5);
        $carga->update(['cantidad_vendida' => 2.5, 'cantidad_devuelta' => 2.5]);

        // Regresan 2.5 cartones: 2 completos (reversión) + 15 huevos sueltos
        $this->crearDescarga($viaje, $this->productoBase, 2.5);

        app(ReintegroDescargasService::class)->procesarReintegrosPendientes($viaje);

        $this->lote->refresh();

        // 2,850 + 60 (2 cartones) + 15 (sueltos) = 2,925
        $this->assertSame(2925.0, (float) $this->lote->cantidad_huevos_remanente);
        $this->assertSame(0.0, $this->stockEnBodega($this->productoBase->id));
    }

    public function test_base_sin_reempaque_revertible_regresa_al_lote_unico(): void
    {
        // Carga legacy: vino "de bodega", sin reempaque asociado.
        // La regla manda igual al lote para preservar el invariante bodega=0.
        $viaje = $this->crearViajeMinimo();

        ViajeCarga::create([
            'viaje_id' => $viaje->id,
            'reempaque_id' => null,
            'producto_id' => $this->productoBase->id,
            'unidad_id' => $this->productoBase->unidad_id,
            'cantidad' => 10,
            'costo_unitario' => 75,
            'costo_bodega_original' => 75,
            'cantidad_de_bodega' => 10,
            'cantidad_de_lote' => 0,
            'cantidad_devuelta' => 10,
            'precio_venta_sugerido' => 100,
            'precio_venta_minimo' => 90,
        ]);

        $this->crearDescarga($viaje, $this->productoBase, 10);

        app(ReintegroDescargasService::class)->procesarReintegrosPendientes($viaje);

        $this->lote->refresh();

        // 3,000 + 300 huevos directo al lote único (devolverHuevos)
        $this->assertSame(3300.0, (float) $this->lote->cantidad_huevos_remanente);
        $this->assertSame(0.0, $this->stockEnBodega($this->productoBase->id));
    }

    // =========================================================
    // OPOA / derivados y productos sin lote → BODEGA (sin cambios)
    // =========================================================

    public function test_opoa_no_vendido_regresa_a_stock_de_bodega(): void
    {
        $productoOpoa = $this->crearProductoOpoa(huevosPorUnidad: 30);
        $viaje = $this->crearViajeMinimo();

        // Cargar 10 OPOA 1x30 (300 huevos del lote base) → remanente 2,700
        $carga = $this->cargarDesdeLote($viaje, $productoOpoa, 10);
        $carga->update(['cantidad_vendida' => 5, 'cantidad_devuelta' => 5]);

        $this->crearDescarga($viaje, $productoOpoa, 5);

        app(ReintegroDescargasService::class)->procesarReintegrosPendientes($viaje);

        $this->lote->refresh();

        // El lote NO recibe nada: el OPOA ya fue reempacado físicamente
        $this->assertSame(2700.0, (float) $this->lote->cantidad_huevos_remanente, 'El lote no debe recibir devoluciones de OPOA');

        // El stock de bodega del OPOA sí sube
        $this->assertSame(5.0, $this->stockEnBodega($productoOpoa->id), 'El OPOA devuelto debe quedar en bodega_producto');
    }

    public function test_opoa_1x15_no_vendido_regresa_a_stock_de_bodega(): void
    {
        $productoOpoa15 = $this->crearProductoOpoa(huevosPorUnidad: 15);
        $viaje = $this->crearViajeMinimo();

        // Cargar 5 OPOA 1x15 (75 huevos del lote base) → remanente 2,925
        $carga = $this->cargarDesdeLote($viaje, $productoOpoa15, 5);
        $carga->update(['cantidad_vendida' => 2, 'cantidad_devuelta' => 3]);

        $this->crearDescarga($viaje, $productoOpoa15, 3);

        app(ReintegroDescargasService::class)->procesarReintegrosPendientes($viaje);

        $this->lote->refresh();

        $this->assertSame(2925.0, (float) $this->lote->cantidad_huevos_remanente);
        $this->assertSame(3.0, $this->stockEnBodega($productoOpoa15->id));
    }

    public function test_producto_sin_lote_sigue_regresando_a_bodega(): void
    {
        // Producto normal (categoría sin origen — no usa lotes)
        $categoriaNormal = Categoria::factory()->create();
        $productoNormal = Producto::factory()->create([
            'categoria_id' => $categoriaNormal->id,
            'unidades_por_bulto' => null,
        ]);

        $viaje = $this->crearViajeMinimo();

        ViajeCarga::create([
            'viaje_id' => $viaje->id,
            'producto_id' => $productoNormal->id,
            'unidad_id' => $productoNormal->unidad_id,
            'cantidad' => 8,
            'costo_unitario' => 100,
            'costo_bodega_original' => 100,
            'cantidad_de_bodega' => 8,
            'cantidad_de_lote' => 0,
            'cantidad_devuelta' => 5,
            'precio_venta_sugerido' => 150,
            'precio_venta_minimo' => 140,
        ]);

        $this->crearDescarga($viaje, $productoNormal, 5);

        app(ReintegroDescargasService::class)->procesarReintegrosPendientes($viaje);

        $this->assertSame(5.0, $this->stockEnBodega($productoNormal->id));
        $this->assertSame(
            0,
            Lote::where('producto_id', $productoNormal->id)->count(),
            'Un producto sin lotes no debe generar lotes al reintegrar'
        );
    }

    // =========================================================
    // Idempotencia y estados
    // =========================================================

    public function test_reingreso_manual_seguido_de_cierre_no_duplica(): void
    {
        $viaje = $this->crearViajeMinimo();

        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 30);
        $carga->update(['cantidad_vendida' => 20, 'cantidad_devuelta' => 10]);
        $descarga = $this->crearDescarga($viaje, $this->productoBase, 10);

        $servicio = app(ReintegroDescargasService::class);

        // 1. Reingreso manual (acción del relation manager)
        $mensajes = $servicio->procesarReintegro($viaje, $descarga);
        $this->assertNotEmpty($mensajes, 'El primer reingreso debe aplicar movimientos');

        // 2. Cierre del viaje (procesa pendientes) — no debe duplicar
        $servicio->procesarReintegrosPendientes($viaje);

        // 3. Reintento directo sobre la misma descarga — tampoco
        $this->assertSame([], $servicio->procesarReintegro($viaje, $descarga->refresh()));

        $this->lote->refresh();
        $this->assertSame(
            2400.0,
            (float) $this->lote->cantidad_huevos_remanente,
            'Los 300 huevos deben reintegrarse UNA sola vez'
        );
    }

    public function test_descarga_danada_no_reintegra_nada(): void
    {
        $viaje = $this->crearViajeMinimo();

        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 10);
        $carga->update(['cantidad_devuelta' => 10]);
        $descarga = $this->crearDescarga($viaje, $this->productoBase, 10, estado: 'danado');

        app(ReintegroDescargasService::class)->procesarReintegrosPendientes($viaje);

        $this->lote->refresh();
        $descarga->refresh();

        // El lote quedó como lo dejó la carga (2,700) — nada regresó
        $this->assertSame(2700.0, (float) $this->lote->cantidad_huevos_remanente);
        $this->assertSame(0.0, $this->stockEnBodega($this->productoBase->id));
        $this->assertFalse($descarga->procesado_reingreso, 'Una descarga dañada no debe marcarse como reintegrada');
    }

    public function test_cerrar_viaje_end_to_end_aplica_regla_base_al_lote(): void
    {
        $viaje = $this->crearViajeMinimo(estado: 'liquidando');

        $carga = $this->cargarDesdeLote($viaje, $this->productoBase, 30);
        $carga->update(['cantidad_vendida' => 20, 'cantidad_devuelta' => 10]);
        $descarga = $this->crearDescarga($viaje, $this->productoBase, 10);

        // Eager load: registrarMovimientosContables() accede a chofer->cuenta
        // y Model::shouldBeStrict() está activo en el entorno de tests.
        $viaje->load('chofer.cuenta');

        $viaje->cerrar();

        $viaje->refresh();
        $this->lote->refresh();
        $descarga->refresh();

        $this->assertSame('cerrado', $viaje->estado);
        $this->assertSame(2400.0, (float) $this->lote->cantidad_huevos_remanente, 'El cierre debe devolver los 10 cartones al lote');
        $this->assertSame(0.0, $this->stockEnBodega($this->productoBase->id));
        $this->assertTrue($descarga->procesado_reingreso);
    }
}
