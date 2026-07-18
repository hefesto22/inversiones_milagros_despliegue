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
use App\Models\ViajeDescarga;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regla de negocio: un viaje en "Regresando" puede volver a "En Ruta"
 * cuando el chofer presionó "Regresar" por error, siempre que aún no
 * exista ninguna descarga registrada.
 *
 * Al revertir se limpia fecha_regreso, para que el regreso real la
 * estampe de nuevo con la hora correcta.
 *
 * @see Viaje::volverARuta()
 */
class VolverARutaTest extends TestCase
{
    use RefreshDatabase;

    private static int $contadorCamion = 0;

    private function crearViaje(string $estado): Viaje
    {
        $admin = User::factory()->create();
        $chofer = User::factory()->create();
        $bodega = Bodega::factory()->create();

        $n = str_pad((string) ++self::$contadorCamion, 6, '0', STR_PAD_LEFT);
        $camion = Camion::create([
            'codigo' => 'CAM-V-'.$n,
            'placa' => 'TSV-'.$n,
            'bodega_id' => $bodega->id,
            'activo' => true,
            'created_by' => $admin->id,
        ]);

        return Viaje::create([
            'camion_id' => $camion->id,
            'chofer_id' => $chofer->id,
            'bodega_origen_id' => $bodega->id,
            'fecha_salida' => now()->subHours(5),
            'fecha_regreso' => $estado === Viaje::ESTADO_REGRESANDO ? now() : null,
            'estado' => $estado,
        ]);
    }

    public function test_volver_a_ruta_revierte_regreso_accidental(): void
    {
        $viaje = $this->crearViaje(Viaje::ESTADO_REGRESANDO);
        $fechaSalida = $viaje->fecha_salida;

        $viaje->volverARuta();
        $viaje->refresh();

        $this->assertSame(Viaje::ESTADO_EN_RUTA, $viaje->estado);
        $this->assertNull($viaje->fecha_regreso, 'La fecha de regreso accidental debe limpiarse');
        $this->assertEquals($fechaSalida, $viaje->fecha_salida, 'La fecha de salida original no debe tocarse');

        // El regreso real vuelve a estampar la fecha
        $viaje->iniciarRegreso();
        $viaje->refresh();

        $this->assertSame(Viaje::ESTADO_REGRESANDO, $viaje->estado);
        $this->assertNotNull($viaje->fecha_regreso, 'El regreso real debe estampar fecha_regreso de nuevo');
    }

    public function test_no_permite_volver_a_ruta_si_ya_hay_descargas(): void
    {
        $viaje = $this->crearViaje(Viaje::ESTADO_REGRESANDO);

        $categoria = Categoria::factory()->create();
        $unidad = Unidad::factory()->create();
        $producto = Producto::factory()->create([
            'categoria_id' => $categoria->id,
            'unidad_id' => $unidad->id,
        ]);

        ViajeDescarga::create([
            'viaje_id' => $viaje->id,
            'producto_id' => $producto->id,
            'unidad_id' => $unidad->id,
            'cantidad' => 5,
            'costo_unitario' => 71.44,
            'subtotal_costo' => 357.20,
            'estado_producto' => 'bueno',
            'reingresa_stock' => true,
            'procesado_reingreso' => false,
            'cobrar_chofer' => false,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ya tiene descargas registradas');

        $viaje->volverARuta();
    }

    public function test_no_permite_volver_a_ruta_desde_otro_estado(): void
    {
        $viaje = $this->crearViaje(Viaje::ESTADO_EN_RUTA);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('estado "Regresando"');

        $viaje->volverARuta();
    }
}
