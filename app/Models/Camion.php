<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Camion extends Model
{
    protected $table = 'camiones';
    protected $fillable = [
        'placa', 'capacidad_cartones_30', 'capacidad_cartones_15', 'activo'
    ];

    public function asignaciones(): HasMany
    {
        return $this->hasMany(ChoferCamionAsignacion::class);
    }
}
