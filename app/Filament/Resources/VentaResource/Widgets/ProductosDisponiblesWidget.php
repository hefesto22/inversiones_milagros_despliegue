<?php

namespace App\Filament\Resources\VentaResource\Widgets;

use App\Models\Producto;
use App\Models\BodegaProducto;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;

class ProductosDisponiblesWidget extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Productos Disponibles';

    #[Reactive]
    public ?int $bodegaId = null;

    public ?int $clienteId = null;

    protected static bool $isLazy = false;

    // Escuchar cambios de bodega desde el formulario
    #[On('bodega-changed')]
    public function actualizarBodega($bodegaId): void
    {
        $this->bodegaId = $bodegaId;
        $this->resetTable();
    }

    #[On('cliente-changed')]
    public function actualizarCliente($clienteId): void
    {
        $this->clienteId = $clienteId;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Producto::query()
                    ->where('activo', true)
                    ->with(['categoria', 'unidad'])
            )
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->limit(40),

                Tables\Columns\TextColumn::make('categoria.nombre')
                    ->label('Categoría')
                    ->badge()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock')
                    ->getStateUsing(function (Producto $record) {
                        if (!$this->bodegaId) return '-';
                        $bp = BodegaProducto::where('bodega_id', $this->bodegaId)
                            ->where('producto_id', $record->id)
                            ->first();
                        return $bp?->stock ?? 0;
                    })
                    ->badge()
                    ->color(fn ($state) => match(true) {
                        $state === '-' => 'gray',
                        $state > 10 => 'success',
                        $state > 0 => 'warning',
                        default => 'danger',
                    }),

                // Precio sin ISV (desglosado)
                Tables\Columns\TextColumn::make('precio_venta')
                    ->label('Precio Venta')
                    ->getStateUsing(function (Producto $record) {
                        if (!$this->bodegaId) return 0;
                        $bp = BodegaProducto::where('bodega_id', $this->bodegaId)
                            ->where('producto_id', $record->id)
                            ->first();
                        $precioConIsv = $bp?->precio_venta_sugerido ?? 0;
                        
                        // Si aplica ISV, el precio guardado YA lo incluye, entonces desgloso
                        if ($record->aplica_isv && $precioConIsv > 0) {
                            return round($precioConIsv / 1.15, 2);
                        }
                        
                        return $precioConIsv;
                    })
                    ->money('HNL')
                    ->color('success')
                    ->weight('bold'),

                // Precio + ISV (precio final - tal cual está guardado)
                Tables\Columns\TextColumn::make('precio_con_isv')
                    ->label('Precio + ISV')
                    ->getStateUsing(function (Producto $record) {
                        if (!$this->bodegaId) return 0;
                        $bp = BodegaProducto::where('bodega_id', $this->bodegaId)
                            ->where('producto_id', $record->id)
                            ->first();
                        
                        // El precio_venta_sugerido YA incluye ISV si aplica
                        // Solo retornamos el valor tal cual
                        return $bp?->precio_venta_sugerido ?? 0;
                    })
                    ->money('HNL')
                    ->color('primary')
                    ->description(fn (Producto $record) => $record->aplica_isv ? 'Incluye 15% ISV' : 'Sin ISV'),

                Tables\Columns\IconColumn::make('aplica_isv')
                    ->label('ISV')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('categoria_id')
                    ->label('Categoría')
                    ->relationship('categoria', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('aplica_isv')
                    ->label('Aplica ISV')
                    ->placeholder('Todos')
                    ->trueLabel('Con ISV')
                    ->falseLabel('Sin ISV'),
            ])
            ->actions([
                Tables\Actions\Action::make('agregar')
                    ->label('Agregar')
                    ->icon('heroicon-o-plus')
                    ->color('success')
                    ->size('sm')
                    ->action(function (Producto $record) {
                        // Obtener datos de bodega_producto
                        $bp = null;
                        $precio = 0;
                        $stock = 0;
                        $costo = 0;

                        if ($this->bodegaId) {
                            $bp = BodegaProducto::where('bodega_id', $this->bodegaId)
                                ->where('producto_id', $record->id)
                                ->first();

                            $precio = $bp?->precio_venta_sugerido ?? 0;
                            $stock = $bp?->stock ?? 0;
                            $costo = $bp?->costo_promedio_actual ?? 0;
                        }

                        // Emitir evento para agregar al repeater
                        $this->dispatch('agregar-producto-venta', [
                            'producto_id' => $record->id,
                            'nombre' => $record->nombre,
                            'unidad_id' => $record->unidad_id,
                            'precio_unitario' => $precio,
                            'costo_unitario' => $costo,
                            'stock_disponible' => $stock,
                            'aplica_isv' => $record->aplica_isv ?? false,
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->title('Producto agregado')
                            ->body($record->nombre)
                            ->success()
                            ->duration(2000)
                            ->send();
                    })
                    ->disabled(function (Producto $record) {
                        if (!$this->bodegaId) return true;
                        $bp = BodegaProducto::where('bodega_id', $this->bodegaId)
                            ->where('producto_id', $record->id)
                            ->first();
                        return !$bp || $bp->stock <= 0;
                    }),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->searchable()
            ->striped()
            ->emptyStateHeading('Sin productos')
            ->emptyStateDescription(
                $this->bodegaId
                    ? 'No hay productos disponibles.'
                    : 'Selecciona una bodega para ver los productos disponibles.'
            )
            ->emptyStateIcon('heroicon-o-cube');
    }

    public static function canView(): bool
    {
        return true;
    }
}