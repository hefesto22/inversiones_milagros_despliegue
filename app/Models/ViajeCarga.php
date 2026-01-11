<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViajeCarga extends Model
{
    use HasFactory;

    protected $table = 'viaje_cargas';

    // Tasa de ISV en Honduras (15%)
    public const ISV_RATE = 0.15;

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
     * Calcular subtotales considerando ISV si aplica
     */
    public function calcular(): void
    {
        // Subtotal costo (siempre sin ISV)
        $this->subtotal_costo = $this->cantidad * $this->costo_unitario;

        // Subtotal venta (con ISV si el producto lo aplica)
        $precioBase = $this->precio_venta_sugerido;
        
        // Verificar si el producto aplica ISV
        $aplicaIsv = $this->producto?->aplica_isv ?? false;
        
        if ($aplicaIsv) {
            // Precio con ISV redondeado hacia arriba (por unidad)
            $precioConIsv = ceil($precioBase * (1 + self::ISV_RATE));
            $this->subtotal_venta = $this->cantidad * $precioConIsv;
        } else {
            $this->subtotal_venta = $this->cantidad * $precioBase;
        }
    }

    /**
     * Obtener precio con ISV (unitario)
     */
    public function getPrecioConIsv(): float
    {
        $precioBase = $this->precio_venta_sugerido ?? 0;
        $aplicaIsv = $this->producto?->aplica_isv ?? false;
        
        if ($aplicaIsv) {
            return ceil($precioBase * (1 + self::ISV_RATE));
        }
        
        return $precioBase;
    }

    /**
     * Obtener monto de ISV del subtotal
     */
    public function getMontoIsv(): float
    {
        $aplicaIsv = $this->producto?->aplica_isv ?? false;
        
        if (!$aplicaIsv) {
            return 0;
        }
        
        $subtotalSinIsv = $this->cantidad * $this->precio_venta_sugerido;
        return $this->subtotal_venta - $subtotalSinIsv;
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
            'precio_con_isv' => $this->getPrecioConIsv(),
            'precio_minimo' => $this->precio_venta_minimo,
            'aplica_isv' => $this->producto?->aplica_isv ?? false,
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