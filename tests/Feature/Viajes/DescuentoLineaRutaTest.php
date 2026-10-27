<?php

declare(strict_types=1);

namespace Tests\Feature\Viajes;

use App\Models\Bodega;
use App\Models\Camion;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Viaje;
use App\Models\ViajeCarga;
use App\Models\ViajeVenta;
use App\Services\Viaje\DescuentoLineaRuta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scope: descuento de cada línea de venta de ruta contra el precio de lista
 * (precio sugerido de la carga) y cómo lo muestra el detalle de la venta.
 *
 * Caso real que lo motivó (VJ-000428, 2026-10-27): Cafeína el Cerrito pagó
 * 120 con la lista en 130. El detalle de la venta solo mostraba "P. Unit.
 * 120" y no se entendía que ahí había un descuento de 10 por cartón.
 *
 * Reglas cubiertas:
 *  - Vendida bajo la lista: descuento = lista − precio, por cartón y por línea.
 *  - Al precio de lista o por encima: sin descuento; lista = precio de venta.
 *  - Precio 0 = entrega por cambio: ni descuento ni precio de lista.
 *  - subtotal a precio de lista − descuento = subtotal de la venta.
 *  - El detalle de la venta muestra precio lista, descuento y precio venta.
 *
 * @see DescuentoLineaRuta
 */
class DescuentoLineaRutaTest extends TestCase
{
    use RefreshDatabase;

    private static int $contadorCamion = 0;

    /**
     * @return array{0: Viaje, 1: ViajeCarga, 2: Producto}
     */
    private function crearEscenario(float $precioLista = 130.00): array
    {
        $admin = User::factory()->create();
        $chofer = User::factory()->create();
        $bodega = Bodega::factory()->create();
        $categoria = Categoria::factory()->create();
        $unidad = Unidad::factory()->create();

        $producto = Producto::factory()->create([
            'categoria_id' => $categoria->id,
            'unidad_id' => $unidad->id,
            'nombre' => 'Huevo Grande 1x30',
        ]);

        $n = str_pad((string) ++self::$contadorCamion, 6, '0', STR_PAD_LEFT);
        $camion = Camion::create([
            'codigo' => 'CAM-D-'.$n,
            'placa' => 'TSD-'.$n,
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
            'cantidad' => 200,
            'costo_unitario' => 100.00,
            'precio_venta_sugerido' => $precioLista,
            'precio_venta_minimo' => 100.00,
            'subtotal_costo' => 20000.00,
            'subtotal_venta' => 200 * $precioLista,
        ]);

        return [$viaje, $carga, $producto];
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $lineas  [cantidad, precio]
     */
    private function crearVenta(Viaje $viaje, ViajeCarga $carga, Producto $producto, array $lineas): ViajeVenta
    {
        $venta = ViajeVenta::create([
            'viaje_id' => $viaje->id,
            'cliente_id' => null,
            'numero_venta' => ViajeVenta::withTrashed()->where('viaje_id', $viaje->id)->count() + 1 ."-D-{$viaje->id}",
            'fecha_venta' => now(),
            'tipo_pago' => 'contado',
            'estado' => 'completada',
        ]);

        foreach ($lineas as [$cantidad, $precio]) {
            $venta->detalles()->create([
                'viaje_carga_id' => $carga->id,
                'producto_id' => $producto->id,
                'cantidad' => $cantidad,
                'precio_base' => $precio,
                'precio_con_isv' => $precio,
                'monto_isv' => 0,
                'costo_unitario' => $precio > 0 ? 100.00 : 0,
                'aplica_isv' => false,
                'subtotal' => $cantidad * $precio,
                'total_isv' => 0,
                'total_linea' => $cantidad * $precio,
            ]);
        }

        return $venta->fresh()->load(['detalles.viajeCarga', 'detalles.producto']);
    }

    public function test_vendida_bajo_la_lista_tiene_descuento_por_carton_y_por_linea(): void
    {
        [$viaje, $carga, $producto] = $this->crearEscenario(130.00);
        $venta = $this->crearVenta($viaje, $carga, $producto, [[95, 120.00]]);
        $detalle = $venta->detalles->first();

        $servicio = new DescuentoLineaRuta;

        $this->assertSame(130.00, $servicio->precioLista($detalle));
        $this->assertSame(10.00, $servicio->descuentoUnitario($detalle));
        $this->assertSame(950.00, $servicio->descuentoLinea($detalle));
        $this->assertFalse($servicio->esEntregaPorCambio($detalle));
    }

    public function test_al_precio_de_lista_o_por_encima_no_hay_descuento(): void
    {
        [$viaje, $carga, $producto] = $this->crearEscenario(130.00);
        $venta = $this->crearVenta($viaje, $carga, $producto, [[10, 130.00], [5, 135.00]]);
        [$aLista, $encima] = $venta->detalles->sortBy('id')->values()->all();

        $servicio = new DescuentoLineaRuta;

        $this->assertSame(0.00, $servicio->descuentoLinea($aLista));
        $this->assertSame(130.00, $servicio->precioLista($aLista));
        $this->assertSame(0.00, $servicio->descuentoLinea($encima));
        $this->assertSame(135.00, $servicio->precioLista($encima), 'Sobre la lista: la lista se muestra igual al precio de venta');
    }

    public function test_precio_cero_es_entrega_por_cambio_y_no_descuento(): void
    {
        [$viaje, $carga, $producto] = $this->crearEscenario(130.00);
        $venta = $this->crearVenta($viaje, $carga, $producto, [[5, 0.00]]);
        $detalle = $venta->detalles->first();

        $servicio = new DescuentoLineaRuta;

        $this->assertTrue($servicio->esEntregaPorCambio($detalle));
        $this->assertSame(0.00, $servicio->descuentoLinea($detalle));
        $this->assertSame(0.00, $servicio->precioLista($detalle));
    }

    public function test_subtotal_a_lista_menos_descuento_da_el_subtotal_de_la_venta(): void
    {
        [$viaje, $carga, $producto] = $this->crearEscenario(130.00);
        $venta = $this->crearVenta($viaje, $carga, $producto, [
            [95, 120.00],   // descuento 950
            [10, 130.00],   // a lista
            [5, 135.00],    // por encima
            [3, 0.00],      // entrega por cambio
        ]);

        $totales = (new DescuentoLineaRuta)->totales($venta);

        $this->assertSame(950.00, $totales['descuento']);
        $this->assertEqualsWithDelta(
            (float) $venta->subtotal,
            $totales['subtotal_lista'] - $totales['descuento'],
            0.001
        );
    }

    public function test_el_detalle_de_la_venta_muestra_precio_lista_descuento_y_precio_venta(): void
    {
        [$viaje, $carga, $producto] = $this->crearEscenario(130.00);
        $venta = $this->crearVenta($viaje, $carga, $producto, [[95, 120.00]]);
        $venta->load(['cliente', 'userCreador']);

        $html = view('filament.resources.viaje.ventas-detalle-modal', ['venta' => $venta])->render();

        $this->assertStringContainsString('Precio lista', $html);
        $this->assertStringContainsString('Precio venta', $html);
        $this->assertStringContainsString('130.00', $html);
        $this->assertStringContainsString('- 10.00', $html);
        $this->assertStringContainsString('Subtotal a precio de lista', $html);
        $this->assertStringContainsString('L 12,350.00', $html);
        $this->assertStringContainsString('- L 950.00', $html);
        $this->assertStringContainsString('L 11,400.00', $html);
    }
}
