<?php

namespace App\Http\Controllers;

use App\Models\Viaje;
use App\Models\Empresa;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class CargaChoferPdfController extends Controller
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
        ]);

        $empresa = Empresa::getData();
        $datos = $this->calcularDatosChofer($viaje);

        $pdf = Pdf::loadView('pdf.carga-chofer', [
            'viaje' => $viaje,
            'empresa' => $empresa,
            'datos' => $datos,
            'fechaImpresion' => now()->format('d/m/Y H:i'),
        ]);

        $pdf->setPaper('letter', 'portrait');

        $filename = "Carga-Chofer-{$viaje->numero_viaje}.pdf";

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
        ]);

        $empresa = Empresa::getData();
        $datos = $this->calcularDatosChofer($viaje);

        $pdf = Pdf::loadView('pdf.carga-chofer', [
            'viaje' => $viaje,
            'empresa' => $empresa,
            'datos' => $datos,
            'fechaImpresion' => now()->format('d/m/Y H:i'),
        ]);

        $pdf->setPaper('letter', 'portrait');

        $filename = "Carga-Chofer-{$viaje->numero_viaje}.pdf";

        return $pdf->download($filename);
    }

    protected function calcularDatosChofer(Viaje $viaje): array
    {
        // Detalle de carga (solo lo que necesita el chofer)
        $cargaInicial = $viaje->cargas()
            ->with(['producto', 'unidad'])
            ->get()
            ->map(function ($carga) {
                return [
                    'producto' => $carga->producto->nombre ?? 'N/A',
                    'unidad' => $carga->unidad->abreviatura ?? $carga->unidad->nombre ?? 'N/A',
                    'cantidad' => $carga->cantidad,
                    'precio_venta' => $carga->precio_venta_sugerido ?? 0,
                    'total_esperado' => $carga->cantidad * ($carga->precio_venta_sugerido ?? 0),
                    'vendida' => $carga->cantidad_vendida ?? 0,
                    'devuelta' => $carga->cantidad_devuelta ?? 0,
                ];
            });

        // Total esperado de venta
        $totalEsperado = $cargaInicial->sum('total_esperado');

        // Ventas realizadas
        $ventas = $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->with(['cliente', 'detalles.producto'])
            ->get();

        $ventasDetalle = $ventas->map(function ($venta) {
            return [
                'numero' => $venta->numero_venta,
                'cliente' => $venta->cliente->nombre ?? 'Consumidor Final',
                'tipo_pago' => $venta->tipo_pago == 'credito' ? 'Crédito' : 'Contado',
                'total' => $venta->total,
                'productos' => $venta->detalles->map(function ($detalle) {
                    return [
                        'producto' => $detalle->producto->nombre ?? 'N/A',
                        'cantidad' => $detalle->cantidad,
                        'precio' => $detalle->precio_base,
                        'total' => $detalle->total,
                    ];
                }),
            ];
        });

        // Totales simples para el chofer
        $totalVendidoContado = $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->where('tipo_pago', '!=', 'credito')
            ->sum('total');

        $totalVendidoCredito = $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->where('tipo_pago', 'credito')
            ->sum('total');

        $totalVendido = $totalVendidoContado + $totalVendidoCredito;

        // Cantidad de productos
        $totalProductosCargados = $cargaInicial->sum('cantidad');
        $totalProductosVendidos = $cargaInicial->sum('vendida');
        $totalProductosDevueltos = $cargaInicial->sum('devuelta');

        return [
            'carga' => $cargaInicial,
            'ventas' => $ventasDetalle,
            'total_esperado' => $totalEsperado,
            'total_vendido' => $totalVendido,
            'total_vendido_contado' => $totalVendidoContado,
            'total_vendido_credito' => $totalVendidoCredito,
            'efectivo_entregar' => $totalVendidoContado,
            'total_productos_cargados' => $totalProductosCargados,
            'total_productos_vendidos' => $totalProductosVendidos,
            'total_productos_devueltos' => $totalProductosDevueltos,
            'cantidad_ventas' => $ventas->count(),
        ];
    }
}