<?php

namespace App\Models\Traits;

use App\Models\ViajeComisionDetalle;
use App\Models\ChoferComisionConfig;
use App\Models\ChoferComisionProducto;

trait CalculaComisionesViaje
{
    /**
     * Calcular comisiones basadas en las ventas de ruta (ViajeVenta)
     */
    public function calcularComisionesRuta(): void
    {
        // Eliminar comisiones anteriores
        $this->comisionesDetalle()->delete();

        // Obtener las ventas de ruta completadas
        $ventas = $this->ventasRuta()
            ->where('estado', 'completada')
            ->with(['detalles.producto.categoria'])
            ->get();

        $totalComisiones = 0;

        foreach ($ventas as $venta) {
            foreach ($venta->detalles as $detalle) {
                $comision = $this->calcularComisionDetalleRuta($venta, $detalle);
                if ($comision) {
                    $totalComisiones += $comision->comision_total;
                }
            }
        }

        // Actualizar totales del viaje
        $this->comision_ganada = $totalComisiones;
        $this->neto_chofer = $this->comision_ganada - $this->cobros_devoluciones;
        $this->save();
    }

    /**
     * Calcular comisión para un detalle de venta de ruta
     * Usa el símbolo de la unidad como factor de equivalencia
     * Ej: Cartón = 1, Medio Cartón = 0.5
     */
    protected function calcularComisionDetalleRuta($venta, $detalle): ?ViajeComisionDetalle
    {
        $producto = $detalle->producto;
        
        if (!$producto) {
            return null;
        }

        $categoriaId = $producto->categoria_id;

        // Obtener configuración de comisión (solo por categoría, ya no por unidad)
        $comisionConfig = $this->obtenerConfigComision($producto->id, $categoriaId);

        if (!$comisionConfig || $comisionConfig['normal'] <= 0) {
            return null; // Sin comisión configurada
        }

        // Obtener el factor de equivalencia desde el símbolo de la unidad
        // Ej: Cartón = 1, Medio Cartón = 0.5
        $carga = $this->cargas()->where('producto_id', $producto->id)->first();
        $unidad = $carga?->unidad;
        $factorUnidad = 1.0;
        
        if ($unidad && is_numeric($unidad->simbolo)) {
            $factorUnidad = (float) $unidad->simbolo;
        }

        // Obtener precio sugerido de la carga
        $precioSugerido = $carga?->precio_venta_sugerido ?? $detalle->precio_base;

        // Determinar tipo de comisión (normal si vendió >= sugerido, reducida si vendió menos)
        $tipoComision = $detalle->precio_base >= $precioSugerido ? 'normal' : 'reducida';
        $comisionBase = $tipoComision === 'normal'
            ? $comisionConfig['normal']
            : $comisionConfig['reducida'];

        // Calcular comisión unitaria aplicando el factor de la unidad
        // Ej: Si comisión = L2.00 y factor = 0.5 (medio cartón), comisión unitaria = L1.00
        $comisionUnitaria = $comisionBase * $factorUnidad;

        // Comisión total = cantidad × comisión unitaria
        $comisionTotal = $detalle->cantidad * $comisionUnitaria;

        return ViajeComisionDetalle::create([
            'viaje_id' => $this->id,
            'viaje_venta_id' => $venta->id,
            'viaje_venta_detalle_id' => $detalle->id,
            'producto_id' => $producto->id,
            'cantidad' => $detalle->cantidad,
            'precio_vendido' => $detalle->precio_base,
            'precio_sugerido' => $precioSugerido,
            'costo' => $detalle->costo_unitario,
            'tipo_comision' => $tipoComision,
            'comision_unitaria' => $comisionUnitaria,
            'comision_total' => $comisionTotal,
        ]);
    }

    /**
     * Obtener configuración de comisión para el chofer
     * Solo busca por categoría (ya no por unidad específica)
     */
    protected function obtenerConfigComision(int $productoId, ?int $categoriaId): ?array
    {
        $choferId = $this->chofer_id;

        // 1. Primero buscar excepción específica por producto
        $excepcion = ChoferComisionProducto::where('user_id', $choferId)
            ->where('producto_id', $productoId)
            ->where('activo', true)
            ->where(function ($q) {
                $q->whereNull('vigente_hasta')
                  ->orWhere('vigente_hasta', '>=', now());
            })
            ->first();

        if ($excepcion) {
            return [
                'normal' => (float) $excepcion->comision_normal,
                'reducida' => (float) $excepcion->comision_reducida,
            ];
        }

        // 2. Buscar configuración por categoría
        $config = ChoferComisionConfig::where('user_id', $choferId)
            ->where('categoria_id', $categoriaId)
            ->where('activo', true)
            ->where(function ($q) {
                $q->whereNull('vigente_hasta')
                  ->orWhere('vigente_hasta', '>=', now());
            })
            ->first();

        if ($config) {
            return [
                'normal' => (float) $config->comision_normal,
                'reducida' => (float) $config->comision_reducida,
            ];
        }

        // Sin configuración
        return null;
    }

    /**
     * Recalcular totales incluyendo ventas de ruta
     */
    public function recalcularTotalesConRuta(): void
    {
        // Totales de carga
        $this->total_cargado_costo = $this->cargas()->sum('subtotal_costo');
        $this->total_cargado_venta = $this->cargas()->sum('subtotal_venta');

        // Totales de ventas de RUTA (ViajeVenta)
        $this->total_vendido = $this->ventasRuta()
            ->whereIn('estado', ['completada', 'confirmada'])
            ->sum('total');

        // Totales de merma
        $this->total_merma_costo = $this->mermas()->sum('subtotal_costo');

        // Totales de devolución
        $this->total_devuelto_costo = $this->descargas()->sum('subtotal_costo');

        // Cobros por devoluciones y mermas
        $this->cobros_devoluciones = $this->descargas()->where('cobrar_chofer', true)->sum('monto_cobrar')
            + $this->mermas()->where('cobrar_chofer', true)->sum('monto_cobrar');

        // Neto chofer (se actualiza después de calcular comisiones)
        $this->neto_chofer = $this->comision_ganada - $this->cobros_devoluciones;

        // Efectivo
        $ventasEfectivo = $this->ventasRuta()
            ->whereIn('estado', ['completada', 'confirmada'])
            ->where('tipo_pago', 'contado')
            ->sum('total');

        $this->efectivo_esperado = $this->efectivo_inicial + $ventasEfectivo;
        $this->diferencia_efectivo = $this->efectivo_entregado - $this->efectivo_esperado;

        $this->save();
    }

    /**
     * Liquidar viaje completo
     */
    public function liquidarCompleto(): array
    {
        // 1. Recalcular totales
        $this->recalcularTotalesConRuta();

        // 2. Calcular comisiones
        $this->calcularComisionesRuta();

        // 3. Recalcular neto (después de comisiones)
        $this->neto_chofer = $this->comision_ganada - $this->cobros_devoluciones;
        $this->save();

        return [
            'total_vendido' => $this->total_vendido,
            'total_merma' => $this->total_merma_costo,
            'total_devuelto' => $this->total_devuelto_costo,
            'comision_ganada' => $this->comision_ganada,
            'cobros' => $this->cobros_devoluciones,
            'neto_chofer' => $this->neto_chofer,
            'efectivo_esperado' => $this->efectivo_esperado,
        ];
    }
}