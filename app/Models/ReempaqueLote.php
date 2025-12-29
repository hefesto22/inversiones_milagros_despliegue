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
        'cartones_facturados_usados',    // 🆕 NUEVO
        'cartones_regalo_usados',        // 🆕 NUEVO
        'costo_parcial',
    ];

    protected $casts = [
        'cantidad_cartones_usados' => 'decimal:3',
        'cantidad_huevos_usados' => 'decimal:3',
        'cartones_facturados_usados' => 'decimal:3',    // 🆕 NUEVO
        'cartones_regalo_usados' => 'decimal:3',        // 🆕 NUEVO
        'costo_parcial' => 'decimal:2',
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

        return $this->cartones_regalo_usados * $lote->huevos_por_carton;
    }

    /**
     * Obtener beneficio monetario de los cartones regalados
     */
    public function getBeneficioRegalos(): float
    {
        $lote = $this->lote;

        if (!$lote) {
            return 0;
        }

        $huevosRegalo = $this->getHuevosRegaloUsados();
        return $huevosRegalo * $lote->costo_por_huevo;
    }

    /**
     * Validar que los cartones usados coincidan
     * cantidad_cartones_usados = cartones_facturados_usados + cartones_regalo_usados
     */
    public function validarCartones(): bool
    {
        $facturados = $this->cartones_facturados_usados ?? 0;
        $regalo = $this->cartones_regalo_usados ?? 0;
        $total = $this->cantidad_cartones_usados ?? 0;

        return abs($total - ($facturados + $regalo)) < 0.001;
    }

    /**
     * Obtener resumen detallado del uso del lote
     */
    public function getResumen(): array
    {
        $lote = $this->lote;

        return [
            'lote_numero' => $lote->numero_lote ?? 'N/A',
            'proveedor' => $lote->proveedor->nombre ?? 'N/A',
            'cartones_facturados_usados' => $this->cartones_facturados_usados,
            'cartones_regalo_usados' => $this->cartones_regalo_usados,
            'cartones_totales_usados' => $this->cantidad_cartones_usados,
            'huevos_usados' => $this->cantidad_huevos_usados,
            'huevos_de_regalo' => $this->getHuevosRegaloUsados(),
            'costo_parcial' => $this->costo_parcial,
            'costo_por_huevo' => $this->getCostoPorHuevo(),
            'beneficio_regalos' => $this->getBeneficioRegalos(),
            'porcentaje_participacion' => $this->getPorcentajeParticipacion(),
        ];
    }

    /**
     * Verificar si este lote usó cartones regalados
     */
    public function usoCartonesRegalo(): bool
    {
        return ($this->cartones_regalo_usados ?? 0) > 0;
    }

    // ============================================
    // EVENTOS (Boot)
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($reempaqueLote) {
            $lote = $reempaqueLote->lote;

            // Validar y calcular cantidad_cartones_usados automáticamente
            $facturados = $reempaqueLote->cartones_facturados_usados ?? 0;
            $regalo = $reempaqueLote->cartones_regalo_usados ?? 0;

            if (is_null($reempaqueLote->cantidad_cartones_usados)) {
                $reempaqueLote->cantidad_cartones_usados = $facturados + $regalo;
            }

            // Calcular cantidad_huevos_usados si no está definida
            if (is_null($reempaqueLote->cantidad_huevos_usados) && $lote) {
                $reempaqueLote->cantidad_huevos_usados =
                    $reempaqueLote->cantidad_cartones_usados * $lote->huevos_por_carton;
            }

            // Calcular costo_parcial si no está definido
            if (is_null($reempaqueLote->costo_parcial) && $lote) {
                $reempaqueLote->costo_parcial =
                    $reempaqueLote->cantidad_huevos_usados * $lote->costo_por_huevo;
            }

            // Validación: No usar más cartones de regalo de los disponibles
            if ($lote && $reempaqueLote->cartones_regalo_usados > 0) {
                $regaloDisponible = $lote->getCartonesRegaloRestantes();

                if ($reempaqueLote->cartones_regalo_usados > $regaloDisponible) {
                    throw new \Exception(
                        "No puedes usar {$reempaqueLote->cartones_regalo_usados} cartones de regalo. " .
                            "Solo hay {$regaloDisponible} disponibles en el lote {$lote->numero_lote}."
                    );
                }
            }
        });

        static::saved(function ($reempaqueLote) {
            // Actualizar el remanente del lote
            $lote = $reempaqueLote->lote;

            if ($lote) {
                $lote->reducirRemanente($reempaqueLote->cantidad_huevos_usados);
            }
        });
    }
}
