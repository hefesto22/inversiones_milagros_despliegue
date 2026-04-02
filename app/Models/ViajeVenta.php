<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class ViajeVenta extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'viaje_ventas';

    protected $fillable = [
        'viaje_id',
        'cliente_id',
        'numero_venta',
        'fecha_venta',
        'tipo_pago',
        'plazo_dias',
        'subtotal',
        'impuesto',
        'descuento',
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
        'plazo_dias' => 'integer',
        'subtotal' => 'decimal:2',
        'impuesto' => 'decimal:2',
        'descuento' => 'decimal:2',
        'total' => 'decimal:2',
        'saldo_pendiente' => 'decimal:2',
        'confirmada_at' => 'datetime',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class, 'viaje_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function userCreador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function confirmadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmada_por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(ViajeVentaDetalle::class, 'viaje_venta_id');
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeBorradores($query)
    {
        return $query->where('estado', 'borrador');
    }

    public function scopeConfirmadas($query)
    {
        return $query->where('estado', 'confirmada');
    }

    public function scopeCompletadas($query)
    {
        return $query->where('estado', 'completada');
    }

    public function scopeCanceladas($query)
    {
        return $query->where('estado', 'cancelada');
    }

    public function scopeContado($query)
    {
        return $query->where('tipo_pago', 'contado');
    }

    public function scopeCredito($query)
    {
        return $query->where('tipo_pago', 'credito');
    }

    public function scopePorViaje($query, int $viajeId)
    {
        return $query->where('viaje_id', $viajeId);
    }

    public function scopePorCliente($query, int $clienteId)
    {
        return $query->where('cliente_id', $clienteId);
    }

    // ============================================
    // MÉTODOS
    // ============================================

    /**
     * Generar número de venta único
     */
    public static function generarNumeroVenta(int $viajeId): string
    {
        // Buscar el último número usado para este viaje (incluyendo eliminados)
        $ultimaVenta = static::withTrashed()
            ->where('viaje_id', $viajeId)
            ->orderByRaw('CAST(SUBSTRING_INDEX(numero_venta, "-", -1) AS UNSIGNED) DESC')
            ->first();

        $secuencia = 1;
        
        if ($ultimaVenta && $ultimaVenta->numero_venta) {
            // Extraer el último número de la secuencia
            $partes = explode('-', $ultimaVenta->numero_venta);
            $ultimoNumero = (int) end($partes);
            $secuencia = $ultimoNumero + 1;
        }

        return 'VR-' . $viajeId . '-' . str_pad($secuencia, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Calcular totales basados en detalles
     */
    public function calcularTotales(): void
    {
        $this->subtotal = $this->detalles()->sum('subtotal');
        $this->impuesto = $this->detalles()->sum('total_isv');
        $totalBruto = $this->detalles()->sum('total_linea');
        $this->total = $totalBruto - $this->descuento;

        if ($this->estado === 'borrador') {
            $this->saldo_pendiente = $this->tipo_pago === 'credito' ? $this->total : 0;
        }

        $this->save();
    }

    /**
     * Confirmar venta.
     *
     * Además de verificar disponibilidad, actualiza `cantidad_vendida` en viaje_cargas
     * para que el inventario del viaje refleje correctamente lo que ya está comprometido.
     * Sin este paso, getCantidadDisponible() en ViajeCarga devuelve valores incorrectos.
     */
    public function confirmar(int $userId): void
    {
        if ($this->estado !== 'borrador') {
            throw new \Exception('Solo se pueden confirmar ventas en borrador');
        }

        // Verificar disponibilidad en el viaje (antes de confirmar)
        $this->verificarDisponibilidad();

        // Verificar crédito del cliente si es a crédito
        if ($this->tipo_pago === 'credito' && $this->cliente) {
            if (!$this->cliente->puedeComprarCredito($this->total)) {
                throw new \Exception('El cliente no tiene crédito disponible suficiente');
            }
        }

        $this->update([
            'estado' => 'confirmada',
            'confirmada_por' => $userId,
            'confirmada_at' => now(),
        ]);

        // FIX: Actualizar cantidad_vendida en viaje_cargas para cada producto vendido.
        // Esto mantiene el stock del viaje consistente y evita doble venta del mismo producto.
        $this->load('detalles');

        foreach ($this->detalles as $detalle) {
            $carga = $this->viaje->cargas()
                ->where('producto_id', $detalle->producto_id)
                ->first();

            if ($carga) {
                $carga->registrarVenta(floatval($detalle->cantidad));
            }
        }
    }

    /**
     * Cancelar venta.
     *
     * Si la venta estaba confirmada, revierte el cantidad_vendida en viaje_cargas
     * para liberar el stock del viaje que quedaba comprometido.
     */
    public function cancelar(): void
    {
        if ($this->estado === 'cancelada') {
            throw new \Exception('La venta ya está cancelada');
        }

        if ($this->viaje && $this->viaje->estado === 'cerrado') {
            throw new \Exception('No se puede cancelar una venta de un viaje cerrado');
        }

        // FIX: Si estaba confirmada, revertir el cantidad_vendida en viaje_cargas
        if (in_array($this->estado, ['confirmada', 'completada'])) {
            $this->load('detalles');

            foreach ($this->detalles as $detalle) {
                $carga = $this->viaje?->cargas()
                    ->where('producto_id', $detalle->producto_id)
                    ->first();

                if ($carga) {
                    // Revertir: reducir cantidad_vendida
                    $carga->cantidad_vendida = max(
                        0,
                        floatval($carga->cantidad_vendida) - floatval($detalle->cantidad)
                    );
                    $carga->saveQuietly(); // Evitar re-trigger del saving boot
                }
            }
        }

        $this->update(['estado' => 'cancelada']);
    }

    /**
     * Registrar cobro
     */
    public function registrarCobro(float $monto): void
    {
        if ($this->tipo_pago !== 'credito') {
            throw new \Exception('Solo se pueden registrar cobros en ventas a crédito');
        }

        if ($monto > $this->saldo_pendiente) {
            throw new \Exception('El monto excede el saldo pendiente');
        }

        $this->decrement('saldo_pendiente', $monto);
    }

    /**
     * Verificar disponibilidad de productos en el viaje
     */
    protected function verificarDisponibilidad(): void
    {
        foreach ($this->detalles as $detalle) {
            // Buscar la carga correspondiente
            $carga = $this->viaje->cargas()
                ->where('producto_id', $detalle->producto_id)
                ->first();

            if (!$carga) {
                throw new \Exception("El producto {$detalle->producto->nombre} no está cargado en este viaje");
            }

            // Verificar disponibilidad
            $disponible = $carga->getCantidadDisponible();

            if ($disponible < $detalle->cantidad) {
                throw new \Exception("Stock insuficiente de {$detalle->producto->nombre} en el viaje. Disponible: {$disponible}");
            }
        }
    }

    /**
     * Verificar si la venta está pagada
     */
    public function estaPagada(): bool
    {
        return $this->saldo_pendiente <= 0;
    }

    /**
     * Boot method para eventos
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($venta) {
            // Generar número de venta si no existe
            if (!$venta->numero_venta) {
                $venta->numero_venta = static::generarNumeroVenta($venta->viaje_id);
            }
        });
    }
}