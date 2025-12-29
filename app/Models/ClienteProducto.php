<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClienteProducto extends Model
{
    use HasFactory;

    protected $table = 'cliente_producto';

    public $timestamps = false;

    protected $fillable = [
        'cliente_id',
        'producto_id',
        'ultimo_precio_venta',
        'ultimo_precio_con_isv',
        'cantidad_ultima_venta',
        'fecha_ultima_venta',
        'total_ventas',
        'cantidad_total_vendida',
    ];

    protected $casts = [
        'ultimo_precio_venta' => 'decimal:2',
        'ultimo_precio_con_isv' => 'decimal:2',
        'cantidad_ultima_venta' => 'decimal:2',
        'fecha_ultima_venta' => 'datetime',
        'total_ventas' => 'integer',
        'cantidad_total_vendida' => 'decimal:2',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    // ============================================
    // HELPERS
    // ============================================

    /**
     * Obtener precio promedio histórico
     */
    public function getPrecioPromedio(): float
    {
        if ($this->total_ventas <= 0) {
            return $this->ultimo_precio_venta ?? 0;
        }

        // Esto es aproximado, para un cálculo real necesitarías historial completo
        return $this->ultimo_precio_venta ?? 0;
    }

    /**
     * Verificar si tiene historial
     */
    public function tieneHistorial(): bool
    {
        return $this->total_ventas > 0;
    }

    /**
     * Obtener días desde última venta
     */
    public function getDiasDesdeUltimaVenta(): ?int
    {
        if (!$this->fecha_ultima_venta) {
            return null;
        }

        return $this->fecha_ultima_venta->diffInDays(now());
    }
}
