<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MovimientoInventarioTipo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Movimiento del Kardex de Inventario — fila del libro mayor.
 *
 * INMUTABLE POR DISEÑO: una vez creada, la fila no se puede actualizar ni
 * borrar (los hooks de boot() lanzan LogicException). Una corrección se asienta
 * como un movimiento nuevo que compensa — igual que en contabilidad.
 *
 * ESCRITURA CENTRALIZADA: la única puerta de entrada legítima es
 * App\Services\Inventario\RegistradorMovimientos. Ningún otro código debe
 * hacer MovimientoInventario::create() — eso garantiza que saldo_despues
 * siempre sea coherente con la transacción del movimiento.
 */
class MovimientoInventario extends Model
{
    protected $table = 'movimientos_inventario';

    public const NIVEL_LOTE   = 'lote';
    public const NIVEL_BODEGA = 'bodega';

    public const UNIDAD_HUEVOS   = 'huevos';
    public const UNIDAD_UNIDADES = 'unidades';

    protected $fillable = [
        'ocurrido_en',
        'tipo',
        'nivel',
        'lote_id',
        'bodega_producto_id',
        'producto_id',
        'bodega_id',
        'unidad',
        'delta',
        'saldo_despues',
        'costo_unitario',
        'valor',
        'referencia_type',
        'referencia_id',
        'descripcion',
        'contexto',
        'created_by',
    ];

    protected $casts = [
        'ocurrido_en'    => 'datetime',
        'tipo'           => MovimientoInventarioTipo::class,
        'delta'          => 'decimal:3',
        'saldo_despues'  => 'decimal:3',
        'costo_unitario' => 'decimal:6',
        'valor'          => 'decimal:4',
        'contexto'       => 'array',
    ];

    // ============================================================
    // INMUTABILIDAD (append-only)
    // ============================================================

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new LogicException(
                'El Kardex es inmutable: los movimientos no se editan. ' .
                'Para corregir, asienta un movimiento de compensación.'
            );
        });

        static::deleting(function () {
            throw new LogicException(
                'El Kardex es inmutable: los movimientos no se borran. ' .
                'Para corregir, asienta un movimiento de compensación.'
            );
        });
    }

    // ============================================================
    // RELACIONES
    // ============================================================

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function bodegaProducto(): BelongsTo
    {
        return $this->belongsTo(BodegaProducto::class, 'bodega_producto_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    public function referencia(): MorphTo
    {
        return $this->morphTo();
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeDeLote(Builder $query, int $loteId): Builder
    {
        return $query->where('lote_id', $loteId);
    }

    public function scopeDeBodegaProducto(Builder $query, int $bodegaProductoId): Builder
    {
        return $query->where('bodega_producto_id', $bodegaProductoId);
    }

    public function scopeDeProductoEnBodega(Builder $query, int $productoId, int $bodegaId): Builder
    {
        return $query->where('producto_id', $productoId)->where('bodega_id', $bodegaId);
    }

    public function scopeDeTipo(Builder $query, MovimientoInventarioTipo $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeEntreFechas(Builder $query, $desde, $hasta): Builder
    {
        return $query->whereBetween('ocurrido_en', [$desde, $hasta]);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    public function esEntrada(): bool
    {
        return (float) $this->delta > 0;
    }

    public function esSalida(): bool
    {
        return (float) $this->delta < 0;
    }

    /**
     * Cartones equivalentes 1x30 (solo tiene sentido en nivel lote).
     */
    public function getCartonesEquivAttribute(): ?float
    {
        if ($this->nivel !== self::NIVEL_LOTE) {
            return null;
        }

        return round((float) $this->delta / 30, 3);
    }
}
