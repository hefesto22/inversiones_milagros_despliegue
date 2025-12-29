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
        'costo_total' => 'decimal:2',
        'costo_unitario_promedio' => 'decimal:4',
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
                'cartones_facturados_usados',    // 🆕 NUEVO
                'cartones_regalo_usados',        // 🆕 NUEVO
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
    // MÉTODOS DE NEGOCIO
    // ============================================

    /**
     * Calcular el porcentaje de merma
     */
    public function getPorcentajeMerma(): float
    {
        if ($this->total_huevos_usados <= 0) {
            return 0;
        }

        return round(($this->merma / $this->total_huevos_usados) * 100, 2);
    }

    /**
     * Verificar si el reempaque está completado
     */
    public function estaCompletado(): bool
    {
        return $this->estado === 'completado';
    }

    /**
     * Obtener el total de huevos empacados
     */
    public function getTotalHuevosEmpacados(): int
    {
        return ($this->cartones_30 * 30) + ($this->cartones_15 * 15) + $this->huevos_sueltos;
    }

    /**
     * Validar que los huevos empacados coincidan con los útiles
     */
    public function validarEmpaque(): bool
    {
        $empacados = $this->getTotalHuevosEmpacados();
        $utiles = $this->huevos_utiles;

        // Permitir diferencia mínima por redondeo
        return abs($empacados - $utiles) < 0.01;
    }

    /**
     * Obtener total de cartones regalados usados en este reempaque
     */
    public function getTotalCartonesRegaloUsados(): float
    {
        return $this->reempaqueLotes()->sum('cartones_regalo_usados');
    }

    /**
     * Obtener beneficio total de cartones regalados
     */
    public function getBeneficioRegalos(): float
    {
        $beneficio = 0;

        foreach ($this->reempaqueLotes as $reempaqueLote) {
            $lote = $reempaqueLote->lote;
            $huevosRegaloUsados = $reempaqueLote->cartones_regalo_usados * $lote->huevos_por_carton;
            $beneficio += $huevosRegaloUsados * $lote->costo_por_huevo;
        }

        return $beneficio;
    }

    /**
     * Calcular eficiencia del reempaque (% de aprovechamiento)
     */
    public function getEficiencia(): float
    {
        if ($this->total_huevos_usados <= 0) {
            return 0;
        }

        return round(($this->huevos_utiles / $this->total_huevos_usados) * 100, 2);
    }

    /**
     * Obtener resumen completo del reempaque
     */
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
            'costo_por_huevo' => $this->costo_unitario_promedio,
            'cartones_regalo_usados' => $this->getTotalCartonesRegaloUsados(),
            'beneficio_regalos' => $this->getBeneficioRegalos(),
            'lotes_usados' => $this->reempaqueLotes->count(),
            'proveedores' => $this->getProveedores()->pluck('nombre')->toArray(),
        ];
    }

    /**
     * Validar que todos los lotes sean del mismo producto (para tipo mezclado)
     */
    public function validarMismoProducto(): bool
    {
        if ($this->tipo === 'individual') {
            return true; // No aplica validación
        }

        $productos = $this->lotes()
            ->distinct()
            ->pluck('producto_id');

        return $productos->count() === 1;
    }

    /**
     * Obtener lista de proveedores involucrados
     */
    public function getProveedores()
    {
        return $this->lotes()
            ->with('proveedor')
            ->get()
            ->pluck('proveedor')
            ->unique('id');
    }

    /**
     * Marcar como completado
     */
    public function marcarCompletado(): void
    {
        $this->estado = 'completado';
        $this->save();
    }

    /**
     * Cancelar el reempaque
     */
    /**
     * Cancelar el reempaque
     */
    /**
     * Cancelar el reempaque
     */
    public function cancelar(?string $motivo = null): void
    {
        $this->estado = 'cancelado';

        if ($motivo) {
            $this->nota = ($this->nota ? $this->nota . "\n\n" : '') . "CANCELADO: " . $motivo;
        }

        $this->save();

        // Devolver huevos a los lotes (incluyendo regalos)
        foreach ($this->reempaqueLotes as $reempaqueLote) {
            $lote = $reempaqueLote->lote;

            // Devolver los huevos usados
            $lote->cantidad_huevos_remanente += $reempaqueLote->cantidad_huevos_usados;

            // Cambiar estado si ahora tiene stock
            if ($lote->cantidad_huevos_remanente > 0) {
                $lote->estado = 'disponible';
            }

            $lote->save();
        }

        // Eliminar productos generados si ya se agregaron a stock
        foreach ($this->reempaqueProductos as $producto) {
            if ($producto->agregado_a_stock) {
                // Revertir el stock
                $bodegaProducto = \App\Models\BodegaProducto::where('bodega_id', $producto->bodega_id)
                    ->where('producto_id', $producto->producto_id)
                    ->first();

                if ($bodegaProducto) {
                    $bodegaProducto->stock -= $producto->cantidad;
                    $bodegaProducto->save();
                }

                $producto->agregado_a_stock = false;
                $producto->save();
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
            // Generar número de reempaque automáticamente si no existe
            if (!$reempaque->numero_reempaque) {
                $ultimoReempaque = static::where('bodega_id', $reempaque->bodega_id)
                    ->orderBy('id', 'desc')
                    ->first();

                $secuencial = $ultimoReempaque
                    ? intval(substr($ultimoReempaque->numero_reempaque, -6)) + 1
                    : 1;

                $reempaque->numero_reempaque = sprintf('R-B%d-%06d', $reempaque->bodega_id, $secuencial);
            }

            // Calcular huevos_utiles automáticamente
            if (is_null($reempaque->huevos_utiles)) {
                $reempaque->huevos_utiles = $reempaque->total_huevos_usados - $reempaque->merma;
            }
        });

        static::saving(function ($reempaque) {
            // Validar que huevos_utiles = total_huevos_usados - merma
            $calculado = $reempaque->total_huevos_usados - $reempaque->merma;

            if (abs($reempaque->huevos_utiles - $calculado) > 0.01) {
                $reempaque->huevos_utiles = $calculado;
            }

            // Calcular costo_unitario_promedio si hay huevos útiles
            if ($reempaque->huevos_utiles > 0 && $reempaque->costo_total > 0) {
                $reempaque->costo_unitario_promedio = $reempaque->costo_total / $reempaque->huevos_utiles;
            }
        });
    }
}
