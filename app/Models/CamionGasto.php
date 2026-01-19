<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CamionGasto extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'camion_gastos';

    protected $fillable = [
        'camion_id',
        'chofer_id',
        'viaje_id',
        'tipo_gasto',
        'fecha',
        'monto',
        'descripcion',
        'litros',
        'precio_por_litro',
        'kilometraje',
        'proveedor',
        'tiene_factura',
        'enviado_whatsapp',
        'enviado_whatsapp_at',
        'estado',
        'motivo_rechazo',
        'aprobado_por',
        'aprobado_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
        'litros' => 'decimal:3',
        'precio_por_litro' => 'decimal:2',
        'kilometraje' => 'decimal:2',
        'tiene_factura' => 'boolean',
        'enviado_whatsapp' => 'boolean',
        'enviado_whatsapp_at' => 'datetime',
        'aprobado_at' => 'datetime',
    ];

    // =====================================================
    // CONSTANTES - TIPOS DE GASTO
    // =====================================================

    public const TIPOS_GASTO = [
        'gasolina' => '⛽ Gasolina',
        'mantenimiento' => '🔧 Mantenimiento',
        'reparacion' => '🛠️ Reparación',
        'peaje' => '🛣️ Peaje',
        'viaticos' => '🍔 Viáticos',
        'lavado' => '🚿 Lavado',
        'otros' => '📦 Otros',
    ];

    public const ESTADOS = [
        'pendiente' => 'Pendiente',
        'aprobado' => 'Aprobado',
        'rechazado' => 'Rechazado',
    ];

    // =====================================================
    // RELACIONES
    // =====================================================

    public function camion(): BelongsTo
    {
        return $this->belongsTo(Camion::class, 'camion_id');
    }

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chofer_id');
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class, 'viaje_id');
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

    // =====================================================
    // ACCESSORS
    // =====================================================

    /**
     * Obtener el label del tipo de gasto con emoji
     */
    public function getTipoGastoLabelAttribute(): string
    {
        return self::TIPOS_GASTO[$this->tipo_gasto] ?? $this->tipo_gasto;
    }

    /**
     * Obtener el label del estado
     */
    public function getEstadoLabelAttribute(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    /**
     * Verificar si es gasto de gasolina
     */
    public function getEsGasolinaAttribute(): bool
    {
        return $this->tipo_gasto === 'gasolina';
    }

    // =====================================================
    // MÉTODOS DE WHATSAPP
    // =====================================================

    /**
     * Generar mensaje para WhatsApp
     */
    public function generarMensajeWhatsApp(): string
    {
        $camion = $this->camion;
        $chofer = $this->chofer;

        $mensaje = "🧾 *GASTO REGISTRADO*\n\n";
        $mensaje .= "🚛 *Camión:* {$camion->codigo} ({$camion->placa})\n";
        $mensaje .= "👤 *Chofer:* {$chofer->name}\n";
        $mensaje .= "📅 *Fecha:* {$this->fecha->format('d/m/Y')} {$this->created_at->format('h:i a')}\n\n";
        $mensaje .= "{$this->tipo_gasto_label}\n";
        $mensaje .= "💰 *Monto:* L " . number_format($this->monto, 2) . "\n";

        if ($this->es_gasolina && $this->litros) {
            $mensaje .= "⛽ *Litros:* {$this->litros}\n";
            if ($this->precio_por_litro) {
                $mensaje .= "💲 *Precio/Litro:* L " . number_format($this->precio_por_litro, 2) . "\n";
            }
        }

        if ($this->kilometraje) {
            $mensaje .= "🛣️ *Kilometraje:* " . number_format($this->kilometraje, 0) . " km\n";
        }

        if ($this->proveedor) {
            $mensaje .= "🏪 *Proveedor:* {$this->proveedor}\n";
        }

        if ($this->descripcion) {
            $mensaje .= "\n📝 *Nota:* {$this->descripcion}\n";
        }

        $mensaje .= "\n📎 *Adjuntar foto de factura/comprobante*";

        return $mensaje;
    }

    /**
     * Generar URL de WhatsApp con mensaje prellenado
     * 
     * @param string|null $numeroGrupo Link del grupo o número de WhatsApp
     */
    public function generarUrlWhatsApp(?string $numeroGrupo = null): string
    {
        $mensaje = urlencode($this->generarMensajeWhatsApp());

        // Si es un link de grupo
        if ($numeroGrupo && str_contains($numeroGrupo, 'chat.whatsapp.com')) {
            // Para grupos no se puede prellenar mensaje, solo abrir el grupo
            return $numeroGrupo;
        }

        // Si es un número directo
        if ($numeroGrupo) {
            $numero = preg_replace('/[^0-9]/', '', $numeroGrupo);
            return "https://wa.me/{$numero}?text={$mensaje}";
        }

        // Sin número, solo abre WhatsApp con el mensaje
        return "https://wa.me/?text={$mensaje}";
    }

    /**
     * Marcar como enviado por WhatsApp
     */
    public function marcarEnviadoWhatsApp(): void
    {
        $this->update([
            'enviado_whatsapp' => true,
            'enviado_whatsapp_at' => now(),
        ]);
    }

    // =====================================================
    // MÉTODOS DE ESTADO
    // =====================================================

    /**
     * Aprobar el gasto
     */
    public function aprobar(int $aprobadorId): void
    {
        $this->update([
            'estado' => 'aprobado',
            'aprobado_por' => $aprobadorId,
            'aprobado_at' => now(),
            'motivo_rechazo' => null,
        ]);
    }

    /**
     * Rechazar el gasto
     */
    public function rechazar(int $aprobadorId, string $motivo): void
    {
        $this->update([
            'estado' => 'rechazado',
            'aprobado_por' => $aprobadorId,
            'aprobado_at' => now(),
            'motivo_rechazo' => $motivo,
        ]);
    }

    /**
     * Verificar si está pendiente
     */
    public function estaPendiente(): bool
    {
        return $this->estado === 'pendiente';
    }

    /**
     * Verificar si está aprobado
     */
    public function estaAprobado(): bool
    {
        return $this->estado === 'aprobado';
    }

    /**
     * Verificar si está rechazado
     */
    public function estaRechazado(): bool
    {
        return $this->estado === 'rechazado';
    }

    // =====================================================
    // SCOPES
    // =====================================================

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }

    public function scopeAprobados($query)
    {
        return $query->where('estado', 'aprobado');
    }

    public function scopeRechazados($query)
    {
        return $query->where('estado', 'rechazado');
    }

    public function scopeDelCamion($query, int $camionId)
    {
        return $query->where('camion_id', $camionId);
    }

    public function scopeDelChofer($query, int $choferId)
    {
        return $query->where('chofer_id', $choferId);
    }

    public function scopeDelMes($query, int $anio, int $mes)
    {
        return $query->whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes);
    }

    public function scopeTipoGasolina($query)
    {
        return $query->where('tipo_gasto', 'gasolina');
    }

    public function scopeEntreFechas($query, $desde, $hasta)
    {
        return $query->whereBetween('fecha', [$desde, $hasta]);
    }

    // =====================================================
    // MÉTODOS ESTÁTICOS PARA REPORTES
    // =====================================================

    /**
     * Obtener total de gastos por camión en un período
     */
    public static function totalPorCamion(int $camionId, ?string $desde = null, ?string $hasta = null): float
    {
        $query = self::where('camion_id', $camionId)
            ->where('estado', 'aprobado');

        if ($desde && $hasta) {
            $query->whereBetween('fecha', [$desde, $hasta]);
        }

        return $query->sum('monto');
    }

    /**
     * Obtener total de gastos por tipo en un período
     */
    public static function totalPorTipo(int $camionId, string $tipo, ?string $desde = null, ?string $hasta = null): float
    {
        $query = self::where('camion_id', $camionId)
            ->where('tipo_gasto', $tipo)
            ->where('estado', 'aprobado');

        if ($desde && $hasta) {
            $query->whereBetween('fecha', [$desde, $hasta]);
        }

        return $query->sum('monto');
    }

    /**
     * Obtener resumen de gastos por camión
     */
    public static function resumenPorCamion(int $camionId, ?string $desde = null, ?string $hasta = null): array
    {
        $query = self::where('camion_id', $camionId)
            ->where('estado', 'aprobado');

        if ($desde && $hasta) {
            $query->whereBetween('fecha', [$desde, $hasta]);
        }

        $gastos = $query->get();

        $resumen = [
            'total' => 0,
            'por_tipo' => [],
            'total_litros' => 0,
            'cantidad_gastos' => $gastos->count(),
        ];

        foreach (self::TIPOS_GASTO as $tipo => $label) {
            $resumen['por_tipo'][$tipo] = [
                'label' => $label,
                'total' => 0,
                'cantidad' => 0,
            ];
        }

        foreach ($gastos as $gasto) {
            $resumen['total'] += $gasto->monto;
            $resumen['por_tipo'][$gasto->tipo_gasto]['total'] += $gasto->monto;
            $resumen['por_tipo'][$gasto->tipo_gasto]['cantidad']++;

            if ($gasto->tipo_gasto === 'gasolina' && $gasto->litros) {
                $resumen['total_litros'] += $gasto->litros;
            }
        }

        return $resumen;
    }
}