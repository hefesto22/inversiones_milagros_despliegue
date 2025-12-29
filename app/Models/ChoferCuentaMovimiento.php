<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChoferCuentaMovimiento extends Model
{
    use HasFactory;

    protected $table = 'chofer_cuenta_movimientos';

    protected $fillable = [
        'user_id',
        'tipo',
        'monto',
        'saldo_anterior',
        'saldo_nuevo',
        'viaje_id',
        'liquidacion_id',
        'concepto',
        'created_by',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'saldo_anterior' => 'decimal:2',
        'saldo_nuevo' => 'decimal:2',
    ];

    // Tipos de movimiento
    public const TIPO_COMISION = 'comision';
    public const TIPO_COBRO_DEVOLUCION = 'cobro_devolucion';
    public const TIPO_COBRO_MERMA = 'cobro_merma';
    public const TIPO_COBRO_FALTANTE = 'cobro_faltante';
    public const TIPO_PAGO_LIQUIDACION = 'pago_liquidacion';
    public const TIPO_AJUSTE_FAVOR = 'ajuste_favor';
    public const TIPO_AJUSTE_CONTRA = 'ajuste_contra';

    // ============================================
    // RELACIONES
    // ============================================

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class, 'viaje_id');
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ============================================
    // MÉTODOS
    // ============================================

    /**
     * Verificar si es movimiento que suma al saldo
     */
    public function esSuma(): bool
    {
        return in_array($this->tipo, [
            self::TIPO_COMISION,
            self::TIPO_AJUSTE_FAVOR,
        ]);
    }

    /**
     * Verificar si es movimiento que resta del saldo
     */
    public function esResta(): bool
    {
        return in_array($this->tipo, [
            self::TIPO_COBRO_DEVOLUCION,
            self::TIPO_COBRO_MERMA,
            self::TIPO_COBRO_FALTANTE,
            self::TIPO_PAGO_LIQUIDACION,
            self::TIPO_AJUSTE_CONTRA,
        ]);
    }

    /**
     * Obtener etiqueta del tipo
     */
    public function getTipoLabel(): string
    {
        return match ($this->tipo) {
            self::TIPO_COMISION => 'Comisión ganada',
            self::TIPO_COBRO_DEVOLUCION => 'Cobro por devolución',
            self::TIPO_COBRO_MERMA => 'Cobro por merma',
            self::TIPO_COBRO_FALTANTE => 'Cobro por faltante',
            self::TIPO_PAGO_LIQUIDACION => 'Pago de liquidación',
            self::TIPO_AJUSTE_FAVOR => 'Ajuste a favor',
            self::TIPO_AJUSTE_CONTRA => 'Ajuste en contra',
            default => $this->tipo,
        };
    }

    /**
     * Obtener color para UI
     */
    public function getColor(): string
    {
        return match ($this->tipo) {
            self::TIPO_COMISION => 'success',
            self::TIPO_AJUSTE_FAVOR => 'success',
            self::TIPO_COBRO_DEVOLUCION => 'danger',
            self::TIPO_COBRO_MERMA => 'danger',
            self::TIPO_COBRO_FALTANTE => 'danger',
            self::TIPO_AJUSTE_CONTRA => 'danger',
            self::TIPO_PAGO_LIQUIDACION => 'info',
            default => 'gray',
        };
    }

    /**
     * Obtener icono para UI
     */
    public function getIcono(): string
    {
        return match ($this->tipo) {
            self::TIPO_COMISION => 'heroicon-o-arrow-trending-up',
            self::TIPO_AJUSTE_FAVOR => 'heroicon-o-plus-circle',
            self::TIPO_COBRO_DEVOLUCION => 'heroicon-o-arrow-uturn-left',
            self::TIPO_COBRO_MERMA => 'heroicon-o-exclamation-triangle',
            self::TIPO_COBRO_FALTANTE => 'heroicon-o-banknotes',
            self::TIPO_AJUSTE_CONTRA => 'heroicon-o-minus-circle',
            self::TIPO_PAGO_LIQUIDACION => 'heroicon-o-check-circle',
            default => 'heroicon-o-document',
        };
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeDelChofer($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeDelViaje($query, int $viajeId)
    {
        return $query->where('viaje_id', $viajeId);
    }

    public function scopeDeLiquidacion($query, int $liquidacionId)
    {
        return $query->where('liquidacion_id', $liquidacionId);
    }

    public function scopeComisiones($query)
    {
        return $query->where('tipo', self::TIPO_COMISION);
    }

    public function scopeCobros($query)
    {
        return $query->whereIn('tipo', [
            self::TIPO_COBRO_DEVOLUCION,
            self::TIPO_COBRO_MERMA,
            self::TIPO_COBRO_FALTANTE,
        ]);
    }

    public function scopePagos($query)
    {
        return $query->where('tipo', self::TIPO_PAGO_LIQUIDACION);
    }

    public function scopeAjustes($query)
    {
        return $query->whereIn('tipo', [
            self::TIPO_AJUSTE_FAVOR,
            self::TIPO_AJUSTE_CONTRA,
        ]);
    }

    public function scopeDelPeriodo($query, $fechaInicio, $fechaFin)
    {
        return $query->whereBetween('created_at', [$fechaInicio, $fechaFin]);
    }
}
