<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ViajeResource\Pages;
use App\Models\Viaje;
use App\Models\Producto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Notifications\Notification;

class ViajeResource extends Resource
{
    protected static ?string $model = Viaje::class;
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Logística';
    protected static ?string $navigationLabel = 'Viajes';
    protected static ?string $modelLabel = 'Viaje';
    protected static ?string $pluralModelLabel = 'Viajes';
    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Información General')
                ->schema([
                    Forms\Components\Select::make('camion_id')
                        ->label('Camión')
                        ->relationship('camion', 'placa')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\Select::make('chofer_user_id')
                        ->label('Chofer')
                        ->relationship('chofer', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\Select::make('bodega_origen_id')
                        ->label('Bodega de Origen')
                        ->relationship('bodegaOrigen', 'nombre')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\Select::make('estado')
                        ->label('Estado')
                        ->options([
                            'en_preparacion' => 'En Preparación',
                            'en_ruta' => 'En Ruta',
                            'cerrado' => 'Cerrado',
                        ])
                        ->default('en_preparacion')
                        ->required()
                        ->disabled(fn ($record) => $record?->estaCerrado())
                        ->columnSpan(1),

                    Forms\Components\DateTimePicker::make('fecha_salida')
                        ->label('Fecha de Salida')
                        ->default(now())
                        ->required()
                        ->native(false)
                        ->seconds(false)
                        ->columnSpan(1),

                    Forms\Components\DateTimePicker::make('fecha_regreso')
                        ->label('Fecha de Regreso')
                        ->native(false)
                        ->seconds(false)
                        ->visible(fn ($record) => $record && $record->estaCerrado())
                        ->columnSpan(1),

                    Forms\Components\Textarea::make('nota')
                        ->label('Notas')
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpan(2),
                ])
                ->columns(2),

            Forms\Components\Section::make('Carga del Viaje')
                ->schema([
                    Forms\Components\Repeater::make('cargas')
                        ->relationship()
                        ->schema([
                            Forms\Components\Select::make('producto_id')
                                ->label('Producto')
                                ->relationship('producto', 'nombre')
                                ->searchable()
                                ->preload()
                                ->required()
                                ->reactive()
                                ->afterStateUpdated(function ($state, callable $set) {
                                    $set('unidad_id_presentacion', null);
                                    $set('factor_a_base', null);
                                })
                                ->columnSpan(2),

                            Forms\Components\Select::make('unidad_id_presentacion')
                                ->label('Presentación')
                                ->options(function (callable $get) {
                                    $productoId = $get('producto_id');
                                    if (!$productoId) {
                                        return [];
                                    }

                                    $producto = Producto::find($productoId);
                                    if (!$producto) {
                                        return [];
                                    }

                                    return $producto->presentaciones()
                                        ->where('activo', true)
                                        ->with('unidad')
                                        ->get()
                                        ->pluck('unidad.nombre', 'unidad_id');
                                })
                                ->searchable()
                                ->required()
                                ->reactive()
                                ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                    $productoId = $get('producto_id');
                                    if (!$productoId || !$state) {
                                        return;
                                    }

                                    $producto = Producto::find($productoId);
                                    $presentacion = $producto->presentaciones()
                                        ->where('unidad_id', $state)
                                        ->first();

                                    if ($presentacion) {
                                        $set('factor_a_base', $presentacion->factor_a_base);
                                    }
                                })
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('cantidad_presentacion')
                                ->label('Cantidad')
                                ->numeric()
                                ->rules(['decimal:0,3'])
                                ->required()
                                ->minValue(0.001)
                                ->step(0.001)
                                ->reactive()
                                ->afterStateUpdated(fn ($state, callable $get, callable $set) =>
                                    static::calcularCantidadBase($get, $set)
                                )
                                ->columnSpan(1),

                            Forms\Components\TextInput::make('factor_a_base')
                                ->label('Factor')
                                ->numeric()
                                ->disabled()
                                ->dehydrated()
                                ->helperText('Factor de conversión a unidad base')
                                ->columnSpan(1),

                            Forms\Components\TextInput::make('cantidad_base')
                                ->label('Cantidad Base')
                                ->numeric()
                                ->disabled()
                                ->dehydrated()
                                ->helperText('Total en unidad base')
                                ->columnSpan(2),
                        ])
                        ->columns(6)
                        ->defaultItems(1)
                        ->addActionLabel('Agregar producto')
                        ->deleteAction(
                            fn ($action) => $action->requiresConfirmation()
                        )
                        ->reorderable(false)
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string =>
                            isset($state['producto_id'])
                                ? Producto::find($state['producto_id'])?->nombre
                                : 'Nuevo producto'
                        )
                        ->disabled(fn ($record) => $record && !$record->estaEnPreparacion()),
                ])
                ->collapsible()
                ->collapsed(false)
                ->visible(fn ($record) => !$record || $record->estaEnPreparacion()),

            Forms\Components\Section::make('Mermas del Viaje')
                ->schema([
                    Forms\Components\Repeater::make('mermas')
                        ->relationship()
                        ->schema([
                            Forms\Components\Select::make('producto_id')
                                ->label('Producto')
                                ->relationship('producto', 'nombre')
                                ->searchable()
                                ->preload()
                                ->required()
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('cantidad_base')
                                ->label('Cantidad (Unidad Base)')
                                ->numeric()
                                ->rules(['decimal:0,3'])
                                ->required()
                                ->minValue(0.001)
                                ->step(0.001)
                                ->helperText('Cantidad de merma en unidad base del producto')
                                ->columnSpan(1),

                            Forms\Components\TextInput::make('motivo')
                                ->label('Motivo')
                                ->maxLength(255)
                                ->placeholder('Ej: Rotura, Vencimiento, etc.')
                                ->columnSpan(3),
                        ])
                        ->columns(6)
                        ->defaultItems(0)
                        ->addActionLabel('Registrar merma')
                        ->deleteAction(
                            fn ($action) => $action->requiresConfirmation()
                        )
                        ->reorderable(false)
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string =>
                            isset($state['producto_id'])
                                ? Producto::find($state['producto_id'])?->nombre . ' - ' . ($state['motivo'] ?? 'Sin motivo')
                                : 'Nueva merma'
                        )
                        ->disabled(fn ($record) => $record && $record->estaCerrado()),
                ])
                ->collapsible()
                ->collapsed(true)
                ->visible(fn ($record) => $record && $record->estaEnRuta()),

            Forms\Components\Section::make('Liquidación de Comisiones')
                ->schema([
                    Forms\Components\Placeholder::make('cartones_30')
                        ->label('Cartones 30 Vendidos')
                        ->content(fn ($record) => $record->liquidacionComision
                            ? number_format($record->liquidacionComision->cartones_30_vendidos, 2)
                            : '—'
                        ),

                    Forms\Components\Placeholder::make('cartones_15')
                        ->label('Cartones 15 Vendidos')
                        ->content(fn ($record) => $record->liquidacionComision
                            ? number_format($record->liquidacionComision->cartones_15_vendidos, 2)
                            : '—'
                        ),

                    Forms\Components\Placeholder::make('total_comision')
                        ->label('Total Comisión')
                        ->content(fn ($record) => $record->liquidacionComision
                            ? 'L ' . number_format($record->liquidacionComision->total_comision, 2)
                            : '—'
                        )
                        ->extraAttributes(['class' => 'text-lg font-bold text-success-600']),
                ])
                ->columns(3)
                ->visible(fn ($record) => $record && $record->estaCerrado() && $record->liquidacionComision),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('fecha_salida')
                    ->label('Fecha Salida')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('camion.placa')
                    ->label('Camión')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('chofer.name')
                    ->label('Chofer')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('bodegaOrigen.nombre')
                    ->label('Bodega Origen')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->colors([
                        'warning' => 'en_preparacion',
                        'primary' => 'en_ruta',
                        'success' => 'cerrado',
                    ])
                    ->icons([
                        'heroicon-o-clock' => 'en_preparacion',
                        'heroicon-o-truck' => 'en_ruta',
                        'heroicon-o-check-circle' => 'cerrado',
                    ])
                    ->formatStateUsing(fn (string $state): string => match($state) {
                        'en_preparacion' => 'En Preparación',
                        'en_ruta' => 'En Ruta',
                        'cerrado' => 'Cerrado',
                        default => ucfirst($state)
                    }),

                Tables\Columns\TextColumn::make('cargas_count')
                    ->label('Productos')
                    ->counts('cargas')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('mermas_count')
                    ->label('Mermas')
                    ->counts('mermas')
                    ->badge()
                    ->color('warning')
                    ->visible(fn ($record) => $record && $record->mermas_count > 0),

                Tables\Columns\TextColumn::make('liquidacionComision.total_comision')
                    ->label('Comisión')
                    ->money('HNL')
                    ->color('success')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('fecha_regreso')
                    ->label('Regreso')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('duracion_horas')
                    ->label('Duración')
                    ->getStateUsing(fn ($record) => $record->duracion_horas
                        ? number_format($record->duracion_horas, 1) . ' hrs'
                        : '—'
                    )
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Creado por')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('fecha_salida', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('camion_id')
                    ->label('Camión')
                    ->relationship('camion', 'placa')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('chofer_user_id')
                    ->label('Chofer')
                    ->relationship('chofer', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('bodega_origen_id')
                    ->label('Bodega Origen')
                    ->relationship('bodegaOrigen', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'en_preparacion' => 'En Preparación',
                        'en_ruta' => 'En Ruta',
                        'cerrado' => 'Cerrado',
                    ])
                    ->native(false),

                Tables\Filters\Filter::make('fecha_salida')
                    ->form([
                        Forms\Components\DatePicker::make('desde')
                            ->label('Desde'),
                        Forms\Components\DatePicker::make('hasta')
                            ->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['desde'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('fecha_salida', '>=', $date),
                            )
                            ->when(
                                $data['hasta'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('fecha_salida', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['desde'] ?? null) {
                            $indicators[] = 'Desde: ' . \Carbon\Carbon::parse($data['desde'])->format('d/m/Y');
                        }
                        if ($data['hasta'] ?? null) {
                            $indicators[] = 'Hasta: ' . \Carbon\Carbon::parse($data['hasta'])->format('d/m/Y');
                        }
                        return $indicators;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('iniciar')
                    ->label('Iniciar Viaje')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Iniciar Viaje')
                    ->modalDescription('¿Estás seguro? Se generarán movimientos de inventario (salida) y el viaje pasará a estado "En Ruta".')
                    ->modalSubmitActionLabel('Sí, iniciar')
                    ->action(function (Viaje $record) {
                        try {
                            if ($record->iniciar()) {
                                Notification::make()
                                    ->success()
                                    ->title('Viaje iniciado')
                                    ->body('El viaje está ahora en ruta. Se generaron las salidas de inventario.')
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error')
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->visible(fn (Viaje $record) => $record->estaEnPreparacion()),

                Tables\Actions\Action::make('cerrar')
                    ->label('Cerrar Viaje')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Cerrar Viaje')
                    ->modalDescription('¿Estás seguro? Se registrarán las mermas y se calcularán las comisiones del chofer.')
                    ->modalSubmitActionLabel('Sí, cerrar')
                    ->action(function (Viaje $record) {
                        try {
                            if ($record->cerrar()) {
                                Notification::make()
                                    ->success()
                                    ->title('Viaje cerrado')
                                    ->body('El viaje ha sido cerrado. Se registraron mermas y comisiones.')
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error')
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->visible(fn (Viaje $record) => $record->estaEnRuta()),

                Tables\Actions\ViewAction::make(),

                Tables\Actions\EditAction::make()
                    ->visible(fn (Viaje $record) => !$record->estaCerrado()),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Viaje $record) => $record->estaEnPreparacion()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->emptyStateHeading('Sin viajes registrados')
            ->emptyStateDescription('Comienza registrando el primer viaje de distribución.')
            ->emptyStateIcon('heroicon-o-truck');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListViajes::route('/'),
            'create' => Pages\CreateViaje::route('/create'),
            'edit' => Pages\EditViaje::route('/{record}/edit'),
            // 'view' key removed due to missing class Pages\ViewViaje
        ];
    }

    /**
     * Calcular cantidad base
     */
    protected static function calcularCantidadBase(callable $get, callable $set): void
    {
        $cantidad = (float) $get('cantidad_presentacion');
        $factor = (float) $get('factor_a_base') ?? 1;

        $cantidadBase = $cantidad * $factor;

        $set('cantidad_base', number_format($cantidadBase, 3, '.', ''));
    }

    /**
     * Navegación con badge
     */
    public static function getNavigationBadge(): ?string
    {
        $enPreparacion = static::getModel()::where('estado', 'en_preparacion')->count();
        $enRuta = static::getModel()::where('estado', 'en_ruta')->count();

        $total = $enPreparacion + $enRuta;
        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $enRuta = static::getModel()::where('estado', 'en_ruta')->count();
        return $enRuta > 0 ? 'primary' : 'warning';
    }
}
