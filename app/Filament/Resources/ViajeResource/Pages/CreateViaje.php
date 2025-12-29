<?php

namespace App\Filament\Resources\ViajeResource\Pages;

use App\Filament\Resources\ViajeResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateViaje extends CreateRecord
{
    protected static string $resource = ViajeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();

        // Generar número de viaje
        if (!isset($data['numero_viaje'])) {
            $ultimoViaje = \App\Models\Viaje::latest('id')->first();
            $numero = $ultimoViaje ? $ultimoViaje->id + 1 : 1;
            $data['numero_viaje'] = 'VJ-' . str_pad($numero, 6, '0', STR_PAD_LEFT);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Viaje creado. Ahora agrega las cargas.';
    }
}
