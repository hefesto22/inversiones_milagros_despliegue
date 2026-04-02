<?php

namespace App\Filament\Resources\LoteResource\Pages;

use App\Enums\LoteEstado;
use App\Filament\Resources\LoteResource;
use App\Models\Concerns\HasBodegaScope;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLotes extends ListRecords
{
    use HasBodegaScope;

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
        $esSuperAdminOJefe = self::esSuperAdminOJefe();
        $bodegasUsuario = $esSuperAdminOJefe ? [] : self::getBodegasUsuario();

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
                ->modifyQueryUsing(fn(Builder $query) => $query->where('estado', LoteEstado::Disponible))
                ->badge(function () use ($esSuperAdminOJefe, $bodegasUsuario) {
                    $query = \App\Models\Lote::where('estado', LoteEstado::Disponible);

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
                ->modifyQueryUsing(fn(Builder $query) => $query->where('estado', LoteEstado::Agotado))
                ->badge(function () use ($esSuperAdminOJefe, $bodegasUsuario) {
                    $query = \App\Models\Lote::where('estado', LoteEstado::Agotado);

                    if (!$esSuperAdminOJefe && !empty($bodegasUsuario)) {
                        $query->whereIn('bodega_id', $bodegasUsuario);
                    }

                    return $query->count();
                })
                ->badgeColor('gray'),
        ];
    }
}
