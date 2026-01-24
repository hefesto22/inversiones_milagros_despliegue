<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\Empresa;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class CotizacionPdfController extends Controller
{
    public function generate(Venta $venta): Response
    {
        // Cargar relaciones necesarias
        $venta->load([
            'cliente',
            'bodega',
            'detalles.producto',
            'detalles.unidad',
            'creador',
        ]);

        // Obtener datos de la empresa
        $empresa = Empresa::getData();

        // Generar número de cotización temporal si no tiene número de venta
        $numeroCotizacion = $venta->numero_venta 
            ?? 'COT-' . str_pad($venta->id, 6, '0', STR_PAD_LEFT);

        $pdf = Pdf::loadView('pdf.cotizacion', [
            'venta' => $venta,
            'empresa' => $empresa,
            'numeroCotizacion' => $numeroCotizacion,
            'fechaEmision' => now()->format('d/m/Y H:i'),
            'fechaValidez' => now()->addDays(7)->format('d/m/Y'),
        ]);

        $pdf->setPaper('letter', 'portrait');

        $filename = "Cotizacion-{$numeroCotizacion}.pdf";

        return $pdf->stream($filename);
    }

    public function download(Venta $venta): Response
    {
        $venta->load([
            'cliente',
            'bodega',
            'detalles.producto',
            'detalles.unidad',
            'creador',
        ]);

        $empresa = Empresa::getData();

        $numeroCotizacion = $venta->numero_venta 
            ?? 'COT-' . str_pad($venta->id, 6, '0', STR_PAD_LEFT);

        $pdf = Pdf::loadView('pdf.cotizacion', [
            'venta' => $venta,
            'empresa' => $empresa,
            'numeroCotizacion' => $numeroCotizacion,
            'fechaEmision' => now()->format('d/m/Y H:i'),
            'fechaValidez' => now()->addDays(7)->format('d/m/Y'),
        ]);

        $pdf->setPaper('letter', 'portrait');

        $filename = "Cotizacion-{$numeroCotizacion}.pdf";

        return $pdf->download($filename);
    }
}