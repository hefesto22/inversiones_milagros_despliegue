<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReempaqueLote extends Model
{
    use HasFactory;

    protected $table = 'reempaque_lotes';

    protected $fillable = [
        'reempaque_id',
        'lote_id',
        'cantidad_cartones_usados',
        'cantidad_huevos_usados',
        'cartones_facturados_usados',
        'cartones_regalo_usados',
        'costo_parcial',
    ];

    protected $casts = [
        'cantidad_cartones_usados' => 'decimal:3',
        'cantidad_huevos_usados' => 'decimal:3',
        'cartones_facturados_usados' => 'decimal:3',
        'cartones_regalo_usados' => 'decimal:3',
        'costo_parcial' => 'decimal:4',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function reempaque(): BelongsTo
    {
        return $this->belongsTo(Reempaque::class, 'reempaque_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_id');
    }

    // ============================================
    // MÉTODOS DE NEGOCIO
    // ============================================

    /**
     * Calcular el costo por huevo de este lote en el reempaque
     * NOTA: Considera que algunos huevos pueden ser de regalo (costo 0)
     */
    public function getCostoPorHuevo(): float
    {
        if ($this->cantidad_huevos_usados <= 0) {
            return 0;
        }

        return $this->costo_parcial / $this->cantidad_huevos_usados;
    }

    /**
     * Obtener el porcentaje de participación en el reempaque
     */
    public function getPorcentajeParticipacion(): float
    {
        $reempaque = $this->reempaque;

        if (!$reempaque || $reempaque->total_huevos_usados <= 0) {
            return 0;
        }

        return round(($this->cantidad_huevos_usados / $reempaque->total_huevos_usados) * 100, 2);
    }

    /**
     * Obtener total de huevos de cartones regalados usados
     */
    public function getHuevosRegaloUsados(): float
    {
        $lote = $this->lote;

        if (!$lote) {
            return 0;
        }

        return $this->cartones_regalo_usados * ($lote->huevos_por_carton ?? 30);
    }

    /**
     * Obtener total de huevos facturados usados
     */
    public function getHuevosFacturadosUsados(): float
    {
        $lote = $this->lote;

        if (!$lote) {
            return 0;
        }

        return $this->cartones_facturados_usados * ($lote->huevos_por_carton ?? 30);
    }

    /**
     * Verificar si este lote usó cartones regalados
     */
    public function usoCartonesRegalo(): bool
    {
        return ($this->cartones_regalo_usados ?? 0) > 0;
    }

    /**
     * Obtener el ahorro por usar cartones de regalo
     * (Lo que hubiera costado si fueran facturados)
     */
    public function getAhorroPorRegalo(): float
    {
        $lote = $this->lote;

        if (!$lote || $this->cartones_regalo_usados <= 0) {
            return 0;
        }

        $huevosRegalo = $this->getHuevosRegaloUsados();

        // Fase 5: usar accessor efectivo para respetar inventario.wac.read_source.
        return round($huevosRegalo * $lote->costo_por_huevo_efectivo, 2);
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($reempaqueLote) {
            $lote = $reempaqueLote->lote;

            // Calcular cantidad_cartones_usados automáticamente
            $facturados = $reempaqueLote->cartones_facturados_usados ?? 0;
            $regalo = $reempaqueLote->cartones_regalo_usados ?? 0;

            if (is_null($reempaqueLote->cantidad_cartones_usados)) {
                $reempaqueLote->cantidad_cartones_usados = $facturados + $regalo;
            }

            // Calcular cantidad_huevos_usados si no está definida
            if (is_null($reempaqueLote->cantidad_huevos_usados) && $lote) {
                $reempaqueLote->cantidad_huevos_usados =
                    $reempaqueLote->cantidad_cartones_usados * ($lote->huevos_por_carton ?? 30);
            }

            // CORREGIDO: Calcular costo_parcial SOLO de huevos facturados
            // Los huevos de regalo tienen costo = 0
            if (is_null($reempaqueLote->costo_parcial) && $lote) {
                $huevosFacturados = $facturados * ($lote->huevos_por_carton ?? 30);

                // Fase 5: usar accessor efectivo para respetar inventario.wac.read_source.
                $reempaqueLote->costo_parcial = round(
                    $huevosFacturados * $lote->costo_por_huevo_efectivo,
                    4
                );
            }
        });
    }
}