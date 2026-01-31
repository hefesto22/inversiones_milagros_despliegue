<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViajeMerma extends Model
{
    use HasFactory;

    protected $table = 'viaje_mermas';

    protected $fillable = [
        'viaje_id',
        'producto_id',
        'unidad_id',
        'cantidad',
        'costo_unitario',
        'subtotal_costo',
        'motivo',
        'descripcion',
        'cobrar_chofer',
        'monto_cobrar',
        'registrado_por',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'costo_unitario' => 'decimal:2',
        'subtotal_costo' => 'decimal:2',
        'cobrar_chofer' => 'boolean',
        'monto_cobrar' => 'decimal:2',
    ];

    // Motivos de merma
    public const MOTIVO_ROTURA = 'rotura';
    public const MOTIVO_VENCIMIENTO = 'vencimiento';
    public const MOTIVO_ROBO = 'robo';
    public const MOTIVO_DANO_TRANSPORTE = 'dano_transporte';
    public const MOTIVO_REGALO_CLIENTE = 'regalo_cliente';
    public const MOTIVO_OTRO = 'otro';

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

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
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
     * Obtener etiqueta de motivo
     */
    public function getMotivoLabel(): string
    {
        return match ($this->motivo) {
            self::MOTIVO_ROTURA => 'Rotura',
            self::MOTIVO_VENCIMIENTO => 'Vencimiento',
            self::MOTIVO_ROBO => 'Robo',
            self::MOTIVO_DANO_TRANSPORTE => 'Daño en transporte',
            self::MOTIVO_REGALO_CLIENTE => 'Regalo a cliente',
            self::MOTIVO_OTRO => 'Otro',
            default => $this->motivo,
        };
    }

    /**
     * Obtener color de motivo para UI
     */
    public function getMotivoColor(): string
    {
        return match ($this->motivo) {
            self::MOTIVO_ROTURA => 'warning',
            self::MOTIVO_VENCIMIENTO => 'danger',
            self::MOTIVO_ROBO => 'danger',
            self::MOTIVO_DANO_TRANSPORTE => 'warning',
            self::MOTIVO_REGALO_CLIENTE => 'info',
            self::MOTIVO_OTRO => 'gray',
            default => 'gray',
        };
    }

    /**
     * Obtener icono de motivo para UI
     */
    public function getMotivoIcono(): string
    {
        return match ($this->motivo) {
            self::MOTIVO_ROTURA => 'heroicon-o-exclamation-triangle',
            self::MOTIVO_VENCIMIENTO => 'heroicon-o-clock',
            self::MOTIVO_ROBO => 'heroicon-o-shield-exclamation',
            self::MOTIVO_DANO_TRANSPORTE => 'heroicon-o-truck',
            self::MOTIVO_REGALO_CLIENTE => 'heroicon-o-gift',
            self::MOTIVO_OTRO => 'heroicon-o-question-mark-circle',
            default => 'heroicon-o-document',
        };
    }

    /**
     * Verificar si es culpa del chofer (normalmente se cobra)
     */
    public function esCulpaChofer(): bool
    {
        return in_array($this->motivo, [
            self::MOTIVO_ROTURA,
            self::MOTIVO_ROBO,
            self::MOTIVO_DANO_TRANSPORTE,
        ]);
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeDelViaje($query, int $viajeId)
    {
        return $query->where('viaje_id', $viajeId);
    }

    public function scopePorMotivo($query, string $motivo)
    {
        return $query->where('motivo', $motivo);
    }

    public function scopeCobradosAlChofer($query)
    {
        return $query->where('cobrar_chofer', true);
    }

    public function scopeNoCobrados($query)
    {
        return $query->where('cobrar_chofer', false);
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($merma) {
            $merma->calcular();
        });

        static::saved(function ($merma) {
            // Actualizar cantidad_merma en la carga correspondiente
            static::actualizarCargaMerma($merma->viaje_id, $merma->producto_id);
        });

        static::deleted(function ($merma) {
            // Recalcular cantidad_merma cuando se borra una merma
            static::actualizarCargaMerma($merma->viaje_id, $merma->producto_id);
        });
    }

    /**
     * Actualizar cantidad_merma en la carga correspondiente
     */
    protected static function actualizarCargaMerma(int $viajeId, int $productoId): void
    {
        $carga = ViajeCarga::where('viaje_id', $viajeId)
            ->where('producto_id', $productoId)
            ->first();

        if ($carga) {
            $totalMerma = ViajeMerma::where('viaje_id', $viajeId)
                ->where('producto_id', $productoId)
                ->sum('cantidad');

            $carga->cantidad_merma = $totalMerma ?? 0;
            $carga->save();
        }
    }
}