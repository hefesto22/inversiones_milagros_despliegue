<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientePrecio extends Model
{
    use HasFactory;

    protected $table = 'cliente_precios';

    protected $fillable = [
        'cliente_id',
        'producto_id',
        'unidad_id',
        'precio_venta',
        'vigente_desde',
        'vigente_hasta',
        'user_id',
    ];

    protected $casts = [
        'precio_venta'   => 'decimal:4',
        'vigente_desde'  => 'date',
        'vigente_hasta'  => 'date',
    ];

    /* -------------------- Relaciones -------------------- */

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
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

    // Filtro por cliente
    public function scopeDeCliente($query, $clienteId)
    {
        return $query->where('cliente_id', $clienteId);
    }
}
