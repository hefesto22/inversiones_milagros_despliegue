<?php

namespace App\Services;

use App\DTOs\LiquidacionData;
use App\Models\Viaje;
use App\Models\CamionGasto;

/**
 * Servicio que calcula todos los datos de liquidacion de un viaje.
 *
 * Fuente unica de verdad para cualquier contexto que necesite
 * datos de liquidacion: PDF, Filament UI, API, reportes, etc.
 *
 * IMPORTANTE: Todos los calculos se hacen en tiempo real desde la BD,
 * no dependen de campos pre-calculados. Esto permite que la liquidacion
 * se pueda consultar en cualquier estado del viaje (en_ruta, cerrado, etc.)
 */
class LiquidacionCalculatorService
{
    /**
     * Calcula todos los datos de liquidacion para un viaje.
     *
     * El viaje debe venir con las relaciones necesarias cargadas,
     * o se cargaran automaticamente via lazy loading.
     * Para mejor performance, usar eagerLoadViaje() antes.
     */
    public function calcular(Viaje $viaje): LiquidacionData
    {
        // Ventas confirmadas/completadas
        $ventasContado = (float) $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->where('tipo_pago', '!=', 'credito')
            ->sum('total');

        $ventasCredito = (float) $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->where('tipo_pago', 'credito')
            ->sum('total');

        $totalVentas = $ventasContado + $ventasCredito;

        // Gastos aprobados
        $gastosAprobados = CamionGasto::where('viaje_id', $viaje->id)
            ->where('estado', 'aprobado')
            ->get();

        $totalGastos = (float) $gastosAprobados->sum('monto');

        // Ventas con detalles para calcular costos y descuentos
        $ventas = $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->with(['detalles.viajeCarga', 'detalles.producto'])
            ->get();

        // Calcular costos, ISV y descuentos desde las ventas
        [$costoData, $detalleDescuentos] = $this->calcularCostosYDescuentos($ventas);

        $detalleDescuentos = collect(array_values($detalleDescuentos));

        // Efectivo
        $efectivoDebeEntregar = $ventasContado;
        $efectivoEntregado = (float) ($viaje->efectivo_entregado ?? 0);
        $diferenciaEfectivo = $efectivoEntregado - $efectivoDebeEntregar;

        // Porcentajes
        $totalCargado = (float) $viaje->cargas()->sum('cantidad');
        $totalVendidoUnidades = (float) $viaje->cargas()->sum('cantidad_vendida');
        $porcentajeVendido = $totalCargado > 0 ? ($totalVendidoUnidades / $totalCargado) * 100 : 0;

        // Detalle de ventas por cliente
        $ventasPorCliente = $this->calcularVentasPorCliente($viaje);

        // Precios reales de venta por producto
        $preciosRealesVenta = $this->calcularPreciosRealesVenta($ventas);

        // Detalle de carga inicial
        $cargaInicial = $this->calcularCargaInicial($viaje, $preciosRealesVenta);

        // Totales de ISV
        $totalIsvCreditoFiscal = (float) $cargaInicial->sum('total_isv_costo_vendido');
        $totalIsvCobrado = (float) $cargaInicial->sum('total_isv_venta_vendido');
        $totalIsvAPagarSar = (float) $cargaInicial->sum('total_isv_a_pagar');
        $totalGananciaReal = (float) $cargaInicial->sum('total_ganancia_vendido');

        // Totales de carga - calcular desde las cargas (no campos guardados)
        $totalCargadoCosto = (float) $cargaInicial->sum('costo_total');
        $totalCargadoVenta = (float) $cargaInicial->sum('venta_esperada');

        // Costo de lo vendido - desde la carga para que cuadre con la tabla
        $costoVendidoConIsv = 0;
        foreach ($cargaInicial as $item) {
            $costoVendidoConIsv += $item['vendida'] * $item['costo_con_isv'];
        }

        // Venta esperada de lo vendido
        $ventaEsperadaDeLoVendido = 0;
        foreach ($viaje->cargas as $carga) {
            $cantidadVendida = $carga->cantidad_vendida ?? 0;
            $precioSugerido = $carga->precio_venta_sugerido ?? 0;
            $ventaEsperadaDeLoVendido += $cantidadVendida * $precioSugerido;
        }

        // ============================================
        // MERMAS EN TIEMPO REAL (siempre desde la BD)
        // ============================================
        $mermasDetalle = $viaje->mermas()
            ->with(['producto', 'unidad'])
            ->get();

        $totalMermaCosto = (float) $mermasDetalle->sum('subtotal_costo');
        $mermasCobrarChofer = (float) $mermasDetalle
            ->where('cobrar_chofer', true)
            ->sum('monto_cobrar');

        // Rentabilidad (incluyendo mermas)
        $margenBruto = $totalVentas - $costoVendidoConIsv;
        $comisiones = (float) ($viaje->comision_ganada ?? 0);
        $utilidadNeta = $margenBruto - $totalGastos - $totalMermaCosto;

        return new LiquidacionData(
            ventas_contado: $ventasContado,
            ventas_credito: $ventasCredito,
            total_ventas: $totalVentas,
            ventas_por_cliente: $ventasPorCliente,

            carga_inicial: $cargaInicial,
            total_cargado_costo: $totalCargadoCosto,
            total_cargado_venta: $totalCargadoVenta,
            porcentaje_vendido: $porcentajeVendido,

            total_descuentos: $costoData['total_descuentos'],
            detalle_descuentos: $detalleDescuentos,
            venta_esperada_de_lo_vendido: $ventaEsperadaDeLoVendido,

            total_devuelto_costo: (float) ($viaje->total_devuelto_costo ?? 0),

            total_merma_costo: $totalMermaCosto,
            mermas_detalle: $mermasDetalle,
            mermas_cobrar_chofer: $mermasCobrarChofer,

            gastos_aprobados: $gastosAprobados,
            total_gastos: $totalGastos,

            comision_ganada: $comisiones,

            costo_vendido: $costoVendidoConIsv,
            margen_bruto: $margenBruto,
            utilidad_neta: $utilidadNeta,

            isv_cobrado: $totalIsvCobrado,
            isv_credito_fiscal: round($totalIsvCreditoFiscal, 2),
            isv_a_pagar_sar: round($totalIsvAPagarSar, 2),
            ganancia_real_sin_isv: round($totalGananciaReal, 2),

            efectivo_debe_entregar: $efectivoDebeEntregar,
            efectivo_entregado: $efectivoEntregado,
            diferencia_efectivo: $diferenciaEfectivo,
            estado_efectivo: $this->getEstadoEfectivo($diferenciaEfectivo, $efectivoDebeEntregar, $efectivoEntregado),
        );
    }

    /**
     * Carga eager las relaciones necesarias para el calculo.
     * Llamar antes de calcular() para evitar N+1.
     */
    public function eagerLoadViaje(Viaje $viaje): Viaje
    {
        return $viaje->load([
            'camion',
            'chofer',
            'bodegaOrigen',
            'cargas.producto',
            'cargas.unidad',
            'ventasRuta.cliente',
            'ventasRuta.detalles.producto',
            'ventasRuta.detalles.viajeCarga',
            'mermas.producto',
            'mermas.unidad',
        ]);
    }

    // ============================================
    // METODOS PRIVADOS DE CALCULO
    // ============================================

    /**
     * Calcula costos de lo vendido, ISV y descuentos desde las ventas.
     */
    private function calcularCostosYDescuentos($ventas): array
    {
        $totalDescuentos = 0;
        $detalleDescuentos = [];

        foreach ($ventas as $venta) {
            foreach ($venta->detalles as $detalle) {
                // Calcular descuento (precio sugerido - precio vendido)
                $precioSugerido = $detalle->viajeCarga?->precio_venta_sugerido ?? $detalle->precio_base;
                $precioVendido = $detalle->precio_base;

                if ($precioVendido < $precioSugerido) {
                    $descuentoUnitario = $precioSugerido - $precioVendido;
                    $descuentoLinea = $descuentoUnitario * $detalle->cantidad;
                    $totalDescuentos += $descuentoLinea;

                    $productoNombre = $detalle->producto->nombre ?? 'Producto';
                    if (!isset($detalleDescuentos[$productoNombre])) {
                        $detalleDescuentos[$productoNombre] = [
                            'producto' => $productoNombre,
                            'cantidad' => 0,
                            'precio_sugerido' => $precioSugerido,
                            'precio_vendido' => $precioVendido,
                            'descuento_unitario' => $descuentoUnitario,
                            'descuento_total' => 0,
                        ];
                    }
                    $detalleDescuentos[$productoNombre]['cantidad'] += $detalle->cantidad;
                    $detalleDescuentos[$productoNombre]['descuento_total'] += $descuentoLinea;
                }
            }
        }

        return [
            ['total_descuentos' => $totalDescuentos],
            $detalleDescuentos,
        ];
    }

    /**
     * Calcula el detalle de ventas agrupado por cliente.
     */
    private function calcularVentasPorCliente(Viaje $viaje): \Illuminate\Support\Collection
    {
        return $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->with('cliente')
            ->get()
            ->map(function ($venta) {
                return [
                    'numero' => $venta->numero_venta,
                    'cliente' => $venta->cliente->nombre ?? 'Sin cliente',
                    'tipo_pago' => $venta->tipo_pago == 'credito' ? 'Credito' : 'Contado',
                    'subtotal' => $venta->subtotal,
                    'impuesto' => $venta->impuesto,
                    'total' => $venta->total,
                ];
            });
    }

    /**
     * Calcula los precios reales de venta promedio por producto.
     */
    private function calcularPreciosRealesVenta($ventas): array
    {
        $preciosRealesVenta = [];

        foreach ($ventas as $venta) {
            foreach ($venta->detalles as $detalle) {
                $productoId = $detalle->producto_id;
                if (!isset($preciosRealesVenta[$productoId])) {
                    $preciosRealesVenta[$productoId] = [
                        'precio_total' => 0,
                        'cantidad' => 0,
                    ];
                }
                $preciosRealesVenta[$productoId]['precio_total'] += $detalle->precio_base * $detalle->cantidad;
                $preciosRealesVenta[$productoId]['cantidad'] += $detalle->cantidad;
            }
        }

        return $preciosRealesVenta;
    }

    /**
     * Calcula el detalle de carga inicial con desglose de ISV.
     */
    private function calcularCargaInicial(Viaje $viaje, array $preciosRealesVenta): \Illuminate\Support\Collection
    {
        return $viaje->cargas()
            ->with(['producto', 'unidad'])
            ->get()
            ->map(function ($carga) use ($preciosRealesVenta) {
                $costoSinIsv = $carga->costo_unitario ?? 0;
                $aplicaIsv = $carga->producto->aplica_isv ?? false;
                $cantidad = $carga->cantidad;
                $cantidadVendida = $carga->cantidad_vendida ?? 0;
                $productoId = $carga->producto_id;

                $precioVentaSinIsv = $carga->precio_venta_sugerido ?? 0;

                $precioRealVentaSinIsv = $precioVentaSinIsv;
                if (isset($preciosRealesVenta[$productoId]) && $preciosRealesVenta[$productoId]['cantidad'] > 0) {
                    $precioRealVentaSinIsv = $preciosRealesVenta[$productoId]['precio_total'] / $preciosRealesVenta[$productoId]['cantidad'];
                }

                $costoConIsv = $aplicaIsv && $costoSinIsv > 0
                    ? round($costoSinIsv * 1.15, 2)
                    : $costoSinIsv;

                $isvCosto = $aplicaIsv ? round($costoSinIsv * 0.15, 2) : 0;

                $precioVentaConIsv = $aplicaIsv && $precioVentaSinIsv > 0
                    ? round($precioVentaSinIsv * 1.15, 2)
                    : $precioVentaSinIsv;

                $precioRealVentaConIsv = $aplicaIsv && $precioRealVentaSinIsv > 0
                    ? round($precioRealVentaSinIsv * 1.15, 2)
                    : $precioRealVentaSinIsv;

                $isvVenta = $aplicaIsv ? round($precioVentaSinIsv * 0.15, 2) : 0;
                $isvVentaReal = $aplicaIsv ? round($precioRealVentaSinIsv * 0.15, 2) : 0;

                $gananciaUnitaria = $precioVentaSinIsv - $costoSinIsv;
                $gananciaUnitariaReal = $precioRealVentaSinIsv - $costoSinIsv;

                $isvAPagarUnitario = $isvVenta - $isvCosto;

                $diferenciaPrecio = $precioRealVentaConIsv - $precioVentaConIsv;

                return [
                    'producto' => $carga->producto->nombre ?? 'N/A',
                    'unidad' => $carga->unidad->abreviatura ?? $carga->unidad->nombre ?? 'N/A',
                    'cantidad' => $cantidad,
                    'vendida' => $cantidadVendida,
                    'devuelta' => $carga->cantidad_devuelta ?? 0,
                    'aplica_isv' => $aplicaIsv,
                    'costo_con_isv' => $costoConIsv,
                    'costo_sin_isv' => $costoSinIsv,
                    'isv_costo' => $isvCosto,
                    'costo_total' => $cantidad * $costoConIsv,
                    'precio_venta_con_isv' => $precioVentaConIsv,
                    'precio_venta_sin_isv' => $precioVentaSinIsv,
                    'isv_venta' => $isvVenta,
                    'venta_esperada' => $cantidad * $precioVentaConIsv,
                    'precio_real_venta_con_isv' => $precioRealVentaConIsv,
                    'precio_real_venta_sin_isv' => $precioRealVentaSinIsv,
                    'isv_venta_real' => $isvVentaReal,
                    'venta_real' => $cantidadVendida * $precioRealVentaConIsv,
                    'diferencia_precio' => $diferenciaPrecio,
                    'ganancia_unitaria' => $gananciaUnitaria,
                    'ganancia_unitaria_real' => $gananciaUnitariaReal,
                    'isv_a_pagar_unitario' => $isvAPagarUnitario,
                    'total_isv_costo_vendido' => $cantidadVendida * $isvCosto,
                    'total_isv_venta_vendido' => $cantidadVendida * $isvVentaReal,
                    'total_isv_a_pagar' => $cantidadVendida * ($isvVentaReal - $isvCosto),
                    'total_ganancia_vendido' => $cantidadVendida * $gananciaUnitariaReal,
                ];
            });
    }

    /**
     * Determina el estado del efectivo entregado.
     */
    private function getEstadoEfectivo(float $diferencia, float $debeEntregar, float $entregado): string
    {
        if ($entregado == 0 && $debeEntregar > 0) {
            return 'Pendiente';
        }

        if (abs($diferencia) < 0.01) {
            return 'Cuadrado';
        } elseif ($diferencia > 0) {
            return 'Sobrante';
        } else {
            return 'Faltante';
        }
    }
}
