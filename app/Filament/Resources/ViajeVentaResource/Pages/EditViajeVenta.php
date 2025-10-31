<?php

namespace App\Filament\Resources\ViajeVentaResource\Pages;

use App\Filament\Resources\ViajeVentaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditViajeVenta extends EditRecord
{
    protected static string $resource = ViajeVentaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
