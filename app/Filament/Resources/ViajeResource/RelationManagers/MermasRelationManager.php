<?php

namespace App\Filament\Resources\ViajeResource\RelationManagers;

use App\Models\ViajeCarga;
use App\Models\ViajeMerma;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class MermasRelationManager extends RelationManager
{
    protected static string $relationship = 'mermas';

    protected static ?string $title = 'Mermas del Viaje';

    protected static ?string $modelLabel = 'Merma';

    protected static ?string $pluralModelLabel = 'Mermas';

    protected static ?string $icon = 'heroicon-o-exclamation-triangle';

    public function isReadOnly(): bool
    {
        return in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado']);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información de la Merma')
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
                                    ->label('Cantidad')
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

                                Forms\Components\Select::make('motivo')
                                    ->label('Motivo')
                                    ->required()
                                    ->options([
                                        ViajeMerma::MOTIVO_ROTURA => 'Rotura',
                                        ViajeMerma::MOTIVO_VENCIMIENTO => 'Vencimiento',
                                        ViajeMerma::MOTIVO_ROBO => 'Robo',
                                        ViajeMerma::MOTIVO_DANO_TRANSPORTE => 'Daño en Transporte',
                                        ViajeMerma::MOTIVO_REGALO_CLIENTE => 'Regalo a Cliente',
                                        ViajeMerma::MOTIVO_OTRO => 'Otro',
                                    ])
                                    ->native(false)
                                    ->default(ViajeMerma::MOTIVO_ROTURA)
                                    ->live(),

                                Forms\Components\TextInput::make('subtotal_costo')
                                    ->label('Costo Total')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled()
                                    ->dehydrated(),
                            ]),

                        Forms\Components\Textarea::make('descripcion')
                            ->label('Descripción')
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Describe el motivo de la merma...'),
                    ]),

                Forms\Components\Section::make('Cobro al Chofer')
                    ->schema([
                        Forms\Components\Toggle::make('cobrar_chofer')
                            ->label('¿Cobrar al chofer?')
                            ->default(false)
                            ->live()
                            ->helperText('Marcar si esta merma se descontará del chofer'),

                        Forms\Components\TextInput::make('monto_cobrar')
                            ->label('Monto a Cobrar')
                            ->numeric()
                            ->prefix('L')
                            ->visible(fn (Forms\Get $get) => $get('cobrar_chofer'))
                            ->helperText('Por defecto es el costo total de la merma'),
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
                    ->color('danger')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('unidad.nombre')
                    ->label('Unidad')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('motivo')
                    ->label('Motivo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'rotura' => 'Rotura',
                        'vencimiento' => 'Vencimiento',
                        'robo' => 'Robo',
                        'dano_transporte' => 'Daño Transporte',
                        'regalo_cliente' => 'Regalo',
                        'otro' => 'Otro',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'rotura' => 'warning',
                        'vencimiento' => 'danger',
                        'robo' => 'danger',
                        'dano_transporte' => 'warning',
                        'regalo_cliente' => 'info',
                        'otro' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('subtotal_costo')
                    ->label('Costo')
                    ->money('HNL')
                    ->sortable()
                    ->color('danger'),

                Tables\Columns\IconColumn::make('cobrar_chofer')
                    ->label('Cobrar')
                    ->boolean()
                    ->trueIcon('heroicon-o-currency-dollar')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('danger')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('monto_cobrar')
                    ->label('Monto Cobro')
                    ->money('HNL')
                    ->visible(fn ($record) => $record?->cobrar_chofer)
                    ->color('danger'),

                Tables\Columns\TextColumn::make('descripcion')
                    ->label('Descripción')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('registradoPor.name')
                    ->label('Registrado por')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('motivo')
                    ->label('Motivo')
                    ->options([
                        'rotura' => 'Rotura',
                        'vencimiento' => 'Vencimiento',
                        'robo' => 'Robo',
                        'dano_transporte' => 'Daño Transporte',
                        'regalo_cliente' => 'Regalo',
                        'otro' => 'Otro',
                    ]),

                Tables\Filters\TernaryFilter::make('cobrar_chofer')
                    ->label('Cobrar al Chofer'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Registrar Merma')
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, ['en_ruta', 'regresando', 'descargando', 'liquidando']))
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['registrado_por'] = Auth::id();
                        
                        // Auto-calcular monto a cobrar si está marcado
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
                            Notification::make()
                                ->title('Error')
                                ->body('Este producto no está cargado en el viaje')
                                ->danger()
                                ->send();
                            throw new \Exception('Producto no cargado');
                        }

                        $disponible = $carga->getCantidadDisponible();
                        if ($data['cantidad'] > $disponible) {
                            Notification::make()
                                ->title('Stock insuficiente')
                                ->body("Solo hay {$disponible} unidades disponibles")
                                ->danger()
                                ->send();
                            throw new \Exception('Stock insuficiente');
                        }
                    })
                    ->after(function () {
                        Notification::make()
                            ->title('Merma registrada')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                
                Tables\Actions\EditAction::make()
                    ->visible(fn () => !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado'])),
                
                Tables\Actions\Action::make('cobrar')
                    ->label('Cobrar')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('danger')
                    ->visible(fn ($record) => !$record->cobrar_chofer && !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado']))
                    ->requiresConfirmation()
                    ->modalDescription('¿Desea cobrar esta merma al chofer?')
                    ->action(function ($record) {
                        $record->cobrarAlChofer();
                        Notification::make()
                            ->title('Merma marcada para cobro')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('no_cobrar')
                    ->label('No Cobrar')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn ($record) => $record->cobrar_chofer && !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado']))
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->noCobrarAlChofer();
                        Notification::make()
                            ->title('Cobro removido')
                            ->success()
                            ->send();
                    }),
                
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado'])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado'])),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Sin mermas')
            ->emptyStateDescription('No se han registrado mermas en este viaje.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}