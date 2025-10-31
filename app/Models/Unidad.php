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
        'user_id',
        'user_update',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function userUpdate()
    {
        return $this->belongsTo(User::class, 'user_update');
    }
}
