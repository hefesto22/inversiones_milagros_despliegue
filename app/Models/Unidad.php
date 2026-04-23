<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Unidad extends Model
{
    use HasFactory;

    protected $table = 'unidades';

    protected $fillable = [
        'nombre',
        'simbolo',
        'es_decimal',
        'activo',
        'created_by',
        'updated_by',
    ];

    // =======================
    // RELACIONES
    // =======================

    // Productos que usan esta unidad
    public function productos()
    {
        return $this->hasMany(Producto::class);
    }

    // Relación con categorías permitidas (nueva pivot categoria_unidad)
    public function categorias()
    {
        return $this->belongsToMany(Categoria::class, 'categoria_unidad')
            ->withTimestamps()
            ->withPivot(['activo', 'created_by', 'updated_by']);
    }

    // Usuario que creó el registro
    public function creador()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Usuario que actualizó el registro
    public function actualizador()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
