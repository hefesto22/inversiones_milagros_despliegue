<?php

namespace App\Filament\Resources\CamionGastoResource\Pages;

use App\Filament\Resources\CamionGastoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCamionGastos extends ListRecords
{
    protected static string $resource = CamionGastoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
