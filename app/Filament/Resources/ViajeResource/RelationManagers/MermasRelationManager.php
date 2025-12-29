<?php

namespace App\Filament\Resources\ViajeResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MermasRelationManager extends RelationManager
{
    protected static string $relationship = 'mermas';

    protected static ?string $title = 'Mermas del Viaje';

    protected static ?string $modelLabel = 'merma';

    public function isReadOnly(): bool
    {
        return $this->getOwnerRecord()->estado === 'finalizado';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\Select::make('producto_id')
                            ->label('Producto')
                            ->options(function () {
                                // Solo productos que están cargados en este viaje
                                return $this->getOwnerRecord()->cargas()
                                    ->with('producto')
                                    ->get()
                                    ->pluck('producto.nombre', 'producto_id')
                                    ->unique();
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
                                        $disponible = $carga->getCantidadDisponible();
                                        $set('cantidad_disponible_info', number_format($disponible, 2) . ' ' . $carga->producto->unidadBase->nombre);
                                    }
                                }
                            }),

                        Forms\Components\Select::make('tipo_merma')
                            ->label('Tipo de Merma')
                            ->required()
                            ->options([
                                'rotura' => 'Rotura',
                                'vencimiento' => 'Vencimiento',
                                'deterioro' => 'Deterioro',
                                'robo' => 'Robo',
                                'otro' => 'Otro',
                            ])
                            ->native(false)
                            ->default('rotura'),

                        Forms\Components\TextInput::make('cantidad_base')
                            ->label('Cantidad')
                            ->required()
                            ->numeric()
                            ->minValue(0.001)
                            ->step(0.01)
                            ->suffix(function (Forms\Get $get) {
                                $productoId = $get('producto_id');
                                if (!$productoId) return '';

                                $producto = \App\Models\Producto::find($productoId);
                                return $producto?->unidadBase->nombre ?? '';
                            })
                            ->helperText('Cantidad en unidad base del producto'),

                        Forms\Components\TextInput::make('valor_estimado')
                            ->label('Valor Estimado')
                            ->numeric()
                            ->prefix('L')
                            ->step(0.01)
                            ->helperText('Valor monetario estimado de la pérdida'),

                        Forms\Components\Placeholder::make('cantidad_disponible_info')
                            ->label('Cantidad Disponible')
                            ->content('Selecciona un producto')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('motivo')
                            ->label('Motivo / Descripción')
                            ->maxLength(500)
                            ->rows(3)
                            ->columnSpanFull()
                            ->placeholder('Describe el motivo de la merma'),
                    ]),
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

                Tables\Columns\TextColumn::make('tipo_merma')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'rotura' => 'Rotura',
                        'vencimiento' => 'Vencimiento',
                        'deterioro' => 'Deterioro',
                        'robo' => 'Robo',
                        'otro' => 'Otro',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'rotura' => 'danger',
                        'vencimiento' => 'warning',
                        'deterioro' => 'orange',
                        'robo' => 'danger',
                        'otro' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('cantidad_base')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn ($record) => ' ' . $record->producto->unidadBase->nombre)
                    ->color('danger')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('valor_estimado')
                    ->label('Valor')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('motivo')
                    ->label('Motivo')
                    ->limit(50)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('registradoPor.name')
                    ->label('Registrado por')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo_merma')
                    ->label('Tipo')
                    ->options([
                        'rotura' => 'Rotura',
                        'vencimiento' => 'Vencimiento',
                        'deterioro' => 'Deterioro',
                        'robo' => 'Robo',
                        'otro' => 'Otro',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, ['en_ruta']))
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['registrado_por'] = Auth::id();
                        return $data;
                    })
                    ->before(function (array $data) {
                        // Validar que hay cantidad disponible
                        $carga = $this->getOwnerRecord()->cargas()
                            ->where('producto_id', $data['producto_id'])
                            ->first();

                        if (!$carga) {
                            throw new \Exception('Este producto no está cargado en el viaje');
                        }

                        $disponible = $carga->getCantidadDisponible();
                        if ($data['cantidad_base'] > $disponible) {
                            throw new \Exception("Solo hay {$disponible} unidades disponibles");
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn () => $this->getOwnerRecord()->estado === 'en_ruta'),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => $this->getOwnerRecord()->estado === 'en_ruta'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => $this->getOwnerRecord()->estado === 'en_ruta'),
                ]),
            ])
            ->emptyStateHeading('Sin mermas registradas')
            ->emptyStateDescription('Las mermas se registran durante el viaje')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
