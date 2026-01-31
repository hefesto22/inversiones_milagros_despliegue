<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lote extends Model
{
    use HasFactory;

    protected $table = 'lotes';

    protected $fillable = [
        'compra_id',
        'compra_detalle_id',
        'reempaque_origen_id',
        'producto_id',
        'proveedor_id',
        'bodega_id',
        'numero_lote',
        // CAMPOS DE CANTIDADES
        'cantidad_cartones_facturados',
        'cantidad_cartones_regalo',
        'cantidad_cartones_recibidos',
        'huevos_por_carton',
        'cantidad_huevos_original',
        'cantidad_huevos_remanente',
        // CAMPOS DE COSTOS
        'costo_total_lote',
        'costo_por_carton_facturado',
        'costo_por_huevo',
        'estado',
        'created_by',
    ];

    protected $casts = [
        'cantidad_cartones_facturados' => 'decimal:2',
        'cantidad_cartones_regalo' => 'decimal:2',
        'cantidad_cartones_recibidos' => 'decimal:2',
        'huevos_por_carton' => 'integer',
        'cantidad_huevos_original' => 'decimal:2',
        'cantidad_huevos_remanente' => 'decimal:2',
        'costo_total_lote' => 'decimal:2',
        'costo_por_carton_facturado' => 'decimal:2',
        'costo_por_huevo' => 'decimal:2',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function compraDetalle(): BelongsTo
    {
        return $this->belongsTo(CompraDetalle::class, 'compra_detalle_id');
    }

    public function reempaqueOrigen(): BelongsTo
    {
        return $this->belongsTo(Reempaque::class, 'reempaque_origen_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reempaqueLotes(): HasMany
    {
        return $this->hasMany(ReempaqueLote::class, 'lote_id');
    }

    // ============================================
    // EVENTOS (Boot)
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($lote) {
            // IMPORTANTE: Los lotes SUELTOS-* ya vienen con sus valores calculados
            // desde consolidarSueltos(), NO deben ser recalculados aqui
            $esLoteSueltos = str_starts_with($lote->numero_lote ?? '', 'SUELTOS-');

            if ($esLoteSueltos) {
                // Para lotes SUELTOS: solo validar que los valores existan
                // NO recalcular nada, los valores vienen correctos desde CreateReempaque
                if (is_null($lote->cantidad_cartones_recibidos)) {
                    $lote->cantidad_cartones_recibidos = 0;
                }
                return; // Salir sin recalcular
            }

            // === LOGICA NORMAL PARA LOTES DE COMPRA (L-*) ===

            $facturados = $lote->cantidad_cartones_facturados ?? 0;
            $regalo = $lote->cantidad_cartones_regalo ?? 0;

            if (is_null($lote->cantidad_cartones_recibidos)) {
                $lote->cantidad_cartones_recibidos = $facturados + $regalo;
            }

            if (is_null($lote->cantidad_huevos_original)) {
                $lote->cantidad_huevos_original = $lote->cantidad_cartones_recibidos * $lote->huevos_por_carton;
            }

            if (is_null($lote->cantidad_huevos_remanente)) {
                $lote->cantidad_huevos_remanente = $lote->cantidad_huevos_original;
            }

            // Calcular costo_por_huevo SOLO para lotes normales
            if (is_null($lote->costo_por_huevo) || $lote->isDirty('costo_total_lote')) {
                $lote->costo_por_huevo = self::calcularCostoPorHuevo(
                    $lote->costo_total_lote ?? 0,
                    $facturados,
                    $lote->huevos_por_carton ?? 30
                );
            }

            // Calcular costo por carton facturado
            if (is_null($lote->costo_por_carton_facturado) && $facturados > 0) {
                $lote->costo_por_carton_facturado = round(($lote->costo_total_lote ?? 0) / $facturados, 2);
            }
        });
    }

    // ============================================
    // METODOS ESTATICOS DE CALCULO
    // ============================================

    /**
     * CALCULAR COSTO POR HUEVO
     *
     * CRITICO: El costo se divide SOLO entre huevos FACTURADOS, NO los regalos.
     * Los huevos de regalo tienen costo CERO y son ganancia pura.
     */
    public static function calcularCostoPorHuevo(
        float $costoTotal,
        float $cartonesFacturados,
        int $huevosPorCarton = 30
    ): float {
        $huevosFacturados = $cartonesFacturados * $huevosPorCarton;

        if ($huevosFacturados <= 0) {
            return 0;
        }

        $costoPorHuevo = $costoTotal / $huevosFacturados;

        return ceil($costoPorHuevo * 100) / 100;
    }

    // ============================================
    // 🆕 METODOS DE MERMA ACUMULADA Y REGALO DISPONIBLE
    // ============================================

    /**
     * Obtener la merma acumulada de todos los reempaques que usaron este lote
     * La merma se distribuye proporcionalmente según los huevos usados de cada lote
     */
    public function getMermaAcumulada(): float
    {
        // Obtener todos los reempaque_lotes de este lote con reempaques completados
        $reempaqueLotes = $this->reempaqueLotes()
            ->whereHas('reempaque', function ($query) {
                $query->where('estado', 'completado');
            })
            ->with('reempaque')
            ->get();

        $mermaAcumulada = 0;

        foreach ($reempaqueLotes as $reempaqueLote) {
            $reempaque = $reempaqueLote->reempaque;
            
            if (!$reempaque || $reempaque->total_huevos_usados <= 0) {
                continue;
            }

            // Calcular la proporción de huevos que este lote aportó al reempaque
            $proporcion = $reempaqueLote->cantidad_huevos_usados / $reempaque->total_huevos_usados;
            
            // La merma proporcional de este lote en ese reempaque
            $mermaProporcional = $reempaque->merma * $proporcion;
            
            $mermaAcumulada += $mermaProporcional;
        }

        return round($mermaAcumulada, 2);
    }

    /**
     * Obtener los huevos de regalo disponibles (total - merma acumulada)
     * Este es el "buffer" que aún puede absorber mermas sin afectar el costo
     */
    public function getHuevosRegaloDisponibles(): float
    {
        // Lotes SUELTOS no tienen regalo
        if ($this->esLoteSueltos()) {
            return 0;
        }

        $regaloTotal = ($this->cantidad_cartones_regalo ?? 0) * ($this->huevos_por_carton ?? 30);
        $mermaAcumulada = $this->getMermaAcumulada();
        
        return max(0, $regaloTotal - $mermaAcumulada);
    }

    /**
     * Verificar si el lote aún tiene buffer de regalo disponible
     */
    public function tieneRegaloDisponible(): bool
    {
        return $this->getHuevosRegaloDisponibles() > 0;
    }

    /**
     * Obtener el resumen del estado del regalo del lote
     */
    public function getResumenRegalo(): array
    {
        $regaloTotal = ($this->cantidad_cartones_regalo ?? 0) * ($this->huevos_por_carton ?? 30);
        $mermaAcumulada = $this->getMermaAcumulada();
        $regaloDisponible = $this->getHuevosRegaloDisponibles();

        return [
            'regalo_total_huevos' => $regaloTotal,
            'merma_acumulada' => $mermaAcumulada,
            'regalo_disponible' => $regaloDisponible,
            'regalo_consumido' => min($regaloTotal, $mermaAcumulada),
            'merma_pagada' => max(0, $mermaAcumulada - $regaloTotal),
        ];
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeDisponible($query)
    {
        return $query->where('estado', 'disponible');
    }

    public function scopeAgotado($query)
    {
        return $query->where('estado', 'agotado');
    }

    public function scopePorBodega($query, int $bodegaId)
    {
        return $query->where('bodega_id', $bodegaId);
    }

    public function scopePorProveedor($query, int $proveedorId)
    {
        return $query->where('proveedor_id', $proveedorId);
    }

    public function scopeConRemanente($query)
    {
        return $query->where('cantidad_huevos_remanente', '>', 0);
    }

    public function scopeSueltos($query)
    {
        return $query->where('numero_lote', 'LIKE', 'SUELTOS-%');
    }

    public function scopeNormales($query)
    {
        return $query->where('numero_lote', 'LIKE', 'L-%')
                    ->where('numero_lote', 'NOT LIKE', 'SUELTOS-%');
    }

    public function scopePorReempaqueOrigen($query, int $reempaqueId)
    {
        return $query->where('reempaque_origen_id', $reempaqueId);
    }

    // ============================================
    // METODOS DE NEGOCIO
    // ============================================

    /**
     * Reducir el remanente del lote
     */
    public function reducirRemanente(float $cantidadHuevos): void
    {
        $this->cantidad_huevos_remanente -= $cantidadHuevos;

        if ($this->cantidad_huevos_remanente <= 0) {
            $this->cantidad_huevos_remanente = 0;
            $this->estado = 'agotado';
        }

        $this->save();
    }

    /**
     * Verificar si el lote esta disponible
     */
    public function estaDisponible(): bool
    {
        return $this->estado === 'disponible' && $this->cantidad_huevos_remanente > 0;
    }

    /**
     * Obtener el porcentaje usado del lote
     */
    public function getPorcentajeUsado(): float
    {
        if ($this->cantidad_huevos_original <= 0) {
            return 0;
        }

        $usado = $this->cantidad_huevos_original - $this->cantidad_huevos_remanente;
        return round(($usado / $this->cantidad_huevos_original) * 100, 2);
    }

    /**
     * Calcular costo total del lote
     */
    public function getCostoTotal(): float
    {
        return $this->costo_total_lote ?? 0;
    }

    /**
     * Calcular costo del remanente
     */
    public function getCostoRemanente(): float
    {
        return $this->calcularCostoDeHuevos($this->cantidad_huevos_remanente);
    }

    /**
     * Obtener cartones totales (facturados + regalo)
     */
    public function getCartonesTotales(): float
    {
        return ($this->cantidad_cartones_facturados ?? 0) + ($this->cantidad_cartones_regalo ?? 0);
    }

    /**
     * Obtener huevos facturados restantes en el remanente
     */
    public function getHuevosFacturadosRestantes(): float
    {
        $huevosFacturadosOriginales = ($this->cantidad_cartones_facturados ?? 0) * ($this->huevos_por_carton ?? 30);
        $huevosUsados = ($this->cantidad_huevos_original ?? 0) - ($this->cantidad_huevos_remanente ?? 0);

        $facturadosRestantes = max(0, $huevosFacturadosOriginales - $huevosUsados);

        return min($facturadosRestantes, $this->cantidad_huevos_remanente ?? 0);
    }

    /**
     * Obtener huevos de regalo restantes en el remanente
     */
    public function getHuevosRegaloRestantes(): float
    {
        $huevosFacturadosRestantes = $this->getHuevosFacturadosRestantes();
        return max(0, ($this->cantidad_huevos_remanente ?? 0) - $huevosFacturadosRestantes);
    }

    /**
     * Obtener cartones regalados restantes
     */
    public function getCartonesRegaloRestantes(): float
    {
        $huevosRegaloRestantes = $this->getHuevosRegaloRestantes();
        return $huevosRegaloRestantes / ($this->huevos_por_carton ?? 30);
    }

    /**
     * CALCULAR COSTO REAL DE UNA CANTIDAD DE HUEVOS
     */
    public function calcularCostoDeHuevos(float $cantidadHuevos): float
    {
        // Para lotes SUELTOS-*, usar costo_por_huevo directamente
        if (str_starts_with($this->numero_lote ?? '', 'SUELTOS-')) {
            return $cantidadHuevos * ($this->costo_por_huevo ?? 0);
        }

        $huevosFacturadosRestantes = $this->getHuevosFacturadosRestantes();

        if ($cantidadHuevos <= $huevosFacturadosRestantes) {
            return round($cantidadHuevos * ($this->costo_por_huevo ?? 0), 2);
        }

        $costoFacturados = $huevosFacturadosRestantes * ($this->costo_por_huevo ?? 0);

        return round($costoFacturados, 2);
    }

    /**
     * Calcular el beneficio de los cartones regalados en dinero
     */
    public function getBeneficioRegalos(): float
    {
        if (($this->cantidad_cartones_regalo ?? 0) <= 0) {
            return 0;
        }

        $huevosRegalados = $this->cantidad_cartones_regalo * $this->huevos_por_carton;
        return round($huevosRegalados * $this->costo_por_huevo, 2);
    }

    /**
     * Calcular costo real por carton (promediando con regalos)
     */
    public function getCostoRealPorCarton(): float
    {
        $cartonesTotales = $this->getCartonesTotales();

        if ($cartonesTotales <= 0) {
            return 0;
        }

        return round($this->costo_total_lote / $cartonesTotales, 2);
    }

    /**
     * Obtener informacion resumida del lote con regalos
     */
    public function getResumenLote(): array
    {
        return [
            'cartones_facturados' => $this->cantidad_cartones_facturados,
            'cartones_regalo' => $this->cantidad_cartones_regalo,
            'cartones_totales' => $this->getCartonesTotales(),
            'huevos_facturados_restantes' => $this->getHuevosFacturadosRestantes(),
            'huevos_regalo_restantes' => $this->getHuevosRegaloRestantes(),
            'costo_por_huevo_facturado' => $this->costo_por_huevo,
            'costo_facturado' => $this->costo_por_carton_facturado,
            'costo_real_promedio' => $this->getCostoRealPorCarton(),
            'beneficio_regalos' => $this->getBeneficioRegalos(),
            'ahorro_porcentaje' => $this->cantidad_cartones_facturados > 0
                ? round(($this->cantidad_cartones_regalo / $this->cantidad_cartones_facturados) * 100, 2)
                : 0,
        ];
    }

    /**
     * Verificar si el lote es de sueltos consolidado
     */
    public function esLoteSueltos(): bool
    {
        return str_starts_with($this->numero_lote ?? '', 'SUELTOS-');
    }

    /**
     * Verificar si el lote tiene huevos de regalo disponibles
     */
    public function tieneRegalosDisponibles(): bool
    {
        return $this->getHuevosRegaloRestantes() > 0;
    }

    /**
     * Obtener el reempaque que origino este lote (si es SUELTOS-*)
     */
    public function getReempaqueOrigenInfo(): ?array
    {
        if (!$this->esLoteSueltos() || !$this->reempaque_origen_id) {
            return null;
        }

        $reempaque = $this->reempaqueOrigen;

        if (!$reempaque) {
            return null;
        }

        return [
            'numero_reempaque' => $reempaque->numero_reempaque,
            'fecha' => $reempaque->created_at?->format('Y-m-d H:i'),
            'lotes_usados' => $reempaque->reempaqueLotes->pluck('lote.numero_lote')->toArray(),
        ];
    }
}