<?php

namespace App\Filament\Resources\ViajeVentaResource\Pages;

use App\Filament\Resources\ViajeVentaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListViajeVentas extends ListRecords
{
    protected static string $resource = ViajeVentaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
