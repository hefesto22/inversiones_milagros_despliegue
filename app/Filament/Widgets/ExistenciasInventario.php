<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Tarjeta del Escritorio para sacar la hoja de existencias.
 *
 * Visibilidad por ROL en vez de HasWidgetShield: el trait obliga a correr
 * shield:generate para crear el permiso, y ese comando ya pisó las policies en
 * producción (incidente 2026-07-25). Un botón no justifica ese riesgo.
 *
 * @see \App\Http\Controllers\ExistenciasPdfController
 */
class ExistenciasInventario extends Widget
{
    protected static string $view = 'filament.widgets.existencias-inventario';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /**
     * OJO con el nombre del rol: el super admin de Shield se llama
     * `super_admin` (config filament-shield), NO "Super Admin". Preguntar por
     * "Super Admin" devuelve false siempre y la tarjeta desaparece sin ruido.
     */
    public static function canView(): bool
    {
        /** @var \App\Models\User|null $usuario */
        $usuario = Auth::user();

        return $usuario?->hasAnyRole([
            config('filament-shield.super_admin.name', 'super_admin'),
            'Jefe',
        ]) ?? false;
    }

    public function getPdfUrl(): string
    {
        return route('existencias.pdf');
    }

    public function getDownloadUrl(): string
    {
        return route('existencias.download');
    }
}
