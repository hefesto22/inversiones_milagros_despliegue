<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoPresentacion extends Model
{
    protected $table = 'producto_presentaciones';

    protected $fillable = [
        'producto_id',
        'unidad_id',
        'factor_a_base',
        'precio_referencia',
        'activo',
    ];

    protected $casts = [
        'factor_a_base' => 'decimal:6',
        'precio_referencia' => 'decimal:2',
        'activo' => 'boolean',
    ];

    /**
     * Producto al que pertenece la presentación.
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /**
     * Unidad de esta presentación (ej: cartón, pieza, litro).
     */
    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_id');
    }
}
