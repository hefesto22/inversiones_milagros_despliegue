<?php

namespace App\Filament\Resources\ProductoResource\Pages;

use App\Filament\Resources\ProductoResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;


class CreateProducto extends CreateRecord
{
    protected static string $resource = ProductoResource::class;

    protected ?int $bodegaInicialId = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Guardamos el usuario autenticado en user_id
        $data['user_id'] = Auth::id();

        // Capturamos la bodega seleccionada
        $this->bodegaInicialId = $data['bodega_inicial_id'] ?? null;

        // Quitamos el campo virtual (no existe en productos)
        unset($data['bodega_inicial_id']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $producto = $this->record;

        if ($this->bodegaInicialId) {
            $producto->bodegas()->syncWithoutDetaching([
                $this->bodegaInicialId => [
                    'stock'       => 0,
                    'stock_min'   => null,
                    'precio_base' => null,
                    'activo'      => true,
                ],
            ]);
        }
    }
}
