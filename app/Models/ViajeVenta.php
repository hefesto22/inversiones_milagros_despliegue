<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class ViajeVenta extends Model
{
    use HasFactory;

    protected $fillable = [
        'viaje_id',
        'cliente_id',
        'fecha_venta',
        'tipo_pago',
        'plazo_dias',
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
        'fecha_venta' => 'datetime',
        'confirmada_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'impuesto' => 'decimal:2',
        'total' => 'decimal:2',
        'saldo_pendiente' => 'decimal:2',
        'plazo_dias' => 'integer',
    ];

    /**
     * Relación con viaje
     */
    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    /**
     * Relación con cliente
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Relación con detalles de la venta
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(ViajeVentaDetalle::class);
    }

    /**
     * Usuario que creó la venta (chofer)
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
     * Scope por viaje
     */
    public function scopePorViaje($query, int $viajeId)
    {
        return $query->where('viaje_id', $viajeId);
    }

    /**
     * Scope por cliente
     */
    public function scopePorCliente($query, int $clienteId)
    {
        return $query->where('cliente_id', $clienteId);
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
        // Si es a crédito, saldo = total
        if ($this->esContado()) {
            $this->saldo_pendiente = 0;
        } elseif ($this->esBorrador() || $this->estaConfirmada()) {
            $this->saldo_pendiente = $this->total;
        }

        $this->save();
    }

    /**
     * Confirmar la venta
     * Nota: NO genera movimientos de inventario aquí
     * Los movimientos se generan al cerrar el viaje completo
     */
    public function confirmar(): bool
    {
        if (!$this->esBorrador()) {
            return false;
        }

        // Verificar que el viaje esté en ruta
        if (!$this->viaje->estaEnRuta()) {
            throw new \Exception('El viaje debe estar en ruta para confirmar ventas');
        }

        // Verificar stock disponible en la carga del viaje
        foreach ($this->detalles as $detalle) {
            $cargado = $this->viaje->cargas()
                ->where('producto_id', $detalle->producto_id)
                ->sum('cantidad_base');

            $vendido = $this->viaje->ventas()
                ->where('estado', 'confirmada')
                ->whereHas('detalles', function ($q) use ($detalle) {
                    $q->where('producto_id', $detalle->producto_id);
                })
                ->get()
                ->flatMap->detalles
                ->where('producto_id', $detalle->producto_id)
                ->sum('cantidad_base');

            $disponible = $cargado - $vendido;

            if ($disponible < $detalle->cantidad_base) {
                throw new \Exception(
                    "Stock insuficiente en el viaje para {$detalle->producto->nombre}. " .
                    "Disponible: {$disponible}, Requerido: {$detalle->cantidad_base}"
                );
            }
        }

        $this->update([
            'estado' => 'confirmada',
            'confirmada_por' => Auth::id(),
            'confirmada_at' => now(),
        ]);

        return true;
    }

    /**
     * Cancelar la venta
     */
    public function cancelar(): bool
    {
        $this->update(['estado' => 'cancelada']);
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

        return $this->fecha_venta->addDays($this->plazo_dias);
    }

    /**
     * Verificar si está vencida
     */
    public function estaVencida(): bool
    {
        if (!$this->esCredito() || $this->saldo_pendiente <= 0) {
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
     * Registrar un pago parcial
     */
    public function registrarPago(float $monto): bool
    {
        if (!$this->esCredito() || $this->saldo_pendiente <= 0) {
            return false;
        }

        $nuevoSaldo = max(0, $this->saldo_pendiente - $monto);

        $this->update([
            'saldo_pendiente' => $nuevoSaldo,
        ]);

        return true;
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
    }
}
