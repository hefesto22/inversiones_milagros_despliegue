<?php

namespace App\Policies;

use App\Enums\AjusteEstado;
use App\Models\AjusteInventario;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AjusteInventarioPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_ajuste::inventario');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AjusteInventario $ajusteInventario): bool
    {
        return $user->can('view_ajuste::inventario');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_ajuste::inventario');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AjusteInventario $ajusteInventario): bool
    {
        return $user->can('update_ajuste::inventario');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AjusteInventario $ajusteInventario): bool
    {
        return $user->can('delete_ajuste::inventario');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_ajuste::inventario');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, AjusteInventario $ajusteInventario): bool
    {
        return $user->can('force_delete_ajuste::inventario');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_ajuste::inventario');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, AjusteInventario $ajusteInventario): bool
    {
        return $user->can('restore_ajuste::inventario');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_ajuste::inventario');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, AjusteInventario $ajusteInventario): bool
    {
        return $user->can('replicate_ajuste::inventario');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_ajuste::inventario');
    }

    // =================================================================
    // ACCIONES CUSTOM DE NEGOCIO (no generadas por Shield)
    // =================================================================
    //
    // Combinan permisos Shield (acceso al recurso) con reglas de negocio
    // (estado válido, segregación de funciones, ventana de tiempo).
    //
    // El Resource y el Service llaman a estas vía $user->can('aprobar', $ajuste).
    // =================================================================

    /**
     * Aprobar un ajuste pendiente de aprobación.
     *
     * Reglas:
     *   - Necesita permiso Shield 'update_ajuste::inventario'.
     *   - El ajuste debe estar en estado PendienteAprobacion.
     *   - Segregación de funciones: el creador del ajuste no puede aprobarlo.
     */
    public function aprobar(User $user, AjusteInventario $ajusteInventario): bool
    {
        if (! $user->can('update_ajuste::inventario')) {
            return false;
        }
        if ($ajusteInventario->estado !== AjusteEstado::PendienteAprobacion) {
            return false;
        }
        // El creador NO puede aprobar su propio ajuste (control interno)
        if ($user->id === $ajusteInventario->created_by) {
            return false;
        }
        return true;
    }

    /**
     * Rechazar un ajuste pendiente de aprobación.
     * Mismas reglas que aprobar (un rechazo también es una decisión gerencial).
     */
    public function rechazar(User $user, AjusteInventario $ajusteInventario): bool
    {
        return $this->aprobar($user, $ajusteInventario);
    }

    /**
     * Aplicar el ajuste al lote (modifica saldo + dispara WAC).
     *
     * Reglas:
     *   - Necesita permiso Shield 'update_ajuste::inventario'.
     *   - No puede aplicarse si ya está en estado terminal (Rechazado, Aplicado).
     *   - Si requiere aprobación, solo puede aplicarse desde Aprobado.
     *   - Si NO requiere aprobación, puede aplicarse directo desde Borrador.
     */
    public function aplicar(User $user, AjusteInventario $ajusteInventario): bool
    {
        if (! $user->can('update_ajuste::inventario')) {
            return false;
        }
        if ($ajusteInventario->esTerminal()) {
            return false;
        }
        if ($ajusteInventario->requiere_aprobacion) {
            return $ajusteInventario->estado === AjusteEstado::Aprobado;
        }
        return $ajusteInventario->estado === AjusteEstado::Borrador;
    }

    /**
     * Crear un ajuste de corrección sobre un ajuste ya aplicado.
     *
     * Reglas:
     *   - Necesita permiso Shield 'create_ajuste::inventario'.
     *   - El ajuste a corregir debe estar en estado Aplicado.
     *   - Solo dentro de la ventana configurada (config: dias_max_correccion).
     */
    public function corregir(User $user, AjusteInventario $ajusteInventario): bool
    {
        if (! $user->can('create_ajuste::inventario')) {
            return false;
        }
        if ($ajusteInventario->estado !== AjusteEstado::Aplicado) {
            return false;
        }
        $diasMax = (int) config('inventario.ajustes.dias_max_correccion', 30);
        if ($ajusteInventario->aplicado_en && $ajusteInventario->aplicado_en->diffInDays(now()) > $diasMax) {
            return false;
        }
        return true;
    }
}
