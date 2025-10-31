<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProveedorProductoPrecio extends Model
{
    protected $table = 'proveedor_producto_precios';

    protected $fillable = [
        'proveedor_id', 'producto_id', 'precio',
        'vigente_desde', 'vigente_hasta', 'user_id'
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActivos($q)
    {
        return $q->whereNull('vigente_hasta');
    }
}
