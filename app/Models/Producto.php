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
        'aplica_isv', // 🆕 NUEVO CAMPO
    ];

    protected $casts = [
        'activo' => 'boolean',
        'aplica_isv' => 'boolean', // 🆕 CAST
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
    // 🆕 MÉTODOS DE ISV
    // =======================

    /**
     * Calcular precio con ISV
     * @param float $precioBase Precio sin ISV
     * @return int Precio con ISV redondeado hacia arriba
     */
    public function calcularPrecioConIsv(float $precioBase): int
    {
        if (!$this->aplica_isv) {
            return (int) ceil($precioBase);
        }

        return (int) ceil($precioBase * (1 + self::ISV_RATE));
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
}
