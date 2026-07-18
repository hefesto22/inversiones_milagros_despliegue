<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Viaje extends Model
{
    use HasFactory;

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
        'comision_pagada',
        'fecha_pago_comision',
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
        'comision_pagada' => 'boolean',
        'fecha_pago_comision' => 'date',
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

    public const ESTADO_RECARGANDO = 'recargando';

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

    /**
     * Movimientos contables del chofer asociados a este viaje
     */
    public function movimientosContables(): HasMany
    {
        return $this->hasMany(ChoferCuentaMovimiento::class, 'viaje_id');
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

        if ($nuevoEstado === self::ESTADO_EN_RUTA && ! $this->fecha_salida) {
            $this->fecha_salida = now();
        }

        if ($nuevoEstado === self::ESTADO_REGRESANDO && ! $this->fecha_regreso) {
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

    /**
     * Revertir un "Regresar" presionado por error: el viaje vuelve a "En Ruta".
     *
     * Solo es válido mientras el viaje sigue en "Regresando" y aún no se ha
     * registrado ninguna descarga. Limpia fecha_regreso para que el regreso
     * real la estampe de nuevo con la hora correcta.
     */
    public function volverARuta(): void
    {
        if ($this->estado !== self::ESTADO_REGRESANDO) {
            throw new \Exception('Solo se puede volver a ruta un viaje en estado "Regresando".');
        }

        if ($this->descargas()->exists()) {
            throw new \Exception('No se puede volver a ruta: el viaje ya tiene descargas registradas.');
        }

        $this->fecha_regreso = null;
        $this->cambiarEstado(self::ESTADO_EN_RUTA);
    }

    public function iniciarDescarga(): void
    {
        $this->cambiarEstado(self::ESTADO_DESCARGANDO);
    }

    public function iniciarLiquidacion(): void
    {
        $this->cambiarEstado(self::ESTADO_LIQUIDANDO);
    }

    public function iniciarRecarga(): void
    {
        $this->cambiarEstado(self::ESTADO_RECARGANDO);
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

            // 4. Registrar movimientos contables en la cuenta del chofer
            $this->registrarMovimientosContables();

            // 5. Cambiar estado
            $this->cambiarEstado(self::ESTADO_CERRADO);
        });
    }

    /**
     * Registrar movimientos contables del viaje en la cuenta del chofer.
     * Base devengado: el gasto se reconoce al cerrar el viaje.
     */
    protected function registrarMovimientosContables(): void
    {
        $cuenta = $this->chofer?->cuenta;

        if (! $cuenta) {
            return;
        }

        // Evitar duplicados: si ya tiene movimiento de comisión, no crear de nuevo
        if ($this->movimientosContables()->where('tipo', 'comision')->exists()) {
            return;
        }

        // Registrar comisión ganada (suma al saldo — se le debe al chofer)
        if ($this->comision_ganada > 0) {
            $cuenta->agregarComision(
                $this->comision_ganada,
                $this->id,
                "Comisión viaje #{$this->id} - {$this->fecha_salida->format('d/m/Y')}"
            );
        }

        // Registrar cobros por devoluciones/mermas (resta del saldo — se le descuenta)
        $cobrosDescargas = $this->descargas()->where('cobrar_chofer', true)->sum('monto_cobrar');
        if ($cobrosDescargas > 0) {
            $cuenta->cobrarDevolucion(
                $cobrosDescargas,
                $this->id,
                "Cobro devoluciones viaje #{$this->id}"
            );
        }

        $cobrosMermas = $this->mermas()->where('cobrar_chofer', true)->sum('monto_cobrar');
        if ($cobrosMermas > 0) {
            $cuenta->cobrarMerma(
                $cobrosMermas,
                $this->id,
                "Cobro mermas viaje #{$this->id}"
            );
        }

        // Registrar cobro por faltante de efectivo
        if ($this->diferencia_efectivo < 0) {
            $cuenta->cobrarFaltante(
                abs($this->diferencia_efectivo),
                $this->id,
                "Faltante efectivo viaje #{$this->id}"
            );
        }
    }

    /**
     * Cancelar viaje y devolver stock a bodega
     *
     * 🎯 FIX INTEGRAL:
     * - Valida que no tenga ventas activas (confirmadas/completadas)
     * - Separa correctamente unidades de bodega vs reempaque
     * - Usa costo_bodega_original para promedio ponderado correcto
     * - Cancela reempaques asociados para devolver huevos a lotes
     */
    public function cancelar(?string $motivo = null): void
    {
        // FIX: Validar que no tenga ventas activas
        $ventasActivas = $this->ventasRuta()
            ->whereIn('estado', ['borrador', 'confirmada', 'completada'])
            ->count();

        if ($ventasActivas > 0) {
            throw new \Exception(
                "No se puede cancelar el viaje. Tiene {$ventasActivas} venta(s) activa(s). ".
                    'Cancele todas las ventas primero.'
            );
        }

        DB::transaction(function () use ($motivo) {
            // Cargar relaciones necesarias
            $this->load('cargas');

            foreach ($this->cargas as $carga) {
                $cantidadTotal = floatval($carga->cantidad);

                // Usar campos de origen para determinar exactamente cuánto vino de cada fuente
                $cantidadDeLote = floatval($carga->cantidad_de_lote ?? 0);
                $cantidadDeBodega = floatval($carga->cantidad_de_bodega ?? 0);

                // Fallback legacy: si no hay campos de origen, todo es bodega
                if ($cantidadDeBodega == 0 && $cantidadDeLote == 0) {
                    $cantidadDeBodega = $cantidadTotal;
                }

                $reempaqueService = app(\App\Application\Services\ReempaqueService::class);

                // 1. Devolver unidades al lote (revertir reempaque)
                if ($cantidadDeLote > 0 && $carga->reempaque_id) {
                    $reempaque = Reempaque::find($carga->reempaque_id);

                    if ($reempaque && ! $reempaque->estaInactivo()) {
                        $reempaqueService->revertirReempaqueParcial(
                            $carga->reempaque_id,
                            $carga->producto_id,
                            $cantidadDeLote
                        );
                    }
                }

                // 2. Devolver unidades de bodega con costo original
                if ($cantidadDeBodega > 0) {
                    $bodegaProducto = BodegaProducto::where('bodega_id', $this->bodega_origen_id)
                        ->where('producto_id', $carga->producto_id)
                        ->lockForUpdate()
                        ->first();

                    $costoOriginal = floatval($carga->costo_bodega_original ?? $carga->costo_unitario ?? 0);

                    if ($bodegaProducto) {
                        $reempaqueService->devolverStockABodega($bodegaProducto, $cantidadDeBodega, $costoOriginal, [
                            'kardex_tipo' => 'retorno_viaje',
                            'kardex_descripcion' => "Cancelación de viaje #{$this->id}",
                            'kardex_referencia_type' => $this->getMorphClass(),
                            'kardex_referencia_id' => $this->id,
                        ]);
                    } else {
                        $bp = BodegaProducto::create([
                            'bodega_id' => $this->bodega_origen_id,
                            'producto_id' => $carga->producto_id,
                            'stock' => $cantidadDeBodega,
                            'costo_promedio_actual' => round($costoOriginal, 4),
                            'stock_minimo' => 0,
                            'activo' => true,
                        ]);
                        $bp->actualizarPrecioVentaSegunCosto();
                        $bp->save();
                    }
                }
            }

            // Agregar motivo de cancelación
            if ($motivo) {
                $this->observaciones = $this->observaciones
                    ? $this->observaciones."\n[CANCELADO] ".$motivo
                    : '[CANCELADO] '.$motivo;
            }

            // Cambiar estado
            $this->cambiarEstado(self::ESTADO_CANCELADO);
        });
    }

    /**
     * Procesar reintegro de descargas al cerrar el viaje.
     *
     * Refactor 2026-07-12: delegado a ReintegroDescargasService — único punto
     * de verdad del destino del retorno (también usado por la acción manual
     * de DescargasRelationManager). Regla de negocio:
     *
     *   - Producto BASE 1x30 (categoría auto-referenciada) → regresa AL LOTE
     *     (reversión del reempaque automático, costo según WAC actual).
     *   - OPOA/derivados y productos sin lote → bodega_producto (como antes).
     *   - Fracciones (sueltos) → lote único del producto.
     *
     * El servicio respeta y marca viaje_descargas.procesado_reingreso, por lo
     * que un reingreso manual previo ya no se duplica al cerrar.
     */
    protected function procesarReintegroDescargas(): void
    {
        app(\App\Services\Viaje\ReintegroDescargasService::class)
            ->procesarReintegrosPendientes($this);
    }

    // ============================================
    // MÉTODOS DE VERIFICACIÓN
    // ============================================

    /**
     * 🎯 NUEVO: Verificar si tiene ventas activas (no canceladas)
     */
    public function tieneVentasActivas(): bool
    {
        return $this->ventasRuta()
            ->whereIn('estado', ['borrador', 'confirmada', 'completada'])
            ->exists();
    }

    /**
     * 🎯 NUEVO: Verificar si se puede cancelar el viaje
     */
    public function puedeCancelarse(): bool
    {
        if (in_array($this->estado, [self::ESTADO_CERRADO, self::ESTADO_CANCELADO])) {
            return false;
        }

        return ! $this->tieneVentasActivas();
    }

    public function estaActivo(): bool
    {
        return ! in_array($this->estado, [self::ESTADO_CERRADO, self::ESTADO_CANCELADO]);
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
        return in_array($this->estado, [self::ESTADO_PLANIFICADO, self::ESTADO_CARGANDO, self::ESTADO_RECARGANDO]);
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
        $this->total_cargado_costo = $this->cargas()->sum('subtotal_costo');
        $this->total_cargado_venta = $this->cargas()->sum('subtotal_venta');

        $this->total_vendido = $this->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->sum('total');

        $this->total_merma_costo = $this->mermas()->sum('subtotal_costo');
        $this->total_devuelto_costo = $this->descargas()->sum('subtotal_costo');

        $this->comision_ganada = $this->comisionesDetalle()->sum('comision_total');

        $cobrosDescargas = $this->descargas()->where('cobrar_chofer', true)->sum('monto_cobrar');
        $cobrosMermas = $this->mermas()->where('cobrar_chofer', true)->sum('monto_cobrar');
        $this->cobros_devoluciones = $cobrosDescargas + $cobrosMermas;

        $this->neto_chofer = $this->comision_ganada - $this->cobros_devoluciones;

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
        $this->comisionesDetalle()->delete();

        $ventas = $this->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->with(['detalles.producto.categoria'])
            ->get();

        foreach ($ventas as $venta) {
            foreach ($venta->detalles as $detalle) {
                $this->calcularComisionDetalleRuta($venta, $detalle);
            }
        }

        $this->comision_ganada = $this->comisionesDetalle()->sum('comision_total');
        $this->neto_chofer = $this->comision_ganada - $this->cobros_devoluciones;
        $this->save();
    }

    protected function calcularComisionDetalleRuta(ViajeVenta $venta, ViajeVentaDetalle $detalle): void
    {
        $producto = $detalle->producto;

        if (! $producto) {
            return;
        }

        // Líneas a precio 0 no generan comisión: son entregas sin venta real
        // (cambio/reposición al cliente, cortesía). Sin esta guarda, la
        // comisión fija le pagaría al chofer por producto que entregó gratis.
        if ((float) $detalle->precio_base <= 0) {
            return;
        }

        $categoriaId = $producto->categoria_id;

        $carga = $this->cargas()->where('producto_id', $producto->id)->first();
        $unidadId = $carga?->unidad_id;

        $comisionConfig = $this->chofer->getComisionPara($producto->id, $categoriaId, $unidadId);

        if ($comisionConfig['normal'] <= 0) {
            return;
        }

        $precioSugerido = $carga?->precio_venta_sugerido ?? $detalle->precio_base;
        $precioVendido = $detalle->precio_base;

        $tipoComision = $precioVendido >= $precioSugerido
            ? ViajeComisionDetalle::TIPO_NORMAL
            : ViajeComisionDetalle::TIPO_REDUCIDA;

        $tasaComision = $tipoComision === ViajeComisionDetalle::TIPO_NORMAL
            ? $comisionConfig['normal']
            : ($comisionConfig['reducida'] ?? $comisionConfig['normal']);

        $unidad = $carga?->unidad;
        $factorUnidad = 1;

        if ($unidad && is_numeric($unidad->simbolo)) {
            $factorUnidad = (float) $unidad->simbolo;
        }

        $esPorcentaje = ($comisionConfig['tipo_comision'] ?? ChoferComisionConfig::TIPO_FIJO) === ChoferComisionConfig::TIPO_PORCENTAJE;

        if ($esPorcentaje) {
            $comisionUnitaria = $precioVendido * ($tasaComision / 100);
            $comisionTotal = $detalle->cantidad * $comisionUnitaria;
        } else {
            $comisionUnitaria = $tasaComision * $factorUnidad;
            $comisionTotal = $detalle->cantidad * $comisionUnitaria;
        }

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
            'comision_unitaria' => round($comisionUnitaria, 4),
            'comision_total' => round($comisionTotal, 2),
        ]);
    }

    protected function calcularComisionDetalle(Venta $venta, VentaDetalle $detalle): void
    {
        $producto = $detalle->producto;

        // Misma guarda que calcularComisionDetalleRuta: precio 0 = entrega
        // sin venta real, no genera comisión.
        if ((float) $detalle->precio_unitario <= 0) {
            return;
        }

        $categoriaId = $producto->categoria_id;
        $unidadId = $detalle->unidad_id;

        $comisionConfig = $this->chofer->getComisionPara($producto->id, $categoriaId, $unidadId);

        if ($comisionConfig['normal'] <= 0) {
            return;
        }

        $carga = $this->cargas()->where('producto_id', $producto->id)->first();
        $precioSugerido = $carga?->precio_venta_sugerido ?? $detalle->precio_unitario;

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
            // FIX: aplicar round() igual que en calcularComisionDetalleRuta() para
            // consistencia y evitar que el cast decimal:4 pierda precisión silenciosamente.
            'comision_unitaria' => round($comisionUnitaria, 4),
            'comision_total' => round($comisionTotal, 2),
        ]);
    }

    /**
     * Liquidar viaje completo - calcular comisiones y cobros
     */
    public function liquidarCompleto(): array
    {
        $this->recalcularTotales();
        $this->calcularComisiones();
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
        if (! $this->km_salida || ! $this->km_regreso) {
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
            if (! $viaje->numero_viaje) {
                $viaje->numero_viaje = self::generarNumeroViaje($viaje);
            }

            if (! $viaje->estado) {
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

        return "VJ-{$camionCodigo}-{$fecha}-".str_pad($numero, 3, '0', STR_PAD_LEFT);
    }
}
