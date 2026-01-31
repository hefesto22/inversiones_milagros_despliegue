<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Cliente extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'clientes';

    protected $fillable = [
        'nombre',
        'rtn',
        'telefono',
        'direccion',
        'email',
        'tipo',
        'limite_credito',
        'saldo_pendiente',
        'dias_credito',
        'acepta_devolucion',
        'porcentaje_devolucion_max',
        'dias_devolucion',
        'notas_acuerdo',
        'estado',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'limite_credito' => 'decimal:2',
        'saldo_pendiente' => 'decimal:2',
        'dias_credito' => 'integer',
        'acepta_devolucion' => 'boolean',
        'porcentaje_devolucion_max' => 'decimal:2',
        'dias_devolucion' => 'integer',
        'estado' => 'boolean',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'cliente_id');
    }

    public function devoluciones(): HasMany
    {
        return $this->hasMany(Devolucion::class, 'cliente_id');
    }

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'cliente_producto')
            ->withPivot([
                'ultimo_precio_venta',
                'ultimo_precio_con_isv',
                'cantidad_ultima_venta',
                'fecha_ultima_venta',
                'total_ventas',
                'cantidad_total_vendida',
            ]);
    }

    public function preciosCliente(): HasMany
    {
        return $this->hasMany(ClienteProducto::class, 'cliente_id');
    }

    // ============================================
    // MÉTODOS DE CRÉDITO
    // ============================================

    /**
     * Verificar si el cliente puede comprar a crédito
     */
    public function puedeComprarCredito(float $montoVenta = 0): bool
    {
        if ($this->dias_credito <= 0) {
            return false;
        }

        if ($this->limite_credito <= 0) {
            return true;
        }

        $deudaProyectada = $this->saldo_pendiente + $montoVenta;
        return $deudaProyectada <= $this->limite_credito;
    }

    /**
     * Obtener crédito disponible
     */
    public function getCreditoDisponible(): float
    {
        if ($this->limite_credito <= 0) {
            return PHP_FLOAT_MAX;
        }

        return max(0, $this->limite_credito - $this->saldo_pendiente);
    }

    /**
     * Agregar deuda al saldo
     */
    public function agregarDeuda(float $monto): void
    {
        $this->saldo_pendiente += $monto;
        $this->save();
    }

    /**
     * Registrar pago (reducir deuda)
     */
    public function registrarPago(float $monto): void
    {
        $this->saldo_pendiente -= $monto;

        if ($this->saldo_pendiente < 0) {
            $this->saldo_pendiente = 0;
        }

        $this->save();
    }

    /**
     * Aplicar nota de crédito (reducir deuda)
     */
    public function aplicarNotaCredito(float $monto): void
    {
        $this->registrarPago($monto);
    }

    /**
     * Verificar si tiene deuda vencida
     */
    public function tieneDeudaVencida(): bool
    {
        return $this->ventas()
            ->where('estado_pago', '!=', 'pagado')
            ->where('fecha_vencimiento', '<', now())
            ->exists();
    }

    /**
     * Obtener total de deuda vencida
     */
    public function getDeudaVencida(): float
    {
        return $this->ventas()
            ->where('estado_pago', '!=', 'pagado')
            ->where('fecha_vencimiento', '<', now())
            ->sum('saldo_pendiente');
    }

    // ============================================
    // MÉTODOS DE DEVOLUCIONES
    // ============================================

    /**
     * Verificar si puede hacer devolución
     */
    public function puedeHacerDevolucion(): bool
    {
        return $this->acepta_devolucion && $this->dias_devolucion > 0;
    }

    /**
     * Verificar si una venta está dentro del plazo de devolución
     */
    public function dentroPlazoDevolución(Venta $venta): bool
    {
        if (!$this->acepta_devolucion) {
            return false;
        }

        $diasDesdeVenta = $venta->created_at->diffInDays(now());
        return $diasDesdeVenta <= $this->dias_devolucion;
    }

    // ============================================
    // MÉTODOS DE PRECIOS
    // ============================================

    /**
     * Obtener último precio de un producto para este cliente
     */
    public function getUltimoPrecio(int $productoId): ?array
    {
        $pivot = $this->productos()
            ->where('producto_id', $productoId)
            ->first();

        if (!$pivot) {
            return null;
        }

        return [
            'precio_sin_isv' => $pivot->pivot->ultimo_precio_venta,
            'precio_con_isv' => $pivot->pivot->ultimo_precio_con_isv,
            'cantidad' => $pivot->pivot->cantidad_ultima_venta,
            'fecha' => $pivot->pivot->fecha_ultima_venta,
        ];
    }

    /**
     * Actualizar último precio de venta
     */
    public function actualizarUltimoPrecio(
        int $productoId,
        float $precioSinIsv,
        ?float $precioConIsv,
        float $cantidad
    ): void {
        $existe = $this->productos()->where('producto_id', $productoId)->exists();

        if ($existe) {
            $this->productos()->updateExistingPivot($productoId, [
                'ultimo_precio_venta' => $precioSinIsv,
                'ultimo_precio_con_isv' => $precioConIsv,
                'cantidad_ultima_venta' => $cantidad,
                'fecha_ultima_venta' => now(),
                'total_ventas' => DB::raw('total_ventas + 1'),
                'cantidad_total_vendida' => DB::raw("cantidad_total_vendida + {$cantidad}"),
            ]);
        } else {
            $this->productos()->attach($productoId, [
                'ultimo_precio_venta' => $precioSinIsv,
                'ultimo_precio_con_isv' => $precioConIsv,
                'cantidad_ultima_venta' => $cantidad,
                'fecha_ultima_venta' => now(),
                'total_ventas' => 1,
                'cantidad_total_vendida' => $cantidad,
            ]);
        }
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    public function scopeConDeuda($query)
    {
        return $query->where('saldo_pendiente', '>', 0);
    }

    public function scopeConDeudaVencida($query)
    {
        return $query->whereHas('ventas', function ($q) {
            $q->where('estado_pago', '!=', 'pagado')
                ->where('fecha_vencimiento', '<', now());
        });
    }

    public function scopePorTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeMayoristas($query)
    {
        return $query->where('tipo', 'mayorista');
    }

    public function scopeMinoristas($query)
    {
        return $query->where('tipo', 'minorista');
    }

    public function scopeDistribuidores($query)
    {
        return $query->where('tipo', 'distribuidor');
    }

    public function scopeRuta($query)
    {
        return $query->where('tipo', 'ruta');
    }

    // ============================================
    // HELPERS
    // ============================================

    /**
     * Obtener resumen del cliente
     */
    public function getResumen(): array
    {
        return [
            'nombre' => $this->nombre,
            'tipo' => $this->tipo,
            'saldo_pendiente' => $this->saldo_pendiente,
            'limite_credito' => $this->limite_credito,
            'credito_disponible' => $this->getCreditoDisponible(),
            'puede_credito' => $this->puedeComprarCredito(),
            'tiene_deuda_vencida' => $this->tieneDeudaVencida(),
            'deuda_vencida' => $this->getDeudaVencida(),
            'acepta_devolucion' => $this->acepta_devolucion,
            'dias_devolucion' => $this->dias_devolucion,
        ];
    }

    /**
     * Obtener etiqueta de estado de crédito
     */
    public function getEtiquetaCredito(): string
    {
        if ($this->saldo_pendiente <= 0) {
            return 'Sin deuda';
        }

        if ($this->tieneDeudaVencida()) {
            return 'Deuda vencida';
        }

        if ($this->limite_credito > 0) {
            $porcentaje = ($this->saldo_pendiente / $this->limite_credito) * 100;

            if ($porcentaje >= 90) {
                return 'Crédito casi agotado';
            }

            if ($porcentaje >= 70) {
                return 'Crédito alto';
            }
        }

        return 'Con deuda';
    }
}
