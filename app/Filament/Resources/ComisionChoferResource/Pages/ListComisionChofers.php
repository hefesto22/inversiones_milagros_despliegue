<?php

namespace App\Filament\Resources\ComisionChoferResource\Pages;

use App\Filament\Resources\ComisionChoferResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListComisionChofers extends ListRecords
{
    protected static string $resource = ComisionChoferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
