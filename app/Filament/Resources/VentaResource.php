<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VentaResource\Pages;
use App\Models\Venta;
use App\Models\Producto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Notifications\Notification;

class VentaResource extends Resource
{
    protected static ?string $model = Venta::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationGroup = 'Ventas';
    protected static ?string $navigationLabel = 'Ventas';
    protected static ?string $modelLabel = 'Venta';
    protected static ?string $pluralModelLabel = 'Ventas';
    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Información General')
                ->schema([
                    Forms\Components\Select::make('cliente_id')
                        ->label('Cliente')
                        ->relationship('cliente', 'nombre')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\Select::make('bodega_id')
                        ->label('Bodega de Origen')
                        ->relationship('bodega', 'nombre')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\DateTimePicker::make('fecha')
                        ->label('Fecha de Venta')
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
                            'liquidada' => 'Liquidada',
                            'cancelada' => 'Cancelada',
                        ])
                        ->default('borrador')
                        ->required()
                        ->disabled(fn ($record) => $record?->estaConfirmada() || $record?->estaLiquidada())
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

            Forms\Components\Section::make('Condiciones de Pago')
                ->schema([
                    Forms\Components\Select::make('tipo_pago')
                        ->label('Tipo de Pago')
                        ->options([
                            'contado' => 'Contado',
                            'credito' => 'Crédito',
                        ])
                        ->default('contado')
                        ->required()
                        ->reactive()
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('plazo_dias')
                        ->label('Plazo (días)')
                        ->numeric()
                        ->minValue(1)
                        ->default(30)
                        ->helperText('Días de crédito')
                        ->visible(fn (callable $get) => $get('tipo_pago') === 'credito')
                        ->required(fn (callable $get) => $get('tipo_pago') === 'credito')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('tasa_interes_mensual')
                        ->label('Tasa Interés Mensual (%)')
                        ->numeric()
                        ->rules(['decimal:0,2'])
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01)
                        ->default(0)
                        ->suffix('%')
                        ->helperText('Tasa de interés mensual')
                        ->visible(fn (callable $get) => $get('tipo_pago') === 'credito')
                        ->columnSpan(1),
                ])
                ->columns(2)
                ->collapsible(),

            Forms\Components\Section::make('Detalles de la Venta')
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
                                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                    $set('unidad_id_presentacion', null);
                                    $set('factor_a_base', null);
                                    $set('precio_unitario_presentacion', null);

                                    // Cargar precio del cliente si existe
                                    $clienteId = $get('../../cliente_id');
                                    if ($clienteId && $state) {
                                        static::cargarPrecioCliente($clienteId, $state, $set);
                                    }
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

                                    // Actualizar precio según presentación
                                    $clienteId = $get('../../cliente_id');
                                    if ($clienteId && $productoId) {
                                        static::cargarPrecioCliente($clienteId, $productoId, $set, $state);
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

                    Forms\Components\Placeholder::make('saldo_pendiente')
                        ->label('Saldo Pendiente')
                        ->content(fn ($record) => $record && $record->esCredito()
                            ? 'L ' . number_format($record->saldo_pendiente, 2)
                            : '—'
                        )
                        ->visible(fn ($record) => $record && $record->esCredito())
                        ->extraAttributes(['class' => 'text-lg font-bold text-warning-600']),
                ])
                ->columns(4)
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

                Tables\Columns\TextColumn::make('cliente.nombre')
                    ->label('Cliente')
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

                Tables\Columns\TextColumn::make('tipo_pago')
                    ->label('Tipo')
                    ->colors([
                        'success' => 'contado',
                        'warning' => 'credito',
                    ])
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->colors([
                        'warning' => 'borrador',
                        'primary' => 'confirmada',
                        'success' => 'liquidada',
                        'danger' => 'cancelada',
                    ])
                    ->icons([
                        'heroicon-o-pencil' => 'borrador',
                        'heroicon-o-check' => 'confirmada',
                        'heroicon-o-check-circle' => 'liquidada',
                        'heroicon-o-x-circle' => 'cancelada',
                    ])
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('saldo_pendiente')
                    ->label('Saldo')
                    ->money('HNL')
                    ->sortable()
                    ->color(fn ($record) => $record->saldo_pendiente > 0 ? 'warning' : 'success')
                    ->visible(fn ($record) => $record && $record->esCredito())
                    ->toggleable(),

                Tables\Columns\IconColumn::make('vencida')
                    ->label('Vencida')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record ? $record->estaVencida() : false)
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->visible(fn ($record) => $record && $record->esCredito())
                    ->toggleable(),

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
                Tables\Filters\SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('tipo_pago')
                    ->label('Tipo de Pago')
                    ->options([
                        'contado' => 'Contado',
                        'credito' => 'Crédito',
                    ])
                    ->native(false),

                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'borrador' => 'Borrador',
                        'confirmada' => 'Confirmada',
                        'liquidada' => 'Liquidada',
                        'cancelada' => 'Cancelada',
                    ])
                    ->native(false),

                Tables\Filters\Filter::make('vencidas')
                    ->label('Solo vencidas')
                    ->query(fn (Builder $query) => $query
                        ->where('tipo_pago', 'credito')
                        ->where('estado', 'confirmada')
                        ->whereNotNull('plazo_dias')
                        ->whereRaw('DATE_ADD(fecha, INTERVAL plazo_dias DAY) < NOW()')
                    )
                    ->toggle(),

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
                    ->modalHeading('Confirmar Venta')
                    ->modalDescription('¿Estás seguro? Se generarán movimientos de inventario y no podrás editar la venta.')
                    ->modalSubmitActionLabel('Sí, confirmar')
                    ->action(function (Venta $record) {
                        try {
                            if ($record->confirmar()) {
                                Notification::make()
                                    ->success()
                                    ->title('Venta confirmada')
                                    ->body('Se generaron los movimientos de inventario exitosamente.')
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
                    ->visible(fn (Venta $record) => $record->esBorrador()),

                Tables\Actions\Action::make('liquidar')
                    ->label('Liquidar')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Liquidar Venta')
                    ->modalDescription('¿Confirmar que el cliente pagó el total de la venta?')
                    ->modalSubmitActionLabel('Sí, liquidar')
                    ->action(function (Venta $record) {
                        if ($record->liquidar()) {
                            Notification::make()
                                ->success()
                                ->title('Venta liquidada')
                                ->body('La venta ha sido marcada como pagada.')
                                ->send();
                        }
                    })
                    ->visible(fn (Venta $record) => $record->estaConfirmada() && $record->esCredito()),

                Tables\Actions\Action::make('registrar_pago')
                    ->label('Registrar Pago')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->form([
                        Forms\Components\TextInput::make('monto')
                            ->label('Monto del Pago')
                            ->numeric()
                            ->rules(['decimal:0,2'])
                            ->required()
                            ->prefix('L')
                            ->minValue(0.01)
                            ->maxValue(fn ($record) => $record->saldo_pendiente)
                            ->helperText(fn ($record) => 'Saldo pendiente: L ' . number_format($record->saldo_pendiente, 2)),
                    ])
                    ->action(function (Venta $record, array $data) {
                        if ($record->registrarPago($data['monto'])) {
                            Notification::make()
                                ->success()
                                ->title('Pago registrado')
                                ->body('El pago ha sido registrado exitosamente.')
                                ->send();
                        }
                    })
                    ->visible(fn (Venta $record) => $record->estaConfirmada() && $record->esCredito() && $record->saldo_pendiente > 0),

                Tables\Actions\ViewAction::make(),

                Tables\Actions\EditAction::make()
                    ->visible(fn (Venta $record) => $record->esBorrador()),

                Tables\Actions\Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancelar Venta')
                    ->modalDescription('¿Estás seguro? Si la venta está confirmada, se revertirán los movimientos de inventario.')
                    ->modalSubmitActionLabel('Sí, cancelar')
                    ->action(function (Venta $record) {
                        if ($record->cancelar()) {
                            Notification::make()
                                ->success()
                                ->title('Venta cancelada')
                                ->body('La venta ha sido cancelada exitosamente.')
                                ->send();
                        }
                    })
                    ->visible(fn (Venta $record) => !$record->estaCancelada()),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Venta $record) => $record->esBorrador()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->emptyStateHeading('Sin ventas registradas')
            ->emptyStateDescription('Comienza registrando la primera venta a un cliente.')
            ->emptyStateIcon('heroicon-o-shopping-bag');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVentas::route('/'),
            'create' => Pages\CreateVenta::route('/create'),
            'edit' => Pages\EditVenta::route('/{record}/edit'),
            // 'view' key removed due to missing class Pages\ViewVenta
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
     * Cargar precio del cliente
     */
    protected static function cargarPrecioCliente(int $clienteId, int $productoId, callable $set, ?int $unidadId = null): void
    {
        $query = \App\Models\ClientePrecio::where('cliente_id', $clienteId)
            ->where('producto_id', $productoId)
            ->whereNull('vigente_hasta');

        if ($unidadId) {
            $query->where('unidad_id', $unidadId);
        }

        $precio = $query->first();

        if ($precio) {
            $set('precio_unitario_presentacion', $precio->precio_venta);
        }
    }

    /**
     * Navegación con badge
     */
    public static function getNavigationBadge(): ?string
    {
        $borradores = static::getModel()::where('estado', 'borrador')->count();
        $vencidas = static::getModel()::where('tipo_pago', 'credito')
            ->where('estado', 'confirmada')
            ->whereNotNull('plazo_dias')
            ->whereRaw('DATE_ADD(fecha, INTERVAL plazo_dias DAY) < NOW()')
            ->count();

        $total = $borradores + $vencidas;
        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $vencidas = static::getModel()::where('tipo_pago', 'credito')
            ->where('estado', 'confirmada')
            ->whereNotNull('plazo_dias')
            ->whereRaw('DATE_ADD(fecha, INTERVAL plazo_dias DAY) < NOW()')
            ->count();

        return $vencidas > 0 ? 'danger' : 'warning';
    }
}
