<?php

namespace App\Filament\Resources\ViajeResource\RelationManagers;

use App\Models\ViajeCarga;
use App\Models\ViajeDescarga;
use App\Models\BodegaProducto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DescargasRelationManager extends RelationManager
{
    protected static string $relationship = 'descargas';

    protected static ?string $title = 'Descarga / Devolución';

    protected static ?string $modelLabel = 'Descarga';

    protected static ?string $pluralModelLabel = 'Descargas';

    protected static ?string $icon = 'heroicon-o-archive-box-arrow-down';

    public function isReadOnly(): bool
    {
        return in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado']);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Producto a Descargar')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('producto_id')
                                    ->label('Producto')
                                    ->options(function () {
                                        return $this->getOwnerRecord()->cargas()
                                            ->with('producto')
                                            ->get()
                                            ->mapWithKeys(function ($carga) {
                                                $disponible = $carga->getCantidadDisponible();
                                                return [
                                                    $carga->producto_id => $carga->producto->nombre . " (Disp: {$disponible})"
                                                ];
                                            });
                                    })
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        if ($state) {
                                            $carga = $this->getOwnerRecord()->cargas()
                                                ->where('producto_id', $state)
                                                ->first();

                                            if ($carga) {
                                                $set('unidad_id', $carga->unidad_id);
                                                $set('costo_unitario', $carga->costo_unitario);
                                                $set('cantidad', $carga->getCantidadDisponible());
                                            }
                                        }
                                    }),

                                Forms\Components\Select::make('unidad_id')
                                    ->label('Unidad')
                                    ->relationship('unidad', 'nombre')
                                    ->required()
                                    ->disabled()
                                    ->dehydrated(),

                                Forms\Components\TextInput::make('cantidad')
                                    ->label('Cantidad a Devolver')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->step(0.01)
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                        $costo = $get('costo_unitario') ?? 0;
                                        $set('subtotal_costo', round($state * $costo, 2));
                                    }),

                                Forms\Components\TextInput::make('costo_unitario')
                                    ->label('Costo Unitario')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled()
                                    ->dehydrated(),

                                Forms\Components\Select::make('estado_producto')
                                    ->label('Estado del Producto')
                                    ->required()
                                    ->options([
                                        'bueno' => 'Bueno (reingresa a inventario)',
                                        'danado' => 'Dañado',
                                        'vencido' => 'Vencido',
                                    ])
                                    ->default('bueno')
                                    ->live()
                                    ->native(false),

                                Forms\Components\TextInput::make('subtotal_costo')
                                    ->label('Costo Total')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled()
                                    ->dehydrated(),
                            ]),

                        Forms\Components\Toggle::make('reingresa_stock')
                            ->label('¿Reingresa al inventario?')
                            ->default(true)
                            ->helperText('Si está en buen estado, el producto vuelve a la bodega')
                            ->live(),

                        Forms\Components\Textarea::make('observaciones')
                            ->label('Observaciones')
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Cobro al Chofer')
                    ->schema([
                        Forms\Components\Toggle::make('cobrar_chofer')
                            ->label('¿Cobrar al chofer?')
                            ->default(false)
                            ->live()
                            ->helperText('Marcar si esta devolución se descontará del chofer'),

                        Forms\Components\TextInput::make('monto_cobrar')
                            ->label('Monto a Cobrar')
                            ->numeric()
                            ->prefix('L')
                            ->visible(fn (Forms\Get $get) => $get('cobrar_chofer'))
                            ->helperText('Por defecto es el costo total'),
                    ])
                    ->collapsed()
                    ->collapsible(),
            ]);
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
                    ->color('primary')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('unidad.nombre')
                    ->label('Unidad')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('estado_producto')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'bueno' => 'Bueno',
                        'danado' => 'Dañado',
                        'vencido' => 'Vencido',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'bueno' => 'success',
                        'danado' => 'warning',
                        'vencido' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('reingresa_stock')
                    ->label('Reingresa')
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-path')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('subtotal_costo')
                    ->label('Costo')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\IconColumn::make('cobrar_chofer')
                    ->label('Cobrar')
                    ->boolean()
                    ->trueIcon('heroicon-o-currency-dollar')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('danger')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado_producto')
                    ->label('Estado')
                    ->options([
                        'bueno' => 'Bueno',
                        'danado' => 'Dañado',
                        'vencido' => 'Vencido',
                    ]),

                Tables\Filters\TernaryFilter::make('reingresa_stock')
                    ->label('Reingresa Stock'),
            ])
            ->headerActions([
                // Acción para generar descarga automática
                Tables\Actions\Action::make('generar_descarga')
                    ->label('Generar Descarga Automática')
                    ->icon('heroicon-o-calculator')
                    ->color('warning')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, ['regresando', 'descargando']) 
                        && $this->getOwnerRecord()->descargas()->count() === 0)
                    ->requiresConfirmation()
                    ->modalHeading('Generar Descarga Automática')
                    ->modalDescription('Se calculará la cantidad no vendida de cada producto (Cargado - Vendido - Merma) y se creará la descarga automáticamente.')
                    ->action(function () {
                        $viaje = $this->getOwnerRecord();
                        $descargasCreadas = 0;

                        DB::transaction(function () use ($viaje, &$descargasCreadas) {
                            foreach ($viaje->cargas as $carga) {
                                $disponible = $carga->getCantidadDisponible();

                                if ($disponible > 0) {
                                    ViajeDescarga::create([
                                        'viaje_id' => $viaje->id,
                                        'producto_id' => $carga->producto_id,
                                        'unidad_id' => $carga->unidad_id,
                                        'cantidad' => $disponible,
                                        'costo_unitario' => $carga->costo_unitario,
                                        'subtotal_costo' => $disponible * $carga->costo_unitario,
                                        'estado_producto' => 'bueno',
                                        'reingresa_stock' => true,
                                        'cobrar_chofer' => false,
                                        'monto_cobrar' => 0,
                                    ]);

                                    // Actualizar cantidad_devuelta en la carga
                                    $carga->increment('cantidad_devuelta', $disponible);
                                    
                                    $descargasCreadas++;
                                }
                            }
                        });

                        if ($descargasCreadas > 0) {
                            Notification::make()
                                ->title('Descarga generada')
                                ->body("Se crearon {$descargasCreadas} registros de descarga")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Sin productos para descargar')
                                ->body('Todos los productos fueron vendidos o registrados como merma')
                                ->info()
                                ->send();
                        }
                    }),

                Tables\Actions\CreateAction::make()
                    ->label('Agregar Descarga Manual')
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, ['regresando', 'descargando', 'liquidando']))
                    ->mutateFormDataUsing(function (array $data): array {
                        if ($data['cobrar_chofer'] && empty($data['monto_cobrar'])) {
                            $data['monto_cobrar'] = $data['subtotal_costo'];
                        }
                        return $data;
                    })
                    ->before(function (array $data) {
                        $carga = $this->getOwnerRecord()->cargas()
                            ->where('producto_id', $data['producto_id'])
                            ->first();

                        if (!$carga) {
                            throw new \Exception('Este producto no está cargado en el viaje');
                        }

                        $disponible = $carga->getCantidadDisponible();
                        if ($data['cantidad'] > $disponible) {
                            Notification::make()
                                ->title('Cantidad excede disponible')
                                ->body("Solo hay {$disponible} unidades disponibles para descargar")
                                ->danger()
                                ->send();
                            throw new \Exception('Cantidad excede disponible');
                        }
                    })
                    ->after(function ($record) {
                        // Actualizar cantidad_devuelta en la carga
                        $carga = $this->getOwnerRecord()->cargas()
                            ->where('producto_id', $record->producto_id)
                            ->first();

                        if ($carga) {
                            $totalDevuelto = ViajeDescarga::where('viaje_id', $record->viaje_id)
                                ->where('producto_id', $record->producto_id)
                                ->sum('cantidad');

                            $carga->cantidad_devuelta = $totalDevuelto;
                            $carga->save();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                
                Tables\Actions\EditAction::make()
                    ->visible(fn () => !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado'])),

                Tables\Actions\Action::make('procesar_reingreso')
                    ->label('Reingresar a Bodega')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn ($record) => $record->reingresa_stock 
                        && $record->estado_producto === 'bueno'
                        && !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado']))
                    ->requiresConfirmation()
                    ->modalDescription('¿Confirma el reingreso de este producto al inventario de bodega?')
                    ->action(function ($record) {
                        $viaje = $this->getOwnerRecord();
                        
                        // Buscar o crear el registro en bodega_producto
                        $bodegaProducto = BodegaProducto::firstOrCreate(
                            [
                                'bodega_id' => $viaje->bodega_origen_id,
                                'producto_id' => $record->producto_id,
                            ],
                            [
                                'stock_actual' => 0,
                                'costo_promedio_actual' => $record->costo_unitario,
                            ]
                        );

                        // Incrementar stock
                        $bodegaProducto->increment('stock_actual', $record->cantidad);

                        Notification::make()
                            ->title('Stock reingresado')
                            ->body("Se agregaron {$record->cantidad} unidades a la bodega")
                            ->success()
                            ->send();
                    }),
                
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado']))
                    ->after(function ($record) {
                        // Actualizar cantidad_devuelta en la carga
                        $carga = $this->getOwnerRecord()->cargas()
                            ->where('producto_id', $record->producto_id)
                            ->first();

                        if ($carga) {
                            $totalDevuelto = ViajeDescarga::where('viaje_id', $record->viaje_id)
                                ->where('producto_id', $record->producto_id)
                                ->sum('cantidad');

                            $carga->cantidad_devuelta = $totalDevuelto;
                            $carga->save();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado'])),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Sin descargas')
            ->emptyStateDescription('Use "Generar Descarga Automática" para calcular los productos no vendidos.')
            ->emptyStateIcon('heroicon-o-archive-box');
    }
}