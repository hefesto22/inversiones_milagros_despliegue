<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class MovimientoInventario extends Model
{
    use HasFactory;

    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'bodega_id',
        'producto_id',
        'tipo',
        'cantidad_base',
        'referencia_tipo',
        'referencia_id',
        'nota',
        'fecha',
        'user_id',
    ];

    protected $casts = [
        'cantidad_base' => 'decimal:3',
        'fecha' => 'datetime',
        'referencia_id' => 'integer',
    ];

    /**
     * Relación con bodega
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * Relación con producto
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * Relación con usuario que registró
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope para movimientos de entrada
     */
    public function scopeEntradas($query)
    {
        return $query->where('tipo', 'entrada');
    }

    /**
     * Scope para movimientos de salida
     */
    public function scopeSalidas($query)
    {
        return $query->where('tipo', 'salida');
    }

    /**
     * Scope para mermas
     */
    public function scopeMermas($query)
    {
        return $query->where('tipo', 'merma');
    }

    /**
     * Scope para ajustes
     */
    public function scopeAjustes($query)
    {
        return $query->where('tipo', 'ajuste');
    }

    /**
     * Scope por bodega
     */
    public function scopePorBodega($query, int $bodegaId)
    {
        return $query->where('bodega_id', $bodegaId);
    }

    /**
     * Scope por producto
     */
    public function scopePorProducto($query, int $productoId)
    {
        return $query->where('producto_id', $productoId);
    }

    /**
     * Scope por rango de fechas
     */
    public function scopeEntreFechas($query, $desde, $hasta)
    {
        return $query->whereBetween('fecha', [$desde, $hasta]);
    }

    /**
     * Scope por tipo de referencia
     */
    public function scopePorReferencia($query, string $tipo, ?int $id = null)
    {
        $query->where('referencia_tipo', $tipo);

        if ($id) {
            $query->where('referencia_id', $id);
        }

        return $query;
    }

    /**
     * Obtener el signo del movimiento para cálculos
     * Entrada y Ajuste positivo = +
     * Salida y Merma = -
     */
    public function getSignoAttribute(): int
    {
        return in_array($this->tipo, ['entrada', 'ajuste']) ? 1 : -1;
    }

    /**
     * Obtener la cantidad con signo
     */
    public function getCantidadConSignoAttribute(): float
    {
        return $this->cantidad_base * $this->signo;
    }

    /**
     * Verificar si es entrada
     */
    public function esEntrada(): bool
    {
        return $this->tipo === 'entrada';
    }

    /**
     * Verificar si es salida
     */
    public function esSalida(): bool
    {
        return $this->tipo === 'salida';
    }

    /**
     * Verificar si es merma
     */
    public function esMerma(): bool
    {
        return $this->tipo === 'merma';
    }

    /**
     * Verificar si es ajuste
     */
    public function esAjuste(): bool
    {
        return $this->tipo === 'ajuste';
    }

    /**
     * Obtener descripción formateada del movimiento
     */
    public function getDescripcionAttribute(): string
    {
        $tipo = ucfirst($this->tipo);
        $cantidad = number_format($this->cantidad_base, 3);
        $unidad = $this->producto->unidadBase->simbolo ?? '';

        return "{$tipo} de {$cantidad} {$unidad} - {$this->producto->nombre}";
    }

    /**
     * Boot del modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Asignar user_id automáticamente al crear
        static::creating(function ($movimiento) {
            if (Auth::check() && !$movimiento->user_id) {
                $movimiento->user_id = Auth::id();
            }
        });

        // Actualizar stock de bodega_producto después de crear
        static::created(function ($movimiento) {
            $movimiento->actualizarStock();
        });

        // Revertir stock antes de eliminar
        static::deleting(function ($movimiento) {
            $movimiento->revertirStock();
        });
    }

    /**
     * Actualizar el stock en bodega_producto
     */
    public function actualizarStock(): void
    {
        $bodegaProducto = DB::table('bodega_producto')
            ->where('bodega_id', $this->bodega_id)
            ->where('producto_id', $this->producto_id)
            ->first();

        if ($bodegaProducto) {
            DB::table('bodega_producto')
                ->where('bodega_id', $this->bodega_id)
                ->where('producto_id', $this->producto_id)
                ->increment('stock', $this->cantidad_con_signo);
        } else {

            // Si no existe la relación, crearla
            DB::table('bodega_producto')->insert([
                'bodega_id' => $this->bodega_id,
                'producto_id' => $this->producto_id,
                'stock' => max(0, $this->cantidad_con_signo),
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Revertir el movimiento del stock
     */
    public function revertirStock(): void
    {
        DB::table('bodega_producto')
            ->where('bodega_id', $this->bodega_id)
            ->where('producto_id', $this->producto_id)
            ->decrement('stock', $this->cantidad_con_signo);
    }

    /**
     * Calcular stock actual hasta esta fecha
     */
    public static function calcularStockHasta($bodegaId, $productoId, $fecha)
    {
        return static::where('bodega_id', $bodegaId)
            ->where('producto_id', $productoId)
            ->where('fecha', '<=', $fecha)
            ->get()
            ->sum('cantidad_con_signo');
    }
}
