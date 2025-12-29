<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevolucionDetalle extends Model
{
    use HasFactory;

    protected $table = 'devolucion_detalles';

    // Tasa de ISV
    public const ISV_RATE = 0.15;

    protected $fillable = [
        'devolucion_id',
        'producto_id',
        'venta_detalle_id',
        'cantidad',
        'precio_unitario',
        'aplica_isv',
        'isv_unitario',
        'subtotal',
        'total_isv',
        'total_linea',
        'estado_producto',
        'reingresa_stock',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'aplica_isv' => 'boolean',
        'isv_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total_isv' => 'decimal:2',
        'total_linea' => 'decimal:2',
        'reingresa_stock' => 'boolean',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(Devolucion::class, 'devolucion_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function ventaDetalle(): BelongsTo
    {
        return $this->belongsTo(VentaDetalle::class, 'venta_detalle_id');
    }

    // ============================================
    // MÉTODOS DE CÁLCULO
    // ============================================

    /**
     * Calcular todos los valores de la línea
     */
    public function calcular(): void
    {
        $this->subtotal = $this->cantidad * $this->precio_unitario;

        if ($this->aplica_isv) {
            $this->isv_unitario = round($this->precio_unitario * self::ISV_RATE, 2);
            $this->total_isv = $this->cantidad * $this->isv_unitario;
        } else {
            $this->isv_unitario = 0;
            $this->total_isv = 0;
        }

        $this->total_linea = $this->subtotal + $this->total_isv;
    }

    // ============================================
    // HELPERS
    // ============================================

    /**
     * Obtener etiqueta del estado del producto
     */
    public function getEstadoProductoLabel(): string
    {
        return match ($this->estado_producto) {
            'bueno' => 'En buen estado',
            'danado' => 'Dañado',
            'vencido' => 'Vencido',
            default => $this->estado_producto,
        };
    }

    /**
     * Determinar si puede reingresar a stock
     */
    public function puedeReingresarStock(): bool
    {
        return $this->estado_producto === 'bueno';
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($detalle) {
            $detalle->calcular();

            if ($detalle->isDirty('estado_producto')) {
                $detalle->reingresa_stock = $detalle->puedeReingresarStock();
            }
        });

        static::saved(function ($detalle) {
            $detalle->devolucion->recalcularTotales();
        });

        static::deleted(function ($detalle) {
            $detalle->devolucion->recalcularTotales();
        });
    }
}
