<?php

namespace App\Http\Controllers;

use App\Models\ViajeVenta;
use App\Models\Empresa;
use Illuminate\Http\Request;

class ViajeVentaController extends Controller
{
    /**
     * Mostrar vista de impresión de la venta
     */
    public function imprimir(ViajeVenta $viajeVenta)
    {
        $viajeVenta->load(['cliente', 'viaje', 'detalles.producto', 'userCreador']);
        
        $empresa = Empresa::first();
        
        return view('viaje-venta.print', [
            'venta' => $viajeVenta,
            'empresa' => $empresa,
        ]);
    }
}