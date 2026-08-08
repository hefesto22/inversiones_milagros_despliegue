<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Enums\LoteEstado;
use App\Models\Bodega;
use App\Models\BodegaProducto;
use App\Models\Camion;
use App\Models\Categoria;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Viaje;
use App\Models\ViajeCarga;
use App\Services\Inventario\ExistenciasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reporte de existencias: qué hay, cuánto y dónde está.
 *
 * Lo que se cuida acá:
 *   - El huevo se presenta como lo cuenta bodega: cartones y sueltos.
 *   - Las tres ubicaciones (lotes, bodega, camión) se suman sin doble conteo.
 *   - Lo que está en cero no aparece.
 *   - Lo que el reporte no puede ver sale como alerta en vez de perderse.
 */
class ExistenciasServiceTest extends TestCase
{
    use RefreshDatabase;

    private static int $contadorCamion = 600;

    private ExistenciasService $servicio;

    private Bodega $bodega;

    private User $usuario;

    private Unidad $cartonDe30;

    private Producto $huevo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = app(ExistenciasService::class);
        $this->bodega = Bodega::factory()->create(['nombre' => 'Bodega Central']);
        $this->usuario = User::factory()->create();

        // El nombre de la unidad NO debe contener "15": ReempaqueService lo usa
        // para decidir 15 o 30 huevos por cartón, y el factory genera dígitos
        // al azar que podrían colarlo.
        $this->cartonDe30 = Unidad::factory()->create(['nombre' => 'carton_de_treinta']);

        $this->huevo = $this->crearProductoDeHuevo('Huevo mediano');
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * Producto cuya categoría usa lotes (auto-referenciada), o sea: huevo.
     */
    private function crearProductoDeHuevo(string $nombre): Producto
    {
        $categoria = Categoria::factory()->create();
        $categoria->update(['categoria_origen_id' => $categoria->id]);

        return Producto::factory()->create([
            'nombre' => $nombre,
            'categoria_id' => $categoria->id,
            'unidad_id' => $this->cartonDe30->id,
        ]);
    }

    /**
     * Producto que no se lleva por lotes (categoria_origen_id en null).
     */
    private function crearProductoSinLotes(string $nombre): Producto
    {
        return Producto::factory()->create([
            'nombre' => $nombre,
            'categoria_id' => Categoria::factory()->create()->id,
            'unidad_id' => Unidad::factory()->create(['nombre' => 'bulto_grande'])->id,
        ]);
    }

    private function crearLote(Producto $producto, float $huevos, int $porCarton = 30, ?LoteEstado $estado = null): Lote
    {
        return Lote::factory()->create([
            'producto_id' => $producto->id,
            'bodega_id' => $this->bodega->id,
            'huevos_por_carton' => $porCarton,
            'cantidad_huevos_remanente' => $huevos,
            'cantidad_huevos_original' => $huevos,
            'estado' => $estado ?? LoteEstado::Disponible,
        ]);
    }

    private function crearViaje(string $estado = Viaje::ESTADO_EN_RUTA): Viaje
    {
        $n = str_pad((string) ++self::$contadorCamion, 6, '0', STR_PAD_LEFT);

        $camion = Camion::create([
            'codigo' => 'CAM-E-'.$n,
            'placa' => 'TSE-'.$n,
            'bodega_id' => $this->bodega->id,
            'activo' => true,
            'created_by' => $this->usuario->id,
        ]);

        return Viaje::create([
            'camion_id' => $camion->id,
            'chofer_id' => User::factory()->create(['name' => 'Chofer '.$n])->id,
            'bodega_origen_id' => $this->bodega->id,
            'fecha_salida' => now(),
            'estado' => $estado,
        ]);
    }

    private function cargar(
        Viaje $viaje,
        Producto $producto,
        float $cantidad,
        float $vendida = 0,
        float $merma = 0,
        float $devuelta = 0
    ): ViajeCarga {
        return ViajeCarga::create([
            'viaje_id' => $viaje->id,
            'producto_id' => $producto->id,
            'unidad_id' => $producto->unidad_id,
            'cantidad' => $cantidad,
            'costo_unitario' => 70,
            'precio_venta_sugerido' => 100,
            'precio_venta_minimo' => 80,
            'subtotal_costo' => $cantidad * 70,
            'subtotal_venta' => $cantidad * 100,
            'cantidad_vendida' => $vendida,
            'cantidad_merma' => $merma,
            'cantidad_devuelta' => $devuelta,
        ]);
    }

    // =========================================================
    // Lotes
    // =========================================================

    #[Test]
    public function el_huevo_de_los_lotes_se_muestra_en_cartones_y_sueltos(): void
    {
        $this->crearLote($this->huevo, 4520);

        $lotes = $this->servicio->existenciasEnLotes();

        $this->assertCount(1, $lotes);
        $this->assertSame('150 cartones y 20 huevos', $lotes->first()['texto']);
        $this->assertSame('Bodega Central', $lotes->first()['bodega']);
    }

    #[Test]
    public function un_lote_exacto_no_menciona_sueltos(): void
    {
        $this->crearLote($this->huevo, 3000);

        $this->assertSame('100 cartones', $this->servicio->existenciasEnLotes()->first()['texto']);
    }

    #[Test]
    public function los_lotes_sin_remanente_no_aparecen(): void
    {
        $this->crearLote($this->huevo, 0);

        $this->assertTrue($this->servicio->existenciasEnLotes()->isEmpty());
    }

    #[Test]
    public function un_lote_de_cartones_de_quince_usa_su_propio_tamano(): void
    {
        // El tamaño de cartón sale del lote, no de un número fijo.
        $this->crearLote($this->huevo, 155, 15);

        $this->assertSame('10 cartones y 5 huevos', $this->servicio->existenciasEnLotes()->first()['texto']);
    }

    // =========================================================
    // Bodegas
    // =========================================================

    #[Test]
    public function el_producto_empacado_aparece_con_su_bodega(): void
    {
        BodegaProducto::factory()->create([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->huevo->id,
            'stock' => 42,
        ]);

        $bodegas = $this->servicio->existenciasEnBodegas();

        $this->assertCount(1, $bodegas);
        $this->assertSame('Bodega Central', $bodegas->first()['bodega']);
        $this->assertSame('42 cartones', $bodegas->first()['texto']);
    }

    #[Test]
    public function el_producto_sin_stock_no_aparece(): void
    {
        BodegaProducto::factory()->create([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->huevo->id,
            'stock' => 0,
        ]);

        $this->assertTrue($this->servicio->existenciasEnBodegas()->isEmpty());
    }

    #[Test]
    public function un_producto_que_no_es_de_huevo_se_muestra_en_su_unidad(): void
    {
        $otro = $this->crearProductoSinLotes('Caja de cartón');

        BodegaProducto::factory()->create([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $otro->id,
            'stock' => 42,
        ]);

        $this->assertSame('42 bulto_grande', $this->servicio->existenciasEnBodegas()->first()['texto']);
    }

    // =========================================================
    // Camiones
    // =========================================================

    #[Test]
    public function lo_que_queda_a_bordo_descuenta_vendido_merma_y_devuelto(): void
    {
        $viaje = $this->crearViaje();
        $this->cargar($viaje, $this->huevo, cantidad: 100, vendida: 30, merma: 5, devuelta: 10);

        $camiones = $this->servicio->existenciasEnCamiones();

        $this->assertCount(1, $camiones);

        $fila = $camiones->first()['productos'][0];

        $this->assertSame(55.0, $fila['disponible']);
        $this->assertSame('55 cartones', $fila['texto']);
    }

    #[Test]
    public function una_carga_agotada_no_aparece(): void
    {
        $viaje = $this->crearViaje();
        $this->cargar($viaje, $this->huevo, cantidad: 40, vendida: 40);

        $this->assertTrue($this->servicio->existenciasEnCamiones()->isEmpty());
    }

    #[Test]
    public function los_viajes_cerrados_no_cuentan(): void
    {
        $viaje = $this->crearViaje(Viaje::ESTADO_CERRADO);
        $this->cargar($viaje, $this->huevo, cantidad: 40);

        $this->assertTrue($this->servicio->existenciasEnCamiones()->isEmpty());
    }

    #[Test]
    public function un_viaje_planificado_si_cuenta_porque_la_carga_ya_salio_de_bodega(): void
    {
        $viaje = $this->crearViaje(Viaje::ESTADO_PLANIFICADO);
        $this->cargar($viaje, $this->huevo, cantidad: 40);

        $this->assertCount(1, $this->servicio->existenciasEnCamiones());
    }

    // =========================================================
    // Consolidado
    // =========================================================

    #[Test]
    public function el_consolidado_suma_las_tres_ubicaciones(): void
    {
        // 4,520 sueltos en lote + 10 cartones en bodega (300) + 5 en camión (150)
        // = 4,970 huevos = 165 cartones y 20 huevos.
        $this->crearLote($this->huevo, 4520);

        BodegaProducto::factory()->create([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->huevo->id,
            'stock' => 10,
        ]);

        $this->cargar($this->crearViaje(), $this->huevo, cantidad: 5);

        $reporte = $this->servicio->generar();
        $fila = $reporte['consolidado']->firstWhere('producto', 'Huevo mediano');

        $this->assertSame('10 cartones', $fila['bodega']);
        $this->assertSame('150 cartones y 20 huevos', $fila['lotes']);
        $this->assertSame('5 cartones', $fila['camiones']);
        $this->assertSame('165 cartones y 20 huevos', $fila['total']);
    }

    #[Test]
    public function el_consolidado_de_un_producto_sin_lotes_no_inventa_columna_de_lotes(): void
    {
        $otro = $this->crearProductoSinLotes('Caja de cartón');

        BodegaProducto::factory()->create([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $otro->id,
            'stock' => 12,
        ]);

        $fila = $this->servicio->generar()['consolidado']->firstWhere('producto', 'Caja de cartón');

        $this->assertSame('—', $fila['lotes']);
        $this->assertSame('12 bulto_grande', $fila['total']);
    }

    // =========================================================
    // Alertas y estado vacío
    // =========================================================

    #[Test]
    public function avisa_cuando_un_lote_agotado_todavia_tiene_huevos(): void
    {
        $this->crearLote($this->huevo, 300, 30, LoteEstado::Agotado);

        $reporte = $this->servicio->generar();

        // Aparece igual en el detalle: el huevo está físicamente ahí.
        $this->assertCount(1, $reporte['lotes']);
        $this->assertStringContainsString('agotado', implode(' ', $reporte['alertas']));
    }

    #[Test]
    public function avisa_cuando_hay_stock_negativo_en_bodega(): void
    {
        $registro = BodegaProducto::factory()->create([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->huevo->id,
            'stock' => 10,
        ]);

        // El negativo se escribe por el query builder A PROPÓSITO: el modelo
        // clampea el stock a 0 en su hook de saving, así que por Eloquent nunca
        // entra un negativo. Pero decrement() y los updates directos NO disparan
        // eventos de modelo, y ese es justamente el camino por el que un
        // negativo aparecería en producción. Si no se prueba así, la alerta
        // quedaría cubierta por un escenario que no existe.
        DB::table('bodega_producto')->where('id', $registro->id)->update(['stock' => -5]);

        $reporte = $this->servicio->generar();

        $this->assertTrue($reporte['bodegas']->isEmpty());
        $this->assertStringContainsString('negativo', implode(' ', $reporte['alertas']));
    }

    #[Test]
    public function sin_existencias_lo_reporta_explicitamente(): void
    {
        $reporte = $this->servicio->generar();

        $this->assertFalse($reporte['hay_existencias']);
        $this->assertTrue($reporte['consolidado']->isEmpty());
    }
}
