<?php

namespace App\Filament\Resources\ViajeResource\RelationManagers;

use App\Models\Producto;
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
                                            
                                            $producto = Producto::find($state);

                                            if ($carga && $producto) {
                                                $set('unidad_id', $carga->unidad_id);
                                                $set('costo_unitario', $carga->costo_unitario);
                                                $set('cantidad_disponible', $carga->getCantidadDisponible());
                                                
                                                // Verificar si el producto tiene unidades por bulto
                                                $unidadesPorBulto = $producto->unidades_por_bulto;
                                                $set('tiene_subunidades', !empty($unidadesPorBulto) && $unidadesPorBulto > 1);
                                                $set('unidades_por_bulto', $unidadesPorBulto ?? 1);
                                                
                                                // Calcular costo por unidad individual
                                                if ($unidadesPorBulto && $unidadesPorBulto > 1) {
                                                    $costoPorUnidad = $carga->costo_unitario / $unidadesPorBulto;
                                                    $set('costo_por_unidad_individual', round($costoPorUnidad, 4));
                                                } else {
                                                    $set('costo_por_unidad_individual', $carga->costo_unitario);
                                                }
                                                
                                                // Reset tipo de merma
                                                $set('tipo_merma', 'bulto_completo');
                                                $set('cantidad_unidades', null);
                                            }
                                        } else {
                                            $set('tiene_subunidades', false);
                                            $set('unidades_por_bulto', 1);
                                            $set('costo_por_unidad_individual', null);
                                        }
                                    }),

                                Forms\Components\Select::make('unidad_id')
                                    ->label('Unidad de Carga')
                                    ->relationship('unidad', 'nombre')
                                    ->required()
                                    ->disabled()
                                    ->dehydrated(),

                                // Información del producto
                                Forms\Components\Placeholder::make('info_producto')
                                    ->label('Información del Producto')
                                    ->content(function (Forms\Get $get) {
                                        $unidadesPorBulto = $get('unidades_por_bulto');
                                        $disponible = $get('cantidad_disponible');
                                        
                                        if (!$unidadesPorBulto || $unidadesPorBulto <= 1) {
                                            return "Disponible: {$disponible} unidades";
                                        }
                                        
                                        $totalUnidades = $disponible * $unidadesPorBulto;
                                        return "Disponible: {$disponible} bultos ({$totalUnidades} unidades individuales) | 1 bulto = {$unidadesPorBulto} unidades";
                                    })
                                    ->columnSpanFull()
                                    ->visible(fn (Forms\Get $get) => $get('producto_id')),

                                // Tipo de merma (solo visible si tiene subunidades)
                                Forms\Components\Radio::make('tipo_merma')
                                    ->label('Tipo de Merma')
                                    ->options([
                                        'bulto_completo' => 'Bultos/Cartones completos',
                                        'unidades_sueltas' => 'Unidades individuales (huevos, piezas, etc.)',
                                    ])
                                    ->default('bulto_completo')
                                    ->required()
                                    ->live()
                                    ->visible(fn (Forms\Get $get) => $get('tiene_subunidades'))
                                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                        // Limpiar cantidades al cambiar tipo
                                        $set('cantidad', null);
                                        $set('cantidad_unidades', null);
                                        $set('subtotal_costo', null);
                                        $set('equivalencia_texto', null);
                                    })
                                    ->columnSpanFull(),

                                // Campo para bultos completos
                                Forms\Components\TextInput::make('cantidad')
                                    ->label(fn (Forms\Get $get) => $get('tipo_merma') === 'unidades_sueltas' ? 'Cantidad (calculada)' : 'Cantidad de Bultos/Cartones')
                                    ->required(fn (Forms\Get $get) => $get('tipo_merma') !== 'unidades_sueltas')
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->step(0.001)
                                    ->live(debounce: 300)
                                    ->disabled(fn (Forms\Get $get) => $get('tipo_merma') === 'unidades_sueltas')
                                    ->dehydrated()
                                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                        if ($get('tipo_merma') !== 'unidades_sueltas') {
                                            $costo = $get('costo_unitario') ?? 0;
                                            $set('subtotal_costo', round($state * $costo, 2));
                                            
                                            // Mostrar equivalencia en unidades
                                            $unidadesPorBulto = $get('unidades_por_bulto') ?? 1;
                                            if ($unidadesPorBulto > 1) {
                                                $totalUnidades = $state * $unidadesPorBulto;
                                                $set('equivalencia_texto', "{$state} bultos = {$totalUnidades} unidades individuales");
                                            }
                                        }
                                    })
                                    ->helperText(fn (Forms\Get $get) => $get('tipo_merma') === 'unidades_sueltas' 
                                        ? 'Se calcula automáticamente desde las unidades' 
                                        : null),

                                // Campo para unidades individuales (solo visible si tipo = unidades_sueltas)
                                Forms\Components\TextInput::make('cantidad_unidades')
                                    ->label('Cantidad de Unidades Individuales')
                                    ->placeholder('Ej: 5 huevos rotos')
                                    ->numeric()
                                    ->minValue(1)
                                    ->step(1)
                                    ->live(debounce: 300)
                                    ->visible(fn (Forms\Get $get) => $get('tipo_merma') === 'unidades_sueltas')
                                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                        $unidadesPorBulto = $get('unidades_por_bulto') ?? 1;
                                        $costoPorUnidad = $get('costo_por_unidad_individual') ?? 0;
                                        
                                        if ($unidadesPorBulto > 0 && $state > 0) {
                                            // Convertir unidades a fracción de bulto
                                            $cantidadBultos = round($state / $unidadesPorBulto, 6);
                                            $set('cantidad', $cantidadBultos);
                                            
                                            // Calcular costo total
                                            $costoTotal = round($state * $costoPorUnidad, 2);
                                            $set('subtotal_costo', $costoTotal);
                                            
                                            // Texto de equivalencia
                                            $set('equivalencia_texto', "{$state} unidades = {$cantidadBultos} bultos");
                                        }
                                    })
                                    ->helperText(fn (Forms\Get $get) => 
                                        $get('unidades_por_bulto') 
                                            ? "1 bulto = {$get('unidades_por_bulto')} unidades | Costo por unidad: L " . number_format($get('costo_por_unidad_individual') ?? 0, 4)
                                            : null
                                    ),

                                // Mostrar equivalencia
                                Forms\Components\Placeholder::make('equivalencia_texto')
                                    ->label('Equivalencia')
                                    ->content(fn (Forms\Get $get) => $get('equivalencia_texto') ?? '-')
                                    ->visible(fn (Forms\Get $get) => !empty($get('equivalencia_texto'))),

                                Forms\Components\TextInput::make('costo_unitario')
                                    ->label('Costo por Bulto')
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
                                    ->label('Costo Total de Merma')
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

                // Campos ocultos para el procesamiento
                Forms\Components\Hidden::make('tiene_subunidades'),
                Forms\Components\Hidden::make('unidades_por_bulto'),
                Forms\Components\Hidden::make('costo_por_unidad_individual'),
                Forms\Components\Hidden::make('cantidad_disponible'),

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
                    ->formatStateUsing(function ($state, $record) {
                        $producto = $record->producto;
                        $unidadesPorBulto = $producto?->unidades_por_bulto;
                        
                        // Si es una fracción y tiene unidades por bulto, mostrar en unidades
                        if ($unidadesPorBulto && $unidadesPorBulto > 1 && fmod($state, 1) != 0) {
                            $unidadesIndividuales = round($state * $unidadesPorBulto);
                            return number_format($state, 4) . " ({$unidadesIndividuales} uni)";
                        }
                        
                        return number_format($state, 2);
                    })
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
                        
                        // Limpiar campos temporales que no van a la BD
                        unset($data['tipo_merma']);
                        unset($data['cantidad_unidades']);
                        unset($data['tiene_subunidades']);
                        unset($data['unidades_por_bulto']);
                        unset($data['costo_por_unidad_individual']);
                        unset($data['cantidad_disponible']);
                        unset($data['equivalencia_texto']);
                        
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
                                ->body("Solo hay {$disponible} unidades disponibles. Intentas registrar {$data['cantidad']}")
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
                    ->visible(fn () => !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado']))
                    ->mutateRecordDataUsing(function (array $data, $record): array {
                        // Cargar datos adicionales para edición
                        $producto = $record->producto;
                        $unidadesPorBulto = $producto?->unidades_por_bulto ?? 1;
                        
                        $data['tiene_subunidades'] = $unidadesPorBulto > 1;
                        $data['unidades_por_bulto'] = $unidadesPorBulto;
                        
                        // Determinar si fue registrado como unidades sueltas
                        if ($unidadesPorBulto > 1 && fmod($data['cantidad'], 1) != 0) {
                            $data['tipo_merma'] = 'unidades_sueltas';
                            $data['cantidad_unidades'] = round($data['cantidad'] * $unidadesPorBulto);
                        } else {
                            $data['tipo_merma'] = 'bulto_completo';
                        }
                        
                        return $data;
                    }),
                
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