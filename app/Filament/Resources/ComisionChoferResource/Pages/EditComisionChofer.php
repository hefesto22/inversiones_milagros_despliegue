<?php

namespace App\Filament\Resources\ComisionChoferResource\Pages;

use App\Filament\Resources\ComisionChoferResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditComisionChofer extends EditRecord
{
    protected static string $resource = ComisionChoferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
