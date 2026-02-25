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
        'costo_promedio_actual' => 'decimal:4',   // FIX: era decimal:2, BD ya es decimal(12,4)
        'precio_venta_sugerido' => 'decimal:4',   // FIX: era decimal:2, BD ya es decimal(12,4)
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
    // METODOS DE VALIDACION
    // =======================

    public function tieneSuficienteStock(float $cantidadRequerida): bool
    {
        return $this->getStockDisponible() >= $cantidadRequerida;
    }

    public function validarReduccion(float $cantidad): void
    {
        if ($cantidad <= 0) {
            throw new \InvalidArgumentException("La cantidad a reducir debe ser mayor a 0");
        }

        if (!$this->tieneSuficienteStock($cantidad)) {
            $disponible = $this->getStockDisponible();
            $producto = $this->producto->nombre ?? 'Producto';
            throw new \Exception(
                "Stock insuficiente de {$producto}. " .
                "Disponible: {$disponible}, Requerido: {$cantidad}"
            );
        }
    }

    // =======================
    // METODOS DE STOCK
    // =======================

    public function getStockDisponible(): float
    {
        return max(0, $this->stock - $this->stock_reservado);
    }

    public function reservarStock(float $cantidad): bool
    {
        if ($this->getStockDisponible() < $cantidad) {
            return false;
        }

        $this->stock_reservado += $cantidad;
        $this->save();

        return true;
    }

    public function liberarReserva(float $cantidad): void
    {
        $this->stock_reservado = max(0, $this->stock_reservado - $cantidad);
        $this->save();
    }

    public function entregarReservado(float $cantidad): void
    {
        $this->stock = max(0, $this->stock - $cantidad);
        $this->stock_reservado = max(0, $this->stock_reservado - $cantidad);
        $this->save();
    }

    // =======================
    // METODOS DE COSTO PROMEDIO CONTINUO (DIARIO)
    // =======================

    /**
     * Actualizar costo promedio con nueva entrada (Weighted Average Cost)
     */
    public function actualizarCostoPromedio(float $cantidadNueva, float $costoUnitarioNuevo): void
    {
        if ($cantidadNueva < 0) {
            throw new \InvalidArgumentException("La cantidad no puede ser negativa");
        }

        if ($cantidadNueva == 0) {
            return;
        }

        $stockAnterior = floatval($this->stock ?? 0);
        $costoAnterior = floatval($this->costo_promedio_actual ?? 0);

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

        // FIX: 4 decimales para mantener precisión (BD ya es decimal(12,4))
        $nuevoCostoPromedio = round($nuevoCostoPromedio, 4);

        $this->stock = $stockTotal;
        $this->costo_promedio_actual = $nuevoCostoPromedio;

        $this->actualizarPrecioVentaSegunCosto();

        $this->save();
    }

    /**
     * Actualizar precio de venta basado en costo_promedio_actual
     */
    public function actualizarPrecioVentaSegunCosto(): void
    {
        $costoBase = floatval($this->costo_promedio_actual ?? 0);

        if ($costoBase <= 0) {
            return;
        }

        $producto = $this->producto;
        
        if (!$producto) {
            return;
        }

        $margen = $producto->margen_ganancia ?? 5;
        $tipoMargen = $producto->tipo_margen ?? 'monto';

        $precioCalculado = match ($tipoMargen) {
            'porcentaje' => $costoBase * (1 + ($margen / 100)),
            'monto' => $costoBase + $margen,
            default => $costoBase + 5,
        };

        if ($producto->tienePrecioMaximo()) {
            $resultado = $producto->calcularPrecioConTope($costoBase, $precioCalculado);
            $precioFinal = $resultado['precio'];
        } else {
            $precioFinal = $precioCalculado;
        }

        $this->precio_venta_sugerido = round($precioFinal, 2);
    }

    /**
     * Reducir stock con validacion
     */
    public function reducirStock(float $cantidad, bool $forzar = false): void
    {
        if ($cantidad <= 0) {
            return;
        }

        if (!$forzar) {
            $this->validarReduccion($cantidad);
        }

        $this->stock = max(0, $this->stock - $cantidad);
        $this->save();
    }

    public function agregarStockSinCosto(float $cantidad): void
    {
        if ($cantidad <= 0) {
            return;
        }

        $this->stock += $cantidad;
        $this->save();
    }

    public function getAnalisisCostos(): array
    {
        $costoPromedio = floatval($this->costo_promedio_actual ?? 0);
        $precioVenta = floatval($this->precio_venta_sugerido ?? 0);
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
            'tiene_precio_maximo' => $tienePrecioMaximo,
            'precio_maximo' => $precioMaximo,
            'alerta_precio_maximo' => $alertaPrecio,
        ];
    }

    public function tieneStock(float $cantidadRequerida = 0): bool
    {
        return $this->getStockDisponible() >= $cantidadRequerida;
    }

    public function bajoDeMinimoStock(): bool
    {
        if (!$this->stock_minimo) {
            return false;
        }

        return $this->stock <= $this->stock_minimo;
    }

    public function getValorInventario(): float
    {
        return $this->stock * floatval($this->costo_promedio_actual ?? 0);
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
            $bodegaProducto->stock = max(0, $bodegaProducto->stock ?? 0);
            $bodegaProducto->stock_reservado = max(0, $bodegaProducto->stock_reservado ?? 0);
        });
    }
}