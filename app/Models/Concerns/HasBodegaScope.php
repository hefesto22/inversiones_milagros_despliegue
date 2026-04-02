<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Centraliza la lógica de autorización por bodega y rol.
 *
 * Reemplaza las 10+ repeticiones de la query:
 *   DB::table('model_has_roles')->join('roles',...)->whereIn('roles.name', ['Super Admin', 'Jefe'])->exists()
 *
 * Uso en Filament Resources:
 *   use HasBodegaScope;
 *   self::esSuperAdminOJefe()       → bool
 *   self::getBodegasUsuario()       → array de IDs
 *   self::getBodegasOptions()       → [id => nombre] para Select
 *   self::scopeQueryPorBodega($q)   → aplica filtro de bodega al query
 */
trait HasBodegaScope
{
    /**
     * Roles con acceso global (sin restricción de bodega).
     */
    protected static array $rolesGlobales = ['Super Admin', 'Jefe'];

    /**
     * Verificar si el usuario actual es Super Admin o Jefe.
     * Resultado cacheado por request para evitar queries duplicadas.
     */
    public static function esSuperAdminOJefe(): bool
    {
        static $cache = [];

        $currentUser = Auth::user();
        if (!$currentUser) {
            return false;
        }

        $cacheKey = $currentUser->id;

        if (!isset($cache[$cacheKey])) {
            $cache[$cacheKey] = DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('model_has_roles.model_type', '=', get_class($currentUser))
                ->where('model_has_roles.model_id', '=', $currentUser->id)
                ->whereIn('roles.name', static::$rolesGlobales)
                ->exists();
        }

        return $cache[$cacheKey];
    }

    /**
     * Obtener IDs de bodegas activas del usuario actual.
     * Resultado cacheado por request.
     */
    public static function getBodegasUsuario(): array
    {
        static $cache = [];

        $currentUser = Auth::user();
        if (!$currentUser) {
            return [];
        }

        $cacheKey = $currentUser->id;

        if (!isset($cache[$cacheKey])) {
            $cache[$cacheKey] = DB::table('bodega_user')
                ->where('user_id', $currentUser->id)
                ->where('activo', true)
                ->pluck('bodega_id')
                ->toArray();
        }

        return $cache[$cacheKey];
    }

    /**
     * Obtener opciones de bodegas para un Select de Filament.
     * Super Admin/Jefe ven todas; otros solo sus bodegas asignadas.
     */
    public static function getBodegasOptions(): array
    {
        if (static::esSuperAdminOJefe()) {
            return \App\Models\Bodega::where('activo', true)
                ->pluck('nombre', 'id')
                ->toArray();
        }

        return DB::table('bodega_user')
            ->join('bodegas', 'bodega_user.bodega_id', '=', 'bodegas.id')
            ->where('bodega_user.user_id', Auth::id())
            ->where('bodegas.activo', true)
            ->pluck('bodegas.nombre', 'bodegas.id')
            ->toArray();
    }

    /**
     * Obtener el ID de la bodega por defecto del usuario.
     * Para Super Admin/Jefe retorna null (deben elegir).
     * Para otros retorna su primera bodega activa.
     */
    public static function getBodegaDefault(): ?int
    {
        if (static::esSuperAdminOJefe()) {
            return null;
        }

        return DB::table('bodega_user')
            ->join('bodegas', 'bodega_user.bodega_id', '=', 'bodegas.id')
            ->where('bodega_user.user_id', Auth::id())
            ->where('bodegas.activo', true)
            ->value('bodegas.id');
    }

    /**
     * Aplicar filtro de bodega a un query de Eloquent.
     * Super Admin/Jefe no se filtran; otros ven solo sus bodegas.
     */
    public static function scopeQueryPorBodega($query)
    {
        $currentUser = Auth::user();
        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        if (static::esSuperAdminOJefe()) {
            return $query;
        }

        $bodegas = static::getBodegasUsuario();

        return $query->whereIn('bodega_id', $bodegas);
    }
}
