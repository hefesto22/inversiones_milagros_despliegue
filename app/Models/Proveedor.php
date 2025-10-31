<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    protected $table = 'proveedores';
    protected $fillable = [
        'nombre',
        'rtn',
        'telefono',
        'direccion',
        'estado'
    ];

    public function precios(): HasMany
    {
        return $this->hasMany(ProveedorPrecio::class);
    }

    public function preciosProducto(): HasMany
    {
        return $this->hasMany(ProveedorProductoPrecio::class);
    }

    public function precioActualDeProducto(int $productoId)
    {
        return $this->preciosProducto()
            ->where('producto_id', $productoId)
            ->whereNull('vigente_hasta')
            ->orderByDesc('vigente_desde')
            ->first();
    }
}
