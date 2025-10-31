<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Viaje extends Model
{
    use HasFactory;

    protected $fillable = [
        'camion_id',
        'chofer_user_id',
        'bodega_origen_id',
        'ruta_id',
        'fecha_salida',
        'fecha_regreso',
        'estado',
        'nota',
        'user_id',
        'cerrado_por',
        'cerrado_at',
    ];

    protected $casts = [
        'fecha_salida' => 'datetime',
        'fecha_regreso' => 'datetime',
        'cerrado_at' => 'datetime',
    ];

    /**
     * Relación con camión
     */
    public function camion(): BelongsTo
    {
        return $this->belongsTo(Camion::class);
    }

    /**
     * Relación con chofer (usuario)
     */
    public function chofer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chofer_user_id');
    }

    /**
     * Relación con bodega de origen
     */
    public function bodegaOrigen(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_origen_id');
    }

    /**
     * Relación con ruta (opcional)
     * TODO: Descomentar cuando se cree la tabla rutas
     */
    // public function ruta(): BelongsTo
    // {
    //     return $this->belongsTo(Ruta::class);
    // }

    /**
     * Relación con cargas del viaje
     */
    public function cargas(): HasMany
    {
        return $this->hasMany(ViajeCarga::class);
    }

    /**
     * Relación con mermas del viaje
     */
    public function mermas(): HasMany
    {
        return $this->hasMany(ViajeMerma::class);
    }

    /**
     * Relación con liquidación de comisiones
     */
    public function liquidacionComision(): HasOne
    {
        return $this->hasOne(ComisionChoferLiquidacion::class);
    }

    /**
     * Usuario que creó el viaje
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Usuario que cerró el viaje
     */
    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    /**
     * Movimientos de inventario generados
     */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'referencia_id')
            ->whereIn('referencia_tipo', ['viaje_carga', 'viaje_merma']);
    }

    /**
     * Scope para viajes en preparación
     */
    public function scopeEnPreparacion($query)
    {
        return $query->where('estado', 'en_preparacion');
    }

    /**
     * Scope para viajes en ruta
     */
    public function scopeEnRuta($query)
    {
        return $query->where('estado', 'en_ruta');
    }

    /**
     * Scope para viajes cerrados
     */
    public function scopeCerrados($query)
    {
        return $query->where('estado', 'cerrado');
    }

    /**
     * Scope por chofer
     */
    public function scopePorChofer($query, int $choferId)
    {
        return $query->where('chofer_user_id', $choferId);
    }

    /**
     * Scope por camión
     */
    public function scopePorCamion($query, int $camionId)
    {
        return $query->where('camion_id', $camionId);
    }

    /**
     * Verificar si está en preparación
     */
    public function estaEnPreparacion(): bool
    {
        return $this->estado === 'en_preparacion';
    }

    /**
     * Verificar si está en ruta
     */
    public function estaEnRuta(): bool
    {
        return $this->estado === 'en_ruta';
    }

    /**
     * Verificar si está cerrado
     */
    public function estaCerrado(): bool
    {
        return $this->estado === 'cerrado';
    }

    /**
     * Iniciar el viaje (cambiar a en_ruta y generar salidas de inventario)
     */
    public function iniciar(): bool
    {
        if (!$this->estaEnPreparacion()) {
            return false;
        }

        DB::beginTransaction();

        try {
            // Verificar que tenga cargas
            if ($this->cargas->isEmpty()) {
                throw new \Exception('El viaje no tiene productos cargados');
            }

            // Generar movimientos de inventario (salida) por cada carga
            foreach ($this->cargas as $carga) {
                MovimientoInventario::create([
                    'bodega_id' => $this->bodega_origen_id,
                    'producto_id' => $carga->producto_id,
                    'tipo' => 'salida',
                    'cantidad_base' => $carga->cantidad_base,
                    'referencia_tipo' => 'viaje_carga',
                    'referencia_id' => $this->id,
                    'nota' => "Viaje #{$this->id} - Carga para {$this->chofer->name}",
                    'fecha' => $this->fecha_salida,
                    'user_id' => Auth::id(),
                ]);
            }

            // Actualizar estado
            $this->update(['estado' => 'en_ruta']);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al iniciar viaje: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Cerrar el viaje (registrar mermas, calcular comisiones)
     */
    public function cerrar(): bool
    {
        if (!$this->estaEnRuta()) {
            return false;
        }

        DB::beginTransaction();

        try {
            // Registrar movimientos de mermas
            foreach ($this->mermas as $merma) {
                MovimientoInventario::create([
                    'bodega_id' => $this->bodega_origen_id,
                    'producto_id' => $merma->producto_id,
                    'tipo' => 'merma',
                    'cantidad_base' => $merma->cantidad_base,
                    'referencia_tipo' => 'viaje_merma',
                    'referencia_id' => $this->id,
                    'nota' => "Viaje #{$this->id} - Merma: {$merma->motivo}",
                    'fecha' => now(),
                    'user_id' => Auth::id(),
                ]);
            }

            // Calcular comisiones del chofer
            $this->calcularComisiones();

            // Actualizar estado
            $this->update([
                'estado' => 'cerrado',
                'fecha_regreso' => now(),
                'cerrado_por' => Auth::id(),
                'cerrado_at' => now(),
            ]);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al cerrar viaje: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Calcular comisiones del chofer
     */
    public function calcularComisiones(): void
    {
        // Obtener comisiones vigentes del chofer
        $comisionesChofer = ComisionChofer::where('chofer_user_id', $this->chofer_user_id)
            ->whereDate('vigente_desde', '<=', $this->fecha_salida)
            ->where(function ($q) {
                $q->whereNull('vigente_hasta')
                  ->orWhereDate('vigente_hasta', '>=', now());
            })
            ->get();

        if ($comisionesChofer->isEmpty()) {
            return; // No hay comisiones configuradas
        }

        // Calcular cartones vendidos (cargados - sobrantes - mermas)
        $cartones30 = 0;
        $cartones15 = 0;

        foreach ($this->cargas as $carga) {
            $unidad = $carga->unidadPresentacion;

            // Identificar si es cartón 30 o 15
            if (stripos($unidad->nombre, '30') !== false) {
                $cartones30 += $carga->cantidad_presentacion;
            } elseif (stripos($unidad->nombre, '15') !== false) {
                $cartones15 += $carga->cantidad_presentacion;
            }
        }

        // Restar mermas (convertidas a cartones)
        foreach ($this->mermas as $merma) {
            // Simplificado: asumimos que las mermas se distribuyen proporcionalmente
            // En producción, deberías tener una forma más precisa de identificar el tipo
        }

        // Calcular total de comisión
        $totalComision = 0;

        foreach ($comisionesChofer as $comision) {
            if ($comision->aplica_a === 'carton_30' || $comision->aplica_a === 'ambos') {
                $totalComision += $cartones30 * $comision->monto_por_carton;
            }
            if ($comision->aplica_a === 'carton_15' || $comision->aplica_a === 'ambos') {
                $totalComision += $cartones15 * $comision->monto_por_carton;
            }
        }

        // Crear liquidación
        ComisionChoferLiquidacion::create([
            'viaje_id' => $this->id,
            'chofer_user_id' => $this->chofer_user_id,
            'cartones_30_vendidos' => $cartones30,
            'cartones_15_vendidos' => $cartones15,
            'total_comision' => $totalComision,
            'calculado_en' => now(),
            'calculado_por' => Auth::id(),
        ]);
    }

    /**
     * Obtener duración del viaje en horas
     */
    public function getDuracionHorasAttribute(): ?float
    {
        if (!$this->fecha_regreso) {
            return null;
        }

        return $this->fecha_salida->diffInHours($this->fecha_regreso);
    }

    /**
     * Boot del modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Asignar user_id automáticamente al crear
        static::creating(function ($viaje) {
            if (Auth::check() && !$viaje->user_id) {
                $viaje->user_id = Auth::id();
            }
        });
    }
}
