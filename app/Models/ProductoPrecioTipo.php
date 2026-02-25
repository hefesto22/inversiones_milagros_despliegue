<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoPrecioTipo extends Model
{
    use HasFactory;

    protected $table = 'producto_precio_tipo';

    protected $fillable = [
        'producto_id',
        'tipo_cliente',
        'descuento_maximo',
        'precio_minimo_fijo',
        'activo',
    ];

    protected $casts = [
        'descuento_maximo' => 'decimal:4',
        'precio_minimo_fijo' => 'decimal:4',
        'activo' => 'boolean',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopePorTipo($query, string $tipo)
    {
        return $query->where('tipo_cliente', $tipo);
    }

    public function scopeDeProducto($query, int $productoId)
    {
        return $query->where('producto_id', $productoId);
    }

    // ============================================
    // HELPERS
    // ============================================

    /**
     * Obtener el descuento máximo efectivo en Lempiras
     */
    public function getDescuentoEfectivo(): float
    {
        return (float) $this->descuento_maximo;
    }

    /**
     * Calcular precio mínimo para un precio de venta dado
     * Si tiene precio_minimo_fijo, usa ese directamente
     * Si no, calcula: precio_venta - descuento_maximo
     */
    public function calcularPrecioMinimo(float $precioVenta): float
    {
        if (!is_null($this->precio_minimo_fijo) && $this->precio_minimo_fijo > 0) {
            return (float) $this->precio_minimo_fijo;
        }

        return round($precioVenta - (float) $this->descuento_maximo, 4);
    }
}