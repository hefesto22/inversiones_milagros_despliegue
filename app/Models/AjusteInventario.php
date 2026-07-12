<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AjusteEstado;
use App\Enums\AjusteMotivo;
use App\Enums\AjusteTipoMovimiento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ajuste de Inventario por conteo físico.
 *
 * Captura diferencias entre saldo en sistema y físico real con trazabilidad
 * completa: motivo, valoración contable, aprobación dual, evidencia.
 *
 * Una reclasificación entre productos crea DOS registros vinculados vía
 * ajuste_pareja_id (uno SalidaReclasificacion + uno EntradaReclasificacion).
 *
 * Una merma residual o corrección crea UN solo registro.
 *
 * INMUTABILIDAD: registros en estado Aplicado no se pueden modificar ni
 * borrar (enforced por Policy). Para corregir, se crea un nuevo ajuste
 * de tipo AjusteCorreccion que documenta el cambio.
 */
class AjusteInventario extends Model
{
    use HasFactory;

    protected $table = 'ajustes_inventario';

    protected $fillable = [
        'lote_id',
        'bodega_producto_id',
        'producto_id',
        'bodega_id',
        'tipo_movimiento',
        'motivo',
        'ajuste_pareja_id',
        'huevos_antes',
        'huevos_despues',
        'delta_huevos',
        'costo_unitario_aplicado',
        'valor_contable_afectado',
        'descripcion',
        'evidencia_path',
        'estado',
        'requiere_aprobacion',
        'aprobado_por',
        'aprobado_en',
        'rechazado_por',
        'rechazado_en',
        'motivo_rechazo',
        'aplicado_por',
        'aplicado_en',
        'created_by',
    ];

    protected $casts = [
        'tipo_movimiento'         => AjusteTipoMovimiento::class,
        'motivo'                  => AjusteMotivo::class,
        'estado'                  => AjusteEstado::class,
        'requiere_aprobacion'     => 'boolean',
        'huevos_antes'            => 'decimal:2',
        'huevos_despues'          => 'decimal:2',
        'delta_huevos'            => 'decimal:2',
        'costo_unitario_aplicado' => 'decimal:6',
        'valor_contable_afectado' => 'decimal:2',
        'aprobado_en'             => 'datetime',
        'rechazado_en'            => 'datetime',
        'aplicado_en'             => 'datetime',
    ];

    // ============================================================
    // RELACIONES
    // ============================================================

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_id');
    }

    public function bodegaProducto(): BelongsTo
    {
        return $this->belongsTo(BodegaProducto::class, 'bodega_producto_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    /**
     * Si este ajuste es parte de una reclasificación, apunta al "otro"
     * registro vinculado (salida ↔ entrada).
     */
    public function pareja(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ajuste_pareja_id');
    }

    /**
     * Relación inversa de pareja — útil cuando el otro ajuste apunta a este.
     */
    public function conPareja(): HasOne
    {
        return $this->hasOne(self::class, 'ajuste_pareja_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function rechazador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rechazado_por');
    }

    public function aplicador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aplicado_por');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeBorrador(Builder $query): Builder
    {
        return $query->where('estado', AjusteEstado::Borrador);
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', AjusteEstado::PendienteAprobacion);
    }

    public function scopeAprobados(Builder $query): Builder
    {
        return $query->where('estado', AjusteEstado::Aprobado);
    }

    public function scopeAplicados(Builder $query): Builder
    {
        return $query->where('estado', AjusteEstado::Aplicado);
    }

    public function scopeRechazados(Builder $query): Builder
    {
        return $query->where('estado', AjusteEstado::Rechazado);
    }

    public function scopeEnBodega(Builder $query, int $bodegaId): Builder
    {
        return $query->where('bodega_id', $bodegaId);
    }

    public function scopeDeProducto(Builder $query, int $productoId): Builder
    {
        return $query->where('producto_id', $productoId);
    }

    public function scopeDeLote(Builder $query, int $loteId): Builder
    {
        return $query->where('lote_id', $loteId);
    }

    public function scopeReclasificaciones(Builder $query): Builder
    {
        return $query->whereIn('tipo_movimiento', [
            AjusteTipoMovimiento::SalidaReclasificacion,
            AjusteTipoMovimiento::EntradaReclasificacion,
        ]);
    }

    public function scopeMermasResiduales(Builder $query): Builder
    {
        return $query->where('tipo_movimiento', AjusteTipoMovimiento::MermaResidual);
    }

    // ============================================================
    // ACCESSORS / HELPERS
    // ============================================================

    /**
     * ¿El ajuste todavía es modificable (estado Borrador)?
     */
    public function esModificable(): bool
    {
        return $this->estado === AjusteEstado::Borrador;
    }

    /**
     * ¿El ajuste ya está terminado (Aplicado o Rechazado)?
     */
    public function esTerminal(): bool
    {
        return in_array($this->estado, [AjusteEstado::Rechazado, AjusteEstado::Aplicado], true);
    }

    /**
     * ¿El ajuste se aplicó exitosamente al lote/bodega_producto?
     */
    public function fueAplicado(): bool
    {
        return $this->estado === AjusteEstado::Aplicado;
    }

    /**
     * ¿Es parte de una reclasificación entre productos?
     */
    public function esReclasificacion(): bool
    {
        return in_array($this->tipo_movimiento, [
            AjusteTipoMovimiento::SalidaReclasificacion,
            AjusteTipoMovimiento::EntradaReclasificacion,
        ], true);
    }

    /**
     * Cartones equivalentes 1x30 del movimiento.
     */
    public function getCartonesEquivAttribute(): float
    {
        return round((float) $this->delta_huevos / 30, 4);
    }

    /**
     * Etiqueta corta del movimiento para reportes.
     */
    public function getResumenAttribute(): string
    {
        $signo = $this->delta_huevos >= 0 ? '+' : '';
        return "{$signo}{$this->delta_huevos} huevos ({$this->tipo_movimiento->label()})";
    }

    /**
     * Generador de número de referencia del ajuste, semejante a Merma.
     * Formato: AJ-B{bodega_id}-{secuencial 6 dígitos}
     */
    public static function generarNumeroReferencia(int $bodegaId): string
    {
        $ultimo = self::where('bodega_id', $bodegaId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('id');

        $secuencial = $ultimo ? ($ultimo + 1) : 1;
        $formato = str_pad((string) $secuencial, 6, '0', STR_PAD_LEFT);

        return "AJ-B{$bodegaId}-{$formato}";
    }
}
