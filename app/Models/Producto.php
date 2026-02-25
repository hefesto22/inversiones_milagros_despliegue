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
        'precio_venta_maximo',
        'margen_minimo_seguridad',
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

    /**
     * Reglas de precio por tipo de cliente
     */
    public function preciosTipo()
    {
        return $this->hasMany(ProductoPrecioTipo::class, 'producto_id');
    }

    /**
     * Clientes con historial de compra (pivote)
     */
    public function clienteProductos()
    {
        return $this->hasMany(ClienteProducto::class, 'producto_id');
    }

    // =======================
    // MÉTODOS DE DESCUENTO MÁXIMO POR CLIENTE
    // =======================

    /**
     * Obtener el descuento máximo permitido para un cliente específico.
     *
     * Jerarquía:
     * 1. Override individual en cliente_producto → si existe, usar ese
     * 2. Regla por tipo de cliente en producto_precio_tipo → si existe, usar ese
     * 3. Fallback → null (sin restricción)
     *
     * @param Cliente $cliente
     * @return float|null Descuento máximo en Lempiras, o null si no hay restricción
     */
    public function obtenerDescuentoMaximo(Cliente $cliente): ?float
    {
        // 1. Buscar override individual en cliente_producto
        $clienteProducto = ClienteProducto::where('cliente_id', $cliente->id)
            ->where('producto_id', $this->id)
            ->whereNotNull('descuento_maximo_override')
            ->first();

        if ($clienteProducto) {
            return (float) $clienteProducto->descuento_maximo_override;
        }

        // 2. Buscar regla por tipo de cliente
        $reglaTipo = ProductoPrecioTipo::where('producto_id', $this->id)
            ->where('tipo_cliente', $cliente->tipo)
            ->where('activo', true)
            ->first();

        if ($reglaTipo) {
            // Si tiene precio mínimo fijo, retornar null y dejar que calcularPrecioMinimo lo maneje
            if (!is_null($reglaTipo->precio_minimo_fijo) && $reglaTipo->precio_minimo_fijo > 0) {
                return null; // Se manejará por precio mínimo fijo
            }

            return (float) $reglaTipo->descuento_maximo;
        }

        // 3. Sin restricción
        return null;
    }

    /**
     * Obtener el precio mínimo de venta para un cliente específico.
     *
     * @param Cliente $cliente
     * @param float $precioVenta Precio de venta actual/sugerido
     * @return array ['precio_minimo' => float|null, 'fuente' => string, 'descuento_maximo' => float|null]
     */
    public function obtenerPrecioMinimo(Cliente $cliente, float $precioVenta): array
    {
        // 1. Buscar override individual
        $clienteProducto = ClienteProducto::where('cliente_id', $cliente->id)
            ->where('producto_id', $this->id)
            ->whereNotNull('descuento_maximo_override')
            ->first();

        if ($clienteProducto) {
            $descuento = (float) $clienteProducto->descuento_maximo_override;
            return [
                'precio_minimo' => round($precioVenta - $descuento, 4),
                'fuente' => 'cliente',
                'descuento_maximo' => $descuento,
            ];
        }

        // 2. Buscar regla por tipo de cliente
        $reglaTipo = ProductoPrecioTipo::where('producto_id', $this->id)
            ->where('tipo_cliente', $cliente->tipo)
            ->where('activo', true)
            ->first();

        if ($reglaTipo) {
            // Si tiene precio mínimo fijo, usarlo directamente
            if (!is_null($reglaTipo->precio_minimo_fijo) && $reglaTipo->precio_minimo_fijo > 0) {
                return [
                    'precio_minimo' => (float) $reglaTipo->precio_minimo_fijo,
                    'fuente' => 'tipo_fijo',
                    'descuento_maximo' => round($precioVenta - (float) $reglaTipo->precio_minimo_fijo, 4),
                ];
            }

            $descuento = (float) $reglaTipo->descuento_maximo;
            return [
                'precio_minimo' => round($precioVenta - $descuento, 4),
                'fuente' => 'tipo',
                'descuento_maximo' => $descuento,
            ];
        }

        // 3. Sin restricción
        return [
            'precio_minimo' => null,
            'fuente' => 'ninguna',
            'descuento_maximo' => null,
        ];
    }

    /**
     * Validar si un precio es permitido para un cliente
     *
     * @param Cliente $cliente
     * @param float $precioVenta Precio de venta actual/sugerido (precio base sin descuento)
     * @param float $precioIntentado Precio que se quiere cobrar
     * @return array ['permitido' => bool, 'precio_minimo' => float|null, 'mensaje' => string|null]
     */
    public function validarPrecioCliente(Cliente $cliente, float $precioVenta, float $precioIntentado): array
    {
        $resultado = $this->obtenerPrecioMinimo($cliente, $precioVenta);

        // Sin restricción
        if (is_null($resultado['precio_minimo'])) {
            return [
                'permitido' => true,
                'precio_minimo' => null,
                'mensaje' => null,
            ];
        }

        $precioMinimo = $resultado['precio_minimo'];
        $permitido = $precioIntentado >= $precioMinimo;

        return [
            'permitido' => $permitido,
            'precio_minimo' => $precioMinimo,
            'mensaje' => $permitido
                ? null
                : "El precio mínimo para este cliente es L " . number_format($precioMinimo, 2) . ". Descuento máximo: L " . number_format($resultado['descuento_maximo'], 2),
        ];
    }

    // =======================
    // MÉTODOS DE PRECIO MÁXIMO COMPETITIVO
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
     * Calcular precio de venta usando el precio máximo competitivo
     *
     * LÓGICA:
     * 1. Si costo < precio_maximo → Usar precio_maximo (igualar a la competencia)
     * 2. Si costo >= precio_maximo → Usar costo + margen_minimo% (proteger contra pérdidas)
     */
    public function calcularPrecioConTope(float $costo, float $precioCalculado): array
    {
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

        if ($costo < $precioMaximo) {
            return [
                'precio' => $precioMaximo,
                'razon' => 'precio_competitivo',
                'alerta' => false,
                'mensaje' => null,
            ];
        }

        $precioConMargenMinimo = $costo * (1 + ($margenMinimo / 100));

        return [
            'precio' => round($precioConMargenMinimo, 2),
            'razon' => 'margen_minimo',
            'alerta' => true,
            'mensaje' => "⚠️ Costo (L" . number_format($costo, 2) . ") supera o iguala precio competitivo (L" . number_format($precioMaximo, 2) . "). Se aplica margen mínimo {$margenMinimo}%.",
        ];
    }

    // =======================
    // MÉTODOS DE ISV
    // =======================

    public function calcularPrecioConIsv(float $precioBase): float
    {
        if (!$this->aplica_isv) {
            return $precioBase;
        }

        return $precioBase * (1 + self::ISV_RATE);
    }

    public function calcularMontoIsv(float $precioBase): float
    {
        if (!$this->aplica_isv) {
            return 0;
        }

        return $precioBase * self::ISV_RATE;
    }

    public function tieneIsv(): bool
    {
        return $this->aplica_isv ?? true;
    }

    // =======================
    // MÉTODOS DE FORMATO DE EMPAQUE
    // =======================

    public function tieneFormatoEmpaque(): bool
    {
        return !empty($this->formato_empaque) && !empty($this->unidades_por_bulto) && $this->unidades_por_bulto > 0;
    }

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

        $bultos = floor($cantidadUnidades / $unidadesPorBulto);
        $sueltos = fmod($cantidadUnidades, $unidadesPorBulto);
        $sueltos = round($sueltos, 3);

        $unidadNombre = $this->unidad->nombre ?? 'unidades';
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

    public function getNombreEmpaque(): string
    {
        $categoriaNombre = strtolower($this->categoria->nombre ?? '');

        if (str_contains($categoriaNombre, 'huevo')) {
            return 'cartón';
        }

        return 'caja';
    }

    public function getNombreEmpaquePlural(): string
    {
        $categoriaNombre = strtolower($this->categoria->nombre ?? '');

        if (str_contains($categoriaNombre, 'huevo')) {
            return 'cartones';
        }

        return 'cajas';
    }

    public function bultosAUnidades(int $cantidadBultos): float
    {
        if (!$this->tieneFormatoEmpaque()) {
            return $cantidadBultos;
        }

        return $cantidadBultos * $this->unidades_por_bulto;
    }

    public function getDescripcionFormato(): ?string
    {
        if (!$this->tieneFormatoEmpaque()) {
            return null;
        }

        $unidadNombre = $this->unidad->nombre ?? 'unidades';
        $nombreEmpaque = $this->getNombreEmpaque();

        return "1 {$nombreEmpaque} = {$this->unidades_por_bulto} {$unidadNombre}";
    }

    public function getNombreConFormato(): string
    {
        if ($this->tieneFormatoEmpaque()) {
            return "{$this->nombre} [{$this->formato_empaque}]";
        }

        return $this->nombre;
    }
}