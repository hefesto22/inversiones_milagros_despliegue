<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Producto extends Model
{
    protected $table = 'productos';

    protected $fillable = [
        'nombre',
        'tipo',
        'unidad_base_id',
        'sku',
        'activo',
        'user_id',
        'user_update',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * Unidad base (pieza, litro, libra, etc.)
     */
    public function unidadBase(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_base_id');
    }

    /**
     * Presentaciones del producto (ej: cartón, pieza, litro).
     */
    public function presentaciones(): HasMany
    {
        return $this->hasMany(ProductoPresentacion::class, 'producto_id');
    }

    /**
     * Relación con bodegas (stock y precio por bodega).
     */
    public function bodegas(): BelongsToMany
    {
        return $this->belongsToMany(Bodega::class, 'bodega_producto')
            ->withPivot(['stock', 'stock_min', 'precio_base', 'activo'])
            ->withTimestamps();
    }

    /**
     * Usuario que creó el producto.
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Usuario que actualizó el producto por última vez.
     */
    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_update');
    }

}
