<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bodega extends Model
{
    use HasFactory;

    protected $table = 'bodegas';

    protected $fillable = [
        'nombre',
        'codigo',
        'ubicacion',
        'activo',
        'created_by',
        'updated_by',
    ];

    // =======================
    // RELACIONES
    // =======================

    // Productos y stock por bodega
    public function productos()
    {
        return $this->hasMany(BodegaProducto::class, 'bodega_id');
    }

    // Usuarios asignados a esta bodega (pivot bodega_user)
    public function usuarios()
    {
        return $this->belongsToMany(User::class, 'bodega_user')
            ->withTimestamps()
            ->withPivot(['rol', 'activo', 'created_by', 'updated_by']);
    }

    // Usuario creador del registro
    public function creador()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Usuario que actualizó el registro
    public function actualizador()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function compras()
    {
        return $this->hasMany(Compra::class, 'bodega_id');
    }

    public function lotes()
    {
        return $this->hasMany(Lote::class, 'bodega_id');
    }

    public function reempaques()
    {
        return $this->hasMany(Reempaque::class, 'bodega_id');
    }

    public function reempaqueProductos()
    {
        return $this->hasMany(ReempaqueProducto::class, 'bodega_id');
    }
}
