<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CamionChofer extends Model
{
    use HasFactory;

    protected $table = 'camion_chofer';

    protected $fillable = [
        'camion_id',
        'user_id',
        'fecha_asignacion',
        'fecha_fin',
        'activo',
        'asignado_por',
    ];

    protected $casts = [
        'fecha_asignacion' => 'date',
        'fecha_fin' => 'date',
        'activo' => 'boolean',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function camion(): BelongsTo
    {
        return $this->belongsTo(Camion::class, 'camion_id');
    }

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function asignadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_por');
    }

    // ============================================
    // MÉTODOS
    // ============================================

    public function finalizar(): void
    {
        $this->fecha_fin = now();
        $this->activo = false;
        $this->save();
    }

    public function estaActiva(): bool
    {
        return $this->activo && is_null($this->fecha_fin);
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeActivas($query)
    {
        return $query->where('activo', true)->whereNull('fecha_fin');
    }

    public function scopeDelChofer($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeDelCamion($query, int $camionId)
    {
        return $query->where('camion_id', $camionId);
    }
}
