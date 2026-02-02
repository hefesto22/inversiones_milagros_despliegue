<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VentaResource\Pages;
use App\Filament\Resources\VentaResource\RelationManagers;
use App\Models\Venta;
use App\Models\Producto;
use App\Models\Cliente;
use App\Models\BodegaProducto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VentaResource extends Resource
{
    protected static ?string $model = Venta::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Venta';

    protected static ?string $pluralModelLabel = 'Ventas';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $currentUser = Auth::user();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        $esSuperAdminOJefe = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();

        if ($esSuperAdminOJefe) {
            return $query;
        }

        $bodegasUsuario = DB::table('bodega_user')
            ->where('user_id', $currentUser->id)
            ->where('activo', true)
            ->pluck('bodega_id')
            ->toArray();

        return $query->whereIn('bodega_id', $bodegasUsuario);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // =====================================================
                // INFORMACIÓN GENERAL
                // =====================================================
                Forms\Components\Section::make('Información General')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('cliente_id')
                                    ->label('Cliente')
                                    ->relationship('cliente', 'nombre', fn ($query) => $query->where('estado', true))
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        if ($state) {
                                            $cliente = Cliente::find($state);
                                            if ($cliente) {
                                                if ($cliente->dias_credito > 0) {
                                                    $set('info_credito', "Límite: L " . number_format($cliente->limite_credito, 2) .
                                                        " | Disponible: L " . number_format($cliente->getCreditoDisponible(), 2) .
                                                        " | Deuda: L " . number_format($cliente->saldo_pendiente, 2));
                                                } else {
                                                    $set('info_credito', 'Solo contado');
                                                }
                                            }
                                        }
                                    })
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('nombre')->required(),
                                        Forms\Components\Select::make('tipo')
                                            ->options([
                                                'mayorista' => 'Mayorista',
                                                'minorista' => 'Minorista',
                                                'distribuidor' => 'Distribuidor',
                                                'ruta' => 'Ruta',
                                            ])
                                            ->default('minorista'),
                                        Forms\Components\TextInput::make('telefono'),
                                    ])
                                    ->createOptionUsing(function (array $data): int {
                                        $data['estado'] = true;
                                        $data['created_by'] = Auth::id();
                                        return Cliente::create($data)->id;
                                    }),

                                Forms\Components\Select::make('bodega_id')
                                    ->label('Bodega')
                                    ->options(function () {
                                        $currentUser = Auth::user();

                                        if (!$currentUser) {
                                            return [];
                                        }

                                        $esSuperAdminOJefe = DB::table('model_has_roles')
                                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                                            ->where('model_has_roles.model_type', '=', get_class($currentUser))
                                            ->where('model_has_roles.model_id', '=', $currentUser->id)
                                            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                                            ->exists();

                                        if ($esSuperAdminOJefe) {
                                            return \App\Models\Bodega::where('activo', true)
                                                ->pluck('nombre', 'id')
                                                ->toArray();
                                        }

                                        return DB::table('bodega_user')
                                            ->join('bodegas', 'bodega_user.bodega_id', '=', 'bodegas.id')
                                            ->where('bodega_user.user_id', $currentUser->id)
                                            ->where('bodegas.activo', true)
                                            ->pluck('bodegas.nombre', 'bodegas.id')
                                            ->toArray();
                                    })
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->default(function () {
                                        $currentUser = Auth::user();

                                        if (!$currentUser) {
                                            return null;
                                        }

                                        $esSuperAdminOJefe = DB::table('model_has_roles')
                                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                                            ->where('model_has_roles.model_type', '=', get_class($currentUser))
                                            ->where('model_has_roles.model_id', '=', $currentUser->id)
                                            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                                            ->exists();

                                        if (!$esSuperAdminOJefe) {
                                            return DB::table('bodega_user')
                                                ->join('bodegas', 'bodega_user.bodega_id', '=', 'bodegas.id')
                                                ->where('bodega_user.user_id', $currentUser->id)
                                                ->where('bodegas.activo', true)
                                                ->value('bodegas.id');
                                        }

                                        return \App\Models\Bodega::where('activo', true)->first()?->id;
                                    })
                                    ->disabled(function () {
                                        $currentUser = Auth::user();

                                        if (!$currentUser) {
                                            return true;
                                        }

                                        $esSuperAdminOJefe = DB::table('model_has_roles')
                                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                                            ->where('model_has_roles.model_type', '=', get_class($currentUser))
                                            ->where('model_has_roles.model_id', '=', $currentUser->id)
                                            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                                            ->exists();

                                        return !$esSuperAdminOJefe;
                                    })
                                    ->dehydrated()
                                    ->helperText(function () {
                                        $currentUser = Auth::user();

                                        if (!$currentUser) {
                                            return '';
                                        }

                                        $esSuperAdminOJefe = DB::table('model_has_roles')
                                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                                            ->where('model_has_roles.model_type', '=', get_class($currentUser))
                                            ->where('model_has_roles.model_id', '=', $currentUser->id)
                                            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                                            ->exists();

                                        if ($esSuperAdminOJefe) {
                                            return 'Puedes seleccionar cualquier bodega';
                                        }

                                        return 'Bodega asignada a tu usuario';
                                    }),

                                Forms\Components\Select::make('tipo_pago')
                                    ->label('Tipo de Pago')
                                    ->required()
                                    ->options(function (Forms\Get $get) {
                                        $clienteId = $get('cliente_id');
                                        $opciones = [
                                            'efectivo' => 'Efectivo',
                                            'transferencia' => 'Transferencia',
                                            'tarjeta' => 'Tarjeta',
                                        ];

                                        if ($clienteId) {
                                            $cliente = Cliente::find($clienteId);
                                            if ($cliente && $cliente->dias_credito > 0) {
                                                $opciones['credito'] = 'Crédito';
                                            }
                                        }

                                        return $opciones;
                                    })
                                    ->default('efectivo')
                                    ->live()
                                    ->native(false),

                                Forms\Components\Placeholder::make('info_credito')
                                    ->label('Estado de Crédito')
                                    ->content(fn ($get) => $get('info_credito') ?? 'Selecciona un cliente')
                                    ->visible(fn (Forms\Get $get) => $get('tipo_pago') === 'credito'),

                                Forms\Components\TextInput::make('numero_venta')
                                    ->label('No. Venta')
                                    ->disabled()
                                    ->dehydrated()
                                    ->placeholder('Se genera al completar')
                                    ->visible(fn ($livewire) => $livewire instanceof Pages\EditVenta),

                                Forms\Components\Select::make('estado')
                                    ->options([
                                        'borrador' => 'Borrador',
                                        'completada' => 'Completada',
                                        'pendiente_pago' => 'Pendiente de Pago',
                                        'pagada' => 'Pagada',
                                        'cancelada' => 'Cancelada',
                                    ])
                                    ->default('borrador')
                                    ->disabled()
                                    ->dehydrated()
                                    ->visible(fn ($livewire) => $livewire instanceof Pages\EditVenta),
                            ]),

                        Forms\Components\Textarea::make('nota')
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Observaciones o notas adicionales'),
                    ]),

                // =====================================================
                // DETALLES DE VENTA
                // =====================================================
                Forms\Components\Section::make('Productos')
                    ->icon('heroicon-o-cube')
                    ->schema([
                        Forms\Components\Repeater::make('detalles')
                            ->relationship('detalles')
                            ->schema([
                                Forms\Components\Select::make('producto_id')
                                    ->label('Producto')
                                    ->options(fn (Forms\Get $get) =>
                                        Producto::where('activo', true)->pluck('nombre', 'id')
                                    )
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        if (!$state) return;

                                        $producto = Producto::find($state);
                                        $bodegaId = $get('../../bodega_id');
                                        $clienteId = $get('../../cliente_id');

                                        if ($producto) {
                                            $set('unidad_id', $producto->unidad_id);
                                            $set('aplica_isv', $producto->aplica_isv ?? false);

                                            // Obtener precio y costo de bodega_producto
                                            if ($bodegaId) {
                                                $bp = BodegaProducto::where('bodega_id', $bodegaId)
                                                    ->where('producto_id', $state)
                                                    ->first();

                                                if ($bp) {
                                                    // El precio_venta_sugerido YA incluye ISV
                                                    $precioConIsv = $bp->precio_venta_sugerido ?? 0;
                                                    $set('precio_con_isv', $precioConIsv);
                                                    
                                                    // Calcular precio sin ISV para mostrar
                                                    if ($producto->aplica_isv && $precioConIsv > 0) {
                                                        $precioSinIsv = round($precioConIsv / 1.15, 2);
                                                    } else {
                                                        $precioSinIsv = $precioConIsv;
                                                    }
                                                    $set('precio_unitario', number_format($precioSinIsv, 2, '.', ''));
                                                    
                                                    $set('costo_unitario', $bp->costo_promedio_actual ?? 0);
                                                    $set('stock_disponible', $bp->stock ?? 0);
                                                }
                                            }

                                            // Buscar último precio del cliente
                                            if ($clienteId) {
                                                $cliente = Cliente::find($clienteId);
                                                $ultimoPrecio = $cliente?->getUltimoPrecio($state);

                                                if ($ultimoPrecio && $ultimoPrecio['precio_sin_isv']) {
                                                    $set('precio_anterior', $ultimoPrecio['precio_sin_isv']);
                                                    $set('info_precio_anterior', 'Última venta: L ' . number_format($ultimoPrecio['precio_sin_isv'], 2));
                                                } else {
                                                    $set('precio_anterior', null);
                                                    $set('info_precio_anterior', 'Primera venta');
                                                }
                                            } else {
                                                $set('precio_anterior', null);
                                                $set('info_precio_anterior', 'Selecciona cliente');
                                            }

                                            // Calcular línea
                                            self::calcularLineaDetalle($get, $set);
                                        }
                                    })
                                    ->columnSpan(2),

                                Forms\Components\Hidden::make('unidad_id'),
                                Forms\Components\Hidden::make('costo_unitario')->default(0),
                                Forms\Components\Hidden::make('aplica_isv')->default(false),
                                Forms\Components\Hidden::make('precio_con_isv')->default(0),

                                Forms\Components\TextInput::make('cantidad')
                                    ->label('Cantidad')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->step(0.01)
                                    ->default(1)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                        $stockDisponible = floatval($get('stock_disponible') ?? 0);
                                        $cantidad = floatval($state ?? 0);

                                        // Si la cantidad excede el stock, ajustar al máximo disponible
                                        if ($stockDisponible > 0 && $cantidad > $stockDisponible) {
                                            $set('cantidad', $stockDisponible);

                                            \Filament\Notifications\Notification::make()
                                                ->title('Cantidad ajustada')
                                                ->body("Stock máximo disponible: {$stockDisponible}")
                                                ->warning()
                                                ->duration(3000)
                                                ->send();
                                        }

                                        self::calcularLineaDetalle($get, $set);
                                    })
                                    ->suffix(fn (Forms\Get $get) =>
                                        $get('stock_disponible') ? 'Stock: ' . intval($get('stock_disponible')) : ''
                                    ),

                                Forms\Components\TextInput::make('precio_unitario')
                                    ->label('Precio')
                                    ->required()
                                    ->numeric()
                                    ->prefix('L')
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) =>
                                        self::calcularLineaDetalle($get, $set)
                                    )
                                    ->helperText(fn (Forms\Get $get) => $get('info_precio_anterior') ?? ''),

                                Forms\Components\Hidden::make('precio_anterior'),
                                Forms\Components\Hidden::make('stock_disponible'),
                                Forms\Components\Hidden::make('info_precio_anterior'),

                                Forms\Components\Placeholder::make('isv_display')
                                    ->label('ISV')
                                    ->content(fn (Forms\Get $get) =>
                                        'L ' . number_format(($get('total_isv') ?? 0), 2)
                                    ),

                                Forms\Components\Hidden::make('isv_unitario')->default(0),
                                Forms\Components\Hidden::make('subtotal')->default(0),
                                Forms\Components\Hidden::make('total_isv')->default(0),
                                Forms\Components\Hidden::make('total_linea')->default(0),

                                Forms\Components\Placeholder::make('total_display')
                                    ->label('Total')
                                    ->content(fn (Forms\Get $get) =>
                                        'L ' . number_format($get('total_linea') ?? 0, 2)
                                    )
                                    ->extraAttributes(['class' => 'font-bold']),
                            ])
                            ->columns(6)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->collapsible()
                            ->cloneable()
                            ->itemLabel(fn (array $state): ?string =>
                                isset($state['producto_id'])
                                    ? Producto::find($state['producto_id'])?->nombre
                                    : 'Nuevo producto'
                            )
                            ->addActionLabel('Agregar producto')
                            ->live()
                            ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) =>
                                self::calcularTotales($get, $set)
                            )
                            ->deleteAction(
                                fn (Forms\Components\Actions\Action $action) => $action
                                    ->after(fn (Forms\Get $get, Forms\Set $set) =>
                                        self::calcularTotales($get, $set)
                                    )
                            ),
                    ]),

                // =====================================================
                // TOTALES
                // =====================================================
                Forms\Components\Section::make('Totales')
                    ->icon('heroicon-o-calculator')
                    ->schema([
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\TextInput::make('subtotal')
                                    ->label('Subtotal')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled()
                                    ->dehydrated()
                                    ->default(0),

                                Forms\Components\TextInput::make('total_isv')
                                    ->label('ISV (15%)')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled()
                                    ->dehydrated()
                                    ->default(0),

                                Forms\Components\TextInput::make('descuento')
                                    ->label('Descuento')
                                    ->numeric()
                                    ->prefix('L')
                                    ->default(0)
                                    ->minValue(0)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) =>
                                        self::calcularTotales($get, $set)
                                    ),

                                Forms\Components\TextInput::make('total')
                                    ->label('TOTAL')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled()
                                    ->dehydrated()
                                    ->default(0)
                                    ->extraAttributes(['class' => 'text-xl font-bold text-green-600']),
                            ]),
                    ]),
            ]);
    }

    /**
     * Calcular valores de una línea de detalle
     * IMPORTANTE: El precio_unitario es SIN ISV, el ISV se calcula aparte
     */
    protected static function calcularLineaDetalle(Forms\Get $get, Forms\Set $set): void
    {
        $cantidad = floatval($get('cantidad') ?? 0);
        $precioUnitario = floatval($get('precio_unitario') ?? 0); // Precio SIN ISV
        $aplicaIsv = $get('aplica_isv') ?? false;

        // Subtotal sin ISV
        $subtotal = $cantidad * $precioUnitario;

        // Calcular ISV solo si aplica
        $isvUnitario = $aplicaIsv ? round($precioUnitario * 0.15, 2) : 0;
        $totalIsv = $cantidad * $isvUnitario;
        $precioConIsv = $precioUnitario + $isvUnitario;

        // Total línea = subtotal + ISV
        $totalLinea = $subtotal + $totalIsv;

        $set('subtotal', round($subtotal, 2));
        $set('isv_unitario', round($isvUnitario, 2));
        $set('precio_con_isv', round($precioConIsv, 2));
        $set('total_isv', round($totalIsv, 2));
        $set('total_linea', round($totalLinea, 2));
    }

    /**
     * Calcular totales de la venta
     */
    protected static function calcularTotales(Forms\Get $get, Forms\Set $set): void
    {
        $detalles = $get('detalles') ?? [];

        $subtotal = 0;
        $totalIsv = 0;

        foreach ($detalles as $detalle) {
            $subtotal += floatval($detalle['subtotal'] ?? 0);
            $totalIsv += floatval($detalle['total_isv'] ?? 0);
        }

        $descuento = floatval($get('descuento') ?? 0);
        $total = $subtotal + $totalIsv - $descuento;

        $set('subtotal', round($subtotal, 2));
        $set('total_isv', round($totalIsv, 2));
        $set('total', round(max(0, $total), 2));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero_venta')
                    ->label('No. Venta')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->placeholder('Sin número'),

                Tables\Columns\TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->limit(25)
                    ->description(fn (Venta $record): string =>
                        $record->cliente?->telefono ?? ''
                    ),

                Tables\Columns\TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->badge()
                    ->color('info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn (Venta $record): string =>
                        $record->created_at->diffForHumans()
                    ),

                Tables\Columns\TextColumn::make('tipo_pago')
                    ->label('Pago')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'efectivo' => 'Efectivo',
                        'transferencia' => 'Transfer.',
                        'tarjeta' => 'Tarjeta',
                        'credito' => 'Crédito',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'efectivo' => 'success',
                        'transferencia' => 'info',
                        'tarjeta' => 'primary',
                        'credito' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total_isv')
                    ->label('ISV')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->weight(fn ($state) => $state > 0 ? 'bold' : 'normal')
                    ->description(function (Venta $record): ?string {
                        if ($record->saldo_pendiente <= 0) return null;
                        if ($record->estaVencida()) return 'Vencida';
                        return null;
                    }),

                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'borrador' => 'Borrador',
                        'completada' => 'Completada',
                        'pendiente_pago' => 'Pend. Pago',
                        'pagada' => 'Pagada',
                        'cancelada' => 'Cancelada',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'borrador' => 'gray',
                        'completada' => 'success',
                        'pendiente_pago' => 'warning',
                        'pagada' => 'success',
                        'cancelada' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('estado_pago')
                    ->label('Pago')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pendiente' => 'Pendiente',
                        'parcial' => 'Parcial',
                        'pagado' => 'Pagado',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'pendiente' => 'danger',
                        'parcial' => 'warning',
                        'pagado' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('creador.name')
                    ->label('Vendedor')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options([
                        'borrador' => 'Borrador',
                        'completada' => 'Completada',
                        'pendiente_pago' => 'Pendiente de Pago',
                        'pagada' => 'Pagada',
                        'cancelada' => 'Cancelada',
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('estado_pago')
                    ->label('Estado de Pago')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'parcial' => 'Parcial',
                        'pagado' => 'Pagado',
                    ]),

                Tables\Filters\SelectFilter::make('tipo_pago')
                    ->label('Tipo de Pago')
                    ->options([
                        'efectivo' => 'Efectivo',
                        'transferencia' => 'Transferencia',
                        'tarjeta' => 'Tarjeta',
                        'credito' => 'Crédito',
                    ]),

                Tables\Filters\SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre'),

                Tables\Filters\SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('con_saldo')
                    ->label('Con saldo pendiente')
                    ->query(fn (Builder $query) => $query->where('saldo_pendiente', '>', 0))
                    ->toggle(),

                Tables\Filters\Filter::make('vencidas')
                    ->label('Vencidas')
                    ->query(fn (Builder $query) => $query->vencidas())
                    ->toggle(),

                Tables\Filters\Filter::make('hoy')
                    ->label('Hoy')
                    ->query(fn (Builder $query) => $query->whereDate('created_at', today()))
                    ->toggle(),

                Tables\Filters\Filter::make('esta_semana')
                    ->label('Esta semana')
                    ->query(fn (Builder $query) => $query->whereBetween('created_at', [
                        now()->startOfWeek(),
                        now()->endOfWeek()
                    ])),

                Tables\Filters\Filter::make('este_mes')
                    ->label('Este mes')
                    ->query(fn (Builder $query) => $query->delMes()),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),

                    Tables\Actions\EditAction::make()
                        ->visible(fn (Venta $record) => $record->estado === 'borrador'),

                    Tables\Actions\Action::make('completar')
                        ->label('Completar Venta')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Completar Venta')
                        ->modalDescription('¿Confirmar esta venta? Se descontará el stock y no podrá modificarse.')
                        ->visible(fn (Venta $record) => $record->estado === 'borrador')
                        ->action(function (Venta $record) {
                            try {
                                $record->completar();
                                \Filament\Notifications\Notification::make()
                                    ->title('Venta completada')
                                    ->body('No. ' . $record->numero_venta)
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Error al completar')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),

                    Tables\Actions\Action::make('registrar_pago')
                        ->label('Registrar Pago')
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->visible(fn (Venta $record) => $record->saldo_pendiente > 0)
                        ->form([
                            Forms\Components\Placeholder::make('info_saldo')
                                ->label('Saldo Pendiente')
                                ->content(fn (Venta $record) => 'L ' . number_format($record->saldo_pendiente, 2)),

                            Forms\Components\TextInput::make('monto')
                                ->label('Monto a Pagar')
                                ->required()
                                ->numeric()
                                ->prefix('L')
                                ->minValue(0.01)
                                ->default(fn (Venta $record) => $record->saldo_pendiente)
                                ->maxValue(fn (Venta $record) => $record->saldo_pendiente),

                            Forms\Components\Select::make('metodo_pago')
                                ->label('Método de Pago')
                                ->options([
                                    'efectivo' => 'Efectivo',
                                    'transferencia' => 'Transferencia',
                                    'tarjeta' => 'Tarjeta',
                                    'cheque' => 'Cheque',
                                ])
                                ->default('efectivo')
                                ->required(),

                            Forms\Components\TextInput::make('referencia')
                                ->label('Referencia')
                                ->placeholder('No. transferencia, cheque, etc.')
                                ->maxLength(100),

                            Forms\Components\Textarea::make('nota')
                                ->label('Nota')
                                ->rows(2),
                        ])
                        ->action(function (Venta $record, array $data) {
                            $record->registrarPago(
                                $data['monto'],
                                $data['metodo_pago'],
                                $data['referencia'] ?? null,
                                $data['nota'] ?? null
                            );

                            \Filament\Notifications\Notification::make()
                                ->title('Pago registrado')
                                ->body('L ' . number_format($data['monto'], 2))
                                ->success()
                                ->send();
                        }),

                    Tables\Actions\Action::make('cancelar')
                        ->label('Cancelar Venta')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Cancelar Venta')
                        ->visible(fn (Venta $record) => in_array($record->estado, ['borrador', 'completada', 'pendiente_pago']))
                        ->form([
                            Forms\Components\Textarea::make('motivo')
                                ->label('Motivo de cancelación')
                                ->required()
                                ->rows(2),
                        ])
                        ->action(function (Venta $record, array $data) {
                            $record->cancelar($data['motivo']);
                            \Filament\Notifications\Notification::make()
                                ->title('Venta cancelada')
                                ->warning()
                                ->send();
                        }),

                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (Venta $record) => $record->estado === 'borrador'),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            foreach ($records as $record) {
                                if ($record->estado !== 'borrador') {
                                    throw new \Exception("Solo se pueden eliminar ventas en borrador.");
                                }
                            }
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->persistFiltersInSession();
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Información de la Venta')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('numero_venta')
                                    ->label('No. Venta')
                                    ->weight('bold')
                                    ->copyable(),

                                Infolists\Components\TextEntry::make('cliente.nombre')
                                    ->label('Cliente'),

                                Infolists\Components\TextEntry::make('bodega.nombre')
                                    ->label('Bodega')
                                    ->badge(),

                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Fecha')
                                    ->dateTime('d/m/Y H:i'),

                                Infolists\Components\TextEntry::make('tipo_pago')
                                    ->label('Tipo de Pago')
                                    ->badge()
                                    ->formatStateUsing(fn ($state) => match ($state) {
                                        'efectivo' => 'Efectivo',
                                        'transferencia' => 'Transferencia',
                                        'tarjeta' => 'Tarjeta',
                                        'credito' => 'Crédito',
                                        default => $state,
                                    })
                                    ->color(fn ($state) => match ($state) {
                                        'efectivo' => 'success',
                                        'transferencia' => 'info',
                                        'tarjeta' => 'primary',
                                        'credito' => 'warning',
                                        default => 'gray',
                                    }),

                                Infolists\Components\TextEntry::make('estado')
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        'borrador' => 'gray',
                                        'completada' => 'success',
                                        'pendiente_pago' => 'warning',
                                        'pagada' => 'success',
                                        'cancelada' => 'danger',
                                        default => 'gray',
                                    }),

                                Infolists\Components\TextEntry::make('estado_pago')
                                    ->label('Estado Pago')
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        'pendiente' => 'danger',
                                        'parcial' => 'warning',
                                        'pagado' => 'success',
                                        default => 'gray',
                                    }),

                                Infolists\Components\TextEntry::make('fecha_vencimiento')
                                    ->label('Vencimiento')
                                    ->date('d/m/Y')
                                    ->placeholder('N/A'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Totales')
                    ->icon('heroicon-o-calculator')
                    ->schema([
                        Infolists\Components\Grid::make(5)
                            ->schema([
                                Infolists\Components\TextEntry::make('subtotal')
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('total_isv')
                                    ->label('ISV')
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('descuento')
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('total')
                                    ->money('HNL')
                                    ->weight('bold')
                                    ->size('lg'),

                                Infolists\Components\TextEntry::make('saldo_pendiente')
                                    ->label('Saldo')
                                    ->money('HNL')
                                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                                    ->weight('bold'),
                            ]),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DetallesRelationManager::class,
            RelationManagers\PagosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVentas::route('/'),
            'create' => Pages\CreateVenta::route('/create'),
            'view' => Pages\ViewVenta::route('/{record}'),
            'edit' => Pages\EditVenta::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('estado', 'borrador')->count();
        return $count > 0 ? $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['numero_venta', 'cliente.nombre'];
    }
}