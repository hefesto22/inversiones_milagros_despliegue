<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReempaqueProducto extends Model
{
    use HasFactory;

    protected $table = 'reempaque_productos';

    protected $fillable = [
        'reempaque_id',
        'producto_id',
        'categoria_id',
        'bodega_id',
        'cantidad',
        'costo_unitario',
        'costo_total',
        'costo_promedio_anterior',
        'stock_anterior',
        'agregado_a_stock',
        'fecha_agregado_stock',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'costo_unitario' => 'decimal:4',
        'costo_total' => 'decimal:4',              // FIX: era decimal:2
        'costo_promedio_anterior' => 'decimal:4',
        'stock_anterior' => 'decimal:3',
        'agregado_a_stock' => 'boolean',
        'fecha_agregado_stock' => 'datetime',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function reempaque(): BelongsTo
    {
        return $this->belongsTo(Reempaque::class, 'reempaque_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeAgregadoAStock($query)
    {
        return $query->where('agregado_a_stock', true);
    }

    public function scopePendienteAgregar($query)
    {
        return $query->where('agregado_a_stock', false);
    }

    // ============================================
    // MÉTODOS DE NEGOCIO
    // ============================================

    /**
     * Agregar este producto al stock de la bodega con costo promedio ponderado
     * Guarda el estado anterior para poder revertir correctamente
     */
    public function agregarAStock(): bool
    {
        if ($this->agregado_a_stock) {
            return false;
        }

        $bodegaProducto = BodegaProducto::firstOrCreate(
            [
                'producto_id' => $this->producto_id,
                'bodega_id' => $this->bodega_id,
            ],
            [
                'stock' => 0,
                'costo_promedio_actual' => 0,
                'stock_minimo' => 0,
                'activo' => true,
            ]
        );

        $stockAnterior = floatval($bodegaProducto->stock);
        $costoAnterior = floatval($bodegaProducto->costo_promedio_actual ?? 0);
        $costoNuevo = floatval($this->costo_unitario ?? 0);

        // Guardar estado anterior para poder revertir
        $this->stock_anterior = $stockAnterior;
        $this->costo_promedio_anterior = $costoAnterior;

        // Si costo es 0, solo agregar stock sin afectar promedio
        if ($costoNuevo <= 0) {
            $bodegaProducto->stock = $stockAnterior + $this->cantidad;
            $bodegaProducto->save();

            $this->agregado_a_stock = true;
            $this->fecha_agregado_stock = now();
            $this->save();

            return true;
        }

        // Calcular nuevo promedio ponderado
        $valorAnterior = $stockAnterior * $costoAnterior;
        $valorNuevo = $this->cantidad * $costoNuevo;
        $stockTotal = $stockAnterior + $this->cantidad;
        $valorTotal = $valorAnterior + $valorNuevo;

        $nuevoCostoPromedio = $costoAnterior > 0
            ? ($stockTotal > 0 ? $valorTotal / $stockTotal : $costoNuevo)
            : $costoNuevo;

        $bodegaProducto->stock = $stockTotal;
        // FIX: 4 decimales para mantener precisión (BD ya es decimal(12,4))
        $bodegaProducto->costo_promedio_actual = round($nuevoCostoPromedio, 4);
        $bodegaProducto->actualizarPrecioVentaSegunCosto();
        $bodegaProducto->save();

        $this->agregado_a_stock = true;
        $this->fecha_agregado_stock = now();
        $this->save();

        return true;
    }

    /**
     * Revertir la adición al stock restaurando el estado anterior
     */
    public function revertirStock(): bool
    {
        if (!$this->agregado_a_stock) {
            return false;
        }

        $bodegaProducto = BodegaProducto::where('producto_id', $this->producto_id)
            ->where('bodega_id', $this->bodega_id)
            ->first();

        if (!$bodegaProducto) {
            return false;
        }

        // Restaurar estado anterior si tenemos los datos
        if (!is_null($this->stock_anterior) && !is_null($this->costo_promedio_anterior)) {
            $bodegaProducto->stock = $this->stock_anterior;
            $bodegaProducto->costo_promedio_actual = $this->costo_promedio_anterior;
            $bodegaProducto->actualizarPrecioVentaSegunCosto();
        } else {
            // Fallback: solo reducir stock
            $bodegaProducto->stock = max(0, $bodegaProducto->stock - $this->cantidad);
        }

        $bodegaProducto->save();

        $this->agregado_a_stock = false;
        $this->fecha_agregado_stock = null;
        $this->save();

        return true;
    }

    /**
     * Verificar si ya fue agregado al stock
     */
    public function yaFueAgregado(): bool
    {
        return $this->agregado_a_stock;
    }

    /**
     * Obtener el margen de ganancia si se vende a precio sugerido
     */
    public function getMargenGanancia(): float
    {
        $bodegaProducto = BodegaProducto::where('producto_id', $this->producto_id)
            ->where('bodega_id', $this->bodega_id)
            ->first();

        if (!$bodegaProducto || !$bodegaProducto->precio_venta_sugerido) {
            return 0;
        }

        return floatval($bodegaProducto->precio_venta_sugerido) - floatval($this->costo_unitario);
    }

    /**
     * Obtener información del costo vs precio de venta
     */
    public function getAnalisisPrecio(): array
    {
        $bodegaProducto = BodegaProducto::where('producto_id', $this->producto_id)
            ->where('bodega_id', $this->bodega_id)
            ->first();

        $precioVenta = floatval($bodegaProducto->precio_venta_sugerido ?? 0);
        $margen = $this->getMargenGanancia();
        $costoUnitario = floatval($this->costo_unitario);
        $porcentajeMargen = $costoUnitario > 0
            ? ($margen / $costoUnitario) * 100
            : 0;

        return [
            'producto' => $this->producto->nombre ?? 'N/A',
            'cantidad_generada' => $this->cantidad,
            'costo_unitario' => $costoUnitario,
            'costo_total' => floatval($this->costo_total),
            'precio_venta_sugerido' => $precioVenta,
            'margen_unitario' => $margen,
            'margen_porcentaje' => round($porcentajeMargen, 2),
            'ganancia_potencial_total' => $margen * floatval($this->cantidad),
            'agregado_a_stock' => $this->agregado_a_stock,
            'fecha_agregado' => $this->fecha_agregado_stock?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Obtener el costo unitario real incluyendo merma
     */
    public function getCostoConMerma(): float
    {
        return floatval($this->costo_unitario);
    }

    /**
     * Verificar si el producto tiene precio de venta configurado
     */
    public function tienePrecioVenta(): bool
    {
        $bodegaProducto = BodegaProducto::where('producto_id', $this->producto_id)
            ->where('bodega_id', $this->bodega_id)
            ->first();

        return $bodegaProducto && (floatval($bodegaProducto->precio_venta_sugerido ?? 0)) > 0;
    }

    // ============================================
    // EVENTOS (Boot)
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($reempaqueProducto) {
            $cantidad = floatval($reempaqueProducto->cantidad);
            $costoUnitario = floatval($reempaqueProducto->costo_unitario);

            if (is_null($reempaqueProducto->costo_total)) {
                $reempaqueProducto->costo_total = $cantidad * $costoUnitario;
            }

            $calculado = $cantidad * $costoUnitario;

            if (abs(floatval($reempaqueProducto->costo_total) - $calculado) > 0.001) {
                $reempaqueProducto->costo_total = $calculado;
            }
        });
    }
}