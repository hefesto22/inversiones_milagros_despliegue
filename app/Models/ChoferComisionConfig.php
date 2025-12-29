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

    public function getDescripcion(): string
    {
        $desc = $this->categoria->nombre ?? 'Sin categoría';

        if ($this->unidad) {
            $desc .= ' - ' . $this->unidad->nombre;
        } else {
            $desc .= ' (cualquier presentación)';
        }

        return $desc;
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

    public function scopeDeCategoria($query, int $categoriaId)
    {
        return $query->where('categoria_id', $categoriaId);
    }
}
