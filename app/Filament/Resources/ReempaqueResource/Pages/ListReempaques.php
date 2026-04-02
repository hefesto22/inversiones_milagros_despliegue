<?php

namespace App\Filament\Resources\ReempaqueResource\Pages;

use App\Filament\Resources\ReempaqueResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListReempaques extends ListRecords
{
    protected static string $resource = ReempaqueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nuevo Reempaque')
                ->icon('heroicon-o-plus-circle'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'todos' => Tab::make('Todos')
                ->badge(fn() => \App\Models\Reempaque::count()),

            'en_proceso' => Tab::make('En Proceso')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('estado', 'en_proceso'))
                ->badge(fn() => \App\Models\Reempaque::where('estado', 'en_proceso')->count())
                ->badgeColor('warning'),

            'completados' => Tab::make('Completados')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('estado', 'completado'))
                ->badge(fn() => \App\Models\Reempaque::where('estado', 'completado')->count())
                ->badgeColor('success'),

            'cancelados' => Tab::make('Cancelados')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('estado', 'cancelado'))
                ->badge(fn() => \App\Models\Reempaque::where('estado', 'cancelado')->count())
                ->badgeColor('danger'),

            'revertidos' => Tab::make('Revertidos')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('estado', 'revertido'))
                ->badge(fn() => \App\Models\Reempaque::where('estado', 'revertido')->count())
                ->badgeColor('info'),
        ];
    }
}
