<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViajeDescarga extends Model
{
    use HasFactory;

    protected $table = 'viaje_descargas';

    protected $fillable = [
        'viaje_id',
        'producto_id',
        'unidad_id',
        'cantidad',
        'costo_unitario',
        'subtotal_costo',
        'estado_producto',
        'reingresa_stock',
        'cobrar_chofer',
        'monto_cobrar',
        'observaciones',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'costo_unitario' => 'decimal:2',
        'subtotal_costo' => 'decimal:2',
        'reingresa_stock' => 'boolean',
        'cobrar_chofer' => 'boolean',
        'monto_cobrar' => 'decimal:2',
    ];

    // Estados del producto
    public const ESTADO_BUENO = 'bueno';
    public const ESTADO_DANADO = 'danado';
    public const ESTADO_VENCIDO = 'vencido';

    // ============================================
    // RELACIONES
    // ============================================

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class, 'viaje_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_id');
    }

    // ============================================
    // MÉTODOS
    // ============================================

    /**
     * Calcular subtotal
     */
    public function calcular(): void
    {
        $this->subtotal_costo = $this->cantidad * $this->costo_unitario;

        if ($this->cobrar_chofer && $this->monto_cobrar <= 0) {
            $this->monto_cobrar = $this->subtotal_costo;
        }
    }

    /**
     * Marcar para cobrar al chofer
     */
    public function cobrarAlChofer(?float $monto = null): void
    {
        $this->cobrar_chofer = true;
        $this->monto_cobrar = $monto ?? $this->subtotal_costo;
        $this->save();
    }

    /**
     * Quitar cobro al chofer
     */
    public function noCobrarAlChofer(): void
    {
        $this->cobrar_chofer = false;
        $this->monto_cobrar = 0;
        $this->save();
    }

    /**
     * Verificar si está en buen estado
     */
    public function estaEnBuenEstado(): bool
    {
        return $this->estado_producto === self::ESTADO_BUENO;
    }

    /**
     * Obtener etiqueta de estado
     */
    public function getEstadoLabel(): string
    {
        return match ($this->estado_producto) {
            self::ESTADO_BUENO => 'Bueno',
            self::ESTADO_DANADO => 'Dañado',
            self::ESTADO_VENCIDO => 'Vencido',
            default => $this->estado_producto,
        };
    }

    /**
     * Obtener color de estado para UI
     */
    public function getEstadoColor(): string
    {
        return match ($this->estado_producto) {
            self::ESTADO_BUENO => 'success',
            self::ESTADO_DANADO => 'warning',
            self::ESTADO_VENCIDO => 'danger',
            default => 'gray',
        };
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeDelViaje($query, int $viajeId)
    {
        return $query->where('viaje_id', $viajeId);
    }

    public function scopeEnBuenEstado($query)
    {
        return $query->where('estado_producto', self::ESTADO_BUENO);
    }

    public function scopeDanados($query)
    {
        return $query->where('estado_producto', self::ESTADO_DANADO);
    }

    public function scopeVencidos($query)
    {
        return $query->where('estado_producto', self::ESTADO_VENCIDO);
    }

    public function scopeQueReingresanStock($query)
    {
        return $query->where('reingresa_stock', true);
    }

    public function scopeCobradosAlChofer($query)
    {
        return $query->where('cobrar_chofer', true);
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($descarga) {
            $descarga->calcular();
        });
    }
}
