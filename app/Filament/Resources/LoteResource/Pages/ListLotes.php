<?php

namespace App\Filament\Resources\LoteResource\Pages;

use App\Filament\Resources\LoteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ListLotes extends ListRecords
{
    protected static string $resource = LoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('crear_reempaque')
                ->label('Crear Reempaque')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->url(fn() => route('filament.admin.resources.reempaques.create')),
        ];
    }

    public function getTabs(): array
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return [];
        }

        // Verificar si es Super Admin o Jefe
        $esSuperAdminOJefe = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();

        // Obtener bodegas del usuario si no es Super Admin o Jefe
        $bodegasUsuario = [];
        if (!$esSuperAdminOJefe) {
            $bodegasUsuario = DB::table('bodega_user')
                ->where('user_id', $currentUser->id)
                ->where('activo', true)
                ->pluck('bodega_id')
                ->toArray();
        }

        return [
            'todos' => Tab::make('Todos')
                ->badge(function () use ($esSuperAdminOJefe, $bodegasUsuario) {
                    $query = \App\Models\Lote::query();

                    if (!$esSuperAdminOJefe && !empty($bodegasUsuario)) {
                        $query->whereIn('bodega_id', $bodegasUsuario);
                    }

                    return $query->count();
                }),

            'disponibles' => Tab::make('Disponibles')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('estado', 'disponible'))
                ->badge(function () use ($esSuperAdminOJefe, $bodegasUsuario) {
                    $query = \App\Models\Lote::where('estado', 'disponible');

                    if (!$esSuperAdminOJefe && !empty($bodegasUsuario)) {
                        $query->whereIn('bodega_id', $bodegasUsuario);
                    }

                    return $query->count();
                })
                ->badgeColor('success'),

            'con_stock' => Tab::make('Con Stock')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('cantidad_huevos_remanente', '>', 0))
                ->badge(function () use ($esSuperAdminOJefe, $bodegasUsuario) {
                    $query = \App\Models\Lote::where('cantidad_huevos_remanente', '>', 0);

                    if (!$esSuperAdminOJefe && !empty($bodegasUsuario)) {
                        $query->whereIn('bodega_id', $bodegasUsuario);
                    }

                    return $query->count();
                })
                ->badgeColor('info'),

            'agotados' => Tab::make('Agotados')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('estado', 'agotado'))
                ->badge(function () use ($esSuperAdminOJefe, $bodegasUsuario) {
                    $query = \App\Models\Lote::where('estado', 'agotado');

                    if (!$esSuperAdminOJefe && !empty($bodegasUsuario)) {
                        $query->whereIn('bodega_id', $bodegasUsuario);
                    }

                    return $query->count();
                })
                ->badgeColor('gray'),
        ];
    }
}
