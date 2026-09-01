<?php

declare(strict_types=1);

namespace Tests\Feature\Viajes;

use App\Models\Bodega;
use App\Models\Camion;
use App\Models\Categoria;
use App\Models\ChoferComisionConfig;
use App\Models\Producto;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Viaje;
use App\Models\ViajeCarga;
use App\Models\ViajeComisionDetalle;
use App\Models\ViajeVenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regla de negocio (2026-09-01): NO existe comisión reducida.
 *
 * Al chofer se le paga la misma tarifa venda al precio sugerido o por debajo.
 * La columna chofer_comision_config.comision_reducida quedó sin efecto.
 *
 * Caso real que motivó el cambio: el 2026-07-02 se editó la configuración de
 * Milton dejando comision_reducida en 0.00 en todas sus categorías. En agosto
 * vendió 5,310 cartones por debajo del sugerido y cobró L 0.00 por todos: su
 * comisión cayó de L 7,569.75 (junio) a L 1,455.75 (agosto).
 *
 * @see Viaje::calcularComisionDetalleRuta()
 */
class ComisionSinTarifaReducidaTest extends TestCase
{
    use RefreshDatabase;

    private static int $contadorCamion = 0;

    /**
     * @return array{0: Viaje, 1: ViajeCarga, 2: Producto}
     */
    private function crearEscenario(float $normal = 1.50, float $reducida = 0.00, string $tipo = ChoferComisionConfig::TIPO_FIJO): array
    {
        $admin = User::factory()->create();
        $chofer = User::factory()->create();
        $bodega = Bodega::factory()->create();
        $categoria = Categoria::factory()->create();
        $unidad = Unidad::factory()->create();

        $producto = Producto::factory()->create([
            'categoria_id' => $categoria->id,
            'unidad_id' => $unidad->id,
        ]);

        ChoferComisionConfig::create([
            'user_id' => $chofer->id,
            'categoria_id' => $categoria->id,
            'unidad_id' => null,
            'tipo_comision' => $tipo,
            'comision_normal' => $normal,
            'comision_reducida' => $reducida,
            'vigente_desde' => now()->toDateString(),
            'activo' => true,
            'created_by' => $admin->id,
        ]);

        $n = str_pad((string) ++self::$contadorCamion, 6, '0', STR_PAD_LEFT);
        $camion = Camion::create([
            'codigo' => 'CAM-SR-'.$n,
            'placa' => 'TSR-'.$n,
            'bodega_id' => $bodega->id,
            'activo' => true,
            'created_by' => $admin->id,
        ]);

        $viaje = Viaje::create([
            'camion_id' => $camion->id,
            'chofer_id' => $chofer->id,
            'bodega_origen_id' => $bodega->id,
            'fecha_salida' => now(),
            'estado' => 'en_ruta',
        ]);

        $carga = ViajeCarga::create([
            'viaje_id' => $viaje->id,
            'producto_id' => $producto->id,
            'unidad_id' => $unidad->id,
            'cantidad' => 100,
            'costo_unitario' => 71.44,
            'precio_venta_sugerido' => 95.00,
            'precio_venta_minimo' => 71.44,
            'subtotal_costo' => 7144.00,
            'subtotal_venta' => 9500.00,
        ]);

        return [$viaje, $carga, $producto];
    }

    private function crearVenta(Viaje $viaje, ViajeCarga $carga, Producto $producto, float $cantidad, float $precio): ViajeVenta
    {
        $venta = ViajeVenta::create([
            'viaje_id' => $viaje->id,
            'cliente_id' => null,
            'numero_venta' => ViajeVenta::withTrashed()->where('viaje_id', $viaje->id)->count() + 1 ."-SR-{$viaje->id}",
            'fecha_venta' => now(),
            'tipo_pago' => 'contado',
            'estado' => 'completada',
        ]);

        $venta->detalles()->create([
            'viaje_carga_id' => $carga->id,
            'producto_id' => $producto->id,
            'cantidad' => $cantidad,
            'precio_base' => $precio,
            'precio_con_isv' => $precio,
            'monto_isv' => 0,
            'costo_unitario' => $precio > 0 ? 71.44 : 0,
            'aplica_isv' => false,
            'subtotal' => $cantidad * $precio,
            'total_isv' => 0,
            'total_linea' => $cantidad * $precio,
        ]);

        return $venta;
    }

    public function test_vender_bajo_el_sugerido_paga_la_tarifa_normal_aunque_la_reducida_sea_cero(): void
    {
        // Config exacta de Milton en producción: normal 1.50, reducida 0.00.
        [$viaje, $carga, $producto] = $this->crearEscenario(normal: 1.50, reducida: 0.00);

        // Vende 20 cartones a L80 (sugerido L95) → antes pagaba L0.00
        $this->crearVenta($viaje, $carga, $producto, 20, 80.00);

        $viaje->calcularComisiones();

        $detalle = $viaje->comisionesDetalle()->sole();

        $this->assertSame(1.50, (float) $detalle->comision_unitaria);
        $this->assertSame(30.00, (float) $detalle->comision_total);
        $this->assertSame(30.00, (float) $viaje->fresh()->comision_ganada);
    }

    public function test_se_sigue_marcando_el_tipo_reducida_como_informacion(): void
    {
        // La marca sirve para saber quién vende bajo el sugerido; ya no
        // cambia el monto, pero no debe perderse.
        [$viaje, $carga, $producto] = $this->crearEscenario(normal: 1.50, reducida: 0.00);

        $this->crearVenta($viaje, $carga, $producto, 5, 80.00);
        $this->crearVenta($viaje, $carga, $producto, 5, 95.00);

        $viaje->calcularComisiones();

        $this->assertSame(
            1,
            $viaje->comisionesDetalle()->where('tipo_comision', ViajeComisionDetalle::TIPO_REDUCIDA)->count()
        );
        $this->assertSame(
            1,
            $viaje->comisionesDetalle()->where('tipo_comision', ViajeComisionDetalle::TIPO_NORMAL)->count()
        );

        // Ambas líneas pagan lo mismo: 5 × 1.50 cada una.
        $this->assertSame(15.00, (float) $viaje->fresh()->comision_ganada);
    }

    public function test_vender_al_sugerido_o_arriba_no_cambia(): void
    {
        [$viaje, $carga, $producto] = $this->crearEscenario(normal: 1.50, reducida: 0.00);

        $this->crearVenta($viaje, $carga, $producto, 10, 95.00);
        $this->crearVenta($viaje, $carga, $producto, 10, 100.00);

        $viaje->calcularComisiones();

        $this->assertSame(30.00, (float) $viaje->fresh()->comision_ganada);
    }

    public function test_comision_por_porcentaje_tambien_usa_siempre_la_tarifa_normal(): void
    {
        // 1% normal / 0.5% reducida: bajo el sugerido debe pagar el 1%.
        [$viaje, $carga, $producto] = $this->crearEscenario(
            normal: 1.00,
            reducida: 0.50,
            tipo: ChoferComisionConfig::TIPO_PORCENTAJE,
        );

        $this->crearVenta($viaje, $carga, $producto, 10, 80.00);

        $viaje->calcularComisiones();

        // 10 × (80 × 1%) = 8.00
        $this->assertSame(8.00, (float) $viaje->fresh()->comision_ganada);
    }

    public function test_precio_cero_sigue_sin_generar_comision(): void
    {
        // Regresión de la guarda de entrega por cambio: no debe reaparecer
        // ahora que la tarifa reducida dejó de existir.
        [$viaje, $carga, $producto] = $this->crearEscenario(normal: 1.50, reducida: 0.00);

        $this->crearVenta($viaje, $carga, $producto, 53, 0.00);

        $viaje->calcularComisiones();

        $this->assertSame(0, $viaje->comisionesDetalle()->count());
        $this->assertSame(0.00, (float) $viaje->fresh()->comision_ganada);
    }
}
