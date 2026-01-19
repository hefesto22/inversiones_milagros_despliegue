<?php

namespace App\Filament\Resources\BodegaGastoResource\Pages;

use App\Filament\Resources\BodegaGastoResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateBodegaGasto extends CreateRecord
{
    protected static string $resource = BodegaGastoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['registrado_por'] = Auth::id();
        $data['created_by'] = Auth::id();
        $data['estado'] = 'pendiente';

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}