<?php

namespace App\Filament\Resources\VentaResource\Pages;

use App\Filament\Resources\VentaResource;
use App\Models\Cliente;
use App\Models\Producto;
use App\Services\PrecioVentaService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditVenta extends EditRecord
{
    protected static string $resource = VentaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->estado === 'borrador' &&
                    $this->record->monto_pagado <= 0
                ),
        ];
    }

    /**
     * Defensa en profundidad: validar precios bloqueados antes de guardar.
     * Misma lógica que en CreateVenta para cerrar el hueco también al editar.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->validarPreciosBloqueados($data);

        return $data;
    }

    /**
     * Rechaza la operación si algún detalle viola el precio autorizado
     * para clientes con bloqueo (Consumidor Final).
     *
     * @throws ValidationException
     */
    protected function validarPreciosBloqueados(array $data): void
    {
        $clienteId = $data['cliente_id'] ?? $this->record->cliente_id ?? null;
        $detalles = $data['detalles'] ?? [];

        if (! $clienteId || empty($detalles)) {
            return;
        }

        $cliente = Cliente::find($clienteId);
        if (! $cliente || ! $cliente->esConsumidorFinal()) {
            return;
        }

        $service = app(PrecioVentaService::class);
        $errores = [];

        foreach ($detalles as $idx => $detalle) {
            $productoId = $detalle['producto_id'] ?? null;
            $precioUnitario = (float) ($detalle['precio_unitario'] ?? 0);

            if (! $productoId) {
                continue;
            }

            $producto = Producto::find($productoId);
            if (! $producto) {
                continue;
            }

            if (! $service->precioCoincide($cliente, $producto, $precioUnitario)) {
                $errores["detalles.{$idx}.precio_unitario"] = [
                    $service->obtenerMensajeBloqueo($cliente, $producto),
                ];
            }
        }

        if (! empty($errores)) {
            throw ValidationException::withMessages($errores);
        }
    }
}
