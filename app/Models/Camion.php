<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;


class Camion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'camiones';

    protected $fillable = [
        'codigo',
        'placa',
        'marca',
        'modelo',
        'anio',
        'bodega_id',
        'capacidad_cartones',
        'capacidad_peso_kg',
        'activo',
        'observaciones',
        'ultimo_mantenimiento',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'anio' => 'integer',
        'capacidad_cartones' => 'integer',
        'capacidad_peso_kg' => 'decimal:2',
        'activo' => 'boolean',
        'ultimo_mantenimiento' => 'date',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function asignacionesChofer(): HasMany
    {
        return $this->hasMany(CamionChofer::class, 'camion_id');
    }

    public function asignacionActiva(): HasOne
    {
        return $this->hasOne(CamionChofer::class, 'camion_id')
            ->where('activo', true)
            ->whereNull('fecha_fin');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(CamionProducto::class, 'camion_id');
    }

    public function viajes(): HasMany
    {
        return $this->hasMany(Viaje::class, 'camion_id');
    }

    // ============================================
    // MÉTODOS
    // ============================================

    public function getChoferActual(): ?User
    {
        return $this->asignacionActiva?->chofer;
    }

    public function tieneChoferAsignado(): bool
    {
        return $this->asignacionActiva !== null;
    }

    public function tieneViajeActivo(): bool
    {
        return $this->viajes()
            ->whereNotIn('estado', ['cerrado', 'cancelado'])
            ->exists();
    }

    public function getViajeActivo(): ?Viaje
    {
        return $this->viajes()
            ->whereNotIn('estado', ['cerrado', 'cancelado'])
            ->first();
    }

    public function getStockProducto(int $productoId): float
    {
        $camionProducto = $this->productos()
            ->where('producto_id', $productoId)
            ->first();

        return $camionProducto?->stock ?? 0;
    }

    public function getTotalProductosEnCamion(): int
    {
        return $this->productos()->where('stock', '>', 0)->count();
    }

    public function getValorInventario(): float
    {
        return $this->productos()->sum(DB::raw('stock * costo_promedio'));
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeDeBodega($query, int $bodegaId)
    {
        return $query->where('bodega_id', $bodegaId);
    }

    public function scopeDisponibles($query)
    {
        return $query->where('activo', true)
            ->whereDoesntHave('viajes', function ($q) {
                $q->whereNotIn('estado', ['cerrado', 'cancelado']);
            });
    }

    public function scopeConChofer($query)
    {
        return $query->whereHas('asignacionActiva');
    }

    public function scopeSinChofer($query)
    {
        return $query->whereDoesntHave('asignacionActiva');
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($camion) {
            if (!$camion->codigo) {
                $camion->codigo = self::generarCodigo();
            }
        });
    }

    protected static function generarCodigo(): string
    {
        $ultimo = static::withTrashed()
            ->where('codigo', 'like', 'CAM-%')
            ->orderBy('codigo', 'desc')
            ->first();

        if ($ultimo) {
            $numero = (int) substr($ultimo->codigo, 4) + 1;
        } else {
            $numero = 1;
        }

        return 'CAM-' . str_pad($numero, 3, '0', STR_PAD_LEFT);
    }
}
