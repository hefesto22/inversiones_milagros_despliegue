<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\ViajeVenta;
use App\Models\Traits\CalculaComisionesViaje;

class Viaje extends Model
{
    use HasFactory;
    use CalculaComisionesViaje;

    protected $table = 'viajes';

    protected $fillable = [
        'numero_viaje',
        'camion_id',
        'chofer_id',
        'bodega_origen_id',
        'fecha_salida',
        'fecha_regreso',
        'km_salida',
        'km_regreso',
        'estado',
        'total_cargado_costo',
        'total_cargado_venta',
        'total_vendido',
        'total_merma_costo',
        'total_devuelto_costo',
        'comision_ganada',
        'cobros_devoluciones',
        'neto_chofer',
        'efectivo_inicial',
        'efectivo_esperado',
        'efectivo_entregado',
        'diferencia_efectivo',
        'observaciones',
        'created_by',
        'cerrado_por',
        'cerrado_en',
    ];

    protected $casts = [
        'fecha_salida' => 'datetime',
        'fecha_regreso' => 'datetime',
        'km_salida' => 'integer',
        'km_regreso' => 'integer',
        'total_cargado_costo' => 'decimal:2',
        'total_cargado_venta' => 'decimal:2',
        'total_vendido' => 'decimal:2',
        'total_merma_costo' => 'decimal:2',
        'total_devuelto_costo' => 'decimal:2',
        'comision_ganada' => 'decimal:2',
        'cobros_devoluciones' => 'decimal:2',
        'neto_chofer' => 'decimal:2',
        'efectivo_inicial' => 'decimal:2',
        'efectivo_esperado' => 'decimal:2',
        'efectivo_entregado' => 'decimal:2',
        'diferencia_efectivo' => 'decimal:2',
        'cerrado_en' => 'datetime',
    ];

    // Estados del viaje
    public const ESTADO_PLANIFICADO = 'planificado';
    public const ESTADO_CARGANDO = 'cargando';
    public const ESTADO_EN_RUTA = 'en_ruta';
    public const ESTADO_REGRESANDO = 'regresando';
    public const ESTADO_DESCARGANDO = 'descargando';
    public const ESTADO_LIQUIDANDO = 'liquidando';
    public const ESTADO_CERRADO = 'cerrado';
    public const ESTADO_CANCELADO = 'cancelado';

    // ============================================
    // RELACIONES
    // ============================================

    public function camion(): BelongsTo
    {
        return $this->belongsTo(Camion::class, 'camion_id');
    }

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chofer_id');
    }

    public function bodegaOrigen(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_origen_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    public function cargas(): HasMany
    {
        return $this->hasMany(ViajeCarga::class, 'viaje_id');
    }

    public function descargas(): HasMany
    {
        return $this->hasMany(ViajeDescarga::class, 'viaje_id');
    }

    public function mermas(): HasMany
    {
        return $this->hasMany(ViajeMerma::class, 'viaje_id');
    }

    public function comisionesDetalle(): HasMany
    {
        return $this->hasMany(ViajeComisionDetalle::class, 'viaje_id');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'viaje_id');
    }
    /**
     * Ventas realizadas en ruta (Punto de Venta del Chofer)
     */
    public function ventasRuta(): HasMany
    {
        return $this->hasMany(ViajeVenta::class, 'viaje_id');
    }
    public function liquidacionViajes(): HasMany
    {
        return $this->hasMany(LiquidacionViaje::class, 'viaje_id');
    }

    public function movimientosCuenta(): HasMany
    {
        return $this->hasMany(ChoferCuentaMovimiento::class, 'viaje_id');
    }

    // ============================================
    // MÉTODOS DE ESTADO
    // ============================================

    public function cambiarEstado(string $nuevoEstado): void
    {
        $this->estado = $nuevoEstado;

        if ($nuevoEstado === self::ESTADO_EN_RUTA && !$this->fecha_salida) {
            $this->fecha_salida = now();
        }

        if ($nuevoEstado === self::ESTADO_REGRESANDO && !$this->fecha_regreso) {
            $this->fecha_regreso = now();
        }

        if ($nuevoEstado === self::ESTADO_CERRADO) {
            $this->cerrado_en = now();
            $this->cerrado_por = Auth::id();
        }

        $this->save();
    }

    public function iniciarCarga(): void
    {
        $this->cambiarEstado(self::ESTADO_CARGANDO);
    }

    public function iniciarRuta(): void
    {
        $this->cambiarEstado(self::ESTADO_EN_RUTA);
    }

    public function iniciarRegreso(): void
    {
        $this->cambiarEstado(self::ESTADO_REGRESANDO);
    }

    public function iniciarDescarga(): void
    {
        $this->cambiarEstado(self::ESTADO_DESCARGANDO);
    }

    public function iniciarLiquidacion(): void
    {
        $this->cambiarEstado(self::ESTADO_LIQUIDANDO);
    }

    public function cerrar(): void
    {
        DB::transaction(function () {
            // 1. Calcular comisiones
            $this->calcularComisiones();

            // 2. Procesar reintegro de stock de las descargas
            $this->procesarReintegroDescargas();

            // 3. Recalcular totales
            $this->recalcularTotales();

            // 4. Cambiar estado
            $this->cambiarEstado(self::ESTADO_CERRADO);
        });
    }

    /**
     * Cancelar viaje y devolver stock a bodega
     * 
     * 🎯 CORREGIDO: Ahora devuelve el stock de todos los productos cargados
     */
    public function cancelar(?string $motivo = null): void
    {
        DB::transaction(function () use ($motivo) {
            // 🎯 DEVOLVER STOCK DE LAS CARGAS A LA BODEGA
            foreach ($this->cargas as $carga) {
                $bodegaProducto = BodegaProducto::where('bodega_id', $this->bodega_origen_id)
                    ->where('producto_id', $carga->producto_id)
                    ->first();

                if ($bodegaProducto) {
                    // Devolver la cantidad cargada al stock
                    // Usamos el costo original de la carga para mantener el promedio ponderado
                    $bodegaProducto->actualizarCostoPromedio(
                        $carga->cantidad,
                        $carga->costo_unitario
                    );
                }
            }

            // Agregar motivo de cancelación
            if ($motivo) {
                $this->observaciones = $this->observaciones
                    ? $this->observaciones . "\n[CANCELADO] " . $motivo
                    : "[CANCELADO] " . $motivo;
            }

            // Cambiar estado
            $this->cambiarEstado(self::ESTADO_CANCELADO);
        });
    }

    protected function procesarReintegroDescargas(): void
    {
        // Obtener descargas que deben reingresar al stock y no han sido procesadas
        $descargas = $this->descargas()
            ->where('reingresa_stock', true)
            ->get();

        foreach ($descargas as $descarga) {
            // Solo reingresar productos en buen estado
            if (!$descarga->estaEnBuenEstado()) {
                continue;
            }

            // Buscar o crear el registro en bodega_producto
            $bodegaProducto = BodegaProducto::firstOrCreate(
                [
                    'bodega_id' => $this->bodega_origen_id,
                    'producto_id' => $descarga->producto_id,
                ],
                [
                    'stock' => 0,
                    'stock_reservado' => 0,
                    'stock_minimo' => 0,
                    'costo_promedio_actual' => $descarga->costo_unitario,
                    'activo' => true,
                ]
            );

            // Reingresar stock usando el costo de la descarga
            // Usamos actualizarCostoPromedio para mantener el weighted average
            $bodegaProducto->actualizarCostoPromedio(
                $descarga->cantidad,
                $descarga->costo_unitario
            );
        }
    }
    // ============================================
    // MÉTODOS DE VERIFICACIÓN
    // ============================================

    public function estaActivo(): bool
    {
        return !in_array($this->estado, [self::ESTADO_CERRADO, self::ESTADO_CANCELADO]);
    }

    public function estaCerrado(): bool
    {
        return $this->estado === self::ESTADO_CERRADO;
    }

    public function estaCancelado(): bool
    {
        return $this->estado === self::ESTADO_CANCELADO;
    }

    public function puedeCargar(): bool
    {
        return in_array($this->estado, [self::ESTADO_PLANIFICADO, self::ESTADO_CARGANDO]);
    }

    public function puedeVender(): bool
    {
        return $this->estado === self::ESTADO_EN_RUTA;
    }

    public function puedeDescargar(): bool
    {
        return in_array($this->estado, [self::ESTADO_REGRESANDO, self::ESTADO_DESCARGANDO]);
    }

    public function estaLiquidado(): bool
    {
        return $this->liquidacionViajes()->exists();
    }

    // ============================================
    // MÉTODOS DE CÁLCULO
    // ============================================

    public function recalcularTotales(): void
    {
        // Totales de carga
        $this->total_cargado_costo = $this->cargas()->sum('subtotal_costo');
        $this->total_cargado_venta = $this->cargas()->sum('subtotal_venta');

        // Totales de ventas - CAMBIAR ventas() por ventasRuta()
        $this->total_vendido = $this->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->sum('total');

        // Totales de merma
        $this->total_merma_costo = $this->mermas()->sum('subtotal_costo');

        // Totales de devolución
        $this->total_devuelto_costo = $this->descargas()->sum('subtotal_costo');

        // Comisiones
        $this->comision_ganada = $this->comisionesDetalle()->sum('comision_total');

        // Cobros por devoluciones
        $this->cobros_devoluciones = $this->descargas()->where('cobrar_chofer', true)->sum('monto_cobrar')
            + $this->mermas()->where('cobrar_chofer', true)->sum('monto_cobrar');

        // Neto chofer
        $this->neto_chofer = $this->comision_ganada - $this->cobros_devoluciones;

        // Efectivo - CAMBIAR ventas() por ventasRuta()
        $ventasEfectivo = $this->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->where('tipo_pago', '!=', 'credito')
            ->sum('total');

        $this->efectivo_esperado = $this->efectivo_inicial + $ventasEfectivo;
        $this->diferencia_efectivo = $this->efectivo_entregado - $this->efectivo_esperado;

        $this->save();
    }

    public function calcularComisiones(): void
    {
        // Eliminar comisiones anteriores
        $this->comisionesDetalle()->delete();

        // Usar ventasRuta() en lugar de ventas() - estas son las ventas en ruta (ViajeVenta)
        $ventas = $this->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->with(['detalles.producto.categoria'])
            ->get();

        foreach ($ventas as $venta) {
            foreach ($venta->detalles as $detalle) {
                $this->calcularComisionDetalleRuta($venta, $detalle);
            }
        }

        // Actualizar total de comisiones
        $this->comision_ganada = $this->comisionesDetalle()->sum('comision_total');
        $this->neto_chofer = $this->comision_ganada - $this->cobros_devoluciones;
        $this->save();
    }

    protected function calcularComisionDetalleRuta(ViajeVenta $venta, ViajeVentaDetalle $detalle): void
    {
        $producto = $detalle->producto;

        if (!$producto) {
            return;
        }

        $categoriaId = $producto->categoria_id;

        // Obtener unidad desde la carga del viaje
        $carga = $this->cargas()->where('producto_id', $producto->id)->first();
        $unidadId = $carga?->unidad_id;

        // Obtener configuración de comisión del chofer
        $comisionConfig = $this->chofer->getComisionPara($producto->id, $categoriaId, $unidadId);

        // Si no hay comisión configurada, saltar
        if ($comisionConfig['normal'] <= 0) {
            return;
        }

        // Obtener precio sugerido de la carga
        $precioSugerido = $carga?->precio_venta_sugerido ?? $detalle->precio_base;

        // Determinar tipo de comisión basado en precio de venta vs precio sugerido
        // ViajeVentaDetalle usa precio_base (sin ISV)
        $precioVendido = $detalle->precio_base;

        $tipoComision = $precioVendido >= $precioSugerido
            ? ViajeComisionDetalle::TIPO_NORMAL
            : ViajeComisionDetalle::TIPO_REDUCIDA;

        $comisionUnitaria = $tipoComision === ViajeComisionDetalle::TIPO_NORMAL
            ? $comisionConfig['normal']
            : ($comisionConfig['reducida'] ?? $comisionConfig['normal']);

        $comisionTotal = $detalle->cantidad * $comisionUnitaria;

        // Crear registro de comisión
        ViajeComisionDetalle::create([
            'viaje_id' => $this->id,
            'viaje_venta_id' => $venta->id,
            'viaje_venta_detalle_id' => $detalle->id,
            'producto_id' => $producto->id,
            'cantidad' => $detalle->cantidad,
            'precio_vendido' => $precioVendido,
            'precio_sugerido' => $precioSugerido,
            'costo' => $detalle->costo_unitario,
            'tipo_comision' => $tipoComision,
            'comision_unitaria' => $comisionUnitaria,
            'comision_total' => $comisionTotal,
        ]);
    }

    protected function calcularComisionDetalle(Venta $venta, VentaDetalle $detalle): void
    {
        $producto = $detalle->producto;
        $categoriaId = $producto->categoria_id;
        $unidadId = $detalle->unidad_id;

        // Obtener configuración de comisión
        $comisionConfig = $this->chofer->getComisionPara($producto->id, $categoriaId, $unidadId);

        if ($comisionConfig['normal'] <= 0) {
            return; // Sin comisión configurada
        }

        // Obtener precio sugerido de la carga
        $carga = $this->cargas()->where('producto_id', $producto->id)->first();
        $precioSugerido = $carga?->precio_venta_sugerido ?? $detalle->precio_unitario;

        // Determinar tipo de comisión
        $tipoComision = $detalle->precio_unitario >= $precioSugerido ? 'normal' : 'reducida';
        $comisionUnitaria = $tipoComision === 'normal'
            ? $comisionConfig['normal']
            : $comisionConfig['reducida'];

        $comisionTotal = $detalle->cantidad * $comisionUnitaria;

        ViajeComisionDetalle::create([
            'viaje_id' => $this->id,
            'venta_id' => $venta->id,
            'venta_detalle_id' => $detalle->id,
            'producto_id' => $producto->id,
            'cantidad' => $detalle->cantidad,
            'precio_vendido' => $detalle->precio_unitario,
            'precio_sugerido' => $precioSugerido,
            'costo' => $detalle->costo_unitario,
            'tipo_comision' => $tipoComision,
            'comision_unitaria' => $comisionUnitaria,
            'comision_total' => $comisionTotal,
        ]);
    }

    public function liquidarCompleto(): array
    {
        // Calcular comisiones
        $this->calcularComisiones();

        // Recalcular totales (sin cerrar)
        $this->recalcularTotales();

        return [
            'comision_ganada' => $this->comision_ganada,
            'cobros' => $this->cobros_devoluciones,
            'neto_chofer' => $this->neto_chofer,
            'total_vendido' => $this->total_vendido,
        ];
    }
    // ============================================
    // MÉTODOS DE CONSULTA
    // ============================================

    public function getKilometrosRecorridos(): ?int
    {
        if (!$this->km_salida || !$this->km_regreso) {
            return null;
        }

        return $this->km_regreso - $this->km_salida;
    }

    public function getResumen(): array
    {
        return [
            'numero_viaje' => $this->numero_viaje,
            'camion' => $this->camion->placa ?? 'N/A',
            'chofer' => $this->chofer->name ?? 'N/A',
            'estado' => $this->estado,
            'fecha_salida' => $this->fecha_salida?->format('d/m/Y H:i'),
            'fecha_regreso' => $this->fecha_regreso?->format('d/m/Y H:i'),
            'km_recorridos' => $this->getKilometrosRecorridos(),
            'total_cargado' => $this->total_cargado_costo,
            'total_vendido' => $this->total_vendido,
            'total_merma' => $this->total_merma_costo,
            'total_devuelto' => $this->total_devuelto_costo,
            'comision_ganada' => $this->comision_ganada,
            'cobros' => $this->cobros_devoluciones,
            'neto_chofer' => $this->neto_chofer,
            'efectivo_esperado' => $this->efectivo_esperado,
            'efectivo_entregado' => $this->efectivo_entregado,
            'diferencia' => $this->diferencia_efectivo,
        ];
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeActivos($query)
    {
        return $query->whereNotIn('estado', [self::ESTADO_CERRADO, self::ESTADO_CANCELADO]);
    }

    public function scopeCerrados($query)
    {
        return $query->where('estado', self::ESTADO_CERRADO);
    }

    public function scopeDelChofer($query, int $choferId)
    {
        return $query->where('chofer_id', $choferId);
    }

    public function scopeDelCamion($query, int $camionId)
    {
        return $query->where('camion_id', $camionId);
    }

    public function scopeDeBodega($query, int $bodegaId)
    {
        return $query->where('bodega_origen_id', $bodegaId);
    }

    public function scopePendientesLiquidar($query)
    {
        return $query->where('estado', self::ESTADO_CERRADO)
            ->whereDoesntHave('liquidacionViajes');
    }

    public function scopeDelPeriodo($query, $fechaInicio, $fechaFin)
    {
        return $query->whereBetween('fecha_salida', [$fechaInicio, $fechaFin]);
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($viaje) {
            if (!$viaje->numero_viaje) {
                $viaje->numero_viaje = self::generarNumeroViaje($viaje);
            }

            if (!$viaje->estado) {
                $viaje->estado = self::ESTADO_PLANIFICADO;
            }
        });
    }

    protected static function generarNumeroViaje(Viaje $viaje): string
    {
        $camionCodigo = $viaje->camion?->codigo ?? 'XXX';
        $fecha = now()->format('ymd');

        $ultimo = static::where('numero_viaje', 'like', "VJ-{$camionCodigo}-{$fecha}-%")
            ->orderBy('numero_viaje', 'desc')
            ->first();

        if ($ultimo) {
            $numero = (int) substr($ultimo->numero_viaje, -3) + 1;
        } else {
            $numero = 1;
        }

        return "VJ-{$camionCodigo}-{$fecha}-" . str_pad($numero, 3, '0', STR_PAD_LEFT);
    }
}