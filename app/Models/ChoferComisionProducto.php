<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChoferComisionProducto extends Model
{
    use HasFactory;

    protected $table = 'chofer_comision_producto';

    protected $fillable = [
        'user_id',
        'producto_id',
        'comision_normal',
        'comision_reducida',
        'vigente_desde',
        'vigente_hasta',
        'activo',
        'created_by',
    ];

    protected $casts = [
        'comision_normal' => 'decimal:2',
        'comision_reducida' => 'decimal:2',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
        'activo' => 'boolean',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ============================================
    // MÉTODOS
    // ============================================

    public function estaVigente(): bool
    {
        if (!$this->activo) {
            return false;
        }

        $hoy = now()->startOfDay();

        if ($this->vigente_desde > $hoy) {
            return false;
        }

        if ($this->vigente_hasta && $this->vigente_hasta < $hoy) {
            return false;
        }

        return true;
    }

    public function finalizar(): void
    {
        $this->vigente_hasta = now();
        $this->activo = false;
        $this->save();
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }

    public function scopeVigentes($query)
    {
        $hoy = now()->startOfDay();

        return $query->where('activo', true)
            ->where('vigente_desde', '<=', $hoy)
            ->where(function ($q) use ($hoy) {
                $q->whereNull('vigente_hasta')
                    ->orWhere('vigente_hasta', '>=', $hoy);
            });
    }

    public function scopeDelChofer($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeDelProducto($query, int $productoId)
    {
        return $query->where('producto_id', $productoId);
    }
}
