<?php

namespace App\Filament\Resources\BodegaGastoResource\Pages;

use App\Filament\Resources\BodegaGastoResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBodegaGasto extends EditRecord
{
    protected static string $resource = BodegaGastoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn() => $this->record->estado === 'pendiente'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}