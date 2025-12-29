<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaUnidad extends Model
{
    protected $table = 'categoria_unidad';

    protected $fillable = [
        'categoria_id',
        'unidad_id',
        'activo',
        'created_by',
        'updated_by',
    ];

    // =======================
    // RELACIONES
    // =======================

    // Categoría asignada
    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    // Unidad asignada a la categoría
    public function unidad()
    {
        return $this->belongsTo(Unidad::class, 'unidad_id');
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
