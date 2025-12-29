<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Compra extends Model
{
    use HasFactory;

    protected $table = 'compras';

    protected $fillable = [
        'proveedor_id',
        'bodega_id',
        'numero_compra', // 🆕
        'tipo_pago',
        'interes_porcentaje', // 🆕 (reemplaza interes_credito)
        'periodo_interes', // 🆕
        'fecha_inicio_credito', // 🆕
        'estado',
        'nota',
        'total',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'interes_porcentaje' => 'decimal:2', // 🆕
        'total' => 'decimal:2',
        'fecha_inicio_credito' => 'date', // 🆕
    ];

    // ============================================
    // RELACIONES
    // ============================================

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

    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(CompraDetalle::class, 'compra_id');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class, 'compra_id');
    }
    // ============================================
    // SCOPES - Estados Específicos
    // ============================================

    public function scopeBorrador($query)
    {
        return $query->where('estado', 'borrador');
    }

    public function scopeOrdenada($query)
    {
        return $query->where('estado', 'ordenada');
    }

    public function scopeRecibidaPagada($query)
    {
        return $query->where('estado', 'recibida_pagada');
    }

    public function scopeRecibidaPendientePago($query)
    {
        return $query->where('estado', 'recibida_pendiente_pago');
    }

    public function scopePorRecibirPagada($query)
    {
        return $query->where('estado', 'por_recibir_pagada');
    }

    public function scopePorRecibirPendientePago($query)
    {
        return $query->where('estado', 'por_recibir_pendiente_pago');
    }

    public function scopeCancelada($query)
    {
        return $query->where('estado', 'cancelada');
    }

    // ============================================
    // SCOPES - Agrupaciones Útiles
    // ============================================

    /**
     * Compras que están pendientes de recibir la mercancía
     */
    public function scopePendienteRecibir($query)
    {
        return $query->whereIn('estado', [
            'ordenada',
            'por_recibir_pagada',
            'por_recibir_pendiente_pago'
        ]);
    }

    /**
     * Compras que están pendientes de pago
     */
    public function scopePendientePago($query)
    {
        return $query->whereIn('estado', [
            'recibida_pendiente_pago',
            'por_recibir_pendiente_pago'
        ]);
    }

    /**
     * Compras completadas (recibidas y pagadas)
     */
    public function scopeCompletadas($query)
    {
        return $query->where('estado', 'recibida_pagada');
    }

    /**
     * Compras activas (no son borrador ni canceladas ni completadas)
     */
    public function scopeActivas($query)
    {
        return $query->whereNotIn('estado', ['borrador', 'cancelada', 'recibida_pagada']);
    }

    // ============================================
    // 🆕 MÉTODOS PARA CÁLCULO DE INTERESES
    // ============================================

    /**
     * Calcular cuántos periodos han pasado desde el inicio del crédito
     */
    public function getPeriodosTranscurridos(): int
    {
        if (!$this->fecha_inicio_credito || !$this->periodo_interes) {
            return 0;
        }

        $fechaInicio = Carbon::parse($this->fecha_inicio_credito);
        $fechaActual = Carbon::now();

        if ($this->periodo_interes === 'semanal') {
            return $fechaInicio->diffInWeeks($fechaActual);
        }

        if ($this->periodo_interes === 'mensual') {
            return $fechaInicio->diffInMonths($fechaActual);
        }

        return 0;
    }

    /**
     * Calcular interés acumulado hasta la fecha
     */
    public function getInteresAcumulado(): float
    {
        if (!$this->interes_porcentaje || $this->tipo_pago !== 'credito') {
            return 0;
        }

        $periodos = $this->getPeriodosTranscurridos();
        $tasaInteres = $this->interes_porcentaje / 100;

        // Interés simple: Total * Tasa * Periodos
        $interesAcumulado = $this->total * $tasaInteres * $periodos;

        return round($interesAcumulado, 2);
    }

    /**
     * Calcular el saldo total con intereses
     */
    public function getSaldoConIntereses(): float
    {
        return $this->total + $this->getInteresAcumulado();
    }

    /**
     * Obtener información detallada del crédito
     */
    public function getInfoCredito(): array
    {
        if ($this->tipo_pago !== 'credito') {
            return [];
        }

        return [
            'monto_original' => $this->total,
            'interes_porcentaje' => $this->interes_porcentaje,
            'periodo' => $this->periodo_interes,
            'fecha_inicio' => $this->fecha_inicio_credito?->format('d/m/Y'),
            'periodos_transcurridos' => $this->getPeriodosTranscurridos(),
            'interes_acumulado' => $this->getInteresAcumulado(),
            'saldo_total' => $this->getSaldoConIntereses(),
        ];
    }

    // ============================================
    // MÉTODOS DE NEGOCIO
    // ============================================

    /**
     * Recalcular total basado en detalles
     */
    public function recalcularTotal(): void
    {
        $this->total = $this->detalles()->sum('subtotal');
        $this->save();
    }

    /**
     * Obtiene el saldo pendiente de pago (actualizado con intereses)
     */
    public function getSaldoPendiente(): float
    {
        // Solo hay saldo pendiente si la mercancía fue recibida pero no se ha pagado
        if ($this->estado === 'recibida_pendiente_pago') {
            // 🆕 Ahora incluye intereses si es a crédito
            return $this->getSaldoConIntereses();
        }
        return 0;
    }

    /**
     * Verifica si la compra está completamente finalizada
     */
    public function estaCompletada(): bool
    {
        return $this->estado === 'recibida_pagada';
    }

    /**
     * Verifica si la compra está cancelada
     */
    public function estaCancelada(): bool
    {
        return $this->estado === 'cancelada';
    }

    /**
     * Verifica si se puede editar (solo borradores)
     */
    public function esEditable(): bool
    {
        return $this->estado === 'borrador';
    }

    /**
     * Verifica si ya fue recibida la mercancía
     */
    public function fueRecibida(): bool
    {
        return in_array($this->estado, [
            'recibida_pagada',
            'recibida_pendiente_pago'
        ]);
    }

    /**
     * Verifica si ya fue pagada
     */
    public function fuePagada(): bool
    {
        return in_array($this->estado, [
            'recibida_pagada',
            'por_recibir_pagada'
        ]);
    }
}
