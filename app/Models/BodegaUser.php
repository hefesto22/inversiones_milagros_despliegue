<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BodegaUser extends Model
{
    protected $table = 'bodega_user';

    protected $fillable = [
        'bodega_id',
        'user_id',
        'rol',
        'activo',
        'created_by',
        'updated_by',
    ];

    // =======================
    // RELACIONES
    // =======================

    // Bodega asignada
    public function bodega()
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    // Usuario asignado a la bodega
    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
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
