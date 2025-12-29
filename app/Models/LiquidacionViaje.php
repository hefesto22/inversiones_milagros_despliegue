<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiquidacionViaje extends Model
{
    use HasFactory;

    protected $table = 'liquidacion_viajes';

    protected $fillable = [
        'liquidacion_id',
        'viaje_id',
        'comision_viaje',
        'cobros_viaje',
        'neto_viaje',
    ];

    protected $casts = [
        'comision_viaje' => 'decimal:2',
        'cobros_viaje' => 'decimal:2',
        'neto_viaje' => 'decimal:2',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class);
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    // ============================================
    // MÉTODOS
    // ============================================

    /**
     * Calcular neto automáticamente
     */
    public function calcularNeto(): void
    {
        $this->neto_viaje = $this->comision_viaje - $this->cobros_viaje;
        $this->save();
    }

    /**
     * Sincronizar datos desde el viaje
     */
    public function sincronizarDesdeViaje(): void
    {
        if ($this->viaje) {
            $this->comision_viaje = $this->viaje->comision_ganada;
            $this->cobros_viaje = $this->viaje->cobros_devoluciones;
            $this->neto_viaje = $this->viaje->neto_chofer;
            $this->save();
        }
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        // Al crear, calcular neto si no viene
        static::creating(function ($liquidacionViaje) {
            if (!$liquidacionViaje->neto_viaje) {
                $liquidacionViaje->neto_viaje = $liquidacionViaje->comision_viaje - $liquidacionViaje->cobros_viaje;
            }
        });

        // Recalcular liquidación cuando se modifica
        static::saved(function ($liquidacionViaje) {
            $liquidacionViaje->liquidacion->recalcular();
        });

        static::deleted(function ($liquidacionViaje) {
            $liquidacionViaje->liquidacion->recalcular();
        });
    }
}
