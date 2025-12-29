<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class ChoferCuenta extends Model
{
    use HasFactory;

    protected $table = 'chofer_cuenta';

    protected $fillable = [
        'user_id',
        'saldo',
        'total_comisiones_historico',
        'total_cobros_historico',
        'total_pagado_historico',
    ];

    protected $casts = [
        'saldo' => 'decimal:2',
        'total_comisiones_historico' => 'decimal:2',
        'total_cobros_historico' => 'decimal:2',
        'total_pagado_historico' => 'decimal:2',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(ChoferCuentaMovimiento::class, 'user_id', 'user_id');
    }

    // ============================================
    // MÉTODOS DE MOVIMIENTOS
    // ============================================

    /**
     * Agregar comisión (suma al saldo)
     */
    public function agregarComision(float $monto, ?int $viajeId = null, ?string $concepto = null): ChoferCuentaMovimiento
    {
        $saldoAnterior = $this->saldo;
        $this->saldo += $monto;
        $this->total_comisiones_historico += $monto;
        $this->save();

        return $this->registrarMovimiento('comision', $monto, $saldoAnterior, $viajeId, null, $concepto);
    }

    /**
     * Cobrar por devolución (resta del saldo)
     */
    public function cobrarDevolucion(float $monto, ?int $viajeId = null, ?string $concepto = null): ChoferCuentaMovimiento
    {
        $saldoAnterior = $this->saldo;
        $this->saldo -= $monto;
        $this->total_cobros_historico += $monto;
        $this->save();

        return $this->registrarMovimiento('cobro_devolucion', $monto, $saldoAnterior, $viajeId, null, $concepto);
    }

    /**
     * Cobrar por merma (resta del saldo)
     */
    public function cobrarMerma(float $monto, ?int $viajeId = null, ?string $concepto = null): ChoferCuentaMovimiento
    {
        $saldoAnterior = $this->saldo;
        $this->saldo -= $monto;
        $this->total_cobros_historico += $monto;
        $this->save();

        return $this->registrarMovimiento('cobro_merma', $monto, $saldoAnterior, $viajeId, null, $concepto);
    }

    /**
     * Cobrar por faltante de efectivo (resta del saldo)
     */
    public function cobrarFaltante(float $monto, ?int $viajeId = null, ?string $concepto = null): ChoferCuentaMovimiento
    {
        $saldoAnterior = $this->saldo;
        $this->saldo -= $monto;
        $this->total_cobros_historico += $monto;
        $this->save();

        return $this->registrarMovimiento('cobro_faltante', $monto, $saldoAnterior, $viajeId, null, $concepto);
    }

    /**
     * Pagar liquidación (resta del saldo, va al chofer)
     */
    public function pagarLiquidacion(float $monto, ?int $liquidacionId = null, ?string $concepto = null): ChoferCuentaMovimiento
    {
        $saldoAnterior = $this->saldo;
        $this->saldo -= $monto;
        $this->total_pagado_historico += $monto;
        $this->save();

        return $this->registrarMovimiento('pago_liquidacion', $monto, $saldoAnterior, null, $liquidacionId, $concepto);
    }

    /**
     * Ajuste a favor del chofer (suma al saldo)
     */
    public function ajusteFavor(float $monto, ?string $concepto = null): ChoferCuentaMovimiento
    {
        $saldoAnterior = $this->saldo;
        $this->saldo += $monto;
        $this->save();

        return $this->registrarMovimiento('ajuste_favor', $monto, $saldoAnterior, null, null, $concepto);
    }

    /**
     * Ajuste en contra del chofer (resta del saldo)
     */
    public function ajusteContra(float $monto, ?string $concepto = null): ChoferCuentaMovimiento
    {
        $saldoAnterior = $this->saldo;
        $this->saldo -= $monto;
        $this->save();

        return $this->registrarMovimiento('ajuste_contra', $monto, $saldoAnterior, null, null, $concepto);
    }

    /**
     * Registrar movimiento en la cuenta
     */
    protected function registrarMovimiento(
        string $tipo,
        float $monto,
        float $saldoAnterior,
        ?int $viajeId = null,
        ?int $liquidacionId = null,
        ?string $concepto = null
    ): ChoferCuentaMovimiento {
        return ChoferCuentaMovimiento::create([
            'user_id' => $this->user_id,
            'tipo' => $tipo,
            'monto' => $monto,
            'saldo_anterior' => $saldoAnterior,
            'saldo_nuevo' => $this->saldo,
            'viaje_id' => $viajeId,
            'liquidacion_id' => $liquidacionId,
            'concepto' => $concepto,
            'created_by' => Auth::id(),
        ]);
    }

    // ============================================
    // MÉTODOS DE CONSULTA
    // ============================================

    /**
     * Verificar si tiene saldo positivo (le debemos)
     */
    public function tieneSaldoAFavor(): bool
    {
        return $this->saldo > 0;
    }

    /**
     * Verificar si tiene saldo negativo (nos debe)
     */
    public function tieneSaldoEnContra(): bool
    {
        return $this->saldo < 0;
    }

    /**
     * Obtener resumen de cuenta
     */
    public function getResumen(): array
    {
        return [
            'chofer' => $this->chofer->name,
            'saldo_actual' => $this->saldo,
            'estado' => $this->saldo >= 0 ? 'A favor' : 'En contra',
            'total_comisiones' => $this->total_comisiones_historico,
            'total_cobros' => $this->total_cobros_historico,
            'total_pagado' => $this->total_pagado_historico,
        ];
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeConSaldoPositivo($query)
    {
        return $query->where('saldo', '>', 0);
    }

    public function scopeConSaldoNegativo($query)
    {
        return $query->where('saldo', '<', 0);
    }
}
