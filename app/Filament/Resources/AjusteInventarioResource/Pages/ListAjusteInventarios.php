<?php

declare(strict_types=1);

namespace App\Filament\Resources\AjusteInventarioResource\Pages;

use App\Filament\Resources\AjusteInventarioResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAjusteInventarios extends ListRecords
{
    protected static string $resource = AjusteInventarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo ajuste'),
        ];
    }
}
