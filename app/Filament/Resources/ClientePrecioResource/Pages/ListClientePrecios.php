<?php

namespace App\Filament\Resources\ClientePrecioResource\Pages;

use App\Filament\Resources\ClientePrecioResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListClientePrecios extends ListRecords
{
    protected static string $resource = ClientePrecioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
