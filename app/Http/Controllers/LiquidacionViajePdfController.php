<?php

namespace App\Http\Controllers;

use App\Models\Viaje;
use App\Models\Empresa;
use App\Models\CamionGasto;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class LiquidacionViajePdfController extends Controller
{
    public function generate(Viaje $viaje): Response
    {
        $viaje->load([
            'camion',
            'chofer',
            'bodegaOrigen',
            'cargas.producto',
            'cargas.unidad',
            'ventasRuta.cliente',
            'ventasRuta.detalles.producto',
            'ventasRuta.detalles.viajeCarga',
            'mermas.producto',
        ]);

        $empresa = Empresa::getData();
        $datos = $this->calcularDatosLiquidacion($viaje);

        // Forzar configuración para Hostinger
        $pdf = Pdf::setOption(['chroot' => base_path()])
            ->loadView('pdf.liquidacion-viaje', [
                'viaje' => $viaje,
                'empresa' => $empresa,
                'datos' => $datos,
                'fechaImpresion' => now()->format('d/m/Y H:i'),
            ]);

        $pdf->setPaper('letter', 'portrait');

        $filename = "Liquidacion-{$viaje->numero_viaje}.pdf";

        return $pdf->stream($filename);
    }

    public function download(Viaje $viaje): Response
    {
        $viaje->load([
            'camion',
            'chofer',
            'bodegaOrigen',
            'cargas.producto',
            'cargas.unidad',
            'ventasRuta.cliente',
            'ventasRuta.detalles.producto',
            'ventasRuta.detalles.viajeCarga',
            'mermas.producto',
        ]);

        $empresa = Empresa::getData();
        $datos = $this->calcularDatosLiquidacion($viaje);

        // Forzar configuración para Hostinger
        $pdf = Pdf::setOption(['chroot' => base_path()])
            ->loadView('pdf.liquidacion-viaje', [
                'viaje' => $viaje,
                'empresa' => $empresa,
                'datos' => $datos,
                'fechaImpresion' => now()->format('d/m/Y H:i'),
            ]);

        $pdf->setPaper('letter', 'portrait');

        $filename = "Liquidacion-{$viaje->numero_viaje}.pdf";

        return $pdf->download($filename);
    }

    protected function calcularDatosLiquidacion(Viaje $viaje): array
    {
        // Ventas
        $ventasContado = $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->where('tipo_pago', '!=', 'credito')
            ->sum('total');

        $ventasCredito = $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->where('tipo_pago', 'credito')
            ->sum('total');

        $totalVentas = $ventasContado + $ventasCredito;

        // Gastos aprobados
        $gastosAprobados = CamionGasto::where('viaje_id', $viaje->id)
            ->where('estado', 'aprobado')
            ->get();

        $totalGastos = $gastosAprobados->sum('monto');

        // Obtener ventas con detalles para calcular costo y descuentos
        $ventas = $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->with(['detalles.viajeCarga'])
            ->get();

        // Costo de lo vendido y descuentos
        $costoVendido = 0;
        $costoVendidoConIsv = 0;
        $isvCreditoFiscal = 0;
        $isvCobrado = 0;
        $totalDescuentos = 0;
        $detalleDescuentos = [];

        foreach ($ventas as $venta) {
            // Sumar ISV cobrado de cada venta
            $isvCobrado += (float) ($venta->impuesto ?? 0);

            foreach ($venta->detalles as $detalle) {
                // Costo de lo vendido (sin ISV)
                $costoUnitarioSinIsv = $detalle->costo_unitario;
                $cantidad = $detalle->cantidad;
                $aplicaIsv = $detalle->producto->aplica_isv ?? false;
                
                // Costo con ISV (lo que realmente pagamos)
                $costoUnitarioConIsv = $aplicaIsv && $costoUnitarioSinIsv > 0
                    ? $costoUnitarioSinIsv * 1.15
                    : $costoUnitarioSinIsv;
                
                $costoLineaSinIsv = $costoUnitarioSinIsv * $cantidad;
                $costoLineaConIsv = $costoUnitarioConIsv * $cantidad;
                
                $costoVendido += $costoLineaSinIsv;
                $costoVendidoConIsv += $costoLineaConIsv;

                // Calcular ISV del costo (crédito fiscal) - solo si el producto aplica ISV
                if ($aplicaIsv && $costoUnitarioSinIsv > 0) {
                    $isvDelCosto = $costoLineaSinIsv * 0.15;
                    $isvCreditoFiscal += $isvDelCosto;
                }

                // Calcular descuento (precio sugerido - precio vendido)
                $precioSugerido = $detalle->viajeCarga?->precio_venta_sugerido ?? $detalle->precio_base;
                $precioVendido = $detalle->precio_base;

                if ($precioVendido < $precioSugerido) {
                    $descuentoUnitario = $precioSugerido - $precioVendido;
                    $descuentoLinea = $descuentoUnitario * $detalle->cantidad;
                    $totalDescuentos += $descuentoLinea;

                    // Guardar detalle del descuento
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

        // Convertir a collection
        $detalleDescuentos = collect(array_values($detalleDescuentos));

        // IMPORTANTE: El costo vendido se calcula DESPUÉS de procesar la carga inicial
        // para usar exactamente los mismos valores que la tabla

        // Efectivo
        $efectivoDebeEntregar = $ventasContado;
        $efectivoEntregado = (float) ($viaje->efectivo_entregado ?? 0);
        $diferenciaEfectivo = $efectivoEntregado - $efectivoDebeEntregar;

        // Porcentajes
        $totalCargado = (float) $viaje->cargas()->sum('cantidad');
        $totalVendidoUnidades = (float) $viaje->cargas()->sum('cantidad_vendida');
        $porcentajeVendido = $totalCargado > 0 ? ($totalVendidoUnidades / $totalCargado) * 100 : 0;

        // Detalle de ventas por cliente
        $ventasPorCliente = $viaje->ventasRuta()
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

        // Detalle de carga inicial CON desglose de ISV
        // Primero obtener precios reales de venta por producto
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

        $cargaInicial = $viaje->cargas()
            ->with(['producto', 'unidad'])
            ->get()
            ->map(function ($carga) use ($preciosRealesVenta) {
                $costoSinIsv = $carga->costo_unitario ?? 0;
                $aplicaIsv = $carga->producto->aplica_isv ?? false;
                $cantidad = $carga->cantidad;
                $cantidadVendida = $carga->cantidad_vendida ?? 0;
                $productoId = $carga->producto_id;
                
                // El precio_venta_sugerido está guardado SIN ISV
                $precioVentaSinIsv = $carga->precio_venta_sugerido ?? 0;
                
                // Precio real de venta (promedio de lo vendido)
                $precioRealVentaSinIsv = $precioVentaSinIsv; // Por defecto el sugerido
                if (isset($preciosRealesVenta[$productoId]) && $preciosRealesVenta[$productoId]['cantidad'] > 0) {
                    $precioRealVentaSinIsv = $preciosRealesVenta[$productoId]['precio_total'] / $preciosRealesVenta[$productoId]['cantidad'];
                }
                
                // Costo CON ISV (lo que realmente pagó)
                $costoConIsv = $aplicaIsv && $costoSinIsv > 0 
                    ? round($costoSinIsv * 1.15, 2) 
                    : $costoSinIsv;
                
                // ISV del costo (crédito fiscal)
                $isvCosto = $aplicaIsv ? round($costoSinIsv * 0.15, 2) : 0;
                
                // Precio de venta CON ISV (sugerido)
                $precioVentaConIsv = $aplicaIsv && $precioVentaSinIsv > 0
                    ? round($precioVentaSinIsv * 1.15, 2)
                    : $precioVentaSinIsv;
                
                // Precio real de venta CON ISV
                $precioRealVentaConIsv = $aplicaIsv && $precioRealVentaSinIsv > 0
                    ? round($precioRealVentaSinIsv * 1.15, 2)
                    : $precioRealVentaSinIsv;
                
                // ISV de la venta
                $isvVenta = $aplicaIsv ? round($precioVentaSinIsv * 0.15, 2) : 0;
                $isvVentaReal = $aplicaIsv ? round($precioRealVentaSinIsv * 0.15, 2) : 0;
                
                // Ganancia real (sin ISV)
                $gananciaUnitaria = $precioVentaSinIsv - $costoSinIsv;
                $gananciaUnitariaReal = $precioRealVentaSinIsv - $costoSinIsv;
                
                // ISV a pagar por unidad
                $isvAPagarUnitario = $isvVenta - $isvCosto;
                
                // Diferencia de precio (vendió más caro o más barato)
                $diferenciaPrecio = $precioRealVentaConIsv - $precioVentaConIsv;
                
                return [
                    'producto' => $carga->producto->nombre ?? 'N/A',
                    'unidad' => $carga->unidad->abreviatura ?? $carga->unidad->nombre ?? 'N/A',
                    'cantidad' => $cantidad,
                    'vendida' => $cantidadVendida,
                    'devuelta' => $carga->cantidad_devuelta ?? 0,
                    'aplica_isv' => $aplicaIsv,
                    // Costos
                    'costo_con_isv' => $costoConIsv,
                    'costo_sin_isv' => $costoSinIsv,
                    'isv_costo' => $isvCosto,
                    'costo_total' => $cantidad * $costoConIsv,
                    // Ventas - Precio sugerido
                    'precio_venta_con_isv' => $precioVentaConIsv,
                    'precio_venta_sin_isv' => $precioVentaSinIsv,
                    'isv_venta' => $isvVenta,
                    'venta_esperada' => $cantidad * $precioVentaConIsv,
                    // Ventas - Precio real
                    'precio_real_venta_con_isv' => $precioRealVentaConIsv,
                    'precio_real_venta_sin_isv' => $precioRealVentaSinIsv,
                    'isv_venta_real' => $isvVentaReal,
                    'venta_real' => $cantidadVendida * $precioRealVentaConIsv,
                    'diferencia_precio' => $diferenciaPrecio,
                    // Ganancia e ISV
                    'ganancia_unitaria' => $gananciaUnitaria,
                    'ganancia_unitaria_real' => $gananciaUnitariaReal,
                    'isv_a_pagar_unitario' => $isvAPagarUnitario,
                    // Totales de lo vendido
                    'total_isv_costo_vendido' => $cantidadVendida * $isvCosto,
                    'total_isv_venta_vendido' => $cantidadVendida * $isvVentaReal,
                    'total_isv_a_pagar' => $cantidadVendida * ($isvVentaReal - $isvCosto),
                    'total_ganancia_vendido' => $cantidadVendida * $gananciaUnitariaReal,
                ];
            });
        
        // Calcular totales de ISV
        $totalIsvCreditoFiscal = $cargaInicial->sum('total_isv_costo_vendido');
        $totalIsvCobrado = $cargaInicial->sum('total_isv_venta_vendido');
        $totalIsvAPagarSar = $cargaInicial->sum('total_isv_a_pagar');
        $totalGananciaReal = $cargaInicial->sum('total_ganancia_vendido');

        // Totales de carga - SIEMPRE calcular desde las cargas (no depender de campos guardados)
        $totalCargadoCosto = (float) $cargaInicial->sum('costo_total');
        $totalCargadoVenta = (float) $cargaInicial->sum('venta_esperada');
        
        // Costo de lo vendido - calcular desde la carga para que cuadre EXACTO con la tabla
        $costoVendidoConIsv = 0;
        foreach ($cargaInicial as $item) {
            $costoVendidoConIsv += $item['vendida'] * $item['costo_con_isv'];
        }
        
        // Venta real desde la carga
        $ventaRealTotal = (float) $cargaInicial->sum('venta_real');

        // Margen bruto y utilidad neta (usando los mismos valores que la tabla)
        $margenBruto = $totalVentas - $costoVendidoConIsv;
        $comisiones = (float) ($viaje->comision_ganada ?? 0);
        $mermas = (float) ($viaje->total_merma_costo ?? 0);
        $utilidadNeta = $margenBruto - $totalGastos;

        // Venta esperada de lo vendido (para comparar con venta real)
        $ventaEsperadaDeLoVendido = 0;
        foreach ($viaje->cargas as $carga) {
            $cantidadVendida = $carga->cantidad_vendida ?? 0;
            $precioSugerido = $carga->precio_venta_sugerido ?? 0;
            $ventaEsperadaDeLoVendido += $cantidadVendida * $precioSugerido;
        }

        return [
            // Resumen de ventas
            'ventas_contado' => $ventasContado,
            'ventas_credito' => $ventasCredito,
            'total_ventas' => $totalVentas,
            'ventas_por_cliente' => $ventasPorCliente,

            // Carga
            'carga_inicial' => $cargaInicial,
            'total_cargado_costo' => $totalCargadoCosto,
            'total_cargado_venta' => $totalCargadoVenta,
            'porcentaje_vendido' => $porcentajeVendido,

            // Descuentos
            'total_descuentos' => $totalDescuentos,
            'detalle_descuentos' => $detalleDescuentos,
            'venta_esperada_de_lo_vendido' => $ventaEsperadaDeLoVendido,

            // Devoluciones y mermas
            'total_devuelto_costo' => (float) ($viaje->total_devuelto_costo ?? 0),
            'total_merma_costo' => $mermas,

            // Gastos
            'gastos_aprobados' => $gastosAprobados,
            'total_gastos' => $totalGastos,

            // Comisiones
            'comision_ganada' => $comisiones,

            // Rentabilidad (usando costo CON ISV)
            'costo_vendido' => $costoVendidoConIsv,
            'margen_bruto' => $margenBruto,
            'utilidad_neta' => $utilidadNeta,

            // ISV detallado para el SAR
            'isv_cobrado' => $totalIsvCobrado,
            'isv_credito_fiscal' => round($totalIsvCreditoFiscal, 2),
            'isv_a_pagar_sar' => round($totalIsvAPagarSar, 2),
            'ganancia_real_sin_isv' => round($totalGananciaReal, 2),

            // Efectivo
            'efectivo_debe_entregar' => $efectivoDebeEntregar,
            'efectivo_entregado' => $efectivoEntregado,
            'diferencia_efectivo' => $diferenciaEfectivo,
            'estado_efectivo' => $this->getEstadoEfectivo($diferenciaEfectivo, $efectivoDebeEntregar, $efectivoEntregado),
        ];
    }

    protected function getEstadoEfectivo(float $diferencia, float $debeEntregar, float $entregado): string
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