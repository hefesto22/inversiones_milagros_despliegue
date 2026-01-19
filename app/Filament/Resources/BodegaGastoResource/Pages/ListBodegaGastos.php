<?php

namespace App\Filament\Resources\BodegaGastoResource\Pages;

use App\Filament\Resources\BodegaGastoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBodegaGastos extends ListRecords
{
    protected static string $resource = BodegaGastoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Registrar Gasto'),
        ];
    }
}