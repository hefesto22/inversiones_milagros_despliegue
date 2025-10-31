<?php

namespace App\Filament\Resources\UnidadResource\Pages;

use App\Filament\Resources\UnidadResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUnidad extends CreateRecord
{
    protected static string $resource = UnidadResource::class;

        protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id(); // crea con el usuario autenticado
        return $data;
    }
}
