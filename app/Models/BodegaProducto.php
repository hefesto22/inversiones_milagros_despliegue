<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BodegaProducto extends Model
{
    protected $table = 'bodega_producto';

    protected $fillable = [
        'bodega_id',
        'producto_id',
        'stock',
        'stock_reservado',
        'stock_minimo',
        'costo_promedio_actual',
        'precio_venta_sugerido',
        'activo',
    ];

    protected $casts = [
        'stock' => 'decimal:3',
        'stock_reservado' => 'decimal:3',
        'stock_minimo' => 'decimal:3',
        'costo_promedio_actual' => 'decimal:2',
        'precio_venta_sugerido' => 'decimal:2',
        'activo' => 'boolean',
    ];

    // =======================
    // RELACIONES
    // =======================

    public function bodega()
    {
        return $this->belongsTo(Bodega::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    // =======================
    // MÉTODOS DE STOCK
    // =======================

    /**
     * Obtener stock disponible (total - reservado)
     */
    public function getStockDisponible(): float
    {
        return max(0, $this->stock - $this->stock_reservado);
    }

    /**
     * Reservar stock (apartado para cliente que pagó pero no se llevó)
     */
    public function reservarStock(float $cantidad): bool
    {
        if ($this->getStockDisponible() < $cantidad) {
            return false;
        }

        $this->stock_reservado += $cantidad;
        $this->save();

        return true;
    }

    /**
     * Liberar stock reservado (cancelación o entrega)
     */
    public function liberarReserva(float $cantidad): void
    {
        $this->stock_reservado -= $cantidad;
        if ($this->stock_reservado < 0) {
            $this->stock_reservado = 0;
        }
        $this->save();
    }

    /**
     * Entregar producto reservado (reducir stock y reserva)
     */
    public function entregarReservado(float $cantidad): void
    {
        $this->stock -= $cantidad;
        $this->stock_reservado -= $cantidad;

        if ($this->stock < 0) $this->stock = 0;
        if ($this->stock_reservado < 0) $this->stock_reservado = 0;

        $this->save();
    }

    // =======================
    // MÉTODOS DE COSTO PROMEDIO CONTINUO (DIARIO)
    // =======================

    /**
     * Actualizar costo promedio con nueva entrada (Weighted Average Cost)
     */
    public function actualizarCostoPromedio(float $cantidadNueva, float $costoUnitarioNuevo): void
    {
        $stockAnterior = $this->stock ?? 0;
        $costoAnterior = $this->costo_promedio_actual ?? 0;

        if ($costoUnitarioNuevo <= 0) {
            $this->stock = $stockAnterior + $cantidadNueva;
            $this->save();
            return;
        }

        $valorAnterior = $stockAnterior * $costoAnterior;
        $valorNuevo = $cantidadNueva * $costoUnitarioNuevo;
        $stockTotal = $stockAnterior + $cantidadNueva;
        $valorTotal = $valorAnterior + $valorNuevo;

        if ($costoAnterior > 0) {
            $nuevoCostoPromedio = $stockTotal > 0 ? $valorTotal / $stockTotal : $costoUnitarioNuevo;
        } else {
            $nuevoCostoPromedio = $costoUnitarioNuevo;
        }

        $nuevoCostoPromedio = round($nuevoCostoPromedio, 2);

        $this->stock = $stockTotal;
        $this->costo_promedio_actual = $nuevoCostoPromedio;

        $this->actualizarPrecioVentaSegunCosto();

        $this->save();
    }

    /**
     * Actualizar precio de venta basado en costo_promedio_actual
     * 
     * 🆕 NUEVA LÓGICA CON PRECIO MÁXIMO:
     * 1. Calcula el precio normal (costo + margen)
     * 2. Si hay precio_maximo configurado, aplica el tope
     * 3. Si el costo >= precio_maximo, aplica margen mínimo de seguridad
     */
    public function actualizarPrecioVentaSegunCosto(): void
    {
        $costoBase = $this->costo_promedio_actual ?? 0;

        if ($costoBase <= 0) {
            return;
        }

        $producto = $this->producto;
        
        if (!$producto) {
            return;
        }

        $margen = $producto->margen_ganancia ?? 5;
        $tipoMargen = $producto->tipo_margen ?? 'monto';

        // Paso 1: Calcular precio normal con el margen configurado
        $precioCalculado = match ($tipoMargen) {
            'porcentaje' => $costoBase * (1 + ($margen / 100)),
            'monto' => $costoBase + $margen,
            default => $costoBase + 5,
        };

        // Paso 2: Aplicar lógica de precio máximo si está configurado
        if ($producto->tienePrecioMaximo()) {
            $resultado = $producto->calcularPrecioConTope($costoBase, $precioCalculado);
            $precioFinal = $resultado['precio'];
            
            // Opcional: Log para debugging cuando hay alerta
            // if ($resultado['alerta']) {
            //     \Log::warning("Producto {$producto->id}: {$resultado['mensaje']}");
            // }
        } else {
            $precioFinal = $precioCalculado;
        }

        // Guardar precio final
        $this->precio_venta_sugerido = round($precioFinal, 2);
    }

    /**
     * Reducir stock sin cambiar costo promedio
     */
    public function reducirStock(float $cantidad): void
    {
        $this->stock -= $cantidad;

        if ($this->stock < 0) {
            $this->stock = 0;
        }

        $this->save();
    }

    /**
     * Agregar stock con costo 0 (huevos de regalo, etc.)
     */
    public function agregarStockSinCosto(float $cantidad): void
    {
        $this->stock += $cantidad;
        $this->save();
    }

    /**
     * Obtener información de costos y precios
     * 🆕 Incluye información sobre precio máximo
     */
    public function getAnalisisCostos(): array
    {
        $costoPromedio = $this->costo_promedio_actual ?? 0;
        $precioVenta = $this->precio_venta_sugerido ?? 0;
        $margen = $precioVenta - $costoPromedio;
        $porcentajeMargen = $costoPromedio > 0 ? ($margen / $costoPromedio) * 100 : 0;

        $producto = $this->producto;
        $tienePrecioMaximo = $producto?->tienePrecioMaximo() ?? false;
        $precioMaximo = $producto?->precio_venta_maximo;
        $alertaPrecio = $tienePrecioMaximo && $costoPromedio >= $precioMaximo;

        return [
            'bodega' => $this->bodega->nombre ?? 'N/A',
            'producto' => $this->producto->nombre ?? 'N/A',
            'stock_actual' => $this->stock,
            'stock_reservado' => $this->stock_reservado,
            'stock_disponible' => $this->getStockDisponible(),
            'costo_promedio_actual' => $costoPromedio,
            'precio_venta_sugerido' => $precioVenta,
            'margen_unitario' => $margen,
            'margen_porcentaje' => round($porcentajeMargen, 2),
            'valor_inventario' => $this->stock * $costoPromedio,
            'ganancia_potencial' => $this->stock * $margen,
            'stock_minimo' => $this->stock_minimo,
            'necesita_reabastecimiento' => $this->stock <= ($this->stock_minimo ?? 0),
            // 🆕 Campos de precio máximo
            'tiene_precio_maximo' => $tienePrecioMaximo,
            'precio_maximo' => $precioMaximo,
            'alerta_precio_maximo' => $alertaPrecio,
        ];
    }

    /**
     * Verificar si tiene stock disponible
     */
    public function tieneStock(float $cantidadRequerida = 0): bool
    {
        return $this->getStockDisponible() >= $cantidadRequerida;
    }

    /**
     * Verificar si está por debajo del stock mínimo
     */
    public function bajoDeMinimoStock(): bool
    {
        if (!$this->stock_minimo) {
            return false;
        }

        return $this->stock <= $this->stock_minimo;
    }

    /**
     * Obtener valor total del inventario en esta bodega
     */
    public function getValorInventario(): float
    {
        return $this->stock * ($this->costo_promedio_actual ?? 0);
    }

    // =======================
    // EVENTOS (Boot)
    // =======================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($bodegaProducto) {
            if (is_null($bodegaProducto->stock)) {
                $bodegaProducto->stock = 0;
            }

            if (is_null($bodegaProducto->stock_reservado)) {
                $bodegaProducto->stock_reservado = 0;
            }

            if (is_null($bodegaProducto->costo_promedio_actual)) {
                $bodegaProducto->costo_promedio_actual = 0;
            }

            if (is_null($bodegaProducto->activo)) {
                $bodegaProducto->activo = true;
            }
        });

        static::saving(function ($bodegaProducto) {
            if ($bodegaProducto->stock < 0) {
                $bodegaProducto->stock = 0;
            }

            if ($bodegaProducto->stock_reservado < 0) {
                $bodegaProducto->stock_reservado = 0;
            }
        });
    }
}