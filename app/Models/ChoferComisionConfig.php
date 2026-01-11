<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChoferComisionConfig extends Model
{
    use HasFactory;

    protected $table = 'chofer_comision_config';

    protected $fillable = [
        'user_id',
        'categoria_id',
        'unidad_id',
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

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
        return $query->where(function ($q) {
            $q->whereNull('vigente_hasta')
              ->orWhere('vigente_hasta', '>=', now());
        });
    }

    public function scopeDelChofer($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeDeCategoria($query, int $categoriaId)
    {
        return $query->where('categoria_id', $categoriaId);
    }

    // ============================================
    // MÉTODOS
    // ============================================

    /**
     * Verificar si está vigente
     */
    public function estaVigente(): bool
    {
        if (!$this->activo) {
            return false;
        }

        if ($this->vigente_hasta && $this->vigente_hasta < now()) {
            return false;
        }

        return true;
    }

    /**
     * Obtener comisión según precio de venta
     */
    public function getComision(float $precioVenta, float $precioSugerido): float
    {
        if ($precioVenta >= $precioSugerido) {
            return $this->comision_normal;
        }

        return $this->comision_reducida;
    }

    /**
     * Descripción para mostrar
     */
    public function getDescripcion(): string
    {
        $desc = $this->categoria?->nombre ?? 'Sin categoría';
        
        if ($this->unidad) {
            $desc .= ' (' . $this->unidad->nombre . ')';
        }

        return $desc;
    }
}