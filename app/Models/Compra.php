<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Compra extends Model
{
    use HasFactory;

    protected $fillable = [
        'proveedor_id',
        'bodega_id',
        'fecha',
        'subtotal',
        'impuesto',
        'total',
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
    ];

    /**
     * Relación con proveedor
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * Relación con bodega
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * Relación con detalles de la compra
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(CompraDetalle::class);
    }

    /**
     * Usuario que creó la compra
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Usuario que confirmó la compra
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
            ->where('referencia_tipo', 'compra');
    }

    /**
     * Scope para compras en borrador
     */
    public function scopeBorradores($query)
    {
        return $query->where('estado', 'borrador');
    }

    /**
     * Scope para compras confirmadas
     */
    public function scopeConfirmadas($query)
    {
        return $query->where('estado', 'confirmada');
    }

    /**
     * Scope para compras canceladas
     */
    public function scopeCanceladas($query)
    {
        return $query->where('estado', 'cancelada');
    }

    /**
     * Scope por proveedor
     */
    public function scopePorProveedor($query, int $proveedorId)
    {
        return $query->where('proveedor_id', $proveedorId);
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
     * Calcular totales desde los detalles
     */
    public function calcularTotales(): void
    {
        $this->subtotal = $this->detalles->sum('total_linea');
        $this->impuesto = $this->subtotal * 0.15; // Ejemplo: 15% de impuesto
        $this->total = $this->subtotal + $this->impuesto;
        $this->save();
    }

    /**
     * Confirmar la compra y generar movimientos de inventario
     */
    public function confirmar(): bool
    {
        if (!$this->esBorrador()) {
            return false;
        }

        DB::beginTransaction();

        try {
            // Generar movimientos de inventario por cada detalle
            foreach ($this->detalles as $detalle) {
                MovimientoInventario::create([
                    'bodega_id' => $this->bodega_id,
                    'producto_id' => $detalle->producto_id,
                    'tipo' => 'entrada',
                    'cantidad_base' => $detalle->cantidad_base,
                    'referencia_tipo' => 'compra',
                    'referencia_id' => $this->id,
                    'nota' => "Compra #{$this->id} - {$this->proveedor->nombre}",
                    'fecha' => $this->fecha,
                    'user_id' => Auth::id(),
                ]);
            }

            // Actualizar estado de la compra
            $this->update([
                'estado' => 'confirmada',
                'confirmada_por' => Auth::id(),
                'confirmada_at' => now(),
            ]);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al confirmar compra: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cancelar la compra
     */
    public function cancelar(): bool
    {
        if ($this->estaConfirmada()) {
            // Si ya está confirmada, revertir movimientos
            $this->revertirMovimientos();
        }

        $this->update(['estado' => 'cancelada']);
        return true;
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
        static::creating(function ($compra) {
            if (Auth::check() && !$compra->user_id) {
                $compra->user_id = Auth::id();
            }
        });

        // Al eliminar una compra, eliminar sus detalles y movimientos
        static::deleting(function ($compra) {
            if ($compra->estaConfirmada()) {
                $compra->revertirMovimientos();
            }
        });
    }
}
