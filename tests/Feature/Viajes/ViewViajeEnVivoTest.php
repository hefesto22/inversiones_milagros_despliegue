<?php

declare(strict_types=1);

namespace Tests\Feature\Viajes;

use App\Filament\Resources\ViajeResource\Pages\ViewViaje;
use App\Models\Bodega;
use App\Models\Camion;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Viaje;
use App\Models\ViajeCarga;
use App\Models\ViajeMerma;
use App\Models\ViajeVenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Scope: el resumen de la pantalla del viaje (ViewViaje) muestra mermas y
 * descuentos en tiempo real, mientras el viaje sigue en ruta.
 *
 * Caso real que lo motivó (VJ-000428, 2026-10-27): el viaje tenía dos
 * mermas registradas (L 479.18) y el resumen mostraba 0, porque leía
 * viajes.total_merma_costo, que solo se refresca en recalcularTotales()
 * (Continuar Ruta y cierre).
 *
 * Reglas cubiertas:
 *  - Las mermas se suman en vivo desde viaje_mermas, sin esperar al cierre.
 *  - La utilidad neta descuenta esas mermas en vivo.
 *  - Con el viaje ya recalculado, el valor en vivo coincide con el guardado.
 *  - Los descuentos se calculan en vivo contra el precio de la carga y
 *    excluyen las líneas a precio 0 (entregas por cambio).
 *
 * @see ViewViaje::calcularMermas()
 * @see ViewViaje::calcularDescuentosOtorgados()
 */
class ViewViajeEnVivoTest extends TestCase
{
    use RefreshDatabase;

    private static int $contadorCamion = 0;

    /**
     * @return array{0: Viaje, 1: ViajeCarga, 2: Producto, 3: User}
     */
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

        $n = str_pad((string) ++self::$contadorCamion, 6, '0', STR_PAD_LEFT);
        $camion = Camion::create([
            'codigo' => 'CAM-V-'.$n,
            'placa' => 'TSV-'.$n,
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
            'cantidad' => 144,
            'costo_unitario' => 100.00,
            'precio_venta_sugerido' => 130.00,
            'precio_venta_minimo' => 100.00,
            'subtotal_costo' => 14400.00,
            'subtotal_venta' => 18720.00,
        ]);

        return [$viaje, $carga, $producto, $admin];
    }

    private function crearVenta(Viaje $viaje, ViajeCarga $carga, Producto $producto, float $cantidad, float $precio): ViajeVenta
    {
        $venta = ViajeVenta::create([
            'viaje_id' => $viaje->id,
            'cliente_id' => null,
            'numero_venta' => ViajeVenta::withTrashed()->where('viaje_id', $viaje->id)->count() + 1 ."-V-{$viaje->id}",
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
            'costo_unitario' => $precio > 0 ? 100.00 : 0,
            'aplica_isv' => false,
            'subtotal' => $cantidad * $precio,
            'total_isv' => 0,
            'total_linea' => $cantidad * $precio,
        ]);

        return $venta;
    }

    private function crearMerma(Viaje $viaje, ViajeCarga $carga, User $admin, float $cantidad, float $costo): ViajeMerma
    {
        return ViajeMerma::create([
            'viaje_id' => $viaje->id,
            'producto_id' => $carga->producto_id,
            'unidad_id' => $carga->unidad_id,
            'cantidad' => $cantidad,
            'costo_unitario' => $costo,
            'motivo' => ViajeMerma::MOTIVO_ROTURA,
            'cobrar_chofer' => false,
            'monto_cobrar' => 0,
            'registrado_por' => $admin->id,
        ]);
    }

    /**
     * Los cálculos del resumen son métodos protegidos de la página; se
     * invocan directo para no depender del panel, Shield ni el render.
     */
    private function calcular(string $metodo, Viaje $viaje): float
    {
        $pagina = new ViewViaje;

        return (float) (new ReflectionMethod($pagina, $metodo))->invoke($pagina, $viaje->fresh());
    }

    public function test_mermas_se_muestran_en_vivo_sin_esperar_al_cierre(): void
    {
        [$viaje, $carga, , $admin] = $this->crearEscenario();

        $this->crearMerma($viaje, $carga, $admin, 4, 107.47);
        $this->crearMerma($viaje, $carga, $admin, 1, 49.30);

        $esperado = (float) ViajeMerma::where('viaje_id', $viaje->id)->sum('subtotal_costo');

        $this->assertGreaterThan(0, $esperado);
        $this->assertSame(
            0.0,
            (float) $viaje->fresh()->total_merma_costo,
            'Precondición: el total guardado no se refresca al registrar mermas'
        );
        $this->assertEqualsWithDelta($esperado, $this->calcular('calcularMermas', $viaje), 0.001);
    }

    public function test_utilidad_neta_descuenta_las_mermas_en_vivo(): void
    {
        [$viaje, $carga, $producto, $admin] = $this->crearEscenario();

        // 10 cartones a 130 con costo 100 → margen bruto 300
        $this->crearVenta($viaje, $carga, $producto, 10, 130.00);
        $this->crearMerma($viaje, $carga, $admin, 4, 107.47);

        $mermas = (float) ViajeMerma::where('viaje_id', $viaje->id)->sum('subtotal_costo');

        $this->assertEqualsWithDelta(300.00, $this->calcular('calcularMargenBruto', $viaje), 0.001);
        $this->assertEqualsWithDelta(300.00 - $mermas, $this->calcular('calcularUtilidadNeta', $viaje), 0.001);
    }

    public function test_viaje_recalculado_da_el_mismo_valor_en_vivo_que_guardado(): void
    {
        [$viaje, $carga, , $admin] = $this->crearEscenario();

        $this->crearMerma($viaje, $carga, $admin, 2, 99.65);
        $viaje->fresh()->recalcularTotales();

        $guardado = (float) $viaje->fresh()->total_merma_costo;

        $this->assertGreaterThan(0, $guardado);
        $this->assertEqualsWithDelta($guardado, $this->calcular('calcularMermas', $viaje), 0.001);
    }

    public function test_descuentos_se_calculan_en_vivo_contra_el_precio_de_la_carga(): void
    {
        [$viaje, $carga, $producto] = $this->crearEscenario();

        // Carga a 130: 95 cartones a 120 → descuento 95 × 10 = 950
        $this->crearVenta($viaje, $carga, $producto, 95, 120.00);
        // A precio de carga → no suma descuento
        $this->crearVenta($viaje, $carga, $producto, 10, 130.00);
        // Entrega por cambio a precio 0 → no es descuento
        $this->crearVenta($viaje, $carga, $producto, 5, 0.00);

        $this->assertEqualsWithDelta(950.00, $this->calcular('calcularDescuentosOtorgados', $viaje), 0.001);
    }
}
