<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\Empresa;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class CotizacionPdfController extends Controller
{
    /**
     * Generar vista previa del PDF (stream en navegador)
     */
    public function generate(Venta $venta): Response
    {
        $data = $this->prepararDatos($venta);

        $pdf = Pdf::loadView('pdf.cotizacion', $data);
        $pdf->setPaper('letter', 'portrait');

        $filename = "Cotizacion-{$data['numeroCotizacion']}.pdf";

        return $pdf->stream($filename);
    }

    /**
     * Descargar el PDF directamente
     */
    public function download(Venta $venta): Response
    {
        $data = $this->prepararDatos($venta);

        $pdf = Pdf::loadView('pdf.cotizacion', $data);
        $pdf->setPaper('letter', 'portrait');

        $filename = "Cotizacion-{$data['numeroCotizacion']}.pdf";

        return $pdf->download($filename);
    }

    /**
     * Preparar todos los datos necesarios para el PDF
     */
    private function prepararDatos(Venta $venta): array
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

        // Generar número de cotización
        $numeroCotizacion = $venta->numero_venta
            ?? 'COT-' . str_pad($venta->id, 6, '0', STR_PAD_LEFT);

        // Resolver ruta absoluta del logo para DomPDF
        $logoPath = null;
        if ($empresa?->logo) {
            // Intentar ruta de producción (Hostinger)
            $path = base_path('../public_html/storage/' . $empresa->logo);

            if (!file_exists($path)) {
                // Intentar ruta local/desarrollo
                $path = storage_path('app/public/' . $empresa->logo);
            }

            if (!file_exists($path)) {
                // Intentar ruta pública directa
                $path = public_path('storage/' . $empresa->logo);
            }

            if (file_exists($path)) {
                $logoPath = $path;
            }
        }

        // Calcular subtotal gravado (productos con ISV)
        $subtotalGravado = $venta->detalles
            ->where('aplica_isv', true)
            ->sum(function ($detalle) {
                return $detalle->cantidad * $detalle->precio_unitario;
            });

        // Generar total en letras
        $totalEnLetras = $this->numeroALetras($venta->total);

        return [
            'venta' => $venta,
            'empresa' => $empresa,
            'numeroCotizacion' => $numeroCotizacion,
            'logoPath' => $logoPath,
            'fechaEmision' => now()->format('d/m/Y H:i'),
            'fechaValidez' => now()->addDays(7)->format('d/m/Y'),
            'subtotalGravado' => $subtotalGravado,
            'totalEnLetras' => $totalEnLetras,
        ];
    }

    /**
     * Convertir número a letras (Lempiras hondureños)
     */
    private function numeroALetras(float $numero): string
    {
        $entero = intval($numero);
        $centavos = round(($numero - $entero) * 100);

        $letras = $this->convertirEnteroALetras($entero);

        $resultado = strtoupper($letras) . ' LEMPIRAS';

        if ($centavos > 0) {
            $resultado .= ' CON ' . str_pad($centavos, 2, '0', STR_PAD_LEFT) . '/100';
        } else {
            $resultado .= ' EXACTOS';
        }

        return $resultado;
    }

    /**
     * Convertir entero a su representación en letras
     */
    private function convertirEnteroALetras(int $numero): string
    {
        if ($numero === 0) {
            return 'cero';
        }

        $unidades = [
            '', 'uno', 'dos', 'tres', 'cuatro', 'cinco',
            'seis', 'siete', 'ocho', 'nueve', 'diez',
            'once', 'doce', 'trece', 'catorce', 'quince',
            'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte',
            'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco',
            'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
        ];

        $decenas = [
            '', '', '', 'treinta', 'cuarenta', 'cincuenta',
            'sesenta', 'setenta', 'ochenta', 'noventa',
        ];

        $centenas = [
            '', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos',
            'seiscientos', 'setecientos', 'ochocientos', 'novecientos',
        ];

        if ($numero < 0) {
            return 'menos ' . $this->convertirEnteroALetras(abs($numero));
        }

        $resultado = '';

        if ($numero >= 1000000) {
            $millones = intval($numero / 1000000);
            if ($millones === 1) {
                $resultado .= 'un millón ';
            } else {
                $resultado .= $this->convertirEnteroALetras($millones) . ' millones ';
            }
            $numero %= 1000000;
        }

        if ($numero >= 1000) {
            $miles = intval($numero / 1000);
            if ($miles === 1) {
                $resultado .= 'mil ';
            } else {
                $resultado .= $this->convertirEnteroALetras($miles) . ' mil ';
            }
            $numero %= 1000;
        }

        if ($numero >= 100) {
            if ($numero === 100) {
                $resultado .= 'cien';
                return trim($resultado);
            }
            $resultado .= $centenas[intval($numero / 100)] . ' ';
            $numero %= 100;
        }

        if ($numero > 0) {
            if ($numero < 30) {
                $resultado .= $unidades[$numero];
            } else {
                $resultado .= $decenas[intval($numero / 10)];
                $resto = $numero % 10;
                if ($resto > 0) {
                    $resultado .= ' y ' . $unidades[$resto];
                }
            }
        }

        return trim($resultado);
    }
}