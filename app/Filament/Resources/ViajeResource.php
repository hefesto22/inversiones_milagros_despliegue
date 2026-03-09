<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ViajeResource\Pages;
use App\Filament\Resources\ViajeResource\RelationManagers;
use App\Models\Viaje;
use App\Models\User;
use App\Models\Camion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ViajeResource extends Resource
{
    protected static ?string $model = Viaje::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Logística';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Viaje';

    protected static ?string $pluralModelLabel = 'Viajes';

    /**
     * Verificar si el usuario actual es Super Admin o Jefe
     */
    protected static function esSuperAdminOJefe(): bool
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return false;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();
    }

    /**
     * Obtener la bodega del usuario actual (directa o desde bodega_user)
     */
    protected static function getBodegaUsuario(): ?int
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return null;
        }

        if ($currentUser->bodega_id) {
            return $currentUser->bodega_id;
        }

        $bodegaAsignada = DB::table('bodega_user')
            ->where('user_id', $currentUser->id)
            ->where('activo', true)
            ->value('bodega_id');

        return $bodegaAsignada;
    }

    /**
     * Obtener IDs de choferes ocupados (asignados a viajes activos)
     */
    protected static function getChoferesOcupados(?int $viajeActualId = null): array
    {
        $query = Viaje::whereNotIn('estado', [
            Viaje::ESTADO_CERRADO,
            Viaje::ESTADO_CANCELADO
        ])->whereNotNull('chofer_id');

        if ($viajeActualId) {
            $query->where('id', '!=', $viajeActualId);
        }

        return $query->pluck('chofer_id')->toArray();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['camion', 'chofer', 'bodegaOrigen']);
        $currentUser = Auth::user();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        if (static::esSuperAdminOJefe()) {
            return $query;
        }

        $bodegaId = static::getBodegaUsuario();

        if ($bodegaId) {
            return $query->where('bodega_origen_id', $bodegaId);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        $puedeSeleccionarBodega = static::esSuperAdminOJefe();
        $bodegaUsuario = static::getBodegaUsuario();

        return $form
            ->schema([
                Forms\Components\Section::make('Información del Viaje')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('camion_id')
                                    ->label('Camión')
                                    ->options(function () use ($bodegaUsuario, $puedeSeleccionarBodega) {
                                        $query = Camion::where('activo', true)
                                            ->whereDoesntHave('viajes', function ($q) {
                                                $q->whereNotIn('estado', [
                                                    Viaje::ESTADO_CERRADO,
                                                    Viaje::ESTADO_CANCELADO
                                                ]);
                                            });

                                        if (!$puedeSeleccionarBodega && $bodegaUsuario) {
                                            $query->where('bodega_id', $bodegaUsuario);
                                        }

                                        return $query->pluck('placa', 'id');
                                    })
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set) {
                                        if ($state) {
                                            $camion = Camion::find($state);
                                            $chofer = $camion?->getChoferActual();
                                            if ($chofer) {
                                                $choferesOcupados = static::getChoferesOcupados();
                                                if (!in_array($chofer->id, $choferesOcupados)) {
                                                    $set('chofer_id', $chofer->id);
                                                }
                                            }
                                        }
                                    })
                                    ->helperText('Solo camiones disponibles'),

                                Forms\Components\Select::make('chofer_id')
                                    ->label('Chofer')
                                    ->options(function (Forms\Get $get, $record) {
                                        $viajeActualId = $record?->id;
                                        $choferesOcupados = static::getChoferesOcupados($viajeActualId);

                                        return User::whereHas('roles', function ($q) {
                                            $q->where('name', 'Chofer');
                                        })
                                            ->whereNotIn('id', $choferesOcupados)
                                            ->pluck('name', 'id');
                                    })
                                    ->required()
                                    ->searchable()
                                    ->helperText('Solo choferes disponibles'),

                                Forms\Components\Select::make('bodega_origen_id')
                                    ->label('Bodega Origen')
                                    ->relationship('bodegaOrigen', 'nombre', fn($query) => $query->where('activo', true))
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->afterStateHydrated(function (Set $set, $state, $record) use ($bodegaUsuario) {
                                        if (is_null($state) && is_null($record) && $bodegaUsuario) {
                                            $set('bodega_origen_id', $bodegaUsuario);
                                        }
                                    })
                                    ->disabled(!$puedeSeleccionarBodega)
                                    ->dehydrated(true),

                                Forms\Components\DateTimePicker::make('fecha_salida')
                                    ->label('Fecha de Salida')
                                    ->native(false)
                                    ->seconds(false),

                                Forms\Components\DateTimePicker::make('fecha_regreso')
                                    ->label('Fecha de Regreso')
                                    ->native(false)
                                    ->seconds(false)
                                    ->visible(fn($record) => $record && in_array($record->estado, [
                                        Viaje::ESTADO_REGRESANDO,
                                        Viaje::ESTADO_DESCARGANDO,
                                        Viaje::ESTADO_LIQUIDANDO,
                                        Viaje::ESTADO_CERRADO
                                    ])),

                                Forms\Components\Select::make('estado')
                                    ->options([
                                        Viaje::ESTADO_PLANIFICADO => 'Planificado',
                                        Viaje::ESTADO_CARGANDO => 'Cargando',
                                        Viaje::ESTADO_EN_RUTA => 'En Ruta',
                                        Viaje::ESTADO_RECARGANDO => 'Recargando',
                                        Viaje::ESTADO_REGRESANDO => 'Regresando',
                                        Viaje::ESTADO_DESCARGANDO => 'Descargando',
                                        Viaje::ESTADO_LIQUIDANDO => 'Liquidando',
                                        Viaje::ESTADO_CERRADO => 'Cerrado',
                                        Viaje::ESTADO_CANCELADO => 'Cancelado',
                                    ])
                                    ->default(Viaje::ESTADO_PLANIFICADO)
                                    ->disabled()
                                    ->dehydrated()
                                    ->native(false),

                                Forms\Components\TextInput::make('numero_viaje')
                                    ->label('No. Viaje')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->placeholder('Se genera automáticamente'),
                            ]),

                        Forms\Components\Textarea::make('observaciones')
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Observaciones del viaje'),
                    ]),

                Forms\Components\Section::make('Kilometraje')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('km_salida')
                                    ->label('Km Salida')
                                    ->numeric()
                                    ->minValue(0),

                                Forms\Components\TextInput::make('km_regreso')
                                    ->label('Km Regreso')
                                    ->numeric()
                                    ->minValue(0)
                                    ->visible(fn($record) => $record && $record->estado !== Viaje::ESTADO_PLANIFICADO),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Forms\Components\Section::make('Efectivo')
                    ->schema([
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\TextInput::make('efectivo_inicial')
                                    ->label('Efectivo Inicial')
                                    ->numeric()
                                    ->prefix('L')
                                    ->default(0)
                                    ->helperText('Para cambio'),

                                Forms\Components\TextInput::make('efectivo_esperado')
                                    ->label('Efectivo Esperado')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\TextInput::make('efectivo_entregado')
                                    ->label('Efectivo Entregado')
                                    ->numeric()
                                    ->prefix('L')
                                    ->visible(fn($record) => $record && in_array($record->estado, [
                                        Viaje::ESTADO_LIQUIDANDO,
                                        Viaje::ESTADO_CERRADO
                                    ])),

                                Forms\Components\TextInput::make('diferencia_efectivo')
                                    ->label('Diferencia')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled()
                                    ->dehydrated(false),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn($record) => $record !== null),

                Forms\Components\Section::make('Totales del Viaje')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('total_cargado_costo')
                                    ->label('Costo Cargado')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled(),

                                Forms\Components\TextInput::make('total_vendido')
                                    ->label('Total Vendido')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled(),

                                Forms\Components\TextInput::make('total_merma_costo')
                                    ->label('Costo Mermas')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled(),

                                Forms\Components\TextInput::make('comision_ganada')
                                    ->label('Comisión Ganada')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled(),

                                Forms\Components\TextInput::make('cobros_devoluciones')
                                    ->label('Cobros')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled(),

                                Forms\Components\TextInput::make('neto_chofer')
                                    ->label('Neto Chofer')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled(),
                            ]),
                    ])
                    ->visible(fn($record) => $record && $record->estado === Viaje::ESTADO_CERRADO)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero_viaje')
                    ->label('No. Viaje')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('camion.placa')
                    ->label('Camión')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('chofer.name')
                    ->label('Chofer')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('bodegaOrigen.nombre')
                    ->label('Bodega')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('fecha_salida')
                    ->label('Salida')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('Sin iniciar'),

                Tables\Columns\TextColumn::make('fecha_regreso')
                    ->label('Regreso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('En curso')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(fn($state) => match ($state) {
                        Viaje::ESTADO_PLANIFICADO => 'Planificado',
                        Viaje::ESTADO_CARGANDO => 'Cargando',
                        Viaje::ESTADO_EN_RUTA => 'En Ruta',
                        Viaje::ESTADO_RECARGANDO => 'Recargando',   // ← AGREGAR
                        Viaje::ESTADO_REGRESANDO => 'Regresando',
                        Viaje::ESTADO_DESCARGANDO => 'Descargando',
                        Viaje::ESTADO_LIQUIDANDO => 'Liquidando',
                        Viaje::ESTADO_CERRADO => 'Cerrado',
                        Viaje::ESTADO_CANCELADO => 'Cancelado',
                        default => $state,
                    })
                    ->color(fn($state) => match ($state) {
                        Viaje::ESTADO_PLANIFICADO => 'gray',
                        Viaje::ESTADO_CARGANDO => 'info',
                        Viaje::ESTADO_EN_RUTA => 'warning',
                        Viaje::ESTADO_RECARGANDO => 'warning',       // ← AGREGAR
                        Viaje::ESTADO_REGRESANDO => 'primary',
                        Viaje::ESTADO_DESCARGANDO => 'info',
                        Viaje::ESTADO_LIQUIDANDO => 'warning',
                        Viaje::ESTADO_CERRADO => 'success',
                        Viaje::ESTADO_CANCELADO => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn($state) => match ($state) {
                        Viaje::ESTADO_PLANIFICADO => 'heroicon-o-clipboard-document-list',
                        Viaje::ESTADO_CARGANDO => 'heroicon-o-archive-box-arrow-down',
                        Viaje::ESTADO_EN_RUTA => 'heroicon-o-truck',
                        Viaje::ESTADO_RECARGANDO => 'heroicon-o-arrow-path',  // ← AGREGAR
                        Viaje::ESTADO_REGRESANDO => 'heroicon-o-arrow-uturn-left',
                        Viaje::ESTADO_DESCARGANDO => 'heroicon-o-archive-box-x-mark',
                        Viaje::ESTADO_LIQUIDANDO => 'heroicon-o-calculator',
                        Viaje::ESTADO_CERRADO => 'heroicon-o-check-circle',
                        Viaje::ESTADO_CANCELADO => 'heroicon-o-x-circle',
                        default => null,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_vendido')
                    ->label('Vendido')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cargas_count')
                    ->label('Items')
                    ->counts('cargas')
                    ->badge()
                    ->color('primary')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ventas_count')
                    ->label('Ventas')
                    ->counts('ventas')
                    ->badge()
                    ->color('success')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options([
                        Viaje::ESTADO_PLANIFICADO => 'Planificado',
                        Viaje::ESTADO_CARGANDO => 'Cargando',
                        Viaje::ESTADO_EN_RUTA => 'En Ruta',
                        Viaje::ESTADO_RECARGANDO => 'Recargando',  // ← AGREGAR
                        Viaje::ESTADO_REGRESANDO => 'Regresando',
                        Viaje::ESTADO_DESCARGANDO => 'Descargando',
                        Viaje::ESTADO_LIQUIDANDO => 'Liquidando',
                        Viaje::ESTADO_CERRADO => 'Cerrado',
                        Viaje::ESTADO_CANCELADO => 'Cancelado',
                    ]),

                Tables\Filters\SelectFilter::make('bodega_origen_id')
                    ->label('Bodega')
                    ->relationship('bodegaOrigen', 'nombre')
                    ->searchable()
                    ->preload()
                    ->visible(fn() => static::esSuperAdminOJefe()),

                Tables\Filters\SelectFilter::make('chofer_id')
                    ->label('Chofer')
                    ->relationship('chofer', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('camion_id')
                    ->label('Camión')
                    ->relationship('camion', 'placa')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('fecha_salida')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->native(false),
                        Forms\Components\DatePicker::make('hasta')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'], fn($q, $date) => $q->whereDate('fecha_salida', '>=', $date))
                            ->when($data['hasta'], fn($q, $date) => $q->whereDate('fecha_salida', '<=', $date));
                    }),
            ])

            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn($record) => !in_array($record->estado, [Viaje::ESTADO_CERRADO, Viaje::ESTADO_CANCELADO])),

                // Acción: Iniciar Carga
                Tables\Actions\Action::make('iniciar_carga')
                    ->label('Iniciar Carga')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('El viaje pasará a estado "Cargando".')
                    ->visible(fn($record) => $record->estado === Viaje::ESTADO_PLANIFICADO)
                    ->action(function ($record) {
                        $record->iniciarCarga();
                        \Filament\Notifications\Notification::make()
                            ->title('Carga iniciada')
                            ->body('Ahora puede agregar productos al viaje.')
                            ->success()
                            ->send();
                    }),

                // Acción: Iniciar Ruta
                Tables\Actions\Action::make('iniciar_ruta')
                    ->label('Iniciar Ruta')
                    ->icon('heroicon-o-truck')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('El viaje saldrá a ruta. Asegúrese de haber cargado todos los productos.')
                    ->visible(fn($record) => in_array($record->estado, [Viaje::ESTADO_PLANIFICADO, Viaje::ESTADO_CARGANDO]))
                    ->action(function ($record) {
                        if ($record->cargas()->count() === 0) {
                            \Filament\Notifications\Notification::make()
                                ->title('Error')
                                ->body('No puede iniciar ruta sin cargar productos.')
                                ->danger()
                                ->send();
                            return;
                        }
                        $record->iniciarRuta();
                        \Filament\Notifications\Notification::make()
                            ->title('Viaje en ruta')
                            ->body('El camión está ahora en ruta.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('recargar')
                    ->label('Recargar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Recargar Camión')
                    ->modalDescription('El viaje pasará a estado "Recargando". Podrá agregar más productos y luego continuar la ruta.')
                    ->visible(fn($record) => $record->estado === Viaje::ESTADO_EN_RUTA)
                    ->action(function ($record) {
                        $record->iniciarRecarga();
                        \Filament\Notifications\Notification::make()
                            ->title('Listo para recargar')
                            ->body('Agregue los productos desde la pestaña "Productos Cargados" y luego presione "Continuar Ruta".')
                            ->info()
                            ->duration(8000)
                            ->send();
                    }),

                // Acción: Continuar Ruta (desde recargando)
                Tables\Actions\Action::make('continuar_ruta')
                    ->label('Continuar Ruta')
                    ->icon('heroicon-o-truck')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Continuar Ruta')
                    ->modalDescription('El viaje volverá a estado "En Ruta" con los nuevos productos cargados.')
                    ->visible(fn($record) => $record->estado === Viaje::ESTADO_RECARGANDO)
                    ->action(function ($record) {
                        $record->recalcularTotales();
                        $record->iniciarRuta();
                        \Filament\Notifications\Notification::make()
                            ->title('Viaje en ruta')
                            ->body('El camión continúa en ruta con los productos recargados.')
                            ->success()
                            ->send();
                    }),
                // Acción: Iniciar Regreso
                Tables\Actions\Action::make('iniciar_regreso')
                    ->label('Regresar')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('El viaje cambiará a estado "Regresando".')
                    ->visible(fn($record) => in_array($record->estado, [Viaje::ESTADO_EN_RUTA, Viaje::ESTADO_RECARGANDO]))
                    ->action(function ($record) {
                        $record->iniciarRegreso();
                        \Filament\Notifications\Notification::make()
                            ->title('Viaje regresando')
                            ->success()
                            ->send();
                    }),

                // Acción: Descargar
                Tables\Actions\Action::make('iniciar_descarga')
                    ->label('Descargar')
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('info')
                    ->modalHeading('Descarga de Productos No Vendidos')
                    ->modalWidth('4xl')
                    ->modalSubmitActionLabel('Confirmar Descarga')
                    ->visible(fn($record) => $record->estado === Viaje::ESTADO_REGRESANDO)
                    ->form(function ($record) {
                        $productosRegresan = [];
                        $totalCosto = 0;

                        foreach ($record->cargas as $carga) {
                            $disponible = $carga->getCantidadDisponible();
                            if ($disponible > 0) {
                                $subtotal = $disponible * $carga->costo_unitario;
                                $productosRegresan[] = [
                                    'carga_id' => $carga->id,
                                    'producto_id' => $carga->producto_id,
                                    'producto_nombre' => $carga->producto->nombre,
                                    'cantidad' => $disponible,
                                    'unidad_id' => $carga->unidad_id,
                                    'unidad_nombre' => $carga->unidad->nombre ?? 'N/A',
                                    'costo_unitario' => $carga->costo_unitario,
                                    'subtotal' => $subtotal,
                                    'cobrar' => false,
                                ];
                                $totalCosto += $subtotal;
                            }
                        }

                        // Si no hay productos para descargar - mostrar mensaje y permitir continuar
                        if (empty($productosRegresan)) {
                            return [
                                Forms\Components\Placeholder::make('sin_productos')
                                    ->label('')
                                    ->content(new \Illuminate\Support\HtmlString(
                                        '<div class="text-center py-4">
                                            <div class="text-success-500 text-4xl mb-2">✓</div>
                                            <div class="text-lg font-bold">Excelente</div>
                                            <div class="text-gray-500">Se vendió todo el inventario. No hay productos para descargar.</div>
                                            <div class="mt-2 text-sm text-gray-400">Al confirmar, el viaje pasará directamente a liquidación.</div>
                                        </div>'
                                    ))
                                    ->columnSpanFull(),

                                Forms\Components\Hidden::make('sin_descargas')
                                    ->default(true),
                            ];
                        }

                        return [
                            Forms\Components\Section::make('Productos que Regresan')
                                ->description('Total devolución: L ' . number_format($totalCosto, 2) . ' | Marque los productos que desea cobrar al chofer')
                                ->schema([
                                    Forms\Components\Repeater::make('productos')
                                        ->schema([
                                            Forms\Components\Grid::make(12)
                                                ->schema([
                                                    Forms\Components\Toggle::make('cobrar')
                                                        ->label('')
                                                        ->inline(false)
                                                        ->columnSpan(1)
                                                        ->live()
                                                        ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                                                            $productos = $get('../../productos') ?? [];
                                                            $total = 0;
                                                            foreach ($productos as $prod) {
                                                                if ($prod['cobrar'] ?? false) {
                                                                    $total += floatval($prod['subtotal'] ?? 0);
                                                                }
                                                            }
                                                            $set('../../total_cobrar', $total);
                                                        }),

                                                    Forms\Components\Placeholder::make('producto_nombre')
                                                        ->label('')
                                                        ->content(fn(Forms\Get $get) => $get('producto_nombre'))
                                                        ->columnSpan(4),

                                                    Forms\Components\Placeholder::make('cantidad_display')
                                                        ->label('')
                                                        ->content(fn(Forms\Get $get) => number_format($get('cantidad'), 2) . ' ' . $get('unidad_nombre'))
                                                        ->columnSpan(3),

                                                    Forms\Components\Placeholder::make('subtotal_display')
                                                        ->label('')
                                                        ->content(fn(Forms\Get $get) => new \Illuminate\Support\HtmlString(
                                                            '<span class="font-bold ' . ($get('cobrar') ? 'text-danger-500' : '') . '">L ' . number_format($get('subtotal'), 2) . '</span>'
                                                        ))
                                                        ->columnSpan(4),

                                                    Forms\Components\Hidden::make('carga_id'),
                                                    Forms\Components\Hidden::make('producto_id'),
                                                    Forms\Components\Hidden::make('unidad_id'),
                                                    Forms\Components\Hidden::make('cantidad'),
                                                    Forms\Components\Hidden::make('unidad_nombre'),
                                                    Forms\Components\Hidden::make('costo_unitario'),
                                                    Forms\Components\Hidden::make('subtotal'),
                                                ]),
                                        ])
                                        ->default($productosRegresan)
                                        ->disabled(fn() => false)
                                        ->addable(false)
                                        ->deletable(false)
                                        ->reorderable(false)
                                        ->columnSpanFull()
                                        ->itemLabel(fn(array $state): ?string => null),
                                ]),

                            Forms\Components\Section::make('')
                                ->schema([
                                    Forms\Components\Grid::make(2)
                                        ->schema([
                                            Forms\Components\Placeholder::make('label_total')
                                                ->label('')
                                                ->content(new \Illuminate\Support\HtmlString('<span class="text-lg font-bold">TOTAL A COBRAR AL CHOFER:</span>')),

                                            Forms\Components\TextInput::make('total_cobrar')
                                                ->label('')
                                                ->prefix('L')
                                                ->disabled()
                                                ->default(0)
                                                ->numeric()
                                                ->extraAttributes(['class' => 'text-2xl font-bold text-danger-600']),
                                        ]),

                                    Forms\Components\Textarea::make('observacion_cobro')
                                        ->label('Motivo del Cobro (opcional)')
                                        ->rows(2)
                                        ->placeholder('Ej: Producto dañado por descuido, producto no entregado, etc.')
                                        ->visible(fn(Forms\Get $get) => ($get('total_cobrar') ?? 0) > 0)
                                        ->columnSpanFull(),
                                ])
                                ->extraAttributes(['class' => 'bg-gray-50 dark:bg-gray-800 border-2 border-warning-500 rounded-xl']),
                        ];
                    })
                    ->action(function ($record, array $data) {
                        // Si no hay productos para descargar, pasar directo a liquidación
                        if (isset($data['sin_descargas']) && $data['sin_descargas']) {
                            DB::transaction(function () use ($record) {
                                $record->iniciarDescarga();
                                $record->iniciarLiquidacion();
                                $record->liquidarCompleto();
                            });

                            \Filament\Notifications\Notification::make()
                                ->title('Viaje listo para cerrar')
                                ->body("Todo vendido. Comisión: L " . number_format($record->comision_ganada, 2))
                                ->success()
                                ->send();
                            return;
                        }

                        $productos = $data['productos'] ?? [];

                        if (empty($productos)) {
                            $record->iniciarDescarga();
                            \Filament\Notifications\Notification::make()
                                ->title('Estado actualizado')
                                ->body('No había productos para descargar.')
                                ->info()
                                ->send();
                            return;
                        }

                        $observacion = $data['observacion_cobro'] ?? null;
                        $descargasCreadas = 0;
                        $totalCobrado = 0;
                        $productosCobrados = [];

                        DB::transaction(function () use ($record, $productos, $observacion, &$descargasCreadas, &$totalCobrado, &$productosCobrados) {
                            foreach ($productos as $item) {
                                $cobrar = $item['cobrar'] ?? false;
                                $subtotal = floatval($item['subtotal'] ?? 0);
                                $montoCobrar = $cobrar ? $subtotal : 0;

                                $carga = $record->cargas()->find($item['carga_id']);
                                $productoNombre = $carga?->producto?->nombre ?? 'Producto';

                                if ($cobrar) {
                                    $totalCobrado += $montoCobrar;
                                    $productosCobrados[] = $productoNombre;
                                }

                                \App\Models\ViajeDescarga::create([
                                    'viaje_id' => $record->id,
                                    'producto_id' => $item['producto_id'],
                                    'unidad_id' => $item['unidad_id'],
                                    'cantidad' => $item['cantidad'],
                                    'costo_unitario' => $item['costo_unitario'],
                                    'subtotal_costo' => $subtotal,
                                    'estado_producto' => 'bueno',
                                    'reingresa_stock' => true,
                                    'cobrar_chofer' => $cobrar,
                                    'monto_cobrar' => $montoCobrar,
                                    'observaciones' => $cobrar ? $observacion : null,
                                ]);

                                if ($carga) {
                                    $carga->increment('cantidad_devuelta', $item['cantidad']);
                                }

                                $descargasCreadas++;
                            }

                            $record->iniciarDescarga();
                        });

                        if ($totalCobrado > 0) {
                            \Filament\Notifications\Notification::make()
                                ->title('Descarga generada')
                                ->body("Se crearon {$descargasCreadas} descargas.\n\nTotal a cobrar: L " . number_format($totalCobrado, 2) . "\nProductos: " . implode(', ', $productosCobrados))
                                ->success()
                                ->duration(10000)
                                ->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->title('Descarga generada')
                                ->body("Se crearon {$descargasCreadas} descargas. Sin cobros al chofer.")
                                ->success()
                                ->send();
                        }
                    }),

                // Acción: Liquidar
                Tables\Actions\Action::make('liquidar')
                    ->label('Liquidar')
                    ->icon('heroicon-o-calculator')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Se calcularán comisiones, cobros y totales del viaje.')
                    ->visible(fn($record) => $record->estado === Viaje::ESTADO_DESCARGANDO && $record->descargas()->exists())
                    ->action(function ($record) {
                        try {
                            $resultado = $record->liquidarCompleto();
                            $record->iniciarLiquidacion();

                            \Filament\Notifications\Notification::make()
                                ->title('Liquidación completada')
                                ->body("Comisión: L " . number_format($resultado['comision_ganada'], 2) .
                                    " | Cobros: L " . number_format($resultado['cobros'], 2) .
                                    " | Neto: L " . number_format($resultado['neto_chofer'], 2))
                                ->success()
                                ->duration(10000)
                                ->send();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Error al liquidar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                // Acción: Cerrar Viaje
                Tables\Actions\Action::make('cerrar')
                    ->label('Cerrar Viaje')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Cerrar Viaje')
                    ->modalDescription('Se reintegrará el stock devuelto a la bodega. Esta acción no se puede revertir.')
                    ->visible(fn($record) => $record->estado === Viaje::ESTADO_LIQUIDANDO)
                    ->action(function ($record) {
                        try {
                            $record->cerrar();

                            \Filament\Notifications\Notification::make()
                                ->title('Viaje cerrado')
                                ->body("Stock reintegrado. Comisión: L " . number_format($record->comision_ganada, 2) .
                                    " | Neto chofer: L " . number_format($record->neto_chofer, 2))
                                ->success()
                                ->duration(10000)
                                ->send();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Error al cerrar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                // Acción: Cancelar
                Tables\Actions\Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancelar Viaje')
                    ->modalDescription(function ($record) {
                        if ($record->tieneVentasActivas()) {
                            $count = $record->ventasRuta()
                                ->whereIn('estado', ['borrador', 'confirmada', 'completada'])
                                ->count();
                            return "⚠️ Este viaje tiene {$count} venta(s) activa(s). Debe cancelar todas las ventas antes de cancelar el viaje.";
                        }
                        return 'Se devolverá el stock a bodega y se revertirán los reempaques. Esta acción no se puede deshacer.';
                    })
                    ->form([
                        Forms\Components\Textarea::make('motivo')
                            ->label('Motivo de cancelación')
                            ->required(),
                    ])
                    ->visible(fn($record) => !in_array($record->estado, [Viaje::ESTADO_CERRADO, Viaje::ESTADO_CANCELADO]))
                    ->action(function ($record, array $data) {
                        try {
                            $record->cancelar($data['motivo']);
                            \Filament\Notifications\Notification::make()
                                ->title('Viaje cancelado')
                                ->body('Stock devuelto a bodega correctamente.')
                                ->warning()
                                ->send();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('No se puede cancelar')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn($record) => $record->estado === Viaje::ESTADO_PLANIFICADO),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CargasRelationManager::class,
            RelationManagers\MermasRelationManager::class,
            RelationManagers\DescargasRelationManager::class,
            RelationManagers\VentasRelationManager::class,
            RelationManagers\ComisionesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListViajes::route('/'),
            'create' => Pages\CreateViaje::route('/create'),
            'view' => Pages\ViewViaje::route('/{record}'),
            'edit' => Pages\EditViaje::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return null;
        }

        $query = static::getModel()::whereNotIn('estado', [
            Viaje::ESTADO_CERRADO,
            Viaje::ESTADO_CANCELADO
        ]);

        if (!static::esSuperAdminOJefe()) {
            $bodegaId = static::getBodegaUsuario();
            if ($bodegaId) {
                $query->where('bodega_origen_id', $bodegaId);
            }
        }

        $activos = $query->count();
        return $activos ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
