<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Enums\AjusteEstado;
use App\Enums\AjusteMotivo;
use App\Enums\AjusteTipoMovimiento;
use App\Models\AjusteInventario;
use App\Models\Lote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests de AjusteInventarioPolicy — reglas de negocio sobre permisos Shield.
 *
 * Scope:
 *   1. Segregación de funciones: el creador de un ajuste NO puede aprobarlo/rechazarlo.
 *   2. Los métodos custom exigen el permiso Shield base ('update_ajuste::inventario'
 *      para aprobar/rechazar/aplicar, 'create_ajuste::inventario' para corregir).
 *   3. aplicar() respeta el workflow: con aprobación requerida solo desde Aprobado,
 *      sin aprobación solo desde Borrador, nunca desde estados terminales.
 *   4. corregir() solo sobre ajustes Aplicados y dentro de la ventana de días.
 *
 * Los permisos Shield se crean directo vía Spatie Permission — no se corre el
 * generador de Shield en testing, solo se necesita que el nombre exista.
 */
class AjusteInventarioPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('update_ajuste::inventario', 'web');
        Permission::findOrCreate('create_ajuste::inventario', 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // =================================================================
    // HELPERS
    // =================================================================

    private function usuarioConPermisos(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('update_ajuste::inventario', 'create_ajuste::inventario');

        return $user;
    }

    /**
     * Ajuste de merma residual en el estado indicado, con FKs reales.
     */
    private function crearAjuste(
        User         $creador,
        AjusteEstado $estado,
        bool         $requiereAprobacion = false,
        array        $extra = [],
    ): AjusteInventario {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();

        return AjusteInventario::create(array_merge([
            'lote_id'                 => $lote->id,
            'producto_id'             => $lote->producto_id,
            'bodega_id'               => $lote->bodega_id,
            'tipo_movimiento'         => AjusteTipoMovimiento::MermaResidual,
            'motivo'                  => AjusteMotivo::RoturaNoDocumentada,
            'huevos_antes'            => 3000.0,
            'huevos_despues'          => 2900.0,
            'delta_huevos'            => -100.0,
            'costo_unitario_aplicado' => 2.605,
            'valor_contable_afectado' => 260.50,
            'descripcion'             => 'Ajuste de prueba para policy',
            'estado'                  => $estado,
            'requiere_aprobacion'     => $requiereAprobacion,
            'created_by'              => $creador->id,
        ], $extra));
    }

    // =================================================================
    // SEGREGACIÓN DE FUNCIONES — aprobar / rechazar
    // =================================================================

    public function test_el_creador_no_puede_aprobar_su_propio_ajuste(): void
    {
        $creador = $this->usuarioConPermisos();
        $ajuste  = $this->crearAjuste($creador, AjusteEstado::PendienteAprobacion, requiereAprobacion: true);

        // Aunque tiene el permiso Shield, la segregación de funciones lo bloquea
        $this->assertFalse($creador->can('aprobar', $ajuste));
        $this->assertFalse($creador->can('rechazar', $ajuste));
    }

    public function test_otro_usuario_con_permiso_si_puede_aprobar(): void
    {
        $creador   = $this->usuarioConPermisos();
        $aprobador = $this->usuarioConPermisos();
        $ajuste    = $this->crearAjuste($creador, AjusteEstado::PendienteAprobacion, requiereAprobacion: true);

        $this->assertTrue($aprobador->can('aprobar', $ajuste));
        $this->assertTrue($aprobador->can('rechazar', $ajuste));
    }

    public function test_sin_permiso_shield_no_puede_aprobar_aunque_no_sea_el_creador(): void
    {
        $creador    = $this->usuarioConPermisos();
        $sinPermiso = User::factory()->create(); // sin ningún permiso Shield
        $ajuste     = $this->crearAjuste($creador, AjusteEstado::PendienteAprobacion, requiereAprobacion: true);

        $this->assertFalse($sinPermiso->can('aprobar', $ajuste));
    }

    public function test_aprobar_solo_aplica_a_ajustes_pendientes(): void
    {
        $creador   = $this->usuarioConPermisos();
        $aprobador = $this->usuarioConPermisos();

        foreach ([AjusteEstado::Borrador, AjusteEstado::Aprobado, AjusteEstado::Rechazado, AjusteEstado::Aplicado] as $estado) {
            $ajuste = $this->crearAjuste($creador, $estado);
            $this->assertFalse(
                $aprobador->can('aprobar', $ajuste),
                "aprobar debería ser false para estado {$estado->value}"
            );
        }
    }

    // =================================================================
    // APLICAR — workflow según requiere_aprobacion
    // =================================================================

    public function test_aplicar_con_aprobacion_requerida_solo_desde_aprobado(): void
    {
        $creador = $this->usuarioConPermisos();
        $user    = $this->usuarioConPermisos();

        $pendiente = $this->crearAjuste($creador, AjusteEstado::PendienteAprobacion, requiereAprobacion: true);
        $aprobado  = $this->crearAjuste($creador, AjusteEstado::Aprobado, requiereAprobacion: true);

        $this->assertFalse($user->can('aplicar', $pendiente));
        $this->assertTrue($user->can('aplicar', $aprobado));
    }

    public function test_aplicar_sin_aprobacion_requerida_solo_desde_borrador(): void
    {
        $creador = $this->usuarioConPermisos();
        $user    = $this->usuarioConPermisos();

        $borrador = $this->crearAjuste($creador, AjusteEstado::Borrador, requiereAprobacion: false);

        $this->assertTrue($user->can('aplicar', $borrador));
    }

    public function test_aplicar_nunca_desde_estados_terminales(): void
    {
        $creador = $this->usuarioConPermisos();
        $user    = $this->usuarioConPermisos();

        $aplicado  = $this->crearAjuste($creador, AjusteEstado::Aplicado);
        $rechazado = $this->crearAjuste($creador, AjusteEstado::Rechazado);

        // Inmutabilidad: Aplicado y Rechazado son terminales
        $this->assertFalse($user->can('aplicar', $aplicado));
        $this->assertFalse($user->can('aplicar', $rechazado));
    }

    // =================================================================
    // CORREGIR — solo Aplicados, dentro de la ventana
    // =================================================================

    public function test_corregir_solo_sobre_ajustes_aplicados_dentro_de_la_ventana(): void
    {
        $creador = $this->usuarioConPermisos();
        $user    = $this->usuarioConPermisos();

        $aplicadoReciente = $this->crearAjuste($creador, AjusteEstado::Aplicado, extra: [
            'aplicado_por' => $creador->id,
            'aplicado_en'  => now()->subDays(5),
        ]);
        $borrador = $this->crearAjuste($creador, AjusteEstado::Borrador);

        $this->assertTrue($user->can('corregir', $aplicadoReciente));
        $this->assertFalse($user->can('corregir', $borrador));
    }

    public function test_corregir_fuera_de_la_ventana_de_dias_es_rechazado(): void
    {
        config(['inventario.ajustes.dias_max_correccion' => 30]);

        $creador = $this->usuarioConPermisos();
        $user    = $this->usuarioConPermisos();

        $aplicadoViejo = $this->crearAjuste($creador, AjusteEstado::Aplicado, extra: [
            'aplicado_por' => $creador->id,
            'aplicado_en'  => now()->subDays(31),
        ]);

        // Pasada la ventana, ni con permisos se puede corregir — solo queda el histórico
        $this->assertFalse($user->can('corregir', $aplicadoViejo));
    }
}
