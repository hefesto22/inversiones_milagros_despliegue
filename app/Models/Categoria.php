<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    protected $table = 'categorias';

    protected $fillable = [
        'nombre',
        'aplica_isv',
        'activo',
        'categoria_origen_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'aplica_isv' => 'boolean',
        'activo' => 'boolean',
    ];

    // =======================
    // RELACIONES
    // =======================

    /**
     * Categoria origen (para productos reempacados)
     * Ej: "Opoa Huevo Grande" tiene origen "Huevo Grande"
     */
    public function categoriaOrigen()
    {
        return $this->belongsTo(Categoria::class, 'categoria_origen_id');
    }

    /**
     * Categorias derivadas (las que usan esta como origen)
     * Ej: "Huevo Grande" tiene derivadas ["Opoa Huevo Grande"]
     */
    public function categoriasDerivadas()
    {
        return $this->hasMany(Categoria::class, 'categoria_origen_id');
    }

    /**
     * Productos asociados a la categoria
     */
    public function productos()
    {
        return $this->hasMany(Producto::class);
    }

    /**
     * Unidades permitidas para esta categoria (pivot categoria_unidad)
     */
    public function unidades()
    {
        return $this->belongsToMany(Unidad::class, 'categoria_unidad')
            ->withTimestamps()
            ->withPivot(['activo', 'created_by', 'updated_by']);
    }

    /**
     * Usuario que creo el registro
     */
    public function creador()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Usuario que actualizo el registro
     */
    public function actualizador()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // =======================
    // METODOS DE NEGOCIO
    // =======================

    /**
     * Verificar si esta categoria usa lotes (tiene origen configurado)
     */
    public function usaLotes(): bool
    {
        return !is_null($this->categoria_origen_id);
    }

    /**
     * Verificar si es categoria que se referencia a si misma
     * Ej: Huevo Marron con origen = Huevo Marron
     */
    public function esAutoReferenciada(): bool
    {
        return $this->categoria_origen_id === $this->id;
    }

    /**
     * Verificar si es categoria derivada (origen es OTRA categoria)
     * Ej: Opoa Huevo Grande con origen = Huevo Grande
     */
    public function esDerivada(): bool
    {
        return $this->usaLotes() && !$this->esAutoReferenciada();
    }

    /**
     * Verificar si es categoria base que no usa lotes
     */
    public function esBaseSinLotes(): bool
    {
        return is_null($this->categoria_origen_id);
    }

    /**
     * Obtener la categoria del lote a usar
     * - Si es derivada: retorna la categoria origen
     * - Si es auto-referenciada: retorna si misma
     * - Si no usa lotes: retorna null
     */
    public function getCategoriaLote(): ?Categoria
    {
        if (!$this->usaLotes()) {
            return null;
        }
        
        return $this->categoriaOrigen;
    }

    /**
     * Obtener ID de la categoria del lote a usar
     */
    public function getCategoriaLoteId(): ?int
    {
        if (!$this->usaLotes()) {
            return null;
        }
        
        return $this->categoria_origen_id;
    }

    /**
     * Verificar si requiere restar de lote al cargar viaje
     */
    public function requiereRestarDeLote(): bool
    {
        return $this->usaLotes();
    }
}