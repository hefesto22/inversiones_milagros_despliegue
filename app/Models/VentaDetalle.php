<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaDetalle extends Model
{
    use HasFactory;

    protected $table = 'venta_detalles';

    // Tasa de ISV
    public const ISV_RATE = 0.15;

    protected $fillable = [
        'venta_id',
        'producto_id',
        'unidad_id',
        'cantidad',
        'precio_unitario',
        'precio_con_isv',
        'costo_unitario',
        'aplica_isv',
        'isv_unitario',
        'descuento_porcentaje',
        'descuento_monto',
        'subtotal',
        'total_isv',
        'total_linea',
        'precio_anterior',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'precio_con_isv' => 'decimal:2',
        'costo_unitario' => 'decimal:2',
        'aplica_isv' => 'boolean',
        'isv_unitario' => 'decimal:2',
        'descuento_porcentaje' => 'decimal:2',
        'descuento_monto' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total_isv' => 'decimal:2',
        'total_linea' => 'decimal:2',
        'precio_anterior' => 'decimal:2',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
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
    // MÉTODOS DE CÁLCULO
    // ============================================

    /**
     * Calcular todos los valores de la línea
     */
    public function calcular(): void
    {
        // Subtotal sin ISV
        $this->subtotal = $this->cantidad * $this->precio_unitario;

        // Calcular ISV si aplica
        if ($this->aplica_isv) {
            $this->isv_unitario = round($this->precio_unitario * self::ISV_RATE, 2);
            $this->precio_con_isv = $this->precio_unitario + $this->isv_unitario;
            $this->total_isv = $this->cantidad * $this->isv_unitario;
        } else {
            $this->isv_unitario = 0;
            $this->precio_con_isv = $this->precio_unitario;
            $this->total_isv = 0;
        }

        // Calcular descuento
        if ($this->descuento_porcentaje > 0) {
            $this->descuento_monto = round(($this->subtotal + $this->total_isv) * ($this->descuento_porcentaje / 100), 2);
        }

        // Total de línea
        $this->total_linea = $this->subtotal + $this->total_isv - $this->descuento_monto;
    }

    /**
     * Calcular ganancia de esta línea
     */
    public function calcularGanancia(): float
    {
        $costoTotal = $this->cantidad * $this->costo_unitario;
        return $this->subtotal - $costoTotal;
    }

    /**
     * Obtener margen de ganancia en porcentaje
     */
    public function getMargenPorcentaje(): float
    {
        if ($this->costo_unitario <= 0) {
            return 100;
        }

        return (($this->precio_unitario - $this->costo_unitario) / $this->costo_unitario) * 100;
    }

    // ============================================
    // MÉTODOS ESTÁTICOS
    // ============================================

    /**
     * Crear detalle desde producto y cliente
     */
    public static function crearDesdeProducto(
        Venta $venta,
        Producto $producto,
        float $cantidad,
        ?float $precioOverride = null,
        ?float $descuentoPorcentaje = null
    ): self {
        // Obtener precio de bodega_producto
        $bodegaProducto = BodegaProducto::where('bodega_id', $venta->bodega_id)
            ->where('producto_id', $producto->id)
            ->first();

        $precioSugerido = $bodegaProducto?->precio_venta_sugerido ?? 0;
        $costoActual = $bodegaProducto?->costo_promedio_actual ?? 0;

        // Usar precio override o el sugerido
        $precioUnitario = $precioOverride ?? $precioSugerido;

        // Obtener último precio del cliente (para mostrar referencia)
        $precioAnterior = null;
        $clienteProducto = $venta->cliente->productos()
            ->where('producto_id', $producto->id)
            ->first();

        if ($clienteProducto) {
            $precioAnterior = $clienteProducto->pivot->ultimo_precio_venta;
        }

        $detalle = new self([
            'venta_id' => $venta->id,
            'producto_id' => $producto->id,
            'unidad_id' => $producto->unidad_id,
            'cantidad' => $cantidad,
            'precio_unitario' => $precioUnitario,
            'costo_unitario' => $costoActual,
            'aplica_isv' => $producto->aplica_isv ?? true,
            'descuento_porcentaje' => $descuentoPorcentaje ?? 0,
            'descuento_monto' => 0,
            'precio_anterior' => $precioAnterior,
        ]);

        $detalle->calcular();

        return $detalle;
    }

    // ============================================
    // HELPERS
    // ============================================

    /**
     * Verificar si el precio cambió respecto al anterior
     */
    public function precioCambio(): bool
    {
        if (!$this->precio_anterior) {
            return false;
        }

        return abs($this->precio_unitario - $this->precio_anterior) > 0.01;
    }

    /**
     * Obtener diferencia de precio
     */
    public function getDiferenciaPrecio(): float
    {
        if (!$this->precio_anterior) {
            return 0;
        }

        return $this->precio_unitario - $this->precio_anterior;
    }

    /**
     * Obtener diferencia de precio en porcentaje
     */
    public function getDiferenciaPorcentaje(): float
    {
        if (!$this->precio_anterior || $this->precio_anterior == 0) {
            return 0;
        }

        return (($this->precio_unitario - $this->precio_anterior) / $this->precio_anterior) * 100;
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($detalle) {
            $detalle->calcular();
        });

        static::saved(function ($detalle) {
            if ($detalle->venta) {
                $detalle->venta->recalcularTotales();
            }
        });

        static::deleted(function ($detalle) {
            if ($detalle->venta) {
                $detalle->venta->recalcularTotales();
            }
        });
    }
}
