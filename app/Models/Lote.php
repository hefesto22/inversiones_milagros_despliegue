<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lote extends Model
{
    use HasFactory;

    protected $table = 'lotes';

    protected $fillable = [
        'compra_id',
        'compra_detalle_id',
        'reempaque_origen_id',
        'producto_id',
        'proveedor_id',
        'bodega_id',
        'numero_lote',
        'cantidad_cartones_facturados',
        'cantidad_cartones_regalo',
        'cantidad_cartones_recibidos',
        'huevos_por_carton',
        'cantidad_huevos_original',
        'cantidad_huevos_remanente',
        'huevos_facturados_acumulados',
        'huevos_regalo_acumulados',
        'huevos_regalo_consumidos',
        'merma_total_acumulada',
        'costo_total_acumulado',
        'costo_total_lote',
        'costo_por_carton_facturado',
        'costo_por_huevo',
        'estado',
        'created_by',
    ];

    protected $casts = [
        'cantidad_cartones_facturados' => 'decimal:2',
        'cantidad_cartones_regalo' => 'decimal:2',
        'cantidad_cartones_recibidos' => 'decimal:2',
        'huevos_por_carton' => 'integer',
        'cantidad_huevos_original' => 'decimal:2',
        'cantidad_huevos_remanente' => 'decimal:2',
        'huevos_facturados_acumulados' => 'decimal:2',
        'huevos_regalo_acumulados' => 'decimal:2',
        'huevos_regalo_consumidos' => 'decimal:2',
        'merma_total_acumulada' => 'decimal:2',
        'costo_total_acumulado' => 'decimal:4',
        'costo_total_lote' => 'decimal:4',
        'costo_por_carton_facturado' => 'decimal:4', // FIX: era decimal:2, truncaba precisión
        'costo_por_huevo' => 'decimal:4',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function compraDetalle(): BelongsTo
    {
        return $this->belongsTo(CompraDetalle::class, 'compra_detalle_id');
    }

    public function reempaqueOrigen(): BelongsTo
    {
        return $this->belongsTo(Reempaque::class, 'reempaque_origen_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reempaqueLotes(): HasMany
    {
        return $this->hasMany(ReempaqueLote::class, 'lote_id');
    }

    public function historialCompras(): HasMany
    {
        return $this->hasMany(HistorialCompraLote::class, 'lote_id');
    }

    public function mermas(): HasMany
    {
        return $this->hasMany(Merma::class, 'lote_id');
    }

    // ============================================
    // MÉTODOS ESTÁTICOS
    // ============================================

    /**
     * Buscar o crear lote único para un producto en una bodega
     */
    public static function obtenerOCrearLoteUnico(
        int $productoId,
        int $bodegaId,
        int $huevosPorCarton = 30,
        ?int $createdBy = null
    ): Lote {
        $numeroLote = "LU-B{$bodegaId}-P{$productoId}";
        
        $lote = self::where('numero_lote', $numeroLote)->first();

        if ($lote) {
            if ($lote->estado === 'agotado' || $lote->cantidad_huevos_remanente <= 0) {
                $lote->resetearParaNuevaCompra();
            }
            return $lote;
        }

        return self::firstOrCreate(
            ['numero_lote' => $numeroLote],
            [
                'producto_id' => $productoId,
                'bodega_id' => $bodegaId,
                'huevos_por_carton' => $huevosPorCarton,
                'cantidad_cartones_facturados' => 0,
                'cantidad_cartones_regalo' => 0,
                'cantidad_cartones_recibidos' => 0,
                'cantidad_huevos_original' => 0,
                'cantidad_huevos_remanente' => 0,
                'huevos_facturados_acumulados' => 0,
                'huevos_regalo_acumulados' => 0,
                'huevos_regalo_consumidos' => 0,
                'merma_total_acumulada' => 0,
                'costo_total_acumulado' => 0,
                'costo_total_lote' => 0,
                'costo_por_carton_facturado' => 0,
                'costo_por_huevo' => 0,
                'estado' => 'disponible',
                'created_by' => $createdBy,
            ]
        );
    }

    /**
     * Resetear acumuladores cuando el lote estaba agotado
     */
    public function resetearParaNuevaCompra(): void
    {
        $this->cantidad_cartones_facturados = 0;
        $this->cantidad_cartones_regalo = 0;
        $this->cantidad_cartones_recibidos = 0;
        $this->cantidad_huevos_original = 0;
        $this->cantidad_huevos_remanente = 0;
        $this->huevos_facturados_acumulados = 0;
        $this->huevos_regalo_acumulados = 0;
        $this->huevos_regalo_consumidos = 0;
        $this->merma_total_acumulada = 0;
        $this->costo_total_acumulado = 0;
        $this->costo_total_lote = 0;
        $this->costo_por_carton_facturado = 0;
        $this->costo_por_huevo = 0;
        $this->estado = 'disponible';
        $this->save();
    }

    // ============================================
    // MÉTODOS DE COMPRAS
    // ============================================

    /**
     * Agregar una compra al lote único con costo promedio ponderado
     */
    public function agregarCompra(
        float $cartonesFacturados,
        float $cartonesRegalo,
        float $costoCompra,
        int $compraId,
        int $compraDetalleId,
        int $proveedorId
    ): array {
        $huevosPorCarton = $this->huevos_por_carton ?? 30;

        $huevosFacturadosNuevos = $cartonesFacturados * $huevosPorCarton;
        $huevosRegaloNuevos = $cartonesRegalo * $huevosPorCarton;
        $huevosTotalesNuevos = $huevosFacturadosNuevos + $huevosRegaloNuevos;

        $costoPorHuevoCompra = $huevosFacturadosNuevos > 0
            ? $costoCompra / $huevosFacturadosNuevos
            : 0;

        $huevosFacturadosActuales = $this->huevos_facturados_acumulados ?? 0;
        $costoActual = $this->costo_total_acumulado ?? 0;

        $costoTotalNuevo = $costoActual + $costoCompra;
        $huevosFacturadosTotales = $huevosFacturadosActuales + $huevosFacturadosNuevos;

        $nuevoCostoPorHuevo = $huevosFacturadosTotales > 0
            ? $costoTotalNuevo / $huevosFacturadosTotales
            : 0;

        // Actualizar lote
        $this->cantidad_cartones_facturados += $cartonesFacturados;
        $this->cantidad_cartones_regalo += $cartonesRegalo;
        $this->cantidad_cartones_recibidos += ($cartonesFacturados + $cartonesRegalo);
        $this->cantidad_huevos_original += $huevosTotalesNuevos;
        $this->cantidad_huevos_remanente += $huevosTotalesNuevos;
        $this->huevos_facturados_acumulados = $huevosFacturadosTotales;
        $this->huevos_regalo_acumulados += $huevosRegaloNuevos;
        $this->costo_total_acumulado = $costoTotalNuevo;
        $this->costo_total_lote = $costoTotalNuevo;
        
        // FIX: 4 decimales para mantener precisión en toda la cadena
        $this->costo_por_carton_facturado = $this->cantidad_cartones_facturados > 0
            ? round($costoTotalNuevo / $this->cantidad_cartones_facturados, 4)
            : 0;
        
        // costo_por_huevo derivado del costo_por_carton para consistencia
        $this->costo_por_huevo = $huevosPorCarton > 0
            ? round($this->costo_por_carton_facturado / $huevosPorCarton, 4)
            : 0;
        
        $this->proveedor_id = $proveedorId;
        $this->compra_id = $compraId;
        $this->compra_detalle_id = $compraDetalleId;
        $this->estado = 'disponible';

        $this->save();

        // Registrar en historial
        HistorialCompraLote::create([
            'lote_id' => $this->id,
            'compra_id' => $compraId,
            'compra_detalle_id' => $compraDetalleId,
            'proveedor_id' => $proveedorId,
            'cartones_facturados' => $cartonesFacturados,
            'cartones_regalo' => $cartonesRegalo,
            'huevos_agregados' => $huevosTotalesNuevos,
            'costo_compra' => $costoCompra,
            'costo_por_huevo_compra' => $costoPorHuevoCompra,
            'costo_promedio_resultante' => $nuevoCostoPorHuevo,
            'huevos_totales_resultante' => $this->cantidad_huevos_remanente,
        ]);

        return [
            'huevos_agregados' => $huevosTotalesNuevos,
            'costo_compra' => $costoCompra,
            'nuevo_costo_promedio' => $nuevoCostoPorHuevo,
            'nuevo_total_huevos' => $this->cantidad_huevos_remanente,
        ];
    }

    // ============================================
    // MÉTODOS DE BUFFER Y CONSUMO FIFO
    // ============================================

    /**
     * Obtener el buffer de regalo disponible (en huevos)
     */
    public function getBufferRegaloDisponible(): float
    {
        $regaloTotal = $this->huevos_regalo_acumulados ?? 0;
        $mermaTotal = $this->merma_total_acumulada ?? 0;
        $regaloConsumido = $this->huevos_regalo_consumidos ?? 0;

        return max(0, $regaloTotal - $mermaTotal - $regaloConsumido);
    }

    /**
     * Obtener huevos facturados disponibles (que tienen costo)
     */
    public function getHuevosFacturadosDisponibles(): float
    {
        $remanente = $this->cantidad_huevos_remanente ?? 0;
        $bufferRegalo = $this->getBufferRegaloDisponible();

        return max(0, $remanente - $bufferRegalo);
    }

    /**
     * Calcular consumo de huevos separando facturados y regalo (FIFO)
     * 
     * LÓGICA:
     * 1. PRIMERO se consumen huevos facturados (con costo)
     * 2. DESPUÉS se consumen huevos de regalo (sin costo)
     */
    public function calcularConsumoHuevos(float $huevosAUsar): array
    {
        $huevosFacturadosDisponibles = $this->getHuevosFacturadosDisponibles();
        $huevosRegaloDisponibles = $this->getBufferRegaloDisponible();
        
        // Derivar costo por huevo desde costo_por_carton para evitar redondeos acumulados
        $huevosPorCarton = $this->huevos_por_carton ?? 30;
        $costoPorCarton = floatval($this->costo_por_carton_facturado ?? 0);
        $costoPorHuevo = $huevosPorCarton > 0 ? $costoPorCarton / $huevosPorCarton : 0;

        if ($huevosAUsar <= $huevosFacturadosDisponibles) {
            $huevosFacturadosUsados = $huevosAUsar;
            $huevosRegaloUsados = 0;
        } else {
            $huevosFacturadosUsados = $huevosFacturadosDisponibles;
            $huevosRegaloUsados = min(
                $huevosAUsar - $huevosFacturadosDisponibles,
                $huevosRegaloDisponibles
            );
        }

        // FIX: 4 decimales para mantener precisión en costos intermedios
        $costo = round($huevosFacturadosUsados * $costoPorHuevo, 4);

        return [
            'huevos_facturados_usados' => $huevosFacturadosUsados,
            'huevos_regalo_usados' => $huevosRegaloUsados,
            'costo' => $costo,
            'huevos_facturados_disponibles' => $huevosFacturadosDisponibles,
            'huevos_regalo_disponibles' => $huevosRegaloDisponibles,
            'costo_por_huevo_usado' => $costoPorHuevo,
        ];
    }

    /**
     * Alias para compatibilidad
     */
    public function getHuevosRegaloDisponibles(): float
    {
        return $this->getBufferRegaloDisponible();
    }

    // ============================================
    // MÉTODOS DE MERMAS
    // ============================================

    /**
     * Registrar una merma
     */
    public function registrarMerma(
        float $cantidadHuevos,
        string $motivo = 'rotos',
        ?string $descripcion = null,
        ?int $createdBy = null
    ): Merma {
        if ($cantidadHuevos > $this->cantidad_huevos_remanente) {
            throw new \Exception(
                "No hay suficientes huevos en el lote. " .
                "Disponible: {$this->cantidad_huevos_remanente}, Solicitado: {$cantidadHuevos}"
            );
        }

        $bufferAntes = $this->getBufferRegaloDisponible();

        $cubiertoBuffer = min($cantidadHuevos, $bufferAntes);
        $perdidaReal = max(0, $cantidadHuevos - $bufferAntes);
        $perdidaLempiras = $perdidaReal * floatval($this->costo_por_huevo ?? 0);

        // Actualizar lote
        $this->cantidad_huevos_remanente -= $cantidadHuevos;
        $this->merma_total_acumulada += $cantidadHuevos;

        if ($perdidaReal > 0) {
            $this->huevos_facturados_acumulados = max(0, $this->huevos_facturados_acumulados - $perdidaReal);
            if ($this->huevos_facturados_acumulados > 0) {
                $this->costo_por_huevo = round($this->costo_total_acumulado / $this->huevos_facturados_acumulados, 4);
                // FIX: 4 decimales
                $this->costo_por_carton_facturado = round($this->costo_por_huevo * ($this->huevos_por_carton ?? 30), 4);
            }
        }

        if ($this->cantidad_huevos_remanente <= 0) {
            $this->cantidad_huevos_remanente = 0;
            $this->estado = 'agotado';
        }

        $this->save();

        return Merma::create([
            'lote_id' => $this->id,
            'bodega_id' => $this->bodega_id,
            'producto_id' => $this->producto_id,
            'numero_merma' => Merma::generarNumeroMerma($this->bodega_id),
            'cantidad_huevos' => $cantidadHuevos,
            'cubierto_por_regalo' => $cubiertoBuffer,
            'perdida_real_huevos' => $perdidaReal,
            'perdida_real_lempiras' => round($perdidaLempiras, 2),
            'motivo' => $motivo,
            'descripcion' => $descripcion,
            'buffer_antes' => $bufferAntes,
            'buffer_despues' => $this->getBufferRegaloDisponible(),
            'created_by' => $createdBy,
        ]);
    }

    // ============================================
    // MÉTODOS DE NEGOCIO
    // ============================================

    /**
     * Reducir el remanente del lote
     */
    public function reducirRemanente(float $cantidadHuevos, float $huevosRegaloUsados = 0): void
    {
        if ($cantidadHuevos > $this->cantidad_huevos_remanente) {
            throw new \Exception(
                "Stock insuficiente en lote {$this->numero_lote}. " .
                "Disponible: {$this->cantidad_huevos_remanente}, Solicitado: {$cantidadHuevos}"
            );
        }

        $this->cantidad_huevos_remanente -= $cantidadHuevos;

        if ($huevosRegaloUsados > 0) {
            $this->huevos_regalo_consumidos = ($this->huevos_regalo_consumidos ?? 0) + $huevosRegaloUsados;
        }

        if ($this->cantidad_huevos_remanente <= 0) {
            $this->cantidad_huevos_remanente = 0;
            $this->estado = 'agotado';
        }

        $this->save();
    }

    /**
     * Verificar si el lote tiene suficientes huevos
     */
    public function tieneSuficientesHuevos(float $cantidadRequerida): bool
    {
        return $this->cantidad_huevos_remanente >= $cantidadRequerida;
    }

    /**
     * Verificar si es lote de sueltos
     */
    public function esLoteSueltos(): bool
    {
        return str_starts_with($this->numero_lote ?? '', 'SUELTOS-');
    }

    /**
     * Verificar si es lote único
     */
    public function esLoteUnico(): bool
    {
        return str_starts_with($this->numero_lote ?? '', 'LU-');
    }

    /**
     * Calcular costo de una cantidad de huevos
     */
    public function calcularCostoDeHuevos(float $cantidadHuevos): float
    {
        return round($cantidadHuevos * floatval($this->costo_por_huevo ?? 0), 4);
    }

    /**
     * Verificar si el lote esta disponible para uso
     */
    public function estaDisponible(): bool
    {
        return $this->estado === 'disponible' && $this->cantidad_huevos_remanente > 0;
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeDisponible($query)
    {
        return $query->where('estado', 'disponible');
    }

    public function scopePorBodega($query, int $bodegaId)
    {
        return $query->where('bodega_id', $bodegaId);
    }

    public function scopeConRemanente($query)
    {
        return $query->where('cantidad_huevos_remanente', '>', 0);
    }

    public function scopeSueltos($query)
    {
        return $query->where('numero_lote', 'LIKE', 'SUELTOS-%');
    }

    public function scopeLotesUnicos($query)
    {
        return $query->where('numero_lote', 'LIKE', 'LU-%');
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($lote) {
            // Lotes SUELTOS y ÚNICOS: no recalcular
            if (str_starts_with($lote->numero_lote ?? '', 'SUELTOS-') ||
                str_starts_with($lote->numero_lote ?? '', 'LU-')) {
                if (is_null($lote->cantidad_cartones_recibidos)) {
                    $lote->cantidad_cartones_recibidos = 0;
                }
                return;
            }

            // Lotes tradicionales (L-*): lógica original
            $facturados = $lote->cantidad_cartones_facturados ?? 0;
            $regalo = $lote->cantidad_cartones_regalo ?? 0;

            if (is_null($lote->cantidad_cartones_recibidos)) {
                $lote->cantidad_cartones_recibidos = $facturados + $regalo;
            }

            if (is_null($lote->cantidad_huevos_original)) {
                $lote->cantidad_huevos_original = $lote->cantidad_cartones_recibidos * $lote->huevos_por_carton;
            }

            if (is_null($lote->cantidad_huevos_remanente)) {
                $lote->cantidad_huevos_remanente = $lote->cantidad_huevos_original;
            }

            if (is_null($lote->costo_por_huevo) || $lote->isDirty('costo_total_lote')) {
                $huevosFacturados = $facturados * ($lote->huevos_por_carton ?? 30);
                $lote->costo_por_huevo = $huevosFacturados > 0
                    ? round(($lote->costo_total_lote ?? 0) / $huevosFacturados, 4)
                    : 0;
            }

            // FIX: 4 decimales para costo_por_carton_facturado
            if (is_null($lote->costo_por_carton_facturado) && $facturados > 0) {
                $lote->costo_por_carton_facturado = round(($lote->costo_total_lote ?? 0) / $facturados, 4);
            }
        });
    }
}