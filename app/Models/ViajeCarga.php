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
        'reempaque_id',
        'producto_id',
        'unidad_id',
        'cantidad',
        'costo_unitario',
        'costo_bodega_original', // Costo original de bodega antes de cargar (para restaurar al eliminar)
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
        'costo_unitario' => 'decimal:4',
        'costo_bodega_original' => 'decimal:4',
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

    public function reempaque(): BelongsTo
    {
        return $this->belongsTo(Reempaque::class, 'reempaque_id');
    }

    // ============================================
    // MÉTODOS DE CÁLCULO
    // ============================================

    /**
     * Calcular subtotales considerando ISV si aplica
     * 
     * NOTA: Solo recalcula si los subtotales no fueron ya establecidos
     * por el CargasRelationManager (que hace cálculos más precisos
     * considerando fuentes mixtas bodega+lote)
     */
    public function calcular(): void
    {
        $cantidad = floatval($this->cantidad ?? 0);
        $costoUnitario = floatval($this->costo_unitario ?? 0);
        $precioBase = floatval($this->precio_venta_sugerido ?? 0);

        // Subtotal costo = costo unitario (sin ISV) × cantidad
        $this->subtotal_costo = round($cantidad * $costoUnitario, 2);

        // Subtotal venta: depende de si aplica ISV
        $aplicaIsv = $this->producto?->aplica_isv ?? false;

        if ($aplicaIsv && $precioBase > 0) {
            // precio_venta_sugerido es SIN ISV, calcular CON ISV
            $precioConIsv = round($precioBase * (1 + self::ISV_RATE), 2);
            $this->subtotal_venta = round($cantidad * $precioConIsv, 2);
        } else {
            // Sin ISV: precio directo
            $this->subtotal_venta = round($cantidad * $precioBase, 2);
        }
    }

    /**
     * Obtener precio con ISV (unitario)
     */
    public function getPrecioConIsv(): float
    {
        $precioBase = floatval($this->precio_venta_sugerido ?? 0);
        $aplicaIsv = $this->producto?->aplica_isv ?? false;

        if ($aplicaIsv && $precioBase > 0) {
            return round($precioBase * (1 + self::ISV_RATE), 2);
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

        $subtotalSinIsv = floatval($this->cantidad) * floatval($this->precio_venta_sugerido);
        return round(floatval($this->subtotal_venta) - $subtotalSinIsv, 2);
    }

    // ============================================
    // MÉTODOS DE INVENTARIO
    // ============================================

    /**
     * Obtener cantidad disponible para vender
     */
    public function getCantidadDisponible(): float
    {
        return floatval($this->cantidad) 
             - floatval($this->cantidad_vendida) 
             - floatval($this->cantidad_merma) 
             - floatval($this->cantidad_devuelta);
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
        $this->cantidad_vendida = floatval($this->cantidad_vendida) + $cantidad;
        $this->save();
    }

    /**
     * Registrar merma
     */
    public function registrarMerma(float $cantidad): void
    {
        $this->cantidad_merma = floatval($this->cantidad_merma) + $cantidad;
        $this->save();
    }

    /**
     * Registrar devolución
     */
    public function registrarDevolucion(float $cantidad): void
    {
        $this->cantidad_devuelta = floatval($this->cantidad_devuelta) + $cantidad;
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
        $cantidad = floatval($this->cantidad);
        if ($cantidad <= 0) {
            return 0;
        }

        return (floatval($this->cantidad_vendida) / $cantidad) * 100;
    }

    /**
     * Obtener la cantidad que vino de bodega (no del reempaque)
     */
    public function getCantidadDeBodega(): float
    {
        $cantidadTotal = floatval($this->cantidad);
        
        if (!$this->reempaque_id) {
            return $cantidadTotal; // Todo vino de bodega
        }

        // Si hay reempaque, calcular cuánto vino del reempaque
        $reempaqueProducto = ReempaqueProducto::where('reempaque_id', $this->reempaque_id)
            ->where('producto_id', $this->producto_id)
            ->first();

        $cantidadReempacada = floatval($reempaqueProducto->cantidad ?? 0);
        return max(0, $cantidadTotal - $cantidadReempacada);
    }

    /**
     * Obtener el costo correcto para restaurar en bodega al eliminar
     * Usa costo_bodega_original si existe, sino fallback a costo_unitario
     */
    public function getCostoParaRestaurar(): float
    {
        return floatval($this->costo_bodega_original ?? $this->costo_unitario ?? 0);
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
            'costo_bodega_original' => $this->costo_bodega_original,
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
            // Recalcular subtotales para mantener consistencia
            $carga->calcular();
        });
    }
}