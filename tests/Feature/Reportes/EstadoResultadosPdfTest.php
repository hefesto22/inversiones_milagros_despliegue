<?php

declare(strict_types=1);

namespace Tests\Feature\Reportes;

use App\Models\ChoferCuentaMovimiento;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Estado de Resultados (PDF): desglose de comisiones por chofer, inclusión
 * del último día del período y control de acceso por permiso Shield
 * (widget_EstadoResultados, el mismo que protege el widget del dashboard).
 *
 * Reglas cubiertas:
 *   - El desglose por chofer usa los MISMOS filtros que la línea total:
 *     la suma de las filas siempre cuadra con actual.comisiones.
 *   - Choferes con comisión solo en el período anterior aparecen con
 *     actual L 0.00 y var -100% (el comparativo no queda cojo).
 *   - Orden: comisión actual desc, luego anterior desc.
 *   - Los movimientos del ÚLTIMO día del período cuentan (regresión del
 *     bug de borde: el límite 'Y-m-d' se interpretaba como medianoche y
 *     dejaba fuera casi todo el último día).
 *   - Sin permiso → 403; con permiso → 200 con content-type PDF.
 *
 * El período se fija con 'personalizado' (julio 2026) para no depender
 * de now(); su comparativo automático es 31 días hacia atrás
 * (2026-05-31 a 2026-06-30).
 */
class EstadoResultadosPdfTest extends TestCase
{
    use RefreshDatabase;

    private const PARAMS_JULIO = [
        'periodo' => 'personalizado',
        'fecha_inicio' => '2026-07-01',
        'fecha_fin' => '2026-07-31',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('widget_EstadoResultados', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo('widget_EstadoResultados');
    }

    // =====================================================
    // Helpers
    // =====================================================

    /**
     * Capturar el array de datos que el controller pasa a la vista del PDF,
     * sin renderizar DomPDF (rápido y sin acoplarse al binario PDF).
     */
    private function capturarDatosDelPdf(array $params): array
    {
        $captured = null;

        // El mock DEBE ser de la clase real: el facade mockea extendiendo
        // Barryvdh\DomPDF\PDF y loadView() esta tipado ": self", asi que
        // devolver un mock anonimo revienta con TypeError en runtime.
        $pdfFake = Mockery::mock(DomPdf::class);
        $pdfFake->shouldReceive('setOption')->andReturnSelf();
        $pdfFake->shouldReceive('setPaper')->andReturnSelf();
        $pdfFake->shouldReceive('stream')->andReturn(response('%PDF-fake'));

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function (string $view, array $data) use (&$captured) {
                $captured = $data;

                return $view === 'pdf.estado-resultados';
            })
            ->andReturn($pdfFake);

        $this->actingAs($this->admin)
            ->get(route('estado-resultados.pdf', $params))
            ->assertOk();

        $this->assertIsArray($captured, 'Pdf::loadView nunca fue invocado');

        return $captured;
    }

    private function comisionDe(User $chofer, float $monto, string $fechaHora): ChoferCuentaMovimiento
    {
        return ChoferCuentaMovimiento::factory()
            ->paraChofer($chofer)
            ->comision($monto)
            ->creadoEl($fechaHora)
            ->create();
    }

    // =====================================================
    // Desglose por chofer
    // =====================================================

    public function test_desglose_por_chofer_cuadra_con_el_total_y_ordena(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Torres']);
        $beto = User::factory()->create(['name' => 'Beto López']);
        $carlos = User::factory()->create(['name' => 'Carlos Ruiz']);

        // Julio (período actual): Beto 500 (en dos movimientos), Ana 300
        $this->comisionDe($beto, 300.00, '2026-07-05 09:00:00');
        $this->comisionDe($beto, 200.00, '2026-07-20 14:30:00');
        $this->comisionDe($ana, 300.00, '2026-07-10 11:00:00');

        // Comparativo (2026-05-31 a 2026-06-30): Beto 200, Carlos 150
        $this->comisionDe($beto, 200.00, '2026-06-15 10:00:00');
        $this->comisionDe($carlos, 150.00, '2026-06-20 10:00:00');

        // Ruido que NO debe entrar al desglose:
        // - pago de liquidación en julio (otro tipo)
        ChoferCuentaMovimiento::factory()
            ->paraChofer($beto)
            ->pagoLiquidacion(400.00)
            ->creadoEl('2026-07-21 08:00:00')
            ->create();
        // - comisión fuera de ambos períodos
        $this->comisionDe($ana, 999.00, '2026-08-02 08:00:00');

        $data = $this->capturarDatosDelPdf(self::PARAMS_JULIO);

        // Totales de la línea "Comisiones a choferes"
        $this->assertSame(800.0, $data['actual']['comisiones']);
        $this->assertSame(350.0, $data['anterior']['comisiones']);

        $filas = $data['comisionesChoferes'];
        $this->assertCount(3, $filas);

        // Orden: actual desc (Beto 500, Ana 300), luego solo-anterior (Carlos)
        $this->assertSame('Beto López', $filas[0]['nombre']);
        $this->assertSame(500.0, $filas[0]['actual']);
        $this->assertSame(200.0, $filas[0]['anterior']);
        $this->assertSame(150.0, $filas[0]['var']);

        $this->assertSame('Ana Torres', $filas[1]['nombre']);
        $this->assertSame(300.0, $filas[1]['actual']);
        $this->assertSame(0.0, $filas[1]['anterior']);
        $this->assertNull($filas[1]['var']);

        $this->assertSame('Carlos Ruiz', $filas[2]['nombre']);
        $this->assertSame(0.0, $filas[2]['actual']);
        $this->assertSame(150.0, $filas[2]['anterior']);
        $this->assertSame(-100.0, $filas[2]['var']);

        // Invariante: el desglose SIEMPRE cuadra con la línea total
        $this->assertSame(
            $data['actual']['comisiones'],
            array_sum(array_column($filas, 'actual'))
        );
        $this->assertSame(
            $data['anterior']['comisiones'],
            array_sum(array_column($filas, 'anterior'))
        );
    }

    public function test_sin_comisiones_el_desglose_va_vacio(): void
    {
        $data = $this->capturarDatosDelPdf(self::PARAMS_JULIO);

        $this->assertSame(0.0, $data['actual']['comisiones']);
        $this->assertSame([], $data['comisionesChoferes']);
    }

    // =====================================================
    // Borde de fechas (regresión)
    // =====================================================

    public function test_comision_del_ultimo_dia_del_periodo_cuenta(): void
    {
        $chofer = User::factory()->create(['name' => 'Chofer Borde']);

        // Antes del fix, un movimiento del 31 de julio por la tarde quedaba
        // FUERA del reporte (el límite '2026-07-31' es medianoche en MySQL).
        $this->comisionDe($chofer, 100.00, '2026-07-31 15:30:00');

        $data = $this->capturarDatosDelPdf(self::PARAMS_JULIO);

        $this->assertSame(100.0, $data['actual']['comisiones']);
        $this->assertCount(1, $data['comisionesChoferes']);
        $this->assertSame('Chofer Borde', $data['comisionesChoferes'][0]['nombre']);
    }

    // =====================================================
    // Acceso (permiso Shield del widget)
    // =====================================================

    public function test_usuario_sin_permiso_recibe_403(): void
    {
        $chofer = User::factory()->create();

        $this->actingAs($chofer)
            ->get(route('estado-resultados.pdf', self::PARAMS_JULIO))
            ->assertForbidden();

        $this->actingAs($chofer)
            ->get(route('estado-resultados.download', self::PARAMS_JULIO))
            ->assertForbidden();
    }

    public function test_invitado_es_redirigido_al_login(): void
    {
        $this->get(route('estado-resultados.pdf', self::PARAMS_JULIO))
            ->assertRedirect();
    }

    public function test_usuario_con_permiso_recibe_el_pdf_real(): void
    {
        // Sin mock: render completo de DomPDF como smoke test del blade
        // (incluye el desglose con un nombre con tilde/eñe).
        $chofer = User::factory()->create(['name' => 'José Muñoz']);
        $this->comisionDe($chofer, 250.00, '2026-07-15 10:00:00');

        $this->actingAs($this->admin)
            ->get(route('estado-resultados.pdf', self::PARAMS_JULIO))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->admin)
            ->get(route('estado-resultados.download', self::PARAMS_JULIO))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
