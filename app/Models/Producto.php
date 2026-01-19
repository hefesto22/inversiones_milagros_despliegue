<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use SoftDeletes;

    protected $table = 'productos';

    // Tasa de ISV en Honduras (15%)
    public const ISV_RATE = 0.15;

    // Margen mínimo de seguridad por defecto (3%)
    public const MARGEN_MINIMO_DEFAULT = 3.00;

    protected $fillable = [
        'nombre',
        'sku',
        'categoria_id',
        'unidad_id',
        'precio_sugerido',
        'descripcion',
        'activo',
        'created_by',
        'updated_by',
        'margen_ganancia',
        'tipo_margen',
        'aplica_isv',
        'precio_venta_maximo',       // 🆕 PRECIO MÁXIMO DE VENTA
        'margen_minimo_seguridad',   // 🆕 MARGEN MÍNIMO DE SEGURIDAD
        'formato_empaque',
        'unidades_por_bulto',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'aplica_isv' => 'boolean',
        'unidades_por_bulto' => 'integer',
        'precio_venta_maximo' => 'decimal:2',
        'margen_minimo_seguridad' => 'decimal:2',
    ];

    // =======================
    // RELACIONES
    // =======================

    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function unidad()
    {
        return $this->belongsTo(Unidad::class, 'unidad_id');
    }

    public function imagenes()
    {
        return $this->hasMany(ProductoImagen::class, 'producto_id');
    }

    public function bodegas()
    {
        return $this->belongsToMany(Bodega::class, 'bodega_producto', 'producto_id', 'bodega_id')
            ->withPivot([
                'stock',
                'stock_minimo',
                'activo',
                'precio_compra_semana_actual',
                'cantidad_comprada_semana',
                'fecha_inicio_semana',
                'precio_venta_calculado',
            ])
            ->withTimestamps();
    }

    public function bodegaProductos()
    {
        return $this->hasMany(BodegaProducto::class, 'producto_id');
    }

    public function compraDetalles()
    {
        return $this->hasMany(CompraDetalle::class, 'producto_id');
    }

    public function creador()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function actualizador()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function lotes()
    {
        return $this->hasMany(Lote::class, 'producto_id');
    }

    public function reempaqueProductos()
    {
        return $this->hasMany(ReempaqueProducto::class, 'producto_id');
    }

    // =======================
    // 🆕 MÉTODOS DE PRECIO MÁXIMO
    // =======================

    /**
     * Verificar si el producto tiene precio máximo configurado
     */
    public function tienePrecioMaximo(): bool
    {
        return !is_null($this->precio_venta_maximo) && $this->precio_venta_maximo > 0;
    }

    /**
     * Obtener el margen mínimo de seguridad
     */
    public function getMargenMinimoSeguridad(): float
    {
        return $this->margen_minimo_seguridad ?? self::MARGEN_MINIMO_DEFAULT;
    }

    /**
     * Calcular precio de venta respetando el tope máximo
     * 
     * Lógica:
     * 1. Si costo >= precio_maximo → Usa costo + margen_minimo (protege contra pérdidas)
     * 2. Si precio_calculado > precio_maximo → Usa precio_maximo (mantiene competitividad)
     * 3. Si precio_calculado <= precio_maximo → Usa precio_calculado (precio normal con margen)
     * 
     * @param float $costo Costo actual del producto
     * @param float $precioCalculado Precio calculado con el margen normal
     * @return array ['precio' => float, 'razon' => string, 'alerta' => bool, 'mensaje' => string|null]
     */
    public function calcularPrecioConTope(float $costo, float $precioCalculado): array
    {
        // Si no hay precio máximo configurado, usar el calculado
        if (!$this->tienePrecioMaximo()) {
            return [
                'precio' => $precioCalculado,
                'razon' => 'normal',
                'alerta' => false,
                'mensaje' => null,
            ];
        }

        $precioMaximo = (float) $this->precio_venta_maximo;
        $margenMinimo = $this->getMargenMinimoSeguridad();

        // Caso 1: El costo es igual o mayor al precio máximo → Proteger contra pérdidas
        if ($costo >= $precioMaximo) {
            $precioConMargenMinimo = $costo * (1 + ($margenMinimo / 100));
            
            return [
                'precio' => round($precioConMargenMinimo, 2),
                'razon' => 'margen_minimo',
                'alerta' => true,
                'mensaje' => "⚠️ Costo (L" . number_format($costo, 2) . ") supera o iguala precio máximo (L" . number_format($precioMaximo, 2) . "). Se aplica margen mínimo {$margenMinimo}%.",
            ];
        }

        // Caso 2: El precio calculado supera el máximo → Usar el máximo como tope
        if ($precioCalculado > $precioMaximo) {
            return [
                'precio' => $precioMaximo,
                'razon' => 'tope_maximo',
                'alerta' => false,
                'mensaje' => null,
            ];
        }

        // Caso 3: El precio calculado es menor o igual al máximo → Usar precio calculado normal
        return [
            'precio' => $precioCalculado,
            'razon' => 'normal',
            'alerta' => false,
            'mensaje' => null,
        ];
    }

    // =======================
    // MÉTODOS DE ISV
    // =======================

    /**
     * Calcular precio con ISV
     * @param float $precioBase Precio sin ISV
     * @return float Precio con ISV
     */
    public function calcularPrecioConIsv(float $precioBase): float
    {
        if (!$this->aplica_isv) {
            return $precioBase;
        }

        return $precioBase * (1 + self::ISV_RATE);
    }

    /**
     * Obtener el monto del ISV para un precio dado
     * @param float $precioBase Precio sin ISV
     * @return float Monto del ISV
     */
    public function calcularMontoIsv(float $precioBase): float
    {
        if (!$this->aplica_isv) {
            return 0;
        }

        return $precioBase * self::ISV_RATE;
    }

    /**
     * Verificar si el producto aplica ISV
     */
    public function tieneIsv(): bool
    {
        return $this->aplica_isv ?? true;
    }

    // =======================
    // MÉTODOS DE FORMATO DE EMPAQUE
    // =======================

    /**
     * Verificar si el producto tiene formato de empaque configurado
     */
    public function tieneFormatoEmpaque(): bool
    {
        return !empty($this->formato_empaque) && !empty($this->unidades_por_bulto) && $this->unidades_por_bulto > 0;
    }

    /**
     * Calcular equivalencia en bultos/cajas a partir de unidades
     * 
     * @param float $cantidadUnidades Cantidad en unidades base (paquetes, bolsas, etc.)
     * @return array ['bultos' => int, 'sueltos' => float, 'texto' => string]
     */
    public function calcularEquivalenciaBultos(float $cantidadUnidades): array
    {
        if (!$this->tieneFormatoEmpaque()) {
            return [
                'bultos' => 0,
                'sueltos' => $cantidadUnidades,
                'texto' => null,
                'tiene_formato' => false,
            ];
        }

        $unidadesPorBulto = $this->unidades_por_bulto;
        $categoriaNombre = strtolower($this->categoria->nombre ?? '');
        
        // Para huevos: mostrar total de huevos individuales
        if (str_contains($categoriaNombre, 'huevo')) {
            $totalHuevos = $cantidadUnidades * $unidadesPorBulto;
            
            return [
                'bultos' => (int) $cantidadUnidades,
                'sueltos' => 0,
                'texto' => number_format($totalHuevos, 0) . " huevos",
                'tiene_formato' => true,
                'unidades_por_bulto' => $unidadesPorBulto,
                'total_unidades' => $totalHuevos,
            ];
        }
        
        // Para otros productos: calcular equivalencia en cajas
        $bultos = floor($cantidadUnidades / $unidadesPorBulto);
        $sueltos = fmod($cantidadUnidades, $unidadesPorBulto);

        $sueltos = round($sueltos, 3);

        $unidadNombre = $this->unidad->nombre ?? 'unidades';
        
        // Determinar nombre del empaque según la categoría
        $nombreEmpaque = $this->getNombreEmpaque();
        $nombreEmpaquePlural = $this->getNombreEmpaquePlural();

        if ($bultos > 0 && $sueltos > 0) {
            $texto = "{$bultos} " . ($bultos == 1 ? $nombreEmpaque : $nombreEmpaquePlural) . " + " . number_format($sueltos, 0) . " {$unidadNombre}";
        } elseif ($bultos > 0) {
            $texto = "{$bultos} " . ($bultos == 1 ? $nombreEmpaque : $nombreEmpaquePlural) . " ✓";
        } else {
            $texto = number_format($sueltos, 0) . " {$unidadNombre}";
        }

        return [
            'bultos' => (int) $bultos,
            'sueltos' => $sueltos,
            'texto' => $texto,
            'tiene_formato' => true,
            'unidades_por_bulto' => $unidadesPorBulto,
        ];
    }

    /**
     * Obtener nombre del empaque en singular según la categoría
     */
    public function getNombreEmpaque(): string
    {
        $categoriaNombre = strtolower($this->categoria->nombre ?? '');
        
        // Si es categoría de huevos, usar "cartón"
        if (str_contains($categoriaNombre, 'huevo')) {
            return 'cartón';
        }
        
        // Por defecto usar "caja"
        return 'caja';
    }

    /**
     * Obtener nombre del empaque en plural según la categoría
     */
    public function getNombreEmpaquePlural(): string
    {
        $categoriaNombre = strtolower($this->categoria->nombre ?? '');
        
        // Si es categoría de huevos, usar "cartones"
        if (str_contains($categoriaNombre, 'huevo')) {
            return 'cartones';
        }
        
        // Por defecto usar "cajas"
        return 'cajas';
    }

    /**
     * Calcular unidades totales a partir de bultos
     */
    public function bultosAUnidades(int $cantidadBultos): float
    {
        if (!$this->tieneFormatoEmpaque()) {
            return $cantidadBultos;
        }

        return $cantidadBultos * $this->unidades_por_bulto;
    }

    /**
     * Obtener texto descriptivo del formato de empaque
     */
    public function getDescripcionFormato(): ?string
    {
        if (!$this->tieneFormatoEmpaque()) {
            return null;
        }

        $unidadNombre = $this->unidad->nombre ?? 'unidades';
        $nombreEmpaque = $this->getNombreEmpaque();
        
        return "1 {$nombreEmpaque} = {$this->unidades_por_bulto} {$unidadNombre}";
    }

    /**
     * Obtener nombre completo del producto con formato
     */
    public function getNombreConFormato(): string
    {
        if ($this->tieneFormatoEmpaque()) {
            return "{$this->nombre} [{$this->formato_empaque}]";
        }

        return $this->nombre;
    }
}