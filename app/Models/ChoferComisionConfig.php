<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChoferComisionConfig extends Model
{
    use HasFactory;

    protected $table = 'chofer_comision_config';

    // Constantes para tipos de comisión
    public const TIPO_FIJO = 'fijo';
    public const TIPO_PORCENTAJE = 'porcentaje';

    protected $fillable = [
        'user_id',
        'categoria_id',
        'unidad_id',
        'tipo_comision',
        'comision_normal',
        'comision_reducida',
        'vigente_desde',
        'vigente_hasta',
        'activo',
        'created_by',
    ];

    protected $casts = [
        'comision_normal' => 'decimal:2',
        'comision_reducida' => 'decimal:2',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
        'activo' => 'boolean',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }

    public function scopeVigentes($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('vigente_hasta')
              ->orWhere('vigente_hasta', '>=', now());
        });
    }

    public function scopeDelChofer($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeDeCategoria($query, int $categoriaId)
    {
        return $query->where('categoria_id', $categoriaId);
    }

    // ============================================
    // MÉTODOS
    // ============================================

    /**
     * Verificar si es comisión por porcentaje
     */
    public function esPorcentaje(): bool
    {
        return $this->tipo_comision === self::TIPO_PORCENTAJE;
    }

    /**
     * Verificar si es comisión fija
     */
    public function esFijo(): bool
    {
        return $this->tipo_comision === self::TIPO_FIJO;
    }

    /**
     * Verificar si está vigente
     */
    public function estaVigente(): bool
    {
        if (!$this->activo) {
            return false;
        }

        if ($this->vigente_hasta && $this->vigente_hasta < now()) {
            return false;
        }

        return true;
    }

    /**
     * Obtener comisión según precio de venta
     * 
     * @param float $precioVenta - Precio al que vendió el chofer
     * @param float $precioSugerido - Precio sugerido/mínimo
     * @param float $cantidad - Cantidad vendida (para comisión fija)
     * @return float
     */
    public function calcularComision(float $precioVenta, float $precioSugerido, float $cantidad = 1): float
    {
        // Determinar qué tasa usar (normal o reducida)
        $tasaComision = $precioVenta >= $precioSugerido 
            ? $this->comision_normal 
            : $this->comision_reducida;

        if ($this->esPorcentaje()) {
            // Porcentaje sobre el total vendido
            $totalVenta = $precioVenta * $cantidad;
            return round($totalVenta * ($tasaComision / 100), 2);
        }

        // Fijo: monto por unidad × cantidad
        return round($tasaComision * $cantidad, 2);
    }

    /**
     * Obtener comisión base (sin cantidad) - retrocompatibilidad
     */
    public function getComision(float $precioVenta, float $precioSugerido): float
    {
        if ($precioVenta >= $precioSugerido) {
            return $this->comision_normal;
        }

        return $this->comision_reducida;
    }

    /**
     * Descripción para mostrar
     */
    public function getDescripcion(): string
    {
        $desc = $this->categoria?->nombre ?? 'Sin categoría';
        
        if ($this->unidad) {
            $desc .= ' (' . $this->unidad->nombre . ')';
        }

        return $desc;
    }

    /**
     * Obtener etiqueta del tipo de comisión
     */
    public function getTipoComisionLabel(): string
    {
        return match($this->tipo_comision) {
            self::TIPO_FIJO => 'Fijo (L)',
            self::TIPO_PORCENTAJE => 'Porcentaje (%)',
            default => 'Fijo (L)',
        };
    }

    /**
     * Formatear valor de comisión para mostrar
     */
    public function formatearComision(float $valor): string
    {
        if ($this->esPorcentaje()) {
            return number_format($valor, 2) . '%';
        }

        return 'L ' . number_format($valor, 2);
    }

    /**
     * Opciones para select de tipo comisión
     */
    public static function getTiposComision(): array
    {
        return [
            self::TIPO_FIJO => 'Fijo (Lempiras)',
            self::TIPO_PORCENTAJE => 'Porcentaje (%)',
        ];
    }
}