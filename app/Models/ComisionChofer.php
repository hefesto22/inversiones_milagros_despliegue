<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class ComisionChofer extends Model
{
    use HasFactory;

    protected $table = 'comisiones_chofer';

    protected $fillable = [
        'chofer_user_id',
        'aplica_a',
        'monto_por_carton',
        'vigente_desde',
        'vigente_hasta',
        'user_id',
    ];

    protected $casts = [
        'monto_por_carton' => 'decimal:4',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    /**
     * Relación con chofer (usuario)
     */
    public function chofer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chofer_user_id');
    }

    /**
     * Usuario que creó la comisión
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope para comisiones vigentes
     */
    public function scopeVigentes($query)
    {
        return $query->whereNull('vigente_hasta')
            ->orWhereDate('vigente_hasta', '>=', now());
    }

    /**
     * Scope por chofer
     */
    public function scopePorChofer($query, int $choferId)
    {
        return $query->where('chofer_user_id', $choferId);
    }

    /**
     * Verificar si está vigente
     */
    public function estaVigente(): bool
    {
        $hoy = now();

        return $this->vigente_desde <= $hoy
            && (is_null($this->vigente_hasta) || $this->vigente_hasta >= $hoy);
    }

    /**
     * Boot del modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Asignar user_id automáticamente al crear
        static::creating(function ($comision) {
            if (Auth::check() && !$comision->user_id) {
                $comision->user_id = Auth::id();
            }
        });
    }
}
