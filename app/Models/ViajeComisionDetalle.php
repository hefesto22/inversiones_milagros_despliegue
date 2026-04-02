<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViajeComisionDetalle extends Model
{
    use HasFactory;

    protected $table = 'viaje_comision_detalle';

    protected $fillable = [
        'viaje_id',
        'viaje_venta_id',
        'viaje_venta_detalle_id',
        'producto_id',
        'cantidad',
        'precio_vendido',
        'precio_sugerido',
        'costo',
        'tipo_comision',
        'comision_unitaria',
        'comision_total',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'precio_vendido' => 'decimal:2',
        'precio_sugerido' => 'decimal:2',
        'costo' => 'decimal:4',        // 4 decimales para mantener precisión del costo unitario
        'comision_unitaria' => 'decimal:4', // FIX: era decimal:2, pero se guarda con round(...,4) en Viaje::calcularComisionDetalleRuta()
        'comision_total' => 'decimal:2',
    ];

    // Tipos de comisión
    public const TIPO_NORMAL = 'normal';
    public const TIPO_REDUCIDA = 'reducida';

    // ============================================
    // RELACIONES
    // ============================================

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class, 'viaje_id');
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(ViajeVenta::class, 'viaje_venta_id');
    }

    public function ventaDetalle(): BelongsTo
    {
        return $this->belongsTo(ViajeVentaDetalle::class, 'viaje_venta_detalle_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    // ============================================
    // MÉTODOS
    // ============================================

    /**
     * Verificar si vendió al precio sugerido o más
     */
    public function vendioAlPrecioSugerido(): bool
    {
        return $this->precio_vendido >= $this->precio_sugerido;
    }

    /**
     * Verificar si vendió por debajo del precio sugerido
     */
    public function vendioBajoPrecioSugerido(): bool
    {
        return $this->precio_vendido < $this->precio_sugerido;
    }

    /**
     * Obtener diferencia de precio
     */
    public function getDiferenciaPrecio(): float
    {
        return $this->precio_vendido - $this->precio_sugerido;
    }

    /**
     * Obtener porcentaje de diferencia
     */
    public function getPorcentajeDiferencia(): float
    {
        if ($this->precio_sugerido <= 0) {
            return 0;
        }

        return (($this->precio_vendido - $this->precio_sugerido) / $this->precio_sugerido) * 100;
    }

    /**
     * Obtener ganancia bruta (sin comisión)
     */
    public function getGananciaBruta(): float
    {
        return ($this->precio_vendido - $this->costo) * $this->cantidad;
    }

    /**
     * Obtener etiqueta de tipo de comisión
     */
    public function getTipoLabel(): string
    {
        return match ($this->tipo_comision) {
            self::TIPO_NORMAL => 'Normal',
            self::TIPO_REDUCIDA => 'Reducida',
            default => $this->tipo_comision,
        };
    }

    /**
     * Obtener color de tipo para UI
     */
    public function getTipoColor(): string
    {
        return match ($this->tipo_comision) {
            self::TIPO_NORMAL => 'success',
            self::TIPO_REDUCIDA => 'warning',
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

    public function scopeDeLaVenta($query, int $ventaId)
    {
        return $query->where('viaje_venta_id', $ventaId);
    }

    public function scopeNormales($query)
    {
        return $query->where('tipo_comision', self::TIPO_NORMAL);
    }

    public function scopeReducidas($query)
    {
        return $query->where('tipo_comision', self::TIPO_REDUCIDA);
    }
}