<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViajeVentaDetalle extends Model
{
    use HasFactory;

    protected $fillable = [
        'viaje_venta_id',
        'producto_id',
        'unidad_id_presentacion',
        'cantidad_presentacion',
        'factor_a_base',
        'cantidad_base',
        'precio_unitario_presentacion',
        'descuento',
        'total_linea',
    ];

    protected $casts = [
        'cantidad_presentacion' => 'decimal:3',
        'factor_a_base' => 'decimal:6',
        'cantidad_base' => 'decimal:3',
        'precio_unitario_presentacion' => 'decimal:4',
        'descuento' => 'decimal:4',
        'total_linea' => 'decimal:2',
    ];

    /**
     * Relación con venta de viaje
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(ViajeVenta::class, 'viaje_venta_id');
    }

    /**
     * Relación con producto
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * Relación con unidad de presentación
     */
    public function unidadPresentacion(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_id_presentacion');
    }

    /**
     * Calcular cantidad base automáticamente
     */
    public function calcularCantidadBase(): void
    {
        $this->cantidad_base = $this->cantidad_presentacion * $this->factor_a_base;
    }

    /**
     * Calcular total de línea
     */
    public function calcularTotalLinea(): void
    {
        $subtotal = $this->cantidad_presentacion * $this->precio_unitario_presentacion;
        $this->total_linea = $subtotal - ($this->descuento ?? 0);
    }

    /**
     * Calcular todo automáticamente
     */
    public function calcular(): void
    {
        $this->calcularCantidadBase();
        $this->calcularTotalLinea();
    }

    /**
     * Obtener el precio unitario en unidad base
     */
    public function getPrecioUnitarioBaseAttribute(): float
    {
        if ($this->factor_a_base == 0) {
            return 0;
        }

        return $this->precio_unitario_presentacion / $this->factor_a_base;
    }

    /**
     * Boot del modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Calcular automáticamente antes de guardar
        static::saving(function ($detalle) {
            $detalle->calcular();
        });

        // Recalcular totales de la venta después de guardar
        static::saved(function ($detalle) {
            $detalle->venta->calcularTotales();
        });

        // Recalcular totales de la venta después de eliminar
        static::deleted(function ($detalle) {
            if ($detalle->venta) {
                $detalle->venta->calcularTotales();
            }
        });
    }
}
