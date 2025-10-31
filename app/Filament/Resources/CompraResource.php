<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompraResource\Pages;
use App\Models\Compra;
use App\Models\Producto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Notifications\Notification;

class CompraResource extends Resource
{
    protected static ?string $model = Compra::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationGroup = 'Compras';
    protected static ?string $navigationLabel = 'Compras';
    protected static ?string $modelLabel = 'Compra';
    protected static ?string $pluralModelLabel = 'Compras';
    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Información General')
                ->schema([
                    Forms\Components\Select::make('proveedor_id')
                        ->label('Proveedor')
                        ->relationship('proveedor', 'nombre')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\Select::make('bodega_id')
                        ->label('Bodega de Destino')
                        ->relationship('bodega', 'nombre')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\DateTimePicker::make('fecha')
                        ->label('Fecha de Compra')
                        ->default(now())
                        ->required()
                        ->native(false)
                        ->seconds(false)
                        ->columnSpan(1),

                    Forms\Components\Select::make('estado')
                        ->label('Estado')
                        ->options([
                            'borrador' => 'Borrador',
                            'confirmada' => 'Confirmada',
                            'cancelada' => 'Cancelada',
                        ])
                        ->default('borrador')
                        ->required()
                        ->disabled(fn ($record) => $record?->estaConfirmada())
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('numero_factura')
                        ->label('Número de Factura')
                        ->maxLength(255)
                        ->columnSpan(1),

                    Forms\Components\Textarea::make('nota')
                        ->label('Notas')
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpan(2),
                ])
                ->columns(2),

            Forms\Components\Section::make('Detalles de la Compra')
                ->schema([
                    Forms\Components\Repeater::make('detalles')
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
                                    $set('precio_unitario_presentacion', null);
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
                                    static::calcularTotales($get, $set)
                                )
                                ->columnSpan(1),

                            Forms\Components\TextInput::make('factor_a_base')
                                ->label('Factor')
                                ->numeric()
                                ->disabled()
                                ->dehydrated()
                                ->helperText('Factor de conversión a unidad base')
                                ->columnSpan(1),

                            Forms\Components\TextInput::make('precio_unitario_presentacion')
                                ->label('Precio Unitario')
                                ->numeric()
                                ->rules(['decimal:0,4'])
                                ->required()
                                ->prefix('L')
                                ->minValue(0)
                                ->step(0.01)
                                ->reactive()
                                ->afterStateUpdated(fn ($state, callable $get, callable $set) =>
                                    static::calcularTotales($get, $set)
                                )
                                ->columnSpan(1),

                            Forms\Components\TextInput::make('descuento')
                                ->label('Descuento')
                                ->numeric()
                                ->rules(['decimal:0,4'])
                                ->prefix('L')
                                ->default(0)
                                ->minValue(0)
                                ->step(0.01)
                                ->reactive()
                                ->afterStateUpdated(fn ($state, callable $get, callable $set) =>
                                    static::calcularTotales($get, $set)
                                )
                                ->columnSpan(1),

                            Forms\Components\TextInput::make('total_linea')
                                ->label('Total')
                                ->numeric()
                                ->disabled()
                                ->dehydrated()
                                ->prefix('L')
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
                        ),
                ])
                ->collapsible()
                ->collapsed(false),

            Forms\Components\Section::make('Totales')
                ->schema([
                    Forms\Components\Placeholder::make('subtotal')
                        ->label('Subtotal')
                        ->content(fn ($record) => $record
                            ? 'L ' . number_format($record->subtotal, 2)
                            : 'L 0.00'
                        ),

                    Forms\Components\Placeholder::make('impuesto')
                        ->label('Impuesto (15%)')
                        ->content(fn ($record) => $record
                            ? 'L ' . number_format($record->impuesto, 2)
                            : 'L 0.00'
                        ),

                    Forms\Components\Placeholder::make('total')
                        ->label('Total')
                        ->content(fn ($record) => $record
                            ? 'L ' . number_format($record->total, 2)
                            : 'L 0.00'
                        )
                        ->extraAttributes(['class' => 'text-lg font-bold']),
                ])
                ->columns(3)
                ->visible(fn ($record) => $record !== null),
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

                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('numero_factura')
                    ->label('Factura')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->colors([
                        'warning' => 'borrador',
                        'success' => 'confirmada',
                        'danger' => 'cancelada',
                    ])
                    ->icons([
                        'heroicon-o-pencil' => 'borrador',
                        'heroicon-o-check-circle' => 'confirmada',
                        'heroicon-o-x-circle' => 'cancelada',
                    ])
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('detalles_count')
                    ->label('Items')
                    ->counts('detalles')
                    ->badge()
                    ->color('gray'),

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
            ->defaultSort('fecha', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'borrador' => 'Borrador',
                        'confirmada' => 'Confirmada',
                        'cancelada' => 'Cancelada',
                    ])
                    ->native(false),

                Tables\Filters\Filter::make('fecha')
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
                                fn (Builder $query, $date): Builder => $query->whereDate('fecha', '>=', $date),
                            )
                            ->when(
                                $data['hasta'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('fecha', '<=', $date),
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
                Tables\Actions\Action::make('confirmar')
                    ->label('Confirmar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar Compra')
                    ->modalDescription('¿Estás seguro? Se generarán movimientos de inventario y no podrás editar la compra.')
                    ->modalSubmitActionLabel('Sí, confirmar')
                    ->action(function (Compra $record) {
                        if ($record->confirmar()) {
                            Notification::make()
                                ->success()
                                ->title('Compra confirmada')
                                ->body('Se generaron los movimientos de inventario exitosamente.')
                                ->send();
                        } else {
                            Notification::make()
                                ->danger()
                                ->title('Error')
                                ->body('No se pudo confirmar la compra.')
                                ->send();
                        }
                    })
                    ->visible(fn (Compra $record) => $record->esBorrador()),

                Tables\Actions\ViewAction::make(),

                Tables\Actions\EditAction::make()
                    ->visible(fn (Compra $record) => $record->esBorrador()),

                Tables\Actions\Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancelar Compra')
                    ->modalDescription('¿Estás seguro? Si la compra está confirmada, se revertirán los movimientos de inventario.')
                    ->modalSubmitActionLabel('Sí, cancelar')
                    ->action(function (Compra $record) {
                        if ($record->cancelar()) {
                            Notification::make()
                                ->success()
                                ->title('Compra cancelada')
                                ->body('La compra ha sido cancelada exitosamente.')
                                ->send();
                        }
                    })
                    ->visible(fn (Compra $record) => !$record->estaCancelada()),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Compra $record) => $record->esBorrador()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->emptyStateHeading('Sin compras registradas')
            ->emptyStateDescription('Comienza registrando la primera compra a un proveedor.')
            ->emptyStateIcon('heroicon-o-shopping-cart');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompras::route('/'),
            'create' => Pages\CreateCompra::route('/create'),
            'edit' => Pages\EditCompra::route('/{record}/edit'),
            // 'view' key removed due to missing class Pages\ViewCompra
        ];
    }

    /**
     * Calcular totales de la línea
     */
    protected static function calcularTotales(callable $get, callable $set): void
    {
        $cantidad = (float) $get('cantidad_presentacion');
        $precio = (float) $get('precio_unitario_presentacion');
        $descuento = (float) $get('descuento') ?? 0;
        $factor = (float) $get('factor_a_base') ?? 1;

        $subtotal = $cantidad * $precio;
        $total = $subtotal - $descuento;
        $cantidadBase = $cantidad * $factor;

        $set('total_linea', number_format($total, 2, '.', ''));
        $set('cantidad_base', number_format($cantidadBase, 3, '.', ''));
    }

    /**
     * Navegación con badge de borradores
     */
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('estado', 'borrador')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
