<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permiso puntual para hacerle una segunda venta de ruta a un cliente en el
 * mismo día, cuando la regla de "una factura por cliente al día" lo bloquea.
 *
 * Vale para UNA sola venta: al usarse queda consumida. Para una tercera venta
 * hay que autorizar de nuevo, a propósito — así el control sigue siendo un
 * control y no un permiso permanente.
 *
 * @see \App\Services\Viaje\LimiteVentaDiariaService
 */
class AutorizacionSegundaVenta extends Model
{
    use HasFactory;

    protected $table = 'autorizaciones_segunda_venta';

    protected $fillable = [
        'cliente_id',
        'fecha',
        'autorizado_por',
        'motivo',
        'consumida_en_viaje_venta_id',
        'consumida_at',
    ];

    protected $casts = [
        'fecha' => 'date',
        'consumida_at' => 'datetime',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }

    public function ventaQueLaConsumio(): BelongsTo
    {
        return $this->belongsTo(ViajeVenta::class, 'consumida_en_viaje_venta_id');
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Autorizaciones que todavía no se usaron.
     */
    public function scopeDisponibles(Builder $query): Builder
    {
        return $query->whereNull('consumida_at');
    }

    /**
     * Autorizaciones de un cliente para un día concreto.
     *
     * `fecha` es una columna DATE, así que se compara contra el string del día
     * (nada de whereDate, que anularía el índice).
     */
    public function scopeDelDia(Builder $query, int $clienteId, string $fecha): Builder
    {
        return $query->where('cliente_id', $clienteId)->where('fecha', $fecha);
    }

    // ============================================
    // MÉTODOS
    // ============================================

    public function estaDisponible(): bool
    {
        return $this->consumida_at === null;
    }

    /**
     * Marcar la autorización como usada por una venta concreta.
     *
     * Se llama DESPUÉS de crear la venta (necesita el id) y dentro de la misma
     * transacción, para que no quede consumida si la venta termina revertida.
     */
    public function consumir(ViajeVenta $venta): void
    {
        $this->update([
            'consumida_en_viaje_venta_id' => $venta->id,
            'consumida_at' => now(),
        ]);
    }
}
