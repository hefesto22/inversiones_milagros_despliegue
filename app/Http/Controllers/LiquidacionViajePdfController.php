<?php

namespace App\Http\Controllers;

use App\Models\Viaje;
use App\Models\Empresa;
use App\Services\LiquidacionCalculatorService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class LiquidacionViajePdfController extends Controller
{
    public function __construct(
        private readonly LiquidacionCalculatorService $liquidacionService,
    ) {}

    public function generate(Viaje $viaje): Response
    {
        $this->liquidacionService->eagerLoadViaje($viaje);

        $datos = $this->liquidacionService->calcular($viaje);

        $pdf = Pdf::setOption(['chroot' => base_path()])
            ->loadView('pdf.liquidacion-viaje', [
                'viaje' => $viaje,
                'empresa' => Empresa::getData(),
                'datos' => $datos->toArray(),
                'fechaImpresion' => now()->format('d/m/Y H:i'),
            ]);

        $pdf->setPaper('letter', 'portrait');

        return $pdf->stream("Liquidacion-{$viaje->numero_viaje}.pdf");
    }

    public function download(Viaje $viaje): Response
    {
        $this->liquidacionService->eagerLoadViaje($viaje);

        $datos = $this->liquidacionService->calcular($viaje);

        $pdf = Pdf::setOption(['chroot' => base_path()])
            ->loadView('pdf.liquidacion-viaje', [
                'viaje' => $viaje,
                'empresa' => Empresa::getData(),
                'datos' => $datos->toArray(),
                'fechaImpresion' => now()->format('d/m/Y H:i'),
            ]);

        $pdf->setPaper('letter', 'portrait');

        return $pdf->download("Liquidacion-{$viaje->numero_viaje}.pdf");
    }
}
