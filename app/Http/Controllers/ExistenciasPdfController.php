<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Services\Inventario\ExistenciasService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * PDF de existencias: qué hay en inventario, cuánto y dónde está.
 *
 * Sin costos ni valores a propósito: es la hoja con la que se cuenta físico,
 * y la idea es poder entregársela a quien cuenta sin mostrarle los números del
 * negocio.
 *
 * El acceso se valida por ROL y no con un permiso de Shield, porque agregar
 * permisos obliga a correr shield:generate y eso ya pisó policies en
 * producción una vez (incidente 2026-07-25).
 */
class ExistenciasPdfController extends Controller
{
    public function __construct(private readonly ExistenciasService $existencias) {}

    public function generate(): Response
    {
        return $this->construir()->stream($this->nombreArchivo());
    }

    public function download(): Response
    {
        return $this->construir()->download($this->nombreArchivo());
    }

    private function construir()
    {
        $this->autorizar();

        $datos = $this->existencias->generar();
        $datos['empresa'] = Empresa::getData();

        return Pdf::loadView('pdf.existencias', $datos)
            ->setOption(['chroot' => base_path()])
            // Apaisado: el resumen lleva cinco columnas y el detalle de
            // camiones seis; en vertical quedan apretadas.
            ->setPaper('letter', 'landscape');
    }

    /**
     * OJO con el nombre del rol: el super admin de Shield se llama
     * `super_admin` (config filament-shield), NO "Super Admin".
     */
    private function autorizar(): void
    {
        /** @var \App\Models\User|null $usuario */
        $usuario = Auth::user();

        abort_unless(
            $usuario && $usuario->hasAnyRole([
                config('filament-shield.super_admin.name', 'super_admin'),
                'Jefe',
            ]),
            403,
            'No tiene permiso para ver las existencias de inventario.'
        );
    }

    private function nombreArchivo(): string
    {
        return 'Existencias_'.now()->format('Y-m-d_Hi').'.pdf';
    }
}
