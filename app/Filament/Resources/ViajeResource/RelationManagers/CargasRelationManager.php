<?php

namespace App\Filament\Resources\ViajeResource\RelationManagers;

use App\Models\Producto;
use App\Models\BodegaProducto;
use App\Models\Unidad;
use App\Models\Viaje;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class CargasRelationManager extends RelationManager
{
    protected static string $relationship = 'cargas';

    protected static ?string $title = 'Productos Cargados';

    protected static ?string $modelLabel = 'Carga';

    protected static ?string $pluralModelLabel = 'Cargas';

    public function isReadOnly(): bool
    {
        return !in_array($this->getOwnerRecord()->estado, [
            Viaje::ESTADO_PLANIFICADO,
            Viaje::ESTADO_CARGANDO
        ]);
    }

    public function form(Form $form): Form
    {
        $viaje = $this->getOwnerRecord();

        return $form
            ->schema([
                Forms\Components\Select::make('producto_id')
                    ->label('Producto')
                    ->options(function () use ($viaje) {
                        return BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('stock', '>', 0)
                            ->with('producto')
                            ->get()
                            ->pluck('producto.nombre', 'producto_id');
                    })
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Set $set) use ($viaje) {
                        if ($state) {
                            $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                                ->where('producto_id', $state)
                                ->first();

                            $producto = Producto::find($state);

                            if ($bodegaProducto) {
                                $set('stock_disponible', number_format($bodegaProducto->stock, 2));
                                $set('costo_unitario', $bodegaProducto->costo_promedio_actual);
                                $set('precio_venta_sugerido', $bodegaProducto->precio_venta_sugerido ?? ($bodegaProducto->costo_promedio_actual + 5));
                                $set('precio_venta_minimo', $bodegaProducto->costo_promedio_actual);
                            }

                            if ($producto) {
                                $set('unidad_id', $producto->unidad_id);
                            }
                        }
                    })
                    ->columnSpan(2),

                Forms\Components\Select::make('unidad_id')
                    ->label('Unidad')
                    ->options(fn () => Unidad::where('activo', true)->pluck('nombre', 'id'))
                    ->required()
                    ->searchable(),

                Forms\Components\TextInput::make('stock_disponible')
                    ->label('Stock Disponible')
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\TextInput::make('cantidad')
                    ->label('Cantidad a Cargar')
                    ->numeric()
                    ->required()
                    ->minValue(0.001)
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                        $costo = floatval($get('costo_unitario') ?? 0);
                        $precio = floatval($get('precio_venta_sugerido') ?? 0);
                        $cantidad = floatval($state ?? 0);

                        $set('subtotal_costo', round($costo * $cantidad, 2));
                        $set('subtotal_venta', round($precio * $cantidad, 2));
                    }),

                Forms\Components\TextInput::make('costo_unitario')
                    ->label('Costo Unitario')
                    ->numeric()
                    ->prefix('L')
                    ->required()
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                        $cantidad = floatval($get('cantidad') ?? 0);
                        $set('subtotal_costo', round(floatval($state) * $cantidad, 2));
                        $set('precio_venta_minimo', floatval($state)); // Mínimo = costo
                    }),

                Forms\Components\TextInput::make('precio_venta_sugerido')
                    ->label('Precio Venta Sugerido')
                    ->numeric()
                    ->prefix('L')
                    ->required()
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                        $cantidad = floatval($get('cantidad') ?? 0);
                        $set('subtotal_venta', round(floatval($state) * $cantidad, 2));
                    }),

                Forms\Components\TextInput::make('precio_venta_minimo')
                    ->label('Precio Mínimo')
                    ->numeric()
                    ->prefix('L')
                    ->disabled()
                    ->dehydrated(true)
                    ->helperText('No puede vender por debajo de este precio'),

                Forms\Components\TextInput::make('subtotal_costo')
                    ->label('Subtotal Costo')
                    ->numeric()
                    ->prefix('L')
                    ->disabled()
                    ->dehydrated(true),

                Forms\Components\TextInput::make('subtotal_venta')
                    ->label('Subtotal Venta Esperado')
                    ->numeric()
                    ->prefix('L')
                    ->disabled()
                    ->dehydrated(true),
            ])
            ->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('producto.nombre')
            ->columns([
                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('unidad.nombre')
                    ->label('Unidad')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('costo_unitario')
                    ->label('Costo Unit.')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('precio_venta_sugerido')
                    ->label('Precio Venta')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('subtotal_costo')
                    ->label('Subtotal Costo')
                    ->money('HNL')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('HNL')),

                Tables\Columns\TextColumn::make('subtotal_venta')
                    ->label('Subtotal Venta')
                    ->money('HNL')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('HNL')),

                // Columnas visibles cuando está en ruta o cerrado
                Tables\Columns\TextColumn::make('cantidad_vendida')
                    ->label('Vendido')
                    ->numeric(decimalPlaces: 2)
                    ->color('success')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_EN_RUTA,
                        Viaje::ESTADO_REGRESANDO,
                        Viaje::ESTADO_DESCARGANDO,
                        Viaje::ESTADO_LIQUIDANDO,
                        Viaje::ESTADO_CERRADO
                    ])),

                Tables\Columns\TextColumn::make('cantidad_merma')
                    ->label('Merma')
                    ->numeric(decimalPlaces: 2)
                    ->color('danger')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_EN_RUTA,
                        Viaje::ESTADO_REGRESANDO,
                        Viaje::ESTADO_DESCARGANDO,
                        Viaje::ESTADO_LIQUIDANDO,
                        Viaje::ESTADO_CERRADO
                    ])),

                Tables\Columns\TextColumn::make('disponible')
                    ->label('Disponible')
                    ->getStateUsing(fn ($record) => $record->getCantidadDisponible())
                    ->numeric(decimalPlaces: 2)
                    ->color('warning')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_EN_RUTA,
                        Viaje::ESTADO_REGRESANDO,
                    ])),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Agregar Producto')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_PLANIFICADO,
                        Viaje::ESTADO_CARGANDO
                    ]))
                    ->before(function (array $data) {
                        // Validar stock disponible
                        $viaje = $this->getOwnerRecord();
                        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('producto_id', $data['producto_id'])
                            ->first();

                        if (!$bodegaProducto || $bodegaProducto->stock < $data['cantidad']) {
                            Notification::make()
                                ->title('Stock insuficiente')
                                ->body('No hay suficiente stock disponible para este producto.')
                                ->danger()
                                ->send();

                            $this->halt();
                        }
                    })
                    ->after(function ($record) {
                        // Descontar del stock de bodega
                        $viaje = $this->getOwnerRecord();
                        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('producto_id', $record->producto_id)
                            ->first();

                        if ($bodegaProducto) {
                            $bodegaProducto->reducirStock($record->cantidad);
                        }

                        // Cambiar estado a cargando si está planificado
                        if ($viaje->estado === Viaje::ESTADO_PLANIFICADO) {
                            $viaje->iniciarCarga();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_PLANIFICADO,
                        Viaje::ESTADO_CARGANDO
                    ]))
                    ->before(function ($record, array $data) {
                        // Si cambia la cantidad, ajustar stock
                        $viaje = $this->getOwnerRecord();
                        $diferencia = $data['cantidad'] - $record->cantidad;

                        if ($diferencia > 0) {
                            // Necesita más stock
                            $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                                ->where('producto_id', $record->producto_id)
                                ->first();

                            if (!$bodegaProducto || $bodegaProducto->stock < $diferencia) {
                                Notification::make()
                                    ->title('Stock insuficiente')
                                    ->body('No hay suficiente stock para aumentar la cantidad.')
                                    ->danger()
                                    ->send();

                                $this->halt();
                            }
                        }
                    })
                    ->after(function ($record, array $data) {
                        $viaje = $this->getOwnerRecord();
                        $cantidadAnterior = $record->getOriginal('cantidad');
                        $diferencia = $record->cantidad - $cantidadAnterior;

                        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('producto_id', $record->producto_id)
                            ->first();

                        if ($bodegaProducto && $diferencia != 0) {
                            if ($diferencia > 0) {
                                $bodegaProducto->reducirStock($diferencia);
                            } else {
                                // Devolver stock
                                $bodegaProducto->stock += abs($diferencia);
                                $bodegaProducto->save();
                            }
                        }
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_PLANIFICADO,
                        Viaje::ESTADO_CARGANDO
                    ]))
                    ->before(function ($record) {
                        // Devolver stock a bodega
                        $viaje = $this->getOwnerRecord();
                        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('producto_id', $record->producto_id)
                            ->first();

                        if ($bodegaProducto) {
                            $bodegaProducto->stock += $record->cantidad;
                            $bodegaProducto->save();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                            Viaje::ESTADO_PLANIFICADO,
                            Viaje::ESTADO_CARGANDO
                        ])),
                ]),
            ])
            ->emptyStateHeading('Sin productos cargados')
            ->emptyStateDescription('Agregue productos para cargar en el camión.')
            ->emptyStateIcon('heroicon-o-archive-box');
    }
}
