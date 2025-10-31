<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;

class Bodega extends Model
{
    use HasFactory;

    protected $table = 'bodegas';

    /**
     * Campos que se pueden asignar en masa
     */
    protected $fillable = [
        'nombre',
        'ubicacion',
        'activo',
        'user_id',
        'user_update',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * Relación: usuarios asignados a la bodega (Many-to-Many)
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'bodega_user')
            ->withTimestamps();
    }

    /**
     * Relación: usuario que creó la bodega
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relación: usuario que actualizó por última vez
     */
    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_update');
    }

    /**
     * Eventos de creación/actualización automáticos
     */
    protected static function booted(): void
    {
        static::creating(function (Bodega $bodega) {
            if (Auth::check()) {
                $bodega->user_id = Auth::id();        // usuario que crea
                $bodega->user_update = Auth::id();    // también como actualizador inicial
            }
        });

        static::updating(function (Bodega $bodega) {
            if (Auth::check()) {
                $bodega->user_update = Auth::id();    // usuario que edita
            }
        });
    }
}
