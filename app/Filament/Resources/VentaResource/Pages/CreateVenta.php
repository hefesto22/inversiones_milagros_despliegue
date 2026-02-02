<?php

namespace App\Filament\Resources\VentaResource\Pages;

use App\Filament\Resources\VentaResource;
use App\Filament\Resources\VentaResource\Widgets\ProductosDisponiblesWidget;
use App\Models\Cliente;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Filament\Notifications\Notification;

class CreateVenta extends CreateRecord
{
    protected static string $resource = VentaResource::class;

    /**
     * Redirigir a la vista de la cotización después de crear
     * para que el usuario pueda imprimirla o procesarla
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();
        $data['estado'] = 'borrador';
        $data['estado_pago'] = 'pendiente';

        return $data;
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Cotización creada')
            ->body('Puedes imprimir la cotización o procesarla como venta.')
            ->success()
            ->actions([
                \Filament\Notifications\Actions\Action::make('ver_cotizacion')
                    ->label('Ver Cotización')
                    ->url(route('pdf.cotizacion', $this->record))
                    ->openUrlInNewTab(),
            ])
            ->persistent()
            ->send();
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ProductosDisponiblesWidget::make([
                'bodegaId' => $this->data['bodega_id'] ?? null,
            ]),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    // Cuando cambia la bodega, notificar al widget
    public function updatedData($value, $key): void
    {
        if ($key === 'bodega_id') {
            $this->dispatch('bodega-changed', bodegaId: $value);
        }

        if ($key === 'cliente_id') {
            $this->dispatch('cliente-changed', clienteId: $value);
        }
    }

    // Escuchar cuando el widget quiere agregar un producto
    #[On('agregar-producto-venta')]
    public function agregarProductoAlCarrito(array $producto): void
    {
        $detalles = $this->data['detalles'] ?? [];

        // Verificar si el producto ya existe en los detalles
        $encontrado = false;
        foreach ($detalles as $index => $detalle) {
            if (isset($detalle['producto_id']) && $detalle['producto_id'] == $producto['producto_id']) {
                // Incrementar cantidad
                $detalles[$index]['cantidad'] = ($detalles[$index]['cantidad'] ?? 1) + 1;
                $encontrado = true;

                // Recalcular línea
                $this->recalcularLinea($detalles[$index]);
                break;
            }
        }

        if (!$encontrado) {
            // El precio_unitario que viene YA INCLUYE ISV si aplica
            $precioConIsv = floatval($producto['precio_unitario']);
            $aplicaIsv = $producto['aplica_isv'] ?? false;

            // Calcular desglose: el precio YA incluye ISV
            if ($aplicaIsv && $precioConIsv > 0) {
                $precioSinIsv = round($precioConIsv / 1.15, 2);
                $isvUnitario = round($precioConIsv - $precioSinIsv, 2);
            } else {
                $precioSinIsv = $precioConIsv;
                $isvUnitario = 0;
            }

            // Agregar nuevo producto
            $detalles[] = [
                'producto_id' => $producto['producto_id'],
                'unidad_id' => $producto['unidad_id'],
                'cantidad' => 1,
                'precio_unitario' => $precioSinIsv, // Precio SIN ISV para cálculos
                'precio_con_isv' => $precioConIsv,  // Precio CON ISV (el que se muestra/cobra)
                'costo_unitario' => $producto['costo_unitario'] ?? 0,
                'aplica_isv' => $aplicaIsv,
                'isv_unitario' => $isvUnitario,
                'subtotal' => $precioSinIsv,
                'total_isv' => $isvUnitario,
                'total_linea' => $precioConIsv, // Total = precio con ISV
                'stock_disponible' => $producto['stock_disponible'] ?? 0,
                'precio_anterior' => null,
            ];
        }

        // Actualizar el formulario
        $this->data['detalles'] = $detalles;

        // Recalcular totales
        $this->recalcularTotales();

        // Forzar actualización del formulario
        $this->form->fill($this->data);
    }

    protected function recalcularLinea(array &$detalle): void
    {
        $cantidad = floatval($detalle['cantidad'] ?? 0);
        $precioConIsv = floatval($detalle['precio_con_isv'] ?? $detalle['precio_unitario'] ?? 0);
        $aplicaIsv = $detalle['aplica_isv'] ?? false;

        // El precio YA incluye ISV, desglosar
        if ($aplicaIsv && $precioConIsv > 0) {
            $precioSinIsv = round($precioConIsv / 1.15, 2);
            $isvUnitario = round($precioConIsv - $precioSinIsv, 2);
        } else {
            $precioSinIsv = $precioConIsv;
            $isvUnitario = 0;
        }

        $subtotal = $cantidad * $precioSinIsv;
        $totalIsv = $cantidad * $isvUnitario;
        $totalLinea = $cantidad * $precioConIsv;

        $detalle['precio_unitario'] = $precioSinIsv;
        $detalle['subtotal'] = round($subtotal, 2);
        $detalle['isv_unitario'] = $isvUnitario;
        $detalle['total_isv'] = round($totalIsv, 2);
        $detalle['total_linea'] = round($totalLinea, 2);
    }

    protected function recalcularTotales(): void
    {
        $detalles = $this->data['detalles'] ?? [];

        $subtotal = 0;
        $totalIsv = 0;

        foreach ($detalles as $detalle) {
            $subtotal += floatval($detalle['subtotal'] ?? 0);
            $totalIsv += floatval($detalle['total_isv'] ?? 0);
        }

        $descuento = floatval($this->data['descuento'] ?? 0);
        $total = $subtotal + $totalIsv - $descuento;

        $this->data['subtotal'] = round($subtotal, 2);
        $this->data['total_isv'] = round($totalIsv, 2);
        $this->data['total'] = round(max(0, $total), 2);
    }

    // Asegurarse de que el widget reciba la bodega inicial
    public function mount(): void
    {
        parent::mount();

        // Despachar bodega inicial si existe
        if (isset($this->data['bodega_id']) && $this->data['bodega_id']) {
            $this->dispatch('bodega-changed', bodegaId: $this->data['bodega_id']);
        }
    }
}