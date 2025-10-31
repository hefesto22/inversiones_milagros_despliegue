<?php

namespace App\Filament\Resources\ChoferCamionAsignacionResource\Pages;

use App\Filament\Resources\ChoferCamionAsignacionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChoferCamionAsignacion extends EditRecord
{
    protected static string $resource = ChoferCamionAsignacionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
