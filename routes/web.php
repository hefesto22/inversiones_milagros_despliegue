<?php

use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use App\Models\Cliente;
use App\Http\Controllers\ViajeVentaController;
use App\Http\Controllers\CotizacionPdfController;
use App\Http\Controllers\FacturaVentaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/login');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', Profile::class)->name('settings.profile');
    Route::get('settings/password', Password::class)->name('settings.password');
    Route::get('settings/appearance', Appearance::class)->name('settings.appearance');

    // API para buscar clientes (Punto de Venta en Ruta)
    Route::get('/api/clientes/buscar', function (Request $request) {
        $query = $request->get('q', '');
        
        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $clientes = Cliente::where('estado', true)
            ->where(function ($q) use ($query) {
                $q->where('nombre', 'like', "%{$query}%")
                  ->orWhere('rtn', 'like', "%{$query}%")
                  ->orWhere('telefono', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'nombre', 'rtn', 'telefono', 'tipo']);

        return response()->json($clientes);
    });

    // Imprimir venta de viaje
    Route::get('/viaje-venta/{viajeVenta}/imprimir', [ViajeVentaController::class, 'imprimir'])
        ->name('viaje-venta.imprimir');

    // =====================================================
    // RUTAS PDF - Cotizaciones y Facturas de Ventas
    // =====================================================
    
    // Cotización - Ver en navegador
    Route::get('/pdf/cotizacion/{venta}', [CotizacionPdfController::class, 'generate'])
        ->name('pdf.cotizacion');
    
    // Cotización - Descargar
    Route::get('/pdf/cotizacion/{venta}/download', [CotizacionPdfController::class, 'download'])
        ->name('pdf.cotizacion.download');

    // Factura de Venta - Imprimir (estilo ticket)
    Route::get('/venta/{venta}/imprimir', [FacturaVentaController::class, 'imprimir'])
        ->name('venta.imprimir');
});

require __DIR__.'/auth.php';