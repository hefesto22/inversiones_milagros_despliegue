<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;


/**
 * @method bool hasRole(string|array|\Spatie\Permission\Contracts\Role $roles, string $guard = null)
 * @method bool hasAnyRole(string|array|\Spatie\Permission\Contracts\Role $roles, string $guard = null)
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'created_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            // Nota: `email_verified_at` no existe en el schema de `users` de este proyecto
            // (el producto no implementa verificación de correo). Cast removido para evitar
            // confusión y quedar alineado con la migración 0001_01_01_000000_create_users_table.
            'password' => 'hashed',
        ];
    }

    // ============================================
    // RELACIONES DE AUDITORÍA
    // ============================================

    public function creador()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usuariosCreados(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    // ============================================
    // RELACIONES DE BODEGAS
    // ============================================

    public function bodegas(): BelongsToMany
    {
        return $this->belongsToMany(Bodega::class, 'bodega_user')
            ->withTimestamps();
    }

    // ============================================
    // RELACIONES DE CHOFER - CAMIONES
    // ============================================

    public function asignacionesCamion(): HasMany
    {
        return $this->hasMany(CamionChofer::class, 'user_id');
    }

    public function asignacionCamionActiva(): HasOne
    {
        return $this->hasOne(CamionChofer::class, 'user_id')
            ->where('activo', true)
            ->whereNull('fecha_fin');
    }

    // ============================================
    // RELACIONES DE CHOFER - COMISIONES
    // ============================================

    public function comisionesConfig(): HasMany
    {
        return $this->hasMany(ChoferComisionConfig::class, 'user_id');
    }

    public function comisionesProducto(): HasMany
    {
        return $this->hasMany(ChoferComisionProducto::class, 'user_id');
    }

    public function cuenta(): HasOne
    {
        return $this->hasOne(ChoferCuenta::class, 'user_id');
    }

    public function cuentaMovimientos(): HasMany
    {
        return $this->hasMany(ChoferCuentaMovimiento::class, 'user_id');
    }

    // ============================================
    // RELACIONES DE CHOFER - VIAJES
    // ============================================

    public function viajesComoChofer(): HasMany
    {
        return $this->hasMany(Viaje::class, 'chofer_id');
    }

    public function liquidaciones(): HasMany
    {
        return $this->hasMany(Liquidacion::class, 'chofer_id');
    }

    // ============================================
    // RELACIONES DE DOCUMENTOS CREADOS
    // ============================================

    public function comprasCreadas(): HasMany
    {
        return $this->hasMany(Compra::class, 'created_by');
    }

    public function ventasCreadas(): HasMany
    {
        return $this->hasMany(Venta::class, 'created_by');
    }

    public function viajesCreados(): HasMany
    {
        return $this->hasMany(Viaje::class, 'created_by');
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeChoferes($query)
    {
        return $query->whereHas('roles', function ($q) {
            $q->where('name', 'Chofer');
        });
    }

    public function scopeConAccesoBodega($query, int $bodegaId)
    {
        return $query->whereHas('bodegas', function ($q) use ($bodegaId) {
            $q->where('bodega_id', $bodegaId);
        });
    }

    // ============================================
    // MÉTODOS GENERALES
    // ============================================

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function tieneAccesoBodega(int $bodegaId): bool
    {
        return $this->bodegas()->where('bodega_id', $bodegaId)->exists();
    }

    public function getBodegasIds(): array
    {
        return $this->bodegas()->pluck('bodegas.id')->toArray();
    }

    // ============================================
    // MÉTODOS DE CHOFER
    // ============================================

    public function esChofer(): bool
    {
        return $this->hasRole('Chofer');
    }

    public function getCamionActual(): ?Camion
    {
        return $this->asignacionCamionActiva?->camion;
    }

    public function tieneViajeActivo(): bool
    {
        return $this->viajesComoChofer()
            ->whereNotIn('estado', ['cerrado', 'cancelado'])
            ->exists();
    }

    public function getViajeActivo(): ?Viaje
    {
        return $this->viajesComoChofer()
            ->whereNotIn('estado', ['cerrado', 'cancelado'])
            ->first();
    }

    /**
     * Obtener o crear cuenta del chofer
     */
    public function getOrCreateCuenta(): ChoferCuenta
    {
        return $this->cuenta ?? ChoferCuenta::create([
            'user_id' => $this->id,
            'saldo' => 0,
            'total_comisiones_historico' => 0,
            'total_cobros_historico' => 0,
            'total_pagado_historico' => 0,
        ]);
    }

    /**
     * Obtener saldo actual de cuenta
     */
    public function getSaldoCuenta(): float
    {
        return $this->cuenta?->saldo ?? 0;
    }

    /**
     * Obtener comisión configurada para un producto/categoría
     * CORREGIDO: Ahora devuelve tipo_comision (fijo/porcentaje)
     */
    public function getComisionPara(int $productoId, int $categoriaId, ?int $unidadId = null): array
    {
        // Primero buscar excepción por producto
        $comisionProducto = $this->comisionesProducto()
            ->where('producto_id', $productoId)
            ->where('activo', true)
            ->where('vigente_desde', '<=', now())
            ->where(function ($q) {
                $q->whereNull('vigente_hasta')
                    ->orWhere('vigente_hasta', '>=', now());
            })
            ->first();

        if ($comisionProducto) {
            return [
                'normal' => (float) $comisionProducto->comision_normal,
                'reducida' => (float) $comisionProducto->comision_reducida,
                'fuente' => 'producto',
                // ChoferComisionProducto NO tiene tipo_comision, asumir fijo
                'tipo_comision' => $comisionProducto->tipo_comision ?? ChoferComisionConfig::TIPO_FIJO,
            ];
        }

        // Buscar por categoría + unidad
        $comisionConfig = $this->comisionesConfig()
            ->where('categoria_id', $categoriaId)
            ->where('activo', true)
            ->where(function ($q) {
                $q->whereNull('vigente_hasta')
                    ->orWhere('vigente_hasta', '>=', now());
            })
            ->where(function ($q) use ($unidadId) {
                $q->where('unidad_id', $unidadId)
                    ->orWhereNull('unidad_id');
            })
            ->orderByRaw('unidad_id IS NULL') // Priorizar específico sobre general
            ->first();

        if ($comisionConfig) {
            return [
                'normal' => (float) $comisionConfig->comision_normal,
                'reducida' => (float) $comisionConfig->comision_reducida,
                'fuente' => 'categoria',
                'tipo_comision' => $comisionConfig->tipo_comision ?? ChoferComisionConfig::TIPO_FIJO,
            ];
        }

        // Sin comisión configurada
        return [
            'normal' => 0,
            'reducida' => 0,
            'fuente' => 'ninguna',
            'tipo_comision' => ChoferComisionConfig::TIPO_FIJO,
        ];
    }

    /**
     * Obtener total de comisiones pendientes de liquidar
     */
    public function getTotalComisionesPendientes(): float
    {
        return $this->viajesComoChofer()
            ->where('estado', 'cerrado')
            ->whereDoesntHave('liquidacionViajes')
            ->sum('neto_chofer');
    }

    /**
     * Obtener viajes pendientes de liquidar
     */
    public function getViajesPendientesLiquidar()
    {
        return $this->viajesComoChofer()
            ->where('estado', 'cerrado')
            ->whereDoesntHave('liquidacionViajes')
            ->orderBy('fecha_salida')
            ->get();
    }
}
