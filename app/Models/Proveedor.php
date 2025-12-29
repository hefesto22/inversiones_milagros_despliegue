<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proveedor extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'proveedores';

    protected $fillable = [
        'nombre',
        'rtn',
        'telefono',
        'direccion',
        'email',
        'estado',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class, 'proveedor_id');
    }

    public function preciosProveedor(): HasMany
    {
        return $this->hasMany(ProveedorProducto::class, 'proveedor_id');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class, 'proveedor_id');
    }
    // ============================================
    // SCOPES
    // ============================================

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    // ============================================
    // MÉTODOS DE CÁLCULO
    // ============================================

    /**
     * Calcula el saldo pendiente del proveedor
     * (Suma de compras que ya fueron recibidas pero aún no han sido pagadas)
     */
    public function getSaldoPendiente(): float
    {
        return $this->compras()
            ->where('estado', 'recibida_pendiente_pago')
            ->sum('total');
    }

    /**
     * Verifica si el proveedor tiene saldo pendiente
     */
    public function tieneSaldoPendiente(): bool
    {
        return $this->getSaldoPendiente() > 0;
    }

    /**
     * Obtiene el total de compras realizadas al proveedor (excluye borradores y canceladas)
     */
    public function getTotalCompras(): float
    {
        return $this->compras()
            ->whereNotIn('estado', ['borrador', 'cancelada'])
            ->sum('total');
    }

    /**
     * Obtiene el total ya pagado al proveedor
     */
    public function getTotalPagado(): float
    {
        return $this->compras()
            ->whereIn('estado', [
                'recibida_pagada',      // Recibida y pagada (completada)
                'por_recibir_pagada',   // Ya pagué pero aún no llega
            ])
            ->sum('total');
    }

    /**
     * Obtiene el total de compras pendientes de recibir
     */
    public function getTotalPendienteRecibir(): float
    {
        return $this->compras()
            ->whereIn('estado', [
                'ordenada',
                'por_recibir_pagada',
                'por_recibir_pendiente_pago',
            ])
            ->sum('total');
    }

    /**
     * Obtiene el total de dinero que debo pagar (incluyendo lo que no ha llegado)
     */
    public function getTotalDeuda(): float
    {
        return $this->compras()
            ->whereIn('estado', [
                'recibida_pendiente_pago',      // Ya llegó, debo pagar
                'por_recibir_pendiente_pago',   // No ha llegado, debo pagar
            ])
            ->sum('total');
    }

    /**
     * Obtiene estadísticas completas del proveedor
     */
    public function getEstadisticas(): array
    {
        return [
            'total_compras' => $this->getTotalCompras(),
            'total_pagado' => $this->getTotalPagado(),
            'saldo_pendiente' => $this->getSaldoPendiente(),
            'total_deuda' => $this->getTotalDeuda(),
            'pendiente_recibir' => $this->getTotalPendienteRecibir(),
            'compras_completadas' => $this->compras()->where('estado', 'recibida_pagada')->count(),
            'compras_activas' => $this->compras()->activas()->count(),
        ];
    }
}
