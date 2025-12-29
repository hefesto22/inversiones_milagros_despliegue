<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViajeCarga extends Model
{
    use HasFactory;

    protected $table = 'viaje_cargas';

    protected $fillable = [
        'viaje_id',
        'producto_id',
        'unidad_id',
        'cantidad',
        'costo_unitario',
        'precio_venta_sugerido',
        'precio_venta_minimo',
        'subtotal_costo',
        'subtotal_venta',
        'cantidad_vendida',
        'cantidad_merma',
        'cantidad_devuelta',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'costo_unitario' => 'decimal:2',
        'precio_venta_sugerido' => 'decimal:2',
        'precio_venta_minimo' => 'decimal:2',
        'subtotal_costo' => 'decimal:2',
        'subtotal_venta' => 'decimal:2',
        'cantidad_vendida' => 'decimal:3',
        'cantidad_merma' => 'decimal:3',
        'cantidad_devuelta' => 'decimal:3',
    ];

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
     * Calcular subtotales
     */
    public function calcular(): void
    {
        $this->subtotal_costo = $this->cantidad * $this->costo_unitario;
        $this->subtotal_venta = $this->cantidad * $this->precio_venta_sugerido;
    }

    /**
     * Obtener cantidad disponible para vender
     */
    public function getCantidadDisponible(): float
    {
        return $this->cantidad - $this->cantidad_vendida - $this->cantidad_merma - $this->cantidad_devuelta;
    }

    /**
     * Verificar si tiene stock disponible
     */
    public function tieneDisponible(float $cantidad): bool
    {
        return $this->getCantidadDisponible() >= $cantidad;
    }

    /**
     * Registrar venta
     */
    public function registrarVenta(float $cantidad): void
    {
        $this->cantidad_vendida += $cantidad;
        $this->save();
    }

    /**
     * Registrar merma
     */
    public function registrarMerma(float $cantidad): void
    {
        $this->cantidad_merma += $cantidad;
        $this->save();
    }

    /**
     * Registrar devolución
     */
    public function registrarDevolucion(float $cantidad): void
    {
        $this->cantidad_devuelta += $cantidad;
        $this->save();
    }

    /**
     * Verificar si está completa (todo vendido/merma/devuelto)
     */
    public function estaCompleta(): bool
    {
        return $this->getCantidadDisponible() <= 0;
    }

    /**
     * Obtener porcentaje vendido
     */
    public function getPorcentajeVendido(): float
    {
        if ($this->cantidad <= 0) {
            return 0;
        }

        return ($this->cantidad_vendida / $this->cantidad) * 100;
    }

    /**
     * Obtener resumen
     */
    public function getResumen(): array
    {
        return [
            'producto' => $this->producto->nombre ?? 'N/A',
            'cantidad_cargada' => $this->cantidad,
            'cantidad_vendida' => $this->cantidad_vendida,
            'cantidad_merma' => $this->cantidad_merma,
            'cantidad_devuelta' => $this->cantidad_devuelta,
            'cantidad_disponible' => $this->getCantidadDisponible(),
            'porcentaje_vendido' => round($this->getPorcentajeVendido(), 2),
            'costo_unitario' => $this->costo_unitario,
            'precio_sugerido' => $this->precio_venta_sugerido,
            'precio_minimo' => $this->precio_venta_minimo,
        ];
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeDelViaje($query, int $viajeId)
    {
        return $query->where('viaje_id', $viajeId);
    }

    public function scopeConDisponible($query)
    {
        return $query->whereRaw('cantidad > (cantidad_vendida + cantidad_merma + cantidad_devuelta)');
    }

    public function scopeCompletas($query)
    {
        return $query->whereRaw('cantidad <= (cantidad_vendida + cantidad_merma + cantidad_devuelta)');
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($carga) {
            $carga->calcular();
        });
    }
}
