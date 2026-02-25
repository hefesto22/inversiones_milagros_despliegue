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
        'categoria_contable',
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
        'papeleria' => 'Papeleria',
        'herramientas' => 'Herramientas',
        'mantenimiento' => 'Mantenimiento',
        'servicios' => 'Servicios (agua, luz, internet)',
        'uniformes' => 'Uniformes',
        'fumigacion' => 'Fumigacion',
        'transporte_local' => 'Transporte Local',
        'honorarios' => 'Honorarios (contabilidad, legal)',
        'inversion' => 'Inversion (terreno, vehiculo, equipo)',
        'otros' => 'Otros',
    ];

    public const CATEGORIAS_CONTABLES = [
        'gasto_venta' => 'Gasto de Venta',
        'gasto_admin' => 'Gasto Administrativo',
        'inversion' => 'Inversion (Activo Fijo)',
    ];

    // =========================================================
    // MAPEO AUTOMATICO: tipo_gasto -> categoria_contable
    // =========================================================

    private const MAPA_CATEGORIA = [
        'papeleria'  => 'gasto_admin',
        'servicios'  => 'gasto_admin',
        'fumigacion' => 'gasto_admin',
        'honorarios' => 'gasto_admin',
        'inversion'  => 'inversion',
        // Todo lo demas => gasto_venta (default)
    ];

    public static function resolverCategoria(string $tipoGasto): string
    {
        return self::MAPA_CATEGORIA[$tipoGasto] ?? 'gasto_venta';
    }

    // =========================================================
    // BOOT - Asignar categoria_contable automaticamente
    // =========================================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function (BodegaGasto $gasto) {
            $gasto->categoria_contable = self::resolverCategoria($gasto->tipo_gasto);
        });
    }

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
    // METODOS AUXILIARES
    // =========================================================

    public function getTipoGastoLabelAttribute(): string
    {
        return self::TIPOS_GASTO[$this->tipo_gasto] ?? $this->tipo_gasto;
    }

    public function isPendiente(): bool
    {
        return $this->estado === 'pendiente';
    }

    public function isAprobado(): bool
    {
        return $this->estado === 'aprobado';
    }

    public function marcarEnviadoWhatsapp(): void
    {
        $this->update([
            'enviado_whatsapp' => true,
            'enviado_whatsapp_at' => now(),
        ]);
    }

    public function aprobar(int $userId): void
    {
        $this->update([
            'estado' => 'aprobado',
            'aprobado_por' => $userId,
            'aprobado_at' => now(),
        ]);
    }

    public function generarTextoWhatsapp(): string
    {
        $tipoLabel = $this->tipo_gasto_label;
        $fecha = $this->fecha->format('d/m/Y');
        $monto = number_format($this->monto, 2);
        $factura = $this->tiene_factura ? 'Si' : 'No';

        $texto = "*GASTO DE BODEGA*\n\n";
        $texto .= "*Bodega:* {$this->bodega->nombre}\n";
        $texto .= "*Fecha:* {$fecha}\n";
        $texto .= "*Categoria:* {$tipoLabel}\n";
        $texto .= "*Detalle:* {$this->detalle}\n";
        $texto .= "*Monto:* L {$monto}\n";
        $texto .= "*Tiene Factura:* {$factura}\n";

        if ($this->tiene_factura) {
            $texto .= "\n_Adjuntar foto de factura_";
        }

        return $texto;
    }

    public function generarUrlWhatsapp(?string $numeroGrupo = null): string
    {
        $texto = urlencode($this->generarTextoWhatsapp());

        if ($numeroGrupo) {
            return "https://wa.me/{$numeroGrupo}?text={$texto}";
        }

        return "https://wa.me/?text={$texto}";
    }
}