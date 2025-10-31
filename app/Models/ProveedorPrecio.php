<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProveedorPrecio extends Model
{
    use HasFactory;

    protected $table = 'proveedor_precios';

    protected $fillable = [
        'proveedor_id',
        'producto_id',
        'unidad_id',
        'precio_compra',
        'vigente_desde',
        'vigente_hasta',
        'user_id',
    ];

    protected $casts = [
        'precio_compra' => 'decimal:4',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    /* -------------------- Relaciones -------------------- */

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function unidad()
    {
        return $this->belongsTo(Unidad::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /* -------------------- Scopes útiles -------------------- */

    // Precio vigente (activo)
    public function scopeVigente($query)
    {
        return $query->whereNull('vigente_hasta');
    }

    // Filtro por proveedor
    public function scopeDeProveedor($query, $proveedorId)
    {
        return $query->where('proveedor_id', $proveedorId);
    }
}
