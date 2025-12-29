<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaPago extends Model
{
    use HasFactory;

    protected $table = 'venta_pagos';

    protected $fillable = [
        'venta_id',
        'monto',
        'metodo_pago',
        'referencia',
        'nota',
        'created_by',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ============================================
    // HELPERS
    // ============================================

    /**
     * Obtener etiqueta del método de pago
     */
    public function getMetodoPagoLabel(): string
    {
        return match ($this->metodo_pago) {
            'efectivo' => 'Efectivo',
            'transferencia' => 'Transferencia',
            'tarjeta' => 'Tarjeta',
            'cheque' => 'Cheque',
            default => $this->metodo_pago,
        };
    }
}
