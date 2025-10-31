<?php

namespace App\Filament\Resources\UnidadResource\Pages;

use App\Filament\Resources\UnidadResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditUnidad extends EditRecord
{
    protected static string $resource = UnidadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['user_update'] = Auth::id(); // registra quién editó
        return $data;
    }
}
