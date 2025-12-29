<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Liquidacion extends Model
{
    use HasFactory;

    protected $table = 'liquidaciones';

    protected $fillable = [
        'numero_liquidacion',
        'chofer_id',
        'tipo_periodo',
        'fecha_inicio',
        'fecha_fin',
        'total_viajes',
        'total_ventas',
        'total_comisiones',
        'total_cobros',
        'saldo_anterior',
        'total_pagar',
        'estado',
        'fecha_pago',
        'metodo_pago',
        'referencia_pago',
        'created_by',
        'aprobado_por',
        'pagado_por',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'total_viajes' => 'integer',
        'total_ventas' => 'decimal:2',
        'total_comisiones' => 'decimal:2',
        'total_cobros' => 'decimal:2',
        'saldo_anterior' => 'decimal:2',
        'total_pagar' => 'decimal:2',
        'fecha_pago' => 'date',
    ];

    // Tipos de periodo
    public const PERIODO_SEMANAL = 'semanal';
    public const PERIODO_QUINCENAL = 'quincenal';
    public const PERIODO_MENSUAL = 'mensual';

    // Estados
    public const ESTADO_BORRADOR = 'borrador';
    public const ESTADO_APROBADA = 'aprobada';
    public const ESTADO_PAGADA = 'pagada';
    public const ESTADO_ANULADA = 'anulada';

    // ============================================
    // RELACIONES
    // ============================================

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chofer_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function pagadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pagado_por');
    }

    public function viajes(): HasMany
    {
        return $this->hasMany(LiquidacionViaje::class, 'liquidacion_id');
    }

    public function movimientosCuenta(): HasMany
    {
        return $this->hasMany(ChoferCuentaMovimiento::class, 'liquidacion_id');
    }

    // ============================================
    // MÉTODOS DE ESTADO
    // ============================================

    public function aprobar(): void
    {
        $this->estado = self::ESTADO_APROBADA;
        $this->aprobado_por = Auth::id();
        $this->save();
    }

    public function pagar(string $metodoPago, ?string $referencia = null): void
    {
        DB::transaction(function () use ($metodoPago, $referencia) {
            $this->estado = self::ESTADO_PAGADA;
            $this->fecha_pago = now();
            $this->metodo_pago = $metodoPago;
            $this->referencia_pago = $referencia;
            $this->pagado_por = Auth::id();
            $this->save();

            // Registrar movimiento en cuenta del chofer
            $cuenta = $this->chofer->getOrCreateCuenta();

            if ($this->total_pagar > 0) {
                $cuenta->pagarLiquidacion(
                    $this->total_pagar,
                    $this->id,
                    "Pago liquidación {$this->numero_liquidacion}"
                );
            }
        });
    }

    public function anular(?string $motivo = null): void
    {
        $this->estado = self::ESTADO_ANULADA;
        $this->save();
    }

    // ============================================
    // MÉTODOS DE VERIFICACIÓN
    // ============================================

    public function estaPendiente(): bool
    {
        return in_array($this->estado, [self::ESTADO_BORRADOR, self::ESTADO_APROBADA]);
    }

    public function estaPagada(): bool
    {
        return $this->estado === self::ESTADO_PAGADA;
    }

    public function estaAnulada(): bool
    {
        return $this->estado === self::ESTADO_ANULADA;
    }

    public function puedeEditar(): bool
    {
        return $this->estado === self::ESTADO_BORRADOR;
    }

    public function puedeAprobar(): bool
    {
        return $this->estado === self::ESTADO_BORRADOR;
    }

    public function puedePagar(): bool
    {
        return $this->estado === self::ESTADO_APROBADA;
    }

    public function puedeAnular(): bool
    {
        return in_array($this->estado, [self::ESTADO_BORRADOR, self::ESTADO_APROBADA]);
    }

    // ============================================
    // MÉTODOS DE CÁLCULO
    // ============================================

    /**
     * Recalcular totales desde los viajes
     */
    public function recalcular(): void
    {
        $this->total_viajes = $this->viajes()->count();
        $this->total_comisiones = $this->viajes()->sum('comision_viaje');
        $this->total_cobros = $this->viajes()->sum('cobros_viaje');

        // Obtener saldo anterior de la cuenta del chofer
        $cuenta = $this->chofer->cuenta;
        $this->saldo_anterior = $cuenta?->saldo ?? 0;

        // Calcular total a pagar
        $this->total_pagar = $this->total_comisiones - $this->total_cobros + $this->saldo_anterior;

        // Total de ventas
        $viajeIds = $this->viajes()->pluck('viaje_id');
        $this->total_ventas = Venta::whereIn('viaje_id', $viajeIds)
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->sum('total');

        $this->save();
    }

    /**
     * Agregar viaje a la liquidación
     */
    public function agregarViaje(Viaje $viaje): LiquidacionViaje
    {
        $liquidacionViaje = LiquidacionViaje::create([
            'liquidacion_id' => $this->id,
            'viaje_id' => $viaje->id,
            'comision_viaje' => $viaje->comision_ganada,
            'cobros_viaje' => $viaje->cobros_devoluciones,
            'neto_viaje' => $viaje->neto_chofer,
        ]);

        $this->recalcular();

        return $liquidacionViaje;
    }

    /**
     * Quitar viaje de la liquidación
     */
    public function quitarViaje(int $viajeId): void
    {
        $this->viajes()->where('viaje_id', $viajeId)->delete();
        $this->recalcular();
    }

    // ============================================
    // MÉTODOS DE CONSULTA
    // ============================================

    public function getResumen(): array
    {
        return [
            'numero' => $this->numero_liquidacion,
            'chofer' => $this->chofer->name,
            'periodo' => $this->tipo_periodo,
            'fecha_inicio' => $this->fecha_inicio->format('d/m/Y'),
            'fecha_fin' => $this->fecha_fin->format('d/m/Y'),
            'total_viajes' => $this->total_viajes,
            'total_ventas' => $this->total_ventas,
            'total_comisiones' => $this->total_comisiones,
            'total_cobros' => $this->total_cobros,
            'saldo_anterior' => $this->saldo_anterior,
            'total_pagar' => $this->total_pagar,
            'estado' => $this->estado,
        ];
    }

    public function getTipoPeriodoLabel(): string
    {
        return match ($this->tipo_periodo) {
            self::PERIODO_SEMANAL => 'Semanal',
            self::PERIODO_QUINCENAL => 'Quincenal',
            self::PERIODO_MENSUAL => 'Mensual',
            default => $this->tipo_periodo,
        };
    }

    public function getEstadoLabel(): string
    {
        return match ($this->estado) {
            self::ESTADO_BORRADOR => 'Borrador',
            self::ESTADO_APROBADA => 'Aprobada',
            self::ESTADO_PAGADA => 'Pagada',
            self::ESTADO_ANULADA => 'Anulada',
            default => $this->estado,
        };
    }

    public function getEstadoColor(): string
    {
        return match ($this->estado) {
            self::ESTADO_BORRADOR => 'gray',
            self::ESTADO_APROBADA => 'warning',
            self::ESTADO_PAGADA => 'success',
            self::ESTADO_ANULADA => 'danger',
            default => 'gray',
        };
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeDelChofer($query, int $choferId)
    {
        return $query->where('chofer_id', $choferId);
    }

    public function scopePendientes($query)
    {
        return $query->whereIn('estado', [self::ESTADO_BORRADOR, self::ESTADO_APROBADA]);
    }

    public function scopePagadas($query)
    {
        return $query->where('estado', self::ESTADO_PAGADA);
    }

    public function scopeDelPeriodo($query, $fechaInicio, $fechaFin)
    {
        return $query->whereBetween('fecha_inicio', [$fechaInicio, $fechaFin]);
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($liquidacion) {
            if (!$liquidacion->numero_liquidacion) {
                $liquidacion->numero_liquidacion = self::generarNumero();
            }

            if (!$liquidacion->estado) {
                $liquidacion->estado = self::ESTADO_BORRADOR;
            }
        });
    }

    protected static function generarNumero(): string
    {
        $anio = now()->format('Y');
        $ultimo = static::where('numero_liquidacion', 'like', "LIQ-{$anio}-%")
            ->orderBy('numero_liquidacion', 'desc')
            ->first();

        if ($ultimo) {
            $numero = (int) substr($ultimo->numero_liquidacion, -4) + 1;
        } else {
            $numero = 1;
        }

        return "LIQ-{$anio}-" . str_pad($numero, 4, '0', STR_PAD_LEFT);
    }
}
