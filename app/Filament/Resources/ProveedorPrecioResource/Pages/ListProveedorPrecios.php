<?php

namespace App\Filament\Resources\ProveedorPrecioResource\Pages;

use App\Filament\Resources\ProveedorPrecioResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProveedorPrecios extends ListRecords
{
    protected static string $resource = ProveedorPrecioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
