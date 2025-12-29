<?php

namespace App\Filament\Resources\BodegaResource\Pages;

use App\Filament\Resources\BodegaResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateBodega extends CreateRecord
{
    protected static string $resource = BodegaResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();
        return $data;
    }
}
