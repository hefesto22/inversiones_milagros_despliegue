<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProveedorProducto extends Model
{
    use HasFactory;

    protected $table = 'proveedor_producto';

    protected $fillable = [
        'proveedor_id',
        'producto_id',
        'ultimo_precio_compra',
        'actualizado_en',
    ];

    protected $casts = [
        'ultimo_precio_compra' => 'decimal:4',
        'actualizado_en' => 'datetime',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    // ============================================
    // MÉTODOS
    // ============================================

    public static function registrarPrecio(int $proveedorId, int $productoId, float $precio): void
    {
        static::updateOrCreate(
            [
                'proveedor_id' => $proveedorId,
                'producto_id' => $productoId,
            ],
            [
                'ultimo_precio_compra' => $precio,
                'actualizado_en' => now(),
            ]
        );
    }
}
