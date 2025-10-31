<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViajeCarga extends Model
{
    use HasFactory;

    protected $fillable = [
        'viaje_id',
        'producto_id',
        'unidad_id_presentacion',
        'cantidad_presentacion',
        'factor_a_base',
        'cantidad_base',
    ];

    protected $casts = [
        'cantidad_presentacion' => 'decimal:3',
        'factor_a_base' => 'decimal:6',
        'cantidad_base' => 'decimal:3',
    ];

    /**
     * Relación con viaje
     */
    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    /**
     * Relación con producto
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * Relación con unidad de presentación
     */
    public function unidadPresentacion(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_id_presentacion');
    }

    /**
     * Calcular cantidad base automáticamente
     */
    public function calcularCantidadBase(): void
    {
        $this->cantidad_base = $this->cantidad_presentacion * $this->factor_a_base;
    }

    /**
     * Boot del modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Calcular cantidad_base antes de guardar
        static::saving(function ($carga) {
            $carga->calcularCantidadBase();
        });
    }
}
