<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Merma extends Model
{
    use HasFactory;

    protected $table = 'mermas';

    protected $fillable = [
        'lote_id',
        'bodega_id',
        'producto_id',
        'numero_merma',
        'cantidad_huevos',
        'cubierto_por_regalo',
        'perdida_real_huevos',
        'perdida_real_lempiras',
        'motivo',
        'descripcion',
        'buffer_antes',
        'buffer_despues',
        'created_by',
    ];

    protected $casts = [
        'cantidad_huevos' => 'decimal:2',
        'cubierto_por_regalo' => 'decimal:2',
        'perdida_real_huevos' => 'decimal:2',
        'perdida_real_lempiras' => 'decimal:2',
        'buffer_antes' => 'decimal:2',
        'buffer_despues' => 'decimal:2',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ============================================
    // MÉTODOS ESTÁTICOS
    // ============================================

    /**
     * Generar número de merma automático
     */
    public static function generarNumeroMerma(int $bodegaId): string
    {
        $ultimaMerma = self::where('numero_merma', 'LIKE', "M-B{$bodegaId}-%")
            ->orderBy('numero_merma', 'desc')
            ->value('numero_merma');

        if ($ultimaMerma) {
            $prefijo = "M-B{$bodegaId}-";
            $numeroSecuencial = (int) str_replace($prefijo, '', $ultimaMerma);
            $nuevoNumero = $numeroSecuencial + 1;
        } else {
            $nuevoNumero = 1;
        }

        $numeroFormateado = str_pad($nuevoNumero, 6, '0', STR_PAD_LEFT);

        return "M-B{$bodegaId}-{$numeroFormateado}";
    }

    // ============================================
    // MÉTODOS DE INSTANCIA
    // ============================================

    /**
     * Verificar si hubo pérdida económica
     */
    public function tuvoPerdidaEconomica(): bool
    {
        return $this->perdida_real_huevos > 0;
    }

    /**
     * Obtener el porcentaje cubierto por regalo
     */
    public function getPorcentajeCubierto(): float
    {
        if ($this->cantidad_huevos <= 0) {
            return 0;
        }

        return round(($this->cubierto_por_regalo / $this->cantidad_huevos) * 100, 2);
    }

    /**
     * Obtener etiqueta del motivo
     */
    public function getMotivoLabel(): string
    {
        return match ($this->motivo) {
            'rotos' => 'Rotos',
            'podridos' => 'Podridos',
            'vencidos' => 'Vencidos',
            'dañados_transporte' => 'Dañados en Transporte',
            'otros' => 'Otros',
            default => $this->motivo,
        };
    }

    /**
     * Obtener resumen de la merma
     */
    public function getResumen(): array
    {
        return [
            'numero' => $this->numero_merma,
            'cantidad' => $this->cantidad_huevos,
            'cubierto' => $this->cubierto_por_regalo,
            'perdida_huevos' => $this->perdida_real_huevos,
            'perdida_lempiras' => $this->perdida_real_lempiras,
            'motivo' => $this->getMotivoLabel(),
            'tuvo_perdida' => $this->tuvoPerdidaEconomica(),
            'porcentaje_cubierto' => $this->getPorcentajeCubierto(),
        ];
    }
}