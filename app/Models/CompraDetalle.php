<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CompraDetalle extends Model
{
    use HasFactory;

    protected $table = 'compra_detalles';

    protected $fillable = [
        'compra_id',
        'producto_id',
        'unidad_id',
        'cantidad_facturada',
        'cantidad_regalo',
        'cantidad_recibida',
        'precio_unitario',
        'precio_con_isv',      // 🆕 Precio con ISV incluido
        'costo_sin_isv',       // 🆕 Costo real sin ISV (precio / 1.15)
        'isv_credito',         // 🆕 ISV crédito fiscal
        'descuento',
        'impuesto',
    ];

    protected $casts = [
        'cantidad_facturada' => 'decimal:3',
        'cantidad_regalo' => 'decimal:3',
        'cantidad_recibida' => 'decimal:3',
        'precio_unitario' => 'decimal:4',
        'precio_con_isv' => 'decimal:4',    // 🆕
        'costo_sin_isv' => 'decimal:4',     // 🆕
        'isv_credito' => 'decimal:4',       // 🆕
        'descuento' => 'decimal:2',
        'impuesto' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_id');
    }

    public function lote(): HasOne
    {
        return $this->hasOne(Lote::class, 'compra_detalle_id');
    }

    // ============================================
    // MÉTODOS DE CÁLCULO
    // ============================================

    /**
     * Calcula el subtotal de la línea
     * Fórmula: (cantidad * precio_unitario) - descuento + impuesto
     */
    public function calcularSubtotal(): float
    {
        $base = ($this->cantidad_facturada ?? 0) * ($this->precio_unitario ?? 0);
        $descuento = $this->descuento ?? 0;
        $impuesto = $this->impuesto ?? 0;

        return $base - $descuento + $impuesto;
    }

    /**
     * Calcula el costo real por unidad considerando los regalos
     * Costo real = Total pagado / Cantidad total recibida
     * 🆕 Si tiene ISV, usa costo_sin_isv
     */
    public function getCostoRealUnitario(): float
    {
        if (($this->cantidad_recibida ?? 0) <= 0) {
            // Si tiene costo_sin_isv, usarlo
            if (!empty($this->costo_sin_isv) && $this->costo_sin_isv > 0) {
                return (float) $this->costo_sin_isv;
            }
            return $this->precio_unitario ?? 0;
        }

        // Si tiene costo_sin_isv, calcular basado en ese
        if (!empty($this->costo_sin_isv) && $this->costo_sin_isv > 0) {
            $totalPagado = ($this->cantidad_facturada ?? 0) * $this->costo_sin_isv;
            return $totalPagado / $this->cantidad_recibida;
        }

        $totalPagado = $this->calcularSubtotal();
        return $totalPagado / $this->cantidad_recibida;
    }

    /**
     * 🆕 Verificar si este detalle tiene ISV
     */
    public function tieneIsv(): bool
    {
        return !empty($this->costo_sin_isv) && $this->costo_sin_isv > 0;
    }

    /**
     * 🆕 Obtener el ISV total de esta línea (isv_credito * cantidad_facturada)
     */
    public function getIsvTotalLinea(): float
    {
        if (!$this->tieneIsv()) {
            return 0;
        }

        return ($this->isv_credito ?? 0) * ($this->cantidad_facturada ?? 0);
    }

    /**
     * Valida que cantidad_recibida >= cantidad_facturada
     */
    public function validarCantidades(): bool
    {
        $facturada = $this->cantidad_facturada ?? 0;
        $regalo = $this->cantidad_regalo ?? 0;
        $recibida = $this->cantidad_recibida ?? 0;

        // cantidad_recibida debe ser igual a facturada + regalo
        return abs($recibida - ($facturada + $regalo)) < 0.001;
    }

    // ============================================
    // EVENTOS (Boot)
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($detalle) {
            // Calcular cantidad_recibida automáticamente si no está definida
            if (is_null($detalle->cantidad_recibida)) {
                $detalle->cantidad_recibida = ($detalle->cantidad_facturada ?? 0) + ($detalle->cantidad_regalo ?? 0);
            }

            // Calcular subtotal automáticamente antes de guardar
            $detalle->subtotal = $detalle->calcularSubtotal();
        });

        static::saved(function ($detalle) {
            // Actualizar total de la compra
            if ($detalle->compra) {
                $detalle->compra->recalcularTotal();
            }
        });

        static::deleted(function ($detalle) {
            // Recalcular total después de eliminar una línea
            if ($detalle->compra) {
                $detalle->compra->recalcularTotal();
            }
        });
    }
}
