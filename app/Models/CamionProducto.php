<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CamionProducto extends Model
{
    use HasFactory;

    protected $table = 'camion_producto';

    protected $fillable = [
        'camion_id',
        'producto_id',
        'stock',
        'costo_promedio',
        'precio_venta_sugerido',
    ];

    protected $casts = [
        'stock' => 'decimal:3',
        'costo_promedio' => 'decimal:2',
        'precio_venta_sugerido' => 'decimal:2',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function camion(): BelongsTo
    {
        return $this->belongsTo(Camion::class, 'camion_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    // ============================================
    // MÉTODOS
    // ============================================

    public function agregarStock(float $cantidad, float $costo, float $precioSugerido): void
    {
        $this->stock += $cantidad;
        $this->costo_promedio = $costo;
        $this->precio_venta_sugerido = $precioSugerido;
        $this->save();
    }

    public function reducirStock(float $cantidad): void
    {
        $this->stock -= $cantidad;
        if ($this->stock < 0) {
            $this->stock = 0;
        }
        $this->save();
    }

    public function vaciarStock(): void
    {
        $this->stock = 0;
        $this->save();
    }

    public function tieneStock(float $cantidadRequerida = 0): bool
    {
        return $this->stock >= $cantidadRequerida;
    }

    public function getValorInventario(): float
    {
        return $this->stock * $this->costo_promedio;
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeConStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    public function scopeDelCamion($query, int $camionId)
    {
        return $query->where('camion_id', $camionId);
    }
}
