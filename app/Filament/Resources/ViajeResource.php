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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['camion', 'chofer', 'bodegaOrigen']);
        $currentUser = Auth::user();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        // Super Admin y Jefe ven todos los viajes
        if (static::esSuperAdminOJefe()) {
            return $query;
        }

        // Otros usuarios solo ven viajes de su bodega
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

                                        // Si no es Super Admin/Jefe, filtrar por su bodega
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
                                                $set('chofer_id', $chofer->id);
                                            }
                                        }
                                    })
                                    ->helperText('Solo camiones disponibles'),

                                Forms\Components\Select::make('chofer_id')
                                    ->label('Chofer')
                                    ->options(function () {
                                        return User::whereHas('roles', function ($q) {
                                            $q->where('name', 'Chofer');
                                        })->pluck('name', 'id');
                                    })
                                    ->required()
                                    ->searchable()
                                    ->helperText('Usuario con rol de chofer'),

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
                        Viaje::ESTADO_REGRESANDO => 'Regresando',
                        Viaje::ESTADO_DESCARGANDO => 'Descargando',
                        Viaje::ESTADO_LIQUIDANDO => 'Liquidando',
                        Viaje::ESTADO_CERRADO => 'Cerrado',
                        Viaje::ESTADO_CANCELADO => 'Cancelado',
                    ]),

                // Filtro de bodega solo visible para Super Admin y Jefe
                Tables\Filters\SelectFilter::make('bodega_origen_id')
                    ->label('Bodega')
                    ->relationship('bodegaOrigen', 'nombre')
                    ->searchable()
                    ->preload()
                    ->visible(fn () => static::esSuperAdminOJefe()),

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

                // Acción: Iniciar Regreso
                Tables\Actions\Action::make('iniciar_regreso')
                    ->label('Regresar')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('El viaje cambiará a estado "Regresando".')
                    ->visible(fn($record) => $record->estado === Viaje::ESTADO_EN_RUTA)
                    ->action(function ($record) {
                        $record->iniciarRegreso();
                        \Filament\Notifications\Notification::make()
                            ->title('Viaje regresando')
                            ->success()
                            ->send();
                    }),

                // Acción: Iniciar Descarga
                Tables\Actions\Action::make('iniciar_descarga')
                    ->label('Descargar')
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn($record) => $record->estado === Viaje::ESTADO_REGRESANDO)
                    ->action(function ($record) {
                        $record->iniciarDescarga();
                        \Filament\Notifications\Notification::make()
                            ->title('Descarga iniciada')
                            ->success()
                            ->send();
                    }),

                // Acción: Liquidar
                Tables\Actions\Action::make('liquidar')
                    ->label('Liquidar')
                    ->icon('heroicon-o-calculator')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Se revisarán cobros y comisiones del chofer.')
                    ->visible(fn($record) => in_array($record->estado, [Viaje::ESTADO_REGRESANDO, Viaje::ESTADO_DESCARGANDO]))
                    ->action(function ($record) {
                        $record->iniciarLiquidacion();
                        \Filament\Notifications\Notification::make()
                            ->title('Liquidación iniciada')
                            ->success()
                            ->send();
                    }),

                // Acción: Cerrar Viaje
                Tables\Actions\Action::make('cerrar')
                    ->label('Cerrar Viaje')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Se calcularán los totales finales y se cerrará el viaje.')
                    ->visible(fn($record) => $record->estado === Viaje::ESTADO_LIQUIDANDO)
                    ->action(function ($record) {
                        try {
                            $record->cerrar();
                            \Filament\Notifications\Notification::make()
                                ->title('Viaje cerrado')
                                ->body('Se han calculado todos los totales.')
                                ->success()
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
                    ->form([
                        Forms\Components\Textarea::make('motivo')
                            ->label('Motivo de cancelación')
                            ->required(),
                    ])
                    ->visible(fn($record) => !in_array($record->estado, [Viaje::ESTADO_CERRADO, Viaje::ESTADO_CANCELADO]))
                    ->action(function ($record, array $data) {
                        $record->cancelar($data['motivo']);
                        \Filament\Notifications\Notification::make()
                            ->title('Viaje cancelado')
                            ->warning()
                            ->send();
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
            RelationManagers\VentasRelationManager::class,
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

        // Si no es Super Admin o Jefe, filtrar por su bodega
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
