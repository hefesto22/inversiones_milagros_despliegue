<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\Empresa;
use Illuminate\View\View;

class FacturaVentaController extends Controller
{
    /**
     * Mostrar factura para imprimir (estilo ticket)
     */
    public function imprimir(Venta $venta): View
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

        return view('pdf.factura-venta', [
            'venta' => $venta,
            'empresa' => $empresa,
        ]);
    }
}