<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class Venta extends Model
{
    use HasFactory;

    protected $table = 'ventas';

    protected $fillable = [
        'cliente_id',
        'bodega_id',
        'numero_venta',
        'tipo_pago',
        'subtotal',
        'total_isv',
        'descuento',
        'total',
        'monto_pagado',
        'saldo_pendiente',
        'fecha_vencimiento',
        'estado',
        'estado_pago',
        'nota',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'total_isv' => 'decimal:2',
        'descuento' => 'decimal:2',
        'total' => 'decimal:2',
        'monto_pagado' => 'decimal:2',
        'saldo_pendiente' => 'decimal:2',
        'fecha_vencimiento' => 'date',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
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
        return $this->hasMany(VentaDetalle::class, 'venta_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(VentaPago::class, 'venta_id');
    }

    public function devoluciones(): HasMany
    {
        return $this->hasMany(Devolucion::class, 'venta_id');
    }

    // ============================================
    // MÉTODOS DE CÁLCULO
    // ============================================

    /**
     * Recalcular totales desde los detalles
     */
    public function recalcularTotales(): void
    {
        $this->subtotal = $this->detalles()->sum('subtotal');
        $this->total_isv = $this->detalles()->sum('total_isv');

        $totalBruto = $this->subtotal + $this->total_isv;
        $this->total = $totalBruto - $this->descuento;

        $this->saldo_pendiente = $this->total - $this->monto_pagado;

        $this->save();
    }

    /**
     * Calcular ganancia de la venta
     */
    public function calcularGanancia(): float
    {
        $costoTotal = $this->detalles()->sum(DB::raw('cantidad * costo_unitario'));
        return $this->subtotal - $costoTotal;
    }

    // ============================================
    // MÉTODOS DE PAGO
    // ============================================

    /**
     * Registrar un pago
     */
    public function registrarPago(
        float $monto,
        string $metodoPago = 'efectivo',
        ?string $referencia = null,
        ?string $nota = null
    ): VentaPago {
        $pago = $this->pagos()->create([
            'monto' => $monto,
            'metodo_pago' => $metodoPago,
            'referencia' => $referencia,
            'nota' => $nota,
            'created_by' => Auth::id(),
        ]);

        $this->monto_pagado += $monto;
        $this->saldo_pendiente = $this->total - $this->monto_pagado;

        if ($this->saldo_pendiente <= 0) {
            $this->estado_pago = 'pagado';
            $this->saldo_pendiente = 0;
        } elseif ($this->monto_pagado > 0) {
            $this->estado_pago = 'parcial';
        }

        $this->save();

        if ($this->tipo_pago === 'credito') {
            $this->cliente->registrarPago($monto);
        }

        return $pago;
    }

    /**
     * Verificar si está pagada
     */
    public function estaPagada(): bool
    {
        return $this->estado_pago === 'pagado';
    }

    /**
     * Verificar si está vencida
     */
    public function estaVencida(): bool
    {
        if ($this->estaPagada()) {
            return false;
        }

        if (!$this->fecha_vencimiento) {
            return false;
        }

        return $this->fecha_vencimiento->isPast();
    }

    /**
     * Obtener días de vencimiento
     */
    public function getDiasVencimiento(): ?int
    {
        if (!$this->fecha_vencimiento) {
            return null;
        }

        return now()->diffInDays($this->fecha_vencimiento, false);
    }

    // ============================================
    // MÉTODOS DE ESTADO
    // ============================================

    /**
     * Completar venta (descontar stock, actualizar precios cliente)
     */
    public function completar(): bool
    {
        if ($this->estado !== 'borrador') {
            return false;
        }

        DB::transaction(function () {
            if (!$this->numero_venta) {
                $this->numero_venta = $this->generarNumeroVenta();
            }

            foreach ($this->detalles as $detalle) {
                $bodegaProducto = BodegaProducto::where('bodega_id', $this->bodega_id)
                    ->where('producto_id', $detalle->producto_id)
                    ->first();

                if ($bodegaProducto) {
                    $bodegaProducto->reducirStock($detalle->cantidad);
                }

                $this->cliente->actualizarUltimoPrecio(
                    $detalle->producto_id,
                    $detalle->precio_unitario,
                    $detalle->precio_con_isv,
                    $detalle->cantidad
                );
            }

            if ($this->tipo_pago === 'credito') {
                $this->estado = 'pendiente_pago';
                $this->estado_pago = 'pendiente';
                $this->saldo_pendiente = $this->total;

                if ($this->cliente->dias_credito > 0) {
                    $this->fecha_vencimiento = now()->addDays($this->cliente->dias_credito);
                }

                $this->cliente->agregarDeuda($this->total);
            } else {
                $this->estado = 'pagada';
                $this->estado_pago = 'pagado';
                $this->monto_pagado = $this->total;
                $this->saldo_pendiente = 0;
            }

            $this->save();
        });

        return true;
    }

    /**
     * Cancelar venta
     */
    public function cancelar(?string $motivo = null): bool
    {
        if ($this->estado === 'cancelada') {
            return false;
        }

        DB::transaction(function () use ($motivo) {
            if (in_array($this->estado, ['completada', 'pendiente_pago', 'pagada'])) {
                foreach ($this->detalles as $detalle) {
                    $bodegaProducto = BodegaProducto::where('bodega_id', $this->bodega_id)
                        ->where('producto_id', $detalle->producto_id)
                        ->first();

                    if ($bodegaProducto) {
                        $bodegaProducto->agregarStockSinCosto($detalle->cantidad);
                    }
                }

                if ($this->tipo_pago === 'credito' && $this->saldo_pendiente > 0) {
                    $this->cliente->registrarPago($this->saldo_pendiente);
                }
            }

            $this->estado = 'cancelada';
            $this->nota = $this->nota
                ? $this->nota . "\n[CANCELADA] " . $motivo
                : "[CANCELADA] " . $motivo;

            $this->save();
        });

        return true;
    }

    // ============================================
    // GENERADORES
    // ============================================

    /**
     * Generar número de venta único
     */
    protected function generarNumeroVenta(): string
    {
        $prefijo = 'V';
        $bodegaCodigo = str_pad($this->bodega_id, 2, '0', STR_PAD_LEFT);
        $fecha = now()->format('ymd');

        $ultimaVenta = static::where('numero_venta', 'like', "{$prefijo}{$bodegaCodigo}-{$fecha}%")
            ->orderBy('numero_venta', 'desc')
            ->first();

        if ($ultimaVenta) {
            $ultimoNumero = (int) substr($ultimaVenta->numero_venta, -4);
            $siguiente = $ultimoNumero + 1;
        } else {
            $siguiente = 1;
        }

        return "{$prefijo}{$bodegaCodigo}-{$fecha}-" . str_pad($siguiente, 4, '0', STR_PAD_LEFT);
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeBorrador($query)
    {
        return $query->where('estado', 'borrador');
    }

    public function scopeCompletadas($query)
    {
        return $query->whereIn('estado', ['completada', 'pendiente_pago', 'pagada']);
    }

    public function scopePendientesPago($query)
    {
        return $query->where('estado_pago', '!=', 'pagado');
    }

    public function scopeVencidas($query)
    {
        return $query->where('estado_pago', '!=', 'pagado')
            ->whereNotNull('fecha_vencimiento')
            ->where('fecha_vencimiento', '<', now());
    }

    public function scopePagada($query)
    {
        return $query->where('estado', 'pagada');
    }

    public function scopeCancelada($query)
    {
        return $query->where('estado', 'cancelada');
    }

    public function scopeDelCliente($query, int $clienteId)
    {
        return $query->where('cliente_id', $clienteId);
    }

    public function scopeDelDia($query, $fecha = null)
    {
        $fecha = $fecha ?? now()->toDateString();
        return $query->whereDate('created_at', $fecha);
    }

    public function scopeDelMes($query, $mes = null, $anio = null)
    {
        $mes = $mes ?? now()->month;
        $anio = $anio ?? now()->year;

        return $query->whereMonth('created_at', $mes)
            ->whereYear('created_at', $anio);
    }

    // ============================================
    // HELPERS
    // ============================================

    /**
     * Obtener resumen de la venta
     */
    public function getResumen(): array
    {
        return [
            'numero_venta' => $this->numero_venta,
            'cliente' => $this->cliente->nombre,
            'fecha' => $this->created_at->format('d/m/Y H:i'),
            'subtotal' => $this->subtotal,
            'isv' => $this->total_isv,
            'descuento' => $this->descuento,
            'total' => $this->total,
            'tipo_pago' => $this->tipo_pago,
            'estado' => $this->estado,
            'estado_pago' => $this->estado_pago,
            'monto_pagado' => $this->monto_pagado,
            'saldo_pendiente' => $this->saldo_pendiente,
            'vencida' => $this->estaVencida(),
            'dias_vencimiento' => $this->getDiasVencimiento(),
            'ganancia' => $this->calcularGanancia(),
        ];
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($venta) {
            if (!$venta->estado) {
                $venta->estado = 'borrador';
            }

            if (!$venta->estado_pago) {
                $venta->estado_pago = 'pendiente';
            }
        });
    }
}
