<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Enums\MovimientoInventarioTipo;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Services\Inventario\RegistradorMovimientos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Policy del Kardex: ver requiere permiso Shield; modificar es IMPOSIBLE
 * para cualquiera — el libro es inmutable también a nivel de autorización.
 */
class KardexPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('view_any_movimiento::inventario', 'web');
        Permission::findOrCreate('view_movimiento::inventario', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function crearMovimiento(): MovimientoInventario
    {
        $lote = Lote::factory()->conCompra(3000.0, 7815.0)->create();

        return app(RegistradorMovimientos::class)->registrarLote(
            lote:  $lote,
            tipo:  MovimientoInventarioTipo::SaldoInicial,
            delta: 3000.0,
        );
    }

    public function test_ver_el_kardex_requiere_permiso_shield(): void
    {
        $conPermiso = User::factory()->create();
        $conPermiso->givePermissionTo('view_any_movimiento::inventario', 'view_movimiento::inventario');
        $sinPermiso = User::factory()->create();

        $mov = $this->crearMovimiento();

        $this->assertTrue($conPermiso->can('viewAny', MovimientoInventario::class));
        $this->assertTrue($conPermiso->can('view', $mov));
        $this->assertFalse($sinPermiso->can('viewAny', MovimientoInventario::class));
        $this->assertFalse($sinPermiso->can('view', $mov));
    }

    public function test_nadie_puede_crear_editar_ni_borrar_desde_la_ui(): void
    {
        // Incluso con TODOS los permisos de ver, las mutaciones están vetadas
        $user = User::factory()->create();
        $user->givePermissionTo('view_any_movimiento::inventario', 'view_movimiento::inventario');

        $mov = $this->crearMovimiento();

        $this->assertFalse($user->can('create', MovimientoInventario::class));
        $this->assertFalse($user->can('update', $mov));
        $this->assertFalse($user->can('delete', $mov));
        $this->assertFalse($user->can('forceDelete', $mov));
        $this->assertFalse($user->can('restore', $mov));
    }
}
