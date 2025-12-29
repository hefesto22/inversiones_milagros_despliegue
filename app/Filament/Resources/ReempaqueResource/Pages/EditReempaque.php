<?php

namespace App\Filament\Resources\ReempaqueResource\Pages;

use App\Filament\Resources\ReempaqueResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReempaque extends EditRecord
{
    protected static string $resource = ReempaqueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

