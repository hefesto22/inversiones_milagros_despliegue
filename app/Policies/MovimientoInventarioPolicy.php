<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MovimientoInventario;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy del Kardex — el libro es INMUTABLE por diseño.
 *
 * Solo view/view_any están gobernados por permisos Shield; todo lo demás
 * (crear, editar, borrar, restaurar, replicar) retorna false incondicional:
 * ni el superadmin puede tocar un asiento desde la UI. Las correcciones se
 * asientan como movimientos nuevos (módulo de Ajuste).
 */
class MovimientoInventarioPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_movimiento::inventario');
    }

    public function view(User $user, MovimientoInventario $movimientoInventario): bool
    {
        return $user->can('view_movimiento::inventario');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MovimientoInventario $movimientoInventario): bool
    {
        return false;
    }

    public function delete(User $user, MovimientoInventario $movimientoInventario): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, MovimientoInventario $movimientoInventario): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, MovimientoInventario $movimientoInventario): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, MovimientoInventario $movimientoInventario): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
