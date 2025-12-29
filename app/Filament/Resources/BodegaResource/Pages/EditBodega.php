<?php

namespace App\Filament\Resources\BodegaResource\Pages;

use App\Filament\Resources\BodegaResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditBodega extends EditRecord
{
    protected static string $resource = BodegaResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = Auth::id();
        return $data;
    }
}
