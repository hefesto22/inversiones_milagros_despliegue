<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class ComisionChoferLiquidacion extends Model
{
    use HasFactory;

    protected $table = 'comisiones_chofer_liquidaciones';

    protected $fillable = [
        'viaje_id',
        'chofer_user_id',
        'cartones_30_vendidos',
        'cartones_15_vendidos',
        'total_comision',
        'calculado_en',
        'calculado_por',
    ];

    protected $casts = [
        'cartones_30_vendidos' => 'decimal:3',
        'cartones_15_vendidos' => 'decimal:3',
        'total_comision' => 'decimal:2',
        'calculado_en' => 'datetime',
    ];

    /**
     * Relación con viaje
     */
    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    /**
     * Relación con chofer (usuario)
     */
    public function chofer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chofer_user_id');
    }

    /**
     * Usuario que calculó la comisión
     */
    public function calculadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculado_por');
    }

    /**
     * Scope por chofer
     */
    public function scopePorChofer($query, int $choferId)
    {
        return $query->where('chofer_user_id', $choferId);
    }

    /**
     * Scope por rango de fechas
     */
    public function scopeEntreFechas($query, $desde, $hasta)
    {
        return $query->whereBetween('calculado_en', [$desde, $hasta]);
    }

    /**
     * Obtener total de cartones vendidos
     */
    public function getTotalCartonesAttribute(): float
    {
        return $this->cartones_30_vendidos + $this->cartones_15_vendidos;
    }

    /**
     * Boot del modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Asignar calculado_por automáticamente al crear
        static::creating(function ($liquidacion) {
            if (Auth::check() && !$liquidacion->calculado_por) {
                $liquidacion->calculado_por = Auth::id();
            }

            if (!$liquidacion->calculado_en) {
                $liquidacion->calculado_en = now();
            }
        });
    }
}
