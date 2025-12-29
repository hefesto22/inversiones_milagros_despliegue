<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class Devolucion extends Model
{
    use HasFactory;

    protected $table = 'devoluciones';

    protected $fillable = [
        'venta_id',
        'cliente_id',
        'bodega_id',
        'numero_devolucion',
        'tipo',
        'motivo',
        'descripcion_motivo',
        'subtotal',
        'total_isv',
        'total',
        'accion',
        'aplicado',
        'stock_reingresado',
        'estado',
        'aprobado_por',
        'fecha_aprobacion',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'total_isv' => 'decimal:2',
        'total' => 'decimal:2',
        'aplicado' => 'boolean',
        'stock_reingresado' => 'boolean',
        'fecha_aprobacion' => 'datetime',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DevolucionDetalle::class, 'devolucion_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
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
        $this->total = $this->subtotal + $this->total_isv;

        $this->save();
    }

    // ============================================
    // MÉTODOS DE ESTADO
    // ============================================

    /**
     * Aprobar la devolución
     */
    public function aprobar(): bool
    {
        if ($this->estado !== 'borrador') {
            return false;
        }

        $this->estado = 'aprobada';
        $this->aprobado_por = Auth::id();
        $this->fecha_aprobacion = now();
        $this->save();

        return true;
    }

    /**
     * Aplicar la devolución
     */
    public function aplicar(): bool
    {
        if ($this->estado !== 'aprobada' || $this->aplicado) {
            return false;
        }

        DB::transaction(function () {
            switch ($this->accion) {
                case 'reembolso_efectivo':
                    break;

                case 'credito_cuenta':
                    $this->cliente->aplicarNotaCredito($this->total);
                    break;

                case 'reposicion_producto':
                    break;
            }

            if (!$this->stock_reingresado) {
                foreach ($this->detalles as $detalle) {
                    if ($detalle->reingresa_stock) {
                        $bodegaProducto = BodegaProducto::where('bodega_id', $this->bodega_id)
                            ->where('producto_id', $detalle->producto_id)
                            ->first();

                        if ($bodegaProducto) {
                            $bodegaProducto->agregarStockSinCosto($detalle->cantidad);
                        }
                    }
                }

                $this->stock_reingresado = true;
            }

            $this->aplicado = true;
            $this->estado = 'aplicada';
            $this->save();
        });

        return true;
    }

    /**
     * Cancelar la devolución
     */
    public function cancelar(?string $motivo = null): bool
    {
        if ($this->estado === 'cancelada' || $this->aplicado) {
            return false;
        }

        $this->estado = 'cancelada';

        if ($motivo) {
            $this->descripcion_motivo = $this->descripcion_motivo
                ? $this->descripcion_motivo . "\n[CANCELADA] " . $motivo
                : "[CANCELADA] " . $motivo;
        }

        $this->save();

        return true;
    }

    // ============================================
    // GENERADORES
    // ============================================

    /**
     * Generar número de devolución único
     */
    public function generarNumeroDevolucion(): string
    {
        $prefijo = match ($this->tipo) {
            'devolucion' => 'DEV',
            'nota_credito' => 'NC',
            'reposicion' => 'REP',
            default => 'DEV',
        };

        $fecha = now()->format('ymd');

        $ultima = static::where('numero_devolucion', 'like', "{$prefijo}-{$fecha}%")
            ->orderBy('numero_devolucion', 'desc')
            ->first();

        if ($ultima) {
            $ultimoNumero = (int) substr($ultima->numero_devolucion, -4);
            $siguiente = $ultimoNumero + 1;
        } else {
            $siguiente = 1;
        }

        return "{$prefijo}-{$fecha}-" . str_pad($siguiente, 4, '0', STR_PAD_LEFT);
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopePendientes($query)
    {
        return $query->whereIn('estado', ['borrador', 'aprobada']);
    }

    public function scopeAplicadas($query)
    {
        return $query->where('estado', 'aplicada');
    }

    public function scopeDelCliente($query, int $clienteId)
    {
        return $query->where('cliente_id', $clienteId);
    }

    // ============================================
    // HELPERS
    // ============================================

    /**
     * Obtener etiqueta del tipo
     */
    public function getTipoLabel(): string
    {
        return match ($this->tipo) {
            'devolucion' => 'Devolución',
            'nota_credito' => 'Nota de Crédito',
            'reposicion' => 'Reposición',
            default => $this->tipo,
        };
    }

    /**
     * Obtener etiqueta del motivo
     */
    public function getMotivoLabel(): string
    {
        return match ($this->motivo) {
            'producto_danado_entrega' => 'Producto dañado en entrega',
            'producto_vencido' => 'Producto vencido',
            'error_pedido' => 'Error en pedido',
            'acuerdo_comercial' => 'Acuerdo comercial',
            'otro' => 'Otro',
            default => $this->motivo,
        };
    }

    /**
     * Obtener etiqueta de la acción
     */
    public function getAccionLabel(): string
    {
        return match ($this->accion) {
            'reembolso_efectivo' => 'Reembolso en efectivo',
            'credito_cuenta' => 'Crédito a cuenta',
            'reposicion_producto' => 'Reposición de producto',
            default => $this->accion,
        };
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($devolucion) {
            if (!$devolucion->numero_devolucion) {
                $devolucion->numero_devolucion = $devolucion->generarNumeroDevolucion();
            }

            if (!$devolucion->estado) {
                $devolucion->estado = 'borrador';
            }
        });
    }
}
