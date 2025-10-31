<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class ViajeMerma extends Model
{
    use HasFactory;

    protected $fillable = [
        'viaje_id',
        'producto_id',
        'cantidad_base',
        'motivo',
        'registrado_por',
    ];

    protected $casts = [
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
     * Usuario que registró la merma
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /**
     * Boot del modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Asignar registrado_por automáticamente al crear
        static::creating(function ($merma) {
            if (Auth::check() && !$merma->registrado_por) {
                $merma->registrado_por = Auth::id();
            }
        });
    }
}
