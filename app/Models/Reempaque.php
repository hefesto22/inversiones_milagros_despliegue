<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Reempaque extends Model
{
    use HasFactory;

    protected $table = 'reempaques';

    protected $fillable = [
        'bodega_id',
        'numero_reempaque',
        'tipo',
        'total_huevos_usados',
        'merma',
        'huevos_utiles',
        'costo_total',
        'costo_unitario_promedio',
        'cartones_30',
        'cartones_15',
        'huevos_sueltos',
        'estado',
        'nota',
        'created_by',
    ];

    protected $casts = [
        'total_huevos_usados' => 'decimal:3',
        'merma' => 'decimal:3',
        'huevos_utiles' => 'decimal:3',
        'costo_total' => 'decimal:4',             // FIX: era decimal:2
        'costo_unitario_promedio' => 'decimal:4',  // FIX: era decimal:2 (columna también era decimal(12,2))
        'cartones_30' => 'integer',
        'cartones_15' => 'integer',
        'huevos_sueltos' => 'integer',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reempaqueLotes(): HasMany
    {
        return $this->hasMany(ReempaqueLote::class, 'reempaque_id');
    }

    public function reempaqueProductos(): HasMany
    {
        return $this->hasMany(ReempaqueProducto::class, 'reempaque_id');
    }

    public function lotes(): BelongsToMany
    {
        return $this->belongsToMany(Lote::class, 'reempaque_lotes', 'reempaque_id', 'lote_id')
            ->withPivot([
                'cantidad_cartones_usados',
                'cantidad_huevos_usados',
                'cartones_facturados_usados',
                'cartones_regalo_usados',
                'costo_parcial'
            ])
            ->withTimestamps();
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeEnProceso($query)
    {
        return $query->where('estado', 'en_proceso');
    }

    public function scopeCompletado($query)
    {
        return $query->where('estado', 'completado');
    }

    public function scopeCancelado($query)
    {
        return $query->where('estado', 'cancelado');
    }

    public function scopeRevertido($query)
    {
        return $query->where('estado', 'revertido');
    }

    public function scopeInactivo($query)
    {
        return $query->whereIn('estado', ['cancelado', 'revertido']);
    }

    public function scopeIndividual($query)
    {
        return $query->where('tipo', 'individual');
    }

    public function scopeMezclado($query)
    {
        return $query->where('tipo', 'mezclado');
    }

    public function scopePorBodega($query, int $bodegaId)
    {
        return $query->where('bodega_id', $bodegaId);
    }

    // ============================================
    // METODOS DE NEGOCIO
    // ============================================

    public function getPorcentajeMerma(): float
    {
        if ($this->total_huevos_usados <= 0) {
            return 0;
        }

        return round(($this->merma / $this->total_huevos_usados) * 100, 2);
    }

    public function estaCompletado(): bool
    {
        return $this->estado === 'completado';
    }

    public function estaCancelado(): bool
    {
        return $this->estado === 'cancelado';
    }

    public function estaRevertido(): bool
    {
        return $this->estado === 'revertido';
    }

    /**
     * Verificar si el reempaque está inactivo (cancelado o revertido)
     */
    public function estaInactivo(): bool
    {
        return in_array($this->estado, ['cancelado', 'revertido']);
    }

    public function puedeCancelarse(): bool
    {
        return !in_array($this->estado, ['cancelado', 'revertido']);
    }

    public function getTotalHuevosEmpacados(): int
    {
        return ($this->cartones_30 * 30) + ($this->cartones_15 * 15) + $this->huevos_sueltos;
    }

    public function validarEmpaque(): bool
    {
        $empacados = $this->getTotalHuevosEmpacados();
        $utiles = $this->huevos_utiles;

        return abs($empacados - $utiles) < 0.01;
    }

    public function getTotalCartonesRegaloUsados(): float
    {
        return $this->reempaqueLotes()->sum('cartones_regalo_usados');
    }

    public function getTotalHuevosRegaloUsados(): float
    {
        // FIX N+1: usa JOIN en lugar de iterar con lazy loading de lote por cada reempaqueLote.
        return (float) $this->reempaqueLotes()
            ->join('lotes', 'reempaque_lotes.lote_id', '=', 'lotes.id')
            ->selectRaw('COALESCE(SUM(reempaque_lotes.cartones_regalo_usados * COALESCE(lotes.huevos_por_carton, 30)), 0) AS total')
            ->value('total');
    }

    public function getBeneficioRegalos(): float
    {
        // FIX N+1: usa JOIN en lugar de iterar con lazy loading de lote por cada reempaqueLote.
        return (float) $this->reempaqueLotes()
            ->join('lotes', 'reempaque_lotes.lote_id', '=', 'lotes.id')
            ->selectRaw('COALESCE(SUM(reempaque_lotes.cartones_regalo_usados * COALESCE(lotes.huevos_por_carton, 30) * lotes.costo_por_huevo), 0) AS beneficio')
            ->value('beneficio');
    }

    public function getEficiencia(): float
    {
        if ($this->total_huevos_usados <= 0) {
            return 0;
        }

        return round(($this->huevos_utiles / $this->total_huevos_usados) * 100, 2);
    }

    public function getResumen(): array
    {
        return [
            'numero' => $this->numero_reempaque,
            'tipo' => $this->tipo === 'individual' ? 'Individual' : 'Mezclado',
            'huevos_usados' => $this->total_huevos_usados,
            'merma' => $this->merma,
            'merma_porcentaje' => $this->getPorcentajeMerma(),
            'huevos_utiles' => $this->huevos_utiles,
            'eficiencia' => $this->getEficiencia(),
            'cartones_30' => $this->cartones_30,
            'cartones_15' => $this->cartones_15,
            'huevos_sueltos' => $this->huevos_sueltos,
            'costo_total' => $this->costo_total,
            'costo_por_huevo' => $this->total_huevos_usados > 0
                ? $this->costo_total / $this->total_huevos_usados
                : 0,
            'cartones_regalo_usados' => $this->getTotalCartonesRegaloUsados(),
            'beneficio_regalos' => $this->getBeneficioRegalos(),
            'lotes_usados' => $this->reempaqueLotes->count(),
            'proveedores' => $this->getProveedores()->pluck('nombre')->toArray(),
        ];
    }

    public function validarMismoProducto(): bool
    {
        if ($this->tipo === 'individual') {
            return true;
        }

        $productos = $this->lotes()
            ->distinct()
            ->pluck('producto_id');

        return $productos->count() === 1;
    }

    public function getProveedores()
    {
        return $this->lotes()
            ->with('proveedor')
            ->get()
            ->pluck('proveedor')
            ->unique('id');
    }

    public function marcarCompletado(): void
    {
        $this->estado = 'completado';
        $this->save();
    }

    public function cancelar(?string $motivo = null): void
    {
        if ($this->estado === 'cancelado') {
            throw new \Exception("El reempaque {$this->numero_reempaque} ya esta cancelado.");
        }

        $this->estado = 'cancelado';

        if ($motivo) {
            $this->nota = ($this->nota ? $this->nota . "\n\n" : '') . "CANCELADO: " . $motivo;
        }

        $this->save();

        foreach ($this->reempaqueLotes as $reempaqueLote) {
            $lote = $reempaqueLote->lote;

            if (!$lote) {
                continue;
            }

            $lote->cantidad_huevos_remanente += $reempaqueLote->cantidad_huevos_usados;

            $huevosRegaloUsados = ($reempaqueLote->cartones_regalo_usados ?? 0) * ($lote->huevos_por_carton ?? 30);
            if ($huevosRegaloUsados > 0) {
                $lote->huevos_regalo_consumidos = max(0, ($lote->huevos_regalo_consumidos ?? 0) - $huevosRegaloUsados);
            }

            if ($lote->cantidad_huevos_remanente > 0) {
                $lote->estado = 'disponible';
            }

            $lote->save();
        }

        foreach ($this->reempaqueProductos as $producto) {
            if ($producto->agregado_a_stock) {
                $producto->revertirStock();
            }
        }
    }

    // ============================================
    // EVENTOS (Boot)
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($reempaque) {
            if (!$reempaque->numero_reempaque) {
                // lockForUpdate previene race conditions en generación de números.
                // Requiere que la creación del reempaque esté dentro de una transacción.
                $ultimoReempaque = static::where('bodega_id', $reempaque->bodega_id)
                    ->lockForUpdate()
                    ->orderBy('id', 'desc')
                    ->first();

                $secuencial = $ultimoReempaque
                    ? intval(substr($ultimoReempaque->numero_reempaque, -6)) + 1
                    : 1;

                $reempaque->numero_reempaque = sprintf('R-B%d-%06d', $reempaque->bodega_id, $secuencial);
            }

            if (is_null($reempaque->huevos_utiles)) {
                $reempaque->huevos_utiles = $reempaque->total_huevos_usados - $reempaque->merma;
            }
        });

        static::saving(function ($reempaque) {
            $calculado = $reempaque->total_huevos_usados - $reempaque->merma;

            if (abs($reempaque->huevos_utiles - $calculado) > 0.01) {
                $reempaque->huevos_utiles = $calculado;
            }

            if ($reempaque->huevos_utiles > 0 && $reempaque->costo_total > 0) {
                // FIX: No redondear aquí, dejar que la BD maneje la precisión
                $reempaque->costo_unitario_promedio = $reempaque->costo_total / $reempaque->huevos_utiles;
            }
        });
    }
}
