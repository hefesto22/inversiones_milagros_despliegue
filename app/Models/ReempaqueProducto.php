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
        'categoria_id',  // 🆕 NUEVO
        'bodega_id',
        'cantidad',
        'costo_unitario',
        'costo_total',
        'agregado_a_stock',
        'fecha_agregado_stock',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'costo_unitario' => 'decimal:4',
        'costo_total' => 'decimal:2',
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
    // 🆕 NUEVA RELACIÓN
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
     * 🎯 REDONDEO HACIA ARRIBA para evitar pérdidas
     * ⚠️ SI EL COSTO ES 0, solo suma stock pero NO afecta el promedio
     */
    public function agregarAStock(): bool
    {
        if ($this->agregado_a_stock) {
            return false; // Ya fue agregado
        }

        // Buscar o crear el registro en bodega_producto
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

        $stockAnterior = $bodegaProducto->stock;
        $costoAnterior = $bodegaProducto->costo_promedio_actual ?? 0;
        $costoNuevo = $this->costo_unitario ?? 0;

        // 🎯 SI EL COSTO NUEVO ES 0, solo agregar stock sin afectar el promedio
        if ($costoNuevo <= 0) {
            $bodegaProducto->stock = $stockAnterior + $this->cantidad;
            // costo_promedio_actual NO cambia
            // precio_venta_sugerido NO cambia
            $bodegaProducto->save();

            // Marcar como agregado
            $this->agregado_a_stock = true;
            $this->fecha_agregado_stock = now();
            $this->save();

            return true;
        }

        // Valor del stock anterior
        $valorStockAnterior = $stockAnterior * $costoAnterior;

        // Valor de la nueva entrada (del reempaque)
        $valorNuevaEntrada = $this->cantidad * $costoNuevo;

        // Stock total después de agregar
        $stockTotal = $stockAnterior + $this->cantidad;

        // Valor total
        $valorTotal = $valorStockAnterior + $valorNuevaEntrada;

        // Nuevo costo promedio ponderado
        if ($costoAnterior > 0) {
            // Hay costo anterior, calcular promedio ponderado normal
            $nuevoCostoPromedio = $stockTotal > 0 ? $valorTotal / $stockTotal : $costoNuevo;
        } else {
            // No había costo anterior, usar el nuevo costo
            $nuevoCostoPromedio = $costoNuevo;
        }

        // 🎯 REDONDEAR COSTO HACIA ARRIBA (evita pérdidas)
        $nuevoCostoPromedio = (int) ceil($nuevoCostoPromedio);

        // Actualizar stock y costo promedio
        $bodegaProducto->stock = $stockTotal;
        $bodegaProducto->costo_promedio_actual = $nuevoCostoPromedio;

        // Calcular precio de venta sugerido (costo + margen)
        $margen = $bodegaProducto->producto->margen_ganancia ?? 5; // Default L5
        $tipoMargen = $bodegaProducto->producto->tipo_margen ?? 'monto';

        if ($tipoMargen === 'porcentaje') {
            $precioVentaCalculado = $nuevoCostoPromedio * (1 + $margen / 100);
        } else {
            $precioVentaCalculado = $nuevoCostoPromedio + $margen;
        }

        // 🎯 REDONDEAR PRECIO HACIA ARRIBA (nunca pierdes)
        $bodegaProducto->precio_venta_sugerido = (int) ceil($precioVentaCalculado);

        $bodegaProducto->save();

        // Marcar como agregado
        $this->agregado_a_stock = true;
        $this->fecha_agregado_stock = now();
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
     * Revertir la adición al stock (para cancelaciones)
     * ⚠️ NOTA: Esto reduce stock pero NO recalcula el costo promedio
     */
    public function revertirStock(): bool
    {
        if (!$this->agregado_a_stock) {
            return false; // No hay nada que revertir
        }

        $bodegaProducto = BodegaProducto::where('producto_id', $this->producto_id)
            ->where('bodega_id', $this->bodega_id)
            ->first();

        if (!$bodegaProducto) {
            return false; // No existe el registro
        }

        // Reducir el stock
        $bodegaProducto->stock -= $this->cantidad;

        // Si el stock queda en 0 o negativo, ajustar
        if ($bodegaProducto->stock < 0) {
            $bodegaProducto->stock = 0;
        }

        $bodegaProducto->save();

        // Marcar como no agregado
        $this->agregado_a_stock = false;
        $this->fecha_agregado_stock = null;
        $this->save();

        return true;
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

        return $bodegaProducto->precio_venta_sugerido - $this->costo_unitario;
    }

    /**
     * Obtener información del costo vs precio de venta
     */
    public function getAnalisisPrecio(): array
    {
        $bodegaProducto = BodegaProducto::where('producto_id', $this->producto_id)
            ->where('bodega_id', $this->bodega_id)
            ->first();

        $precioVenta = $bodegaProducto->precio_venta_sugerido ?? 0;
        $margen = $this->getMargenGanancia();
        $porcentajeMargen = $this->costo_unitario > 0
            ? ($margen / $this->costo_unitario) * 100
            : 0;

        return [
            'producto' => $this->producto->nombre ?? 'N/A',
            'cantidad_generada' => $this->cantidad,
            'costo_unitario' => $this->costo_unitario,
            'costo_total' => $this->costo_total,
            'precio_venta_sugerido' => $precioVenta,
            'margen_unitario' => $margen,
            'margen_porcentaje' => round($porcentajeMargen, 2),
            'ganancia_potencial_total' => $margen * $this->cantidad,
            'agregado_a_stock' => $this->agregado_a_stock,
            'fecha_agregado' => $this->fecha_agregado_stock?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Obtener el costo unitario real incluyendo merma
     */
    public function getCostoConMerma(): float
    {
        $reempaque = $this->reempaque;

        if (!$reempaque) {
            return $this->costo_unitario;
        }

        // El costo_unitario ya incluye la merma porque se calcula sobre huevos_utiles
        return $this->costo_unitario;
    }

    /**
     * Verificar si el producto tiene precio de venta configurado
     */
    public function tienePrecioVenta(): bool
    {
        $bodegaProducto = BodegaProducto::where('producto_id', $this->producto_id)
            ->where('bodega_id', $this->bodega_id)
            ->first();

        return $bodegaProducto && ($bodegaProducto->precio_venta_sugerido ?? 0) > 0;
    }

    // ============================================
    // EVENTOS (Boot)
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($reempaqueProducto) {
            // Calcular costo_total automáticamente
            if (is_null($reempaqueProducto->costo_total)) {
                $reempaqueProducto->costo_total =
                    $reempaqueProducto->cantidad * $reempaqueProducto->costo_unitario;
            }

            // Validar que costo_total coincida
            $calculado = $reempaqueProducto->cantidad * $reempaqueProducto->costo_unitario;

            if (abs($reempaqueProducto->costo_total - $calculado) > 0.01) {
                $reempaqueProducto->costo_total = $calculado;
            }
        });
    }
}
