<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClienteProductoPrecio extends Model
{
    protected $table = 'cliente_producto_precios';

    protected $fillable = [
        'cliente_id', 'producto_id', 'precio',
        'vigente_desde', 'vigente_hasta', 'user_id'
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scope: precio activo
    public function scopeActivos($q)
    {
        return $q->whereNull('vigente_hasta');
    }
}
