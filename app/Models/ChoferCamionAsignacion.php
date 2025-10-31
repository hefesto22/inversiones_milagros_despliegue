<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChoferCamionAsignacion extends Model
{
    protected $table = 'chofer_camion_asignaciones';
    protected $fillable = [
        'user_id', 'camion_id', 'vigente_desde', 'vigente_hasta', 'activo'
    ];

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function camion(): BelongsTo
    {
        return $this->belongsTo(Camion::class, 'camion_id');
    }
}
