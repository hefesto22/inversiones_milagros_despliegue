<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistorialCompraLote extends Model
{
    use HasFactory;

    protected $table = 'historial_compras_lote';

    protected $fillable = [
        'lote_id',
        'compra_id',
        'compra_detalle_id',
        'proveedor_id',
        'cartones_facturados',
        'cartones_regalo',
        'huevos_agregados',
        'costo_compra',
        'costo_por_huevo_compra',
        'costo_promedio_resultante',
        'huevos_totales_resultante',
    ];

    protected $casts = [
        'cartones_facturados' => 'decimal:2',
        'cartones_regalo' => 'decimal:2',
        'huevos_agregados' => 'decimal:2',
        'costo_compra' => 'decimal:2',
        'costo_por_huevo_compra' => 'decimal:4',
        'costo_promedio_resultante' => 'decimal:4',
        'huevos_totales_resultante' => 'decimal:2',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_id');
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function compraDetalle(): BelongsTo
    {
        return $this->belongsTo(CompraDetalle::class, 'compra_detalle_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    // ============================================
    // MÉTODOS DE INSTANCIA
    // ============================================

    /**
     * Obtener resumen de esta entrada
     */
    public function getResumen(): array
    {
        return [
            'fecha' => $this->created_at->format('d/m/Y H:i'),
            'proveedor' => $this->proveedor->nombre ?? 'Sin proveedor',
            'compra' => $this->compra->numero_compra ?? 'Sin número',
            'cartones_facturados' => $this->cartones_facturados,
            'cartones_regalo' => $this->cartones_regalo,
            'huevos_agregados' => $this->huevos_agregados,
            'costo_compra' => $this->costo_compra,
            'costo_por_huevo' => $this->costo_por_huevo_compra,
            'costo_promedio_despues' => $this->costo_promedio_resultante,
        ];
    }
}