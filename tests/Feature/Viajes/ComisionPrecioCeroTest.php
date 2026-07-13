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
use App\Models\ViajeVenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regla de negocio: líneas de venta a precio 0 NO generan comisión.
 *
 * Caso real que motivó la guarda (viaje 239, 2026-07-02): se entregaron
 * 53 cartones a un cliente por cambio/reposición, registrados como venta
 * con precio 0 y costo 0. Sin la guarda, la comisión fija del chofer
 * (L1.50/cartón, tipo "reducida" porque 0 < precio sugerido) le pagaba
 * L79.50 por producto que entregó gratis.
 *
 * @see Viaje::calcularComisionDetalleRuta()
 */
class ComisionPrecioCeroTest extends TestCase
{
    use RefreshDatabase;

    private static int $contadorCamion = 0;

    private function crearEscenario(): array
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

        // Comisión fija L1.50 por unidad (normal y reducida), como Milton en producción.
        ChoferComisionConfig::create([
            'user_id' => $chofer->id,
            'categoria_id' => $categoria->id,
            'unidad_id' => null,
            'tipo_comision' => ChoferComisionConfig::TIPO_FIJO,
            'comision_normal' => 1.50,
            'comision_reducida' => 1.50,
            'vigente_desde' => now()->toDateString(),
            'activo' => true,
            'created_by' => $admin->id,
        ]);

        $n = str_pad((string) ++self::$contadorCamion, 6, '0', STR_PAD_LEFT);
        $camion = Camion::create([
            'codigo' => 'CAM-C-'.$n,
            'placa' => 'TSC-'.$n,
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
            'cantidad' => 60,
            'costo_unitario' => 71.44,
            'precio_venta_sugerido' => 95.00,
            'precio_venta_minimo' => 71.44,
            'subtotal_costo' => 4286.40,
            'subtotal_venta' => 5700.00,
        ]);

        return [$viaje, $carga, $producto];
    }

    private function crearVenta(Viaje $viaje, ViajeCarga $carga, Producto $producto, float $cantidad, float $precio): ViajeVenta
    {
        $venta = ViajeVenta::create([
            'viaje_id' => $viaje->id,
            'cliente_id' => null,
            'numero_venta' => ViajeVenta::withTrashed()->where('viaje_id', $viaje->id)->count() + 1 ."-T-{$viaje->id}",
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

    public function test_linea_a_precio_cero_no_genera_comision(): void
    {
        [$viaje, $carga, $producto] = $this->crearEscenario();

        // Venta real: 2 cartones a precio sugerido → comisión normal 2 × 1.50
        $this->crearVenta($viaje, $carga, $producto, 2, 95.00);

        // Entrega por cambio: 53 cartones a precio 0 → SIN comisión
        $this->crearVenta($viaje, $carga, $producto, 53, 0.00);

        $viaje->calcularComisiones();

        $this->assertSame(
            1,
            $viaje->comisionesDetalle()->count(),
            'Solo la línea con precio > 0 debe generar detalle de comisión'
        );

        $comision = $viaje->comisionesDetalle()->first();
        $this->assertSame(95.00, (float) $comision->precio_vendido);
        $this->assertSame(3.00, (float) $comision->comision_total);

        $this->assertSame(
            3.00,
            (float) $viaje->fresh()->comision_ganada,
            'La comisión del viaje debe excluir por completo la línea a precio 0'
        );
    }

    public function test_venta_normal_sigue_generando_comision_reducida_bajo_sugerido(): void
    {
        // Regresión: la guarda solo aplica a precio <= 0. Vender a L80
        // (bajo sugerido pero > 0) debe seguir pagando comisión reducida.
        [$viaje, $carga, $producto] = $this->crearEscenario();

        $this->crearVenta($viaje, $carga, $producto, 10, 80.00);

        $viaje->calcularComisiones();

        $this->assertSame(1, $viaje->comisionesDetalle()->count());
        $this->assertSame(15.00, (float) $viaje->comisionesDetalle()->first()->comision_total);
    }
}
