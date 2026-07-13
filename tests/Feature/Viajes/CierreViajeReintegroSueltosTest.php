<?php

declare(strict_types=1);

namespace Tests\Feature\Viajes;

use App\Enums\LoteEstado;
use App\Events\Inventario\DevolucionAplicadaAlLote;
use App\Models\Bodega;
use App\Models\Camion;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use App\Models\Viaje;
use App\Services\Viaje\ReintegroDescargasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests del fix al bug "Duplicate entry '{producto}-{bodega}-disponible'
 * for key 'lote_unico_producto_bodega'" que aparecía al cerrar un viaje
 * cuyo reintegro de descargas generaba huevos sueltos sobre un producto/bodega
 * que ya tenía un lote único disponible (caso normal de producción).
 *
 * El reintegro delega en Lote::obtenerOCrearLoteUnico + Lote::devolverHuevos
 * en lugar de intentar crear lotes con prefijo "SUELTOS-Pxx-Bxx" que chocaban
 * contra la constraint unique(producto_id, bodega_id, estado).
 *
 * Refactor 2026-07-12: la lógica se movió de Viaje::reintegrarALoteSueltos
 * (protected, se invocaba vía reflection) a
 * ReintegroDescargasService::reintegrarSueltosAlLoteUnico (público). Los
 * escenarios cubiertos son los mismos. La cobertura del cierre end-to-end
 * vive en ReintegroDescargasBaseAlLoteTest.
 */
class CierreViajeReintegroSueltosTest extends TestCase
{
    use RefreshDatabase;

    private static int $contadorCamion = 0;

    private function crearViajeMinimo(int $bodegaId): Viaje
    {
        $chofer = User::factory()->create();
        $admin = User::factory()->create();

        // camiones.codigo y camiones.placa son string(20) y UNIQUE.
        // Usar contador estático garantiza unicidad determinística aun con RefreshDatabase.
        $n = str_pad((string) ++self::$contadorCamion, 6, '0', STR_PAD_LEFT);
        $camion = Camion::create([
            'codigo' => 'CAM-T-'.$n,   // 12 chars
            'placa' => 'TST-'.$n,     // 10 chars
            'bodega_id' => $bodegaId,
            'activo' => true,
            'created_by' => $admin->id,
        ]);

        return Viaje::create([
            'camion_id' => $camion->id,
            'chofer_id' => $chofer->id,
            'bodega_origen_id' => $bodegaId,
            'fecha_salida' => now(),
            'estado' => 'liquidando',
        ]);
    }

    /**
     * Invoca el reintegro de sueltos vía ReintegroDescargasService
     * (mismo camino que usan el cierre del viaje y la acción manual).
     */
    private function invocarReintegro(
        Viaje $viaje,
        int $productoId,
        int $cantidadHuevos,
        int $unidadesPorBulto
    ): void {
        app(ReintegroDescargasService::class)->reintegrarSueltosAlLoteUnico(
            $viaje,
            $productoId,
            $cantidadHuevos,
            $unidadesPorBulto
        );
    }

    public function test_reintegro_no_explota_cuando_ya_existe_lote_unico_disponible(): void
    {
        // Reproduce exactamente la situación de producción del 2026-05-18:
        // existe un LU-B*-P* con estado=disponible para (producto, bodega).
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();

        $loteExistente = Lote::factory()
            ->wacInicializado(huevos: 5669.0, costoInventario: 12282.83)
            ->create([
                'numero_lote' => "LU-B{$bodega->id}-P{$producto->id}",
                'producto_id' => $producto->id,
                'bodega_id' => $bodega->id,
                'estado' => LoteEstado::Disponible,
            ]);

        $viaje = $this->crearViajeMinimo($bodega->id);

        $remananteAntes = (float) $loteExistente->cantidad_huevos_remanente;

        // Antes del fix: lanzaba SQLSTATE[23000] 1062 Duplicate entry.
        $this->invocarReintegro($viaje, $producto->id, 15, 30);

        $loteExistente->refresh();

        // Mismo lote — no se creó uno paralelo
        $this->assertSame(1, Lote::where('producto_id', $producto->id)
            ->where('bodega_id', $bodega->id)
            ->count(), 'No deben existir lotes paralelos para (producto, bodega)');

        $this->assertSame(
            $remananteAntes + 15,
            (float) $loteExistente->cantidad_huevos_remanente,
            'El remanente debe haberse incrementado en la cantidad reintegrada'
        );

        $this->assertSame(
            LoteEstado::Disponible,
            $loteExistente->estado,
            'El estado del lote único debe permanecer disponible'
        );
    }

    public function test_reintegro_reactiva_lote_unico_agotado(): void
    {
        // Caso que también va a ocurrir en producción: el último cliente vació
        // el lote y luego un viaje regresa con sueltos del mismo producto.
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();

        $loteAgotado = Lote::factory()
            ->wacInicializado(huevos: 1000.0, costoInventario: 2500.0)
            ->agotado()
            ->create([
                'numero_lote' => "LU-B{$bodega->id}-P{$producto->id}",
                'producto_id' => $producto->id,
                'bodega_id' => $bodega->id,
            ]);

        $viaje = $this->crearViajeMinimo($bodega->id);

        $this->invocarReintegro($viaje, $producto->id, 30, 30);

        $loteAgotado->refresh();

        $this->assertSame(
            LoteEstado::Disponible,
            $loteAgotado->estado,
            'Un reintegro sobre lote agotado debe reactivarlo a disponible'
        );

        $this->assertSame(
            30.0,
            (float) $loteAgotado->cantidad_huevos_remanente,
            'El remanente debe quedar igual a la cantidad reintegrada (estaba en 0)'
        );
    }

    public function test_reintegro_crea_lote_unico_cuando_no_existe(): void
    {
        // Producto/bodega nuevo, sin historial de lote.
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();

        $this->assertSame(
            0,
            Lote::where('producto_id', $producto->id)
                ->where('bodega_id', $bodega->id)
                ->count(),
            'Precondición: no debe haber lote previo'
        );

        $viaje = $this->crearViajeMinimo($bodega->id);

        $this->invocarReintegro($viaje, $producto->id, 12, 30);

        $loteCreado = Lote::where('producto_id', $producto->id)
            ->where('bodega_id', $bodega->id)
            ->first();

        $this->assertNotNull($loteCreado, 'Debe haberse creado un lote único nuevo');
        $this->assertSame(
            "LU-B{$bodega->id}-P{$producto->id}",
            $loteCreado->numero_lote,
            'El lote nuevo debe seguir el formato LU-B{bodega}-P{producto}'
        );
        $this->assertSame(LoteEstado::Disponible, $loteCreado->estado);
        $this->assertSame(12.0, (float) $loteCreado->cantidad_huevos_remanente);
    }

    public function test_reintegro_dispara_devolucion_aplicada_al_lote_para_que_wa_c_se_actualice(): void
    {
        // Garantiza que el reintegro entra al motor WAC central como el resto
        // de los flujos (compras, ventas, devoluciones). Antes del fix el
        // evento NO se disparaba porque el método obsoleto hacía Lote::create
        // directo sin pasar por devolverHuevos().
        Event::fake([DevolucionAplicadaAlLote::class]);

        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();

        Lote::factory()
            ->wacInicializado(huevos: 1000.0, costoInventario: 2500.0)
            ->create([
                'numero_lote' => "LU-B{$bodega->id}-P{$producto->id}",
                'producto_id' => $producto->id,
                'bodega_id' => $bodega->id,
                'estado' => LoteEstado::Disponible,
            ]);

        $viaje = $this->crearViajeMinimo($bodega->id);

        $this->invocarReintegro($viaje, $producto->id, 10, 30);

        Event::assertDispatched(
            DevolucionAplicadaAlLote::class,
            fn (DevolucionAplicadaAlLote $e) => $e->lote->producto_id === $producto->id
                && $e->lote->bodega_id === $bodega->id
        );
    }
}
