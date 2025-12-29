<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    protected $table = 'categorias';

    protected $fillable = [
        'nombre',
        'aplica_isv',
        'activo',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'aplica_isv' => 'boolean',
        'activo' => 'boolean',
    ];

    // =======================
    // RELACIONES
    // =======================

    // Productos asociados a la categoría
    public function productos()
    {
        return $this->hasMany(Producto::class);
    }

    // Unidades permitidas para esta categoría (pivot categoria_unidad)
    public function unidades()
    {
        return $this->belongsToMany(Unidad::class, 'categoria_unidad')
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
