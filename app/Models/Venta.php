<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Venta extends Model
{
    use HasFactory;

    protected $fillable = [
        'cliente_id',
        'bodega_id',
        'fecha',
        'tipo_pago',
        'plazo_dias',
        'tasa_interes_mensual',
        'subtotal',
        'impuesto',
        'total',
        'saldo_pendiente',
        'estado',
        'numero_factura',
        'nota',
        'user_id',
        'confirmada_por',
        'confirmada_at',
    ];

    protected $casts = [
        'fecha' => 'datetime',
        'confirmada_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'impuesto' => 'decimal:2',
        'total' => 'decimal:2',
        'saldo_pendiente' => 'decimal:2',
        'plazo_dias' => 'integer',
        'tasa_interes_mensual' => 'decimal:2',
    ];

    /**
     * Relación con cliente
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Relación con bodega
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * Relación con detalles de la venta
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(VentaDetalle::class);
    }

    /**
     * Usuario que creó la venta
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Usuario que confirmó la venta
     */
    public function confirmadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmada_por');
    }

    /**
     * Movimientos de inventario generados
     */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'referencia_id')
            ->where('referencia_tipo', 'venta');
    }

    /**
     * Scope para ventas en borrador
     */
    public function scopeBorradores($query)
    {
        return $query->where('estado', 'borrador');
    }

    /**
     * Scope para ventas confirmadas
     */
    public function scopeConfirmadas($query)
    {
        return $query->where('estado', 'confirmada');
    }

    /**
     * Scope para ventas canceladas
     */
    public function scopeCanceladas($query)
    {
        return $query->where('estado', 'cancelada');
    }

    /**
     * Scope para ventas liquidadas
     */
    public function scopeLiquidadas($query)
    {
        return $query->where('estado', 'liquidada');
    }

    /**
     * Scope para ventas al contado
     */
    public function scopeContado($query)
    {
        return $query->where('tipo_pago', 'contado');
    }

    /**
     * Scope para ventas a crédito
     */
    public function scopeCredito($query)
    {
        return $query->where('tipo_pago', 'credito');
    }

    /**
     * Scope por cliente
     */
    public function scopePorCliente($query, int $clienteId)
    {
        return $query->where('cliente_id', $clienteId);
    }

    /**
     * Scope por bodega
     */
    public function scopePorBodega($query, int $bodegaId)
    {
        return $query->where('bodega_id', $bodegaId);
    }

    /**
     * Verificar si es borrador
     */
    public function esBorrador(): bool
    {
        return $this->estado === 'borrador';
    }

    /**
     * Verificar si está confirmada
     */
    public function estaConfirmada(): bool
    {
        return $this->estado === 'confirmada';
    }

    /**
     * Verificar si está cancelada
     */
    public function estaCancelada(): bool
    {
        return $this->estado === 'cancelada';
    }

    /**
     * Verificar si está liquidada
     */
    public function estaLiquidada(): bool
    {
        return $this->estado === 'liquidada';
    }

    /**
     * Verificar si es al contado
     */
    public function esContado(): bool
    {
        return $this->tipo_pago === 'contado';
    }

    /**
     * Verificar si es a crédito
     */
    public function esCredito(): bool
    {
        return $this->tipo_pago === 'credito';
    }

    /**
     * Calcular totales desde los detalles
     */
    public function calcularTotales(): void
    {
        $this->subtotal = $this->detalles->sum('total_linea');
        $this->impuesto = $this->subtotal * 0.15; // Ejemplo: 15% de impuesto
        $this->total = $this->subtotal + $this->impuesto;

        // Si es al contado, saldo pendiente = 0
        // Si es a crédito y no está liquidada, saldo = total
        if ($this->esContado()) {
            $this->saldo_pendiente = 0;
        } elseif ($this->esBorrador() || $this->estaConfirmada()) {
            $this->saldo_pendiente = $this->total;
        }

        $this->save();
    }

    /**
     * Confirmar la venta y generar movimientos de inventario
     */
    public function confirmar(): bool
    {
        if (!$this->esBorrador()) {
            return false;
        }

        DB::beginTransaction();

        try {
            // Verificar stock disponible
            foreach ($this->detalles as $detalle) {
                $stockDisponible = DB::table('bodega_producto')
                    ->where('bodega_id', $this->bodega_id)
                    ->where('producto_id', $detalle->producto_id)
                    ->value('stock') ?? 0;

                if ($stockDisponible < $detalle->cantidad_base) {
                    throw new \Exception(
                        "Stock insuficiente para {$detalle->producto->nombre}. " .
                        "Disponible: {$stockDisponible}, Requerido: {$detalle->cantidad_base}"
                    );
                }
            }

            // Generar movimientos de inventario por cada detalle
            foreach ($this->detalles as $detalle) {
                MovimientoInventario::create([
                    'bodega_id' => $this->bodega_id,
                    'producto_id' => $detalle->producto_id,
                    'tipo' => 'salida',
                    'cantidad_base' => $detalle->cantidad_base,
                    'referencia_tipo' => 'venta',
                    'referencia_id' => $this->id,
                    'nota' => "Venta #{$this->id} - {$this->cliente->nombre}",
                    'fecha' => $this->fecha,
                    'user_id' => Auth::id(),
                ]);
            }

            // Actualizar estado de la venta
            $nuevoEstado = $this->esContado() ? 'liquidada' : 'confirmada';

            $this->update([
                'estado' => $nuevoEstado,
                'confirmada_por' => Auth::id(),
                'confirmada_at' => now(),
            ]);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al confirmar venta: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Cancelar la venta
     */
    public function cancelar(): bool
    {
        if ($this->estaConfirmada() || $this->estaLiquidada()) {
            // Si ya está confirmada o liquidada, revertir movimientos
            $this->revertirMovimientos();
        }

        $this->update(['estado' => 'cancelada']);
        return true;
    }

    /**
     * Liquidar la venta (marcar como pagada)
     */
    public function liquidar(): bool
    {
        if (!$this->estaConfirmada() || $this->esContado()) {
            return false;
        }

        $this->update([
            'estado' => 'liquidada',
            'saldo_pendiente' => 0,
        ]);

        return true;
    }

    /**
     * Registrar un pago parcial
     */
    public function registrarPago(float $monto): bool
    {
        if (!$this->esCredito() || $this->estaLiquidada() || $this->estaCancelada()) {
            return false;
        }

        $nuevoSaldo = max(0, $this->saldo_pendiente - $monto);

        $this->update([
            'saldo_pendiente' => $nuevoSaldo,
            'estado' => $nuevoSaldo <= 0 ? 'liquidada' : 'confirmada',
        ]);

        return true;
    }

    /**
     * Calcular fecha de vencimiento
     */
    public function getFechaVencimientoAttribute(): ?\Carbon\Carbon
    {
        if (!$this->esCredito() || !$this->plazo_dias) {
            return null;
        }

        return $this->fecha->addDays($this->plazo_dias);
    }

    /**
     * Verificar si está vencida
     */
    public function estaVencida(): bool
    {
        if (!$this->esCredito() || $this->estaLiquidada()) {
            return false;
        }

        $fechaVencimiento = $this->fecha_vencimiento;
        return $fechaVencimiento && $fechaVencimiento->isPast();
    }

    /**
     * Calcular días de mora
     */
    public function getDiasMoraAttribute(): int
    {
        if (!$this->estaVencida()) {
            return 0;
        }

        return now()->diffInDays($this->fecha_vencimiento);
    }

    /**
     * Revertir movimientos de inventario
     */
    protected function revertirMovimientos(): void
    {
        foreach ($this->movimientos as $movimiento) {
            $movimiento->delete();
        }
    }

    /**
     * Boot del modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Asignar user_id automáticamente al crear
        static::creating(function ($venta) {
            if (Auth::check() && !$venta->user_id) {
                $venta->user_id = Auth::id();
            }
        });

        // Al eliminar una venta, eliminar sus detalles y movimientos
        static::deleting(function ($venta) {
            if ($venta->estaConfirmada() || $venta->estaLiquidada()) {
                $venta->revertirMovimientos();
            }
        });
    }
}
