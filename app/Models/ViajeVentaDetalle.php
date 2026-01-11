<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViajeVentaDetalle extends Model
{
    use HasFactory;

    protected $table = 'viaje_venta_detalles';

    protected $fillable = [
        'viaje_venta_id',
        'viaje_carga_id',
        'producto_id',
        'cantidad',
        'precio_base',
        'precio_con_isv',
        'monto_isv',
        'costo_unitario',
        'aplica_isv',
        'subtotal',
        'total_isv',
        'total_linea',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_base' => 'decimal:2',
        'precio_con_isv' => 'decimal:2',
        'monto_isv' => 'decimal:2',
        'costo_unitario' => 'decimal:2',
        'aplica_isv' => 'boolean',
        'subtotal' => 'decimal:2',
        'total_isv' => 'decimal:2',
        'total_linea' => 'decimal:2',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function venta(): BelongsTo
    {
        return $this->belongsTo(ViajeVenta::class, 'viaje_venta_id');
    }

    public function viajeCarga(): BelongsTo
    {
        return $this->belongsTo(ViajeCarga::class, 'viaje_carga_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    // ============================================
    // MÉTODOS
    // ============================================

    /**
     * Calcular totales de la línea
     */
    public function calcular(): void
    {
        $this->subtotal = $this->cantidad * $this->precio_base;
        $this->total_isv = $this->cantidad * $this->monto_isv;
        $this->total_linea = $this->cantidad * $this->precio_con_isv;
    }

    /**
     * Calcular ganancia de esta línea
     */
    public function calcularGanancia(): float
    {
        $costoTotal = $this->cantidad * $this->costo_unitario;
        return $this->subtotal - $costoTotal;
    }

    /**
     * Obtener margen de ganancia en porcentaje
     */
    public function getMargenPorcentaje(): float
    {
        if ($this->costo_unitario <= 0) {
            return 100;
        }

        return (($this->precio_base - $this->costo_unitario) / $this->costo_unitario) * 100;
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($detalle) {
            $detalle->calcular();
        });

        static::saved(function ($detalle) {
            // Recalcular totales de la venta
            if ($detalle->venta) {
                $detalle->venta->calcularTotales();
            }
        });

        static::deleted(function ($detalle) {
            if ($detalle->venta) {
                $detalle->venta->calcularTotales();
            }
        });
    }
}