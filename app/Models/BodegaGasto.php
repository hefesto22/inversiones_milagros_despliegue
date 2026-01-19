<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BodegaGasto extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'bodega_gastos';

    protected $fillable = [
        'bodega_id',
        'registrado_por',
        'tipo_gasto',
        'fecha',
        'detalle',
        'monto',
        'tiene_factura',
        'enviado_whatsapp',
        'enviado_whatsapp_at',
        'estado',
        'aprobado_por',
        'aprobado_at',
        'created_by',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
        'tiene_factura' => 'boolean',
        'enviado_whatsapp' => 'boolean',
        'enviado_whatsapp_at' => 'datetime',
        'aprobado_at' => 'datetime',
    ];

    // =========================================================
    // TIPOS DE GASTO (Array para usar en selects)
    // =========================================================

    public const TIPOS_GASTO = [
        'cartones' => 'Cartones',
        'empaque' => 'Empaque (cintas, etiquetas, bolsas)',
        'limpieza' => 'Limpieza',
        'papeleria' => 'Papelería',
        'herramientas' => 'Herramientas',
        'mantenimiento' => 'Mantenimiento',
        'servicios' => 'Servicios (agua, luz, internet)',
        'uniformes' => 'Uniformes',
        'fumigacion' => 'Fumigación',
        'transporte_local' => 'Transporte Local',
        'otros' => 'Otros',
    ];

    // =========================================================
    // RELACIONES
    // =========================================================

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // =========================================================
    // MÉTODOS AUXILIARES
    // =========================================================

    /**
     * Obtener el label del tipo de gasto
     */
    public function getTipoGastoLabelAttribute(): string
    {
        return self::TIPOS_GASTO[$this->tipo_gasto] ?? $this->tipo_gasto;
    }

    /**
     * Verificar si está pendiente
     */
    public function isPendiente(): bool
    {
        return $this->estado === 'pendiente';
    }

    /**
     * Verificar si está aprobado
     */
    public function isAprobado(): bool
    {
        return $this->estado === 'aprobado';
    }

    /**
     * Marcar como enviado por WhatsApp
     */
    public function marcarEnviadoWhatsapp(): void
    {
        $this->update([
            'enviado_whatsapp' => true,
            'enviado_whatsapp_at' => now(),
        ]);
    }

    /**
     * Aprobar el gasto
     */
    public function aprobar(int $userId): void
    {
        $this->update([
            'estado' => 'aprobado',
            'aprobado_por' => $userId,
            'aprobado_at' => now(),
        ]);
    }

    /**
     * Generar texto para WhatsApp
     */
    public function generarTextoWhatsapp(): string
    {
        $tipoLabel = $this->tipo_gasto_label;
        $fecha = $this->fecha->format('d/m/Y');
        $monto = number_format($this->monto, 2);
        $factura = $this->tiene_factura ? 'Sí' : 'No';

        $texto = "📋 *GASTO DE BODEGA*\n\n";
        $texto .= "🏢 *Bodega:* {$this->bodega->nombre}\n";
        $texto .= "📅 *Fecha:* {$fecha}\n";
        $texto .= "📁 *Categoría:* {$tipoLabel}\n";
        $texto .= "📝 *Detalle:* {$this->detalle}\n";
        $texto .= "💰 *Monto:* L {$monto}\n";
        $texto .= "🧾 *Tiene Factura:* {$factura}\n";

        if ($this->tiene_factura) {
            $texto .= "\n📎 _Adjuntar foto de factura_";
        }

        return $texto;
    }

    /**
     * Generar URL de WhatsApp
     */
    public function generarUrlWhatsapp(?string $numeroGrupo = null): string
    {
        $texto = urlencode($this->generarTextoWhatsapp());
        
        if ($numeroGrupo) {
            return "https://wa.me/{$numeroGrupo}?text={$texto}";
        }

        // Si no hay número, abre WhatsApp para elegir contacto/grupo
        return "https://wa.me/?text={$texto}";
    }
}