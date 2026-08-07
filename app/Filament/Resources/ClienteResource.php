<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClienteResource\Pages;
use App\Filament\Resources\ClienteResource\RelationManagers;
use App\Models\Cliente;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ClienteResource extends Resource
{
    protected static ?string $model = Cliente::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    /**
     * Eager load de ubicación para evitar N+1 en el listado/infolist
     * (preventLazyLoading está activo).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['departamento', 'municipio']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // =====================================================
                // INFORMACIÓN GENERAL
                // =====================================================
                Forms\Components\Section::make('Información General')
                    ->icon('heroicon-o-user')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('nombre')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(2)
                                    ->placeholder('Nombre del cliente'),

                                Forms\Components\Select::make('tipo')
                                    ->required()
                                    ->options([
                                        'mayorista' => 'Mayorista',
                                        'minorista' => 'Minorista',
                                        'distribuidor' => 'Distribuidor',
                                        'ruta' => 'Cliente de Ruta',
                                    ])
                                    ->native(false)
                                    ->default('minorista'),

                                Forms\Components\TextInput::make('rtn')
                                    ->label('RTN')
                                    ->maxLength(30)
                                    ->placeholder('0000-0000-000000'),

                                Forms\Components\TextInput::make('telefono')
                                    ->tel()
                                    ->maxLength(30)
                                    ->placeholder('0000-0000'),

                                Forms\Components\TextInput::make('email')
                                    ->email()
                                    ->maxLength(100)
                                    ->placeholder('cliente@ejemplo.com'),
                            ]),

                        // Ubicación: departamento y municipio en cascada.
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('departamento_id')
                                    ->label('Departamento')
                                    ->required()
                                    ->options(fn () => \App\Models\Departamento::orderBy('nombre')->pluck('nombre', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->live()
                                    ->placeholder('Seleccione un departamento')
                                    // Al cambiar de departamento, limpiar el municipio elegido.
                                    ->afterStateUpdated(fn (Forms\Set $set) => $set('municipio_id', null)),

                                Forms\Components\Select::make('municipio_id')
                                    ->label('Municipio')
                                    ->required()
                                    ->options(fn (Forms\Get $get) => filled($get('departamento_id'))
                                        ? \App\Models\Municipio::where('departamento_id', $get('departamento_id'))
                                            ->orderBy('nombre')
                                            ->pluck('nombre', 'id')
                                        : [])
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->disabled(fn (Forms\Get $get) => blank($get('departamento_id')))
                                    ->placeholder('Seleccione primero un departamento')
                                    // Defensa: el municipio debe pertenecer al departamento elegido.
                                    ->rule(fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                        if (filled($value) && ! \App\Models\Municipio::where('id', $value)
                                            ->where('departamento_id', $get('departamento_id'))
                                            ->exists()) {
                                            $fail('El municipio no pertenece al departamento seleccionado.');
                                        }
                                    }),
                            ]),

                        Forms\Components\Textarea::make('direccion')
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Dirección completa del cliente'),
                    ]),

                // =====================================================
                // CONFIGURACIÓN DE CRÉDITO
                // =====================================================
                Forms\Components\Section::make('Configuración de Crédito')
                    ->icon('heroicon-o-credit-card')
                    ->description('Define los límites y condiciones de crédito para este cliente')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('limite_credito')
                                    ->label('Límite de Crédito')
                                    ->numeric()
                                    ->prefix('L')
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(999999.99)
                                    ->step(0.01)
                                    ->helperText('0 = Sin límite definido'),

                                Forms\Components\TextInput::make('dias_credito')
                                    ->label('Días de Crédito')
                                    ->numeric()
                                    ->suffix('días')
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(365)
                                    ->helperText('0 = Solo contado'),

                                Forms\Components\Placeholder::make('saldo_actual')
                                    ->label('Saldo Pendiente Actual')
                                    ->content(function (?Cliente $record): string {
                                        if (! $record) {
                                            return 'L 0.00';
                                        }
                                        $saldo = $record->saldo_pendiente ?? 0;
                                        $color = $saldo > 0 ? 'text-red-600 font-bold' : 'text-green-600';

                                        return "<span class='{$color}'>L ".number_format($saldo, 2).'</span>';
                                    })
                                    ->extraAttributes(['class' => 'text-lg'])
                                    ->visible(fn ($livewire) => $livewire instanceof Pages\EditCliente),
                            ]),

                        // Información de crédito (solo en edición)
                        Forms\Components\Placeholder::make('info_credito')
                            ->label('')
                            ->content(function (?Cliente $record): string {
                                if (! $record || ! $record->limite_credito || $record->limite_credito <= 0) {
                                    return '';
                                }

                                $disponible = $record->getCreditoDisponible();
                                $porcentajeUsado = $record->limite_credito > 0
                                    ? (($record->saldo_pendiente / $record->limite_credito) * 100)
                                    : 0;

                                $colorBarra = match (true) {
                                    $porcentajeUsado >= 90 => 'bg-red-500',
                                    $porcentajeUsado >= 70 => 'bg-yellow-500',
                                    default => 'bg-green-500',
                                };

                                return "
                                    <div class='space-y-2'>
                                        <div class='flex justify-between text-sm'>
                                            <span>Crédito Disponible:</span>
                                            <span class='font-semibold'>L ".number_format($disponible, 2)."</span>
                                        </div>
                                        <div class='w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700'>
                                            <div class='{$colorBarra} h-2.5 rounded-full' style='width: ".min($porcentajeUsado, 100)."%'></div>
                                        </div>
                                        <div class='text-xs text-gray-500 text-right'>".number_format($porcentajeUsado, 1).'% utilizado</div>
                                    </div>
                                ';
                            })
                            ->columnSpanFull()
                            ->visible(fn ($livewire) => $livewire instanceof Pages\EditCliente),
                    ])
                    ->collapsible(),

                // =====================================================
                // POLÍTICA DE DEVOLUCIONES
                // =====================================================
                Forms\Components\Section::make('Política de Devoluciones')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->description('Configura si este cliente tiene derecho a devoluciones o reposiciones')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Toggle::make('acepta_devolucion')
                                    ->label('Acepta Devoluciones')
                                    ->default(false)
                                    ->live()
                                    ->helperText('Activar si tiene acuerdo de devolución'),

                                Forms\Components\TextInput::make('dias_devolucion')
                                    ->label('Días para Devolver')
                                    ->numeric()
                                    ->suffix('días')
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(30)
                                    ->visible(fn (Forms\Get $get) => $get('acepta_devolucion'))
                                    ->helperText('Plazo máximo desde la compra'),

                                Forms\Components\TextInput::make('porcentaje_devolucion_max')
                                    ->label('% Máximo Devolución')
                                    ->numeric()
                                    ->suffix('%')
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->visible(fn (Forms\Get $get) => $get('acepta_devolucion'))
                                    ->helperText('Porcentaje máximo de la venta'),
                            ]),

                        Forms\Components\Textarea::make('notas_acuerdo')
                            ->label('Notas del Acuerdo')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->visible(fn (Forms\Get $get) => $get('acepta_devolucion'))
                            ->placeholder('Ej: "Se repone si se daña dentro de la semana, máximo 5%"'),
                    ])
                    ->collapsible()
                    ->collapsed(),

                // =====================================================
                // ESTADO
                // =====================================================
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Toggle::make('estado')
                            ->label('Cliente Activo')
                            ->default(true)
                            ->helperText('Los clientes inactivos no aparecen en las ventas'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Cliente $record): string => $record->telefono ?? ''),

                Tables\Columns\TextColumn::make('tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'mayorista' => 'Mayorista',
                        'minorista' => 'Minorista',
                        'distribuidor' => 'Distribuidor',
                        'ruta' => 'Ruta',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'mayorista' => 'success',
                        'minorista' => 'info',
                        'distribuidor' => 'warning',
                        'ruta' => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('rtn')
                    ->label('RTN')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('telefono')
                    ->searchable()
                    ->icon('heroicon-o-phone')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('departamento.nombre')
                    ->label('Departamento')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Sin asignar')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('municipio.nombre')
                    ->label('Municipio')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Sin asignar')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('limite_credito')
                    ->label('Límite')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable()
                    ->description(fn (Cliente $record): string => $record->dias_credito > 0 ? "{$record->dias_credito} días" : 'Contado'
                    ),

                Tables\Columns\TextColumn::make('saldo_pendiente')
                    ->label('Deuda')
                    ->money('HNL')
                    ->sortable()
                    ->color(fn ($state) => match (true) {
                        $state <= 0 => 'success',
                        default => 'danger',
                    })
                    ->weight(fn ($state) => $state > 0 ? 'bold' : 'normal')
                    ->description(function (Cliente $record): ?string {
                        if ($record->saldo_pendiente <= 0) {
                            return null;
                        }
                        if ($record->tieneDeudaVencida()) {
                            return '⚠️ Vencida';
                        }

                        return null;
                    }),

                Tables\Columns\TextColumn::make('credito_disponible')
                    ->label('Disponible')
                    ->getStateUsing(fn (Cliente $record) => $record->getCreditoDisponible())
                    ->formatStateUsing(function ($state) {
                        if ($state === PHP_FLOAT_MAX) {
                            return 'Sin límite';
                        }

                        return 'L '.number_format($state, 2);
                    })
                    ->color('success')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('acepta_devolucion')
                    ->label('Dev.')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\ToggleColumn::make('estado')
                    ->label('Activo'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->options([
                        'mayorista' => 'Mayorista',
                        'minorista' => 'Minorista',
                        'distribuidor' => 'Distribuidor',
                        'ruta' => 'Ruta',
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('departamento_id')
                    ->label('Departamento')
                    ->relationship('departamento', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('municipio_id')
                    ->label('Municipio')
                    ->relationship('municipio', 'nombre')
                    ->searchable(),

                Tables\Filters\TernaryFilter::make('estado')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),

                Tables\Filters\Filter::make('con_deuda')
                    ->label('Con deuda')
                    ->query(fn (Builder $query) => $query->where('saldo_pendiente', '>', 0))
                    ->toggle(),

                Tables\Filters\Filter::make('deuda_vencida')
                    ->label('Deuda vencida')
                    ->query(fn (Builder $query) => $query->conDeudaVencida())
                    ->toggle(),

                Tables\Filters\Filter::make('acepta_devolucion')
                    ->label('Acepta devoluciones')
                    ->query(fn (Builder $query) => $query->where('acepta_devolucion', true))
                    ->toggle(),

                // Clientes con al menos un descuento especial activo en sus productos.
                Tables\Filters\Filter::make('con_descuento_especial')
                    ->label('Con descuento especial')
                    ->query(fn (Builder $query) => $query->whereHas(
                        'preciosCliente',
                        fn (Builder $sub) => $sub->whereNotNull('descuento_maximo_override')
                    ))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),

                    Tables\Actions\Action::make('ver_estado_cuenta')
                        ->label('Estado de Cuenta')
                        ->icon('heroicon-o-document-text')
                        ->color('info')
                        ->url(fn (Cliente $record) => static::getUrl('view', ['record' => $record]))
                        ->visible(fn (Cliente $record) => $record->saldo_pendiente > 0),

                    // Válvula del control "una factura de ruta por cliente al día".
                    // Habilita UNA venta extra para hoy, con motivo y con el nombre
                    // de quien la autorizó: el control no se levanta, se registra.
                    // @see \App\Services\Viaje\LimiteVentaDiariaService
                    Tables\Actions\Action::make('autorizar_segunda_venta')
                        ->label('Autorizar segunda venta')
                        ->icon('heroicon-o-shield-check')
                        ->color('warning')
                        ->modalHeading(fn (Cliente $record) => 'Autorizar segunda venta a '.$record->nombre)
                        ->modalDescription('Habilita UNA venta de ruta extra para el día de hoy. Si después necesitan otra más, hay que autorizarla de nuevo.')
                        ->modalSubmitActionLabel('Autorizar')
                        // Consumidor Final no tiene límite, así que no hay nada que autorizarle.
                        ->visible(fn (Cliente $record) => ! $record->esConsumidorFinal()
                            && (Auth::user()?->hasAnyRole(['Super Admin', 'Jefe', 'Encargado']) ?? false))
                        ->form([
                            Forms\Components\Placeholder::make('situacion_de_hoy')
                                ->label('Situación de hoy')
                                ->content(function (Cliente $record) {
                                    $servicio = app(\App\Services\Viaje\LimiteVentaDiariaService::class);
                                    $ventas = $servicio->ventasVivasDelDia($record);
                                    $pendientes = $servicio->autorizacionesDisponibles($record);

                                    return $ventas.' '.($ventas === 1 ? 'venta de ruta hoy' : 'ventas de ruta hoy')
                                        .' · '.$pendientes.' '.($pendientes === 1 ? 'autorización sin usar' : 'autorizaciones sin usar');
                                }),

                            Forms\Components\Textarea::make('motivo')
                                ->label('Motivo')
                                ->required()
                                ->maxLength(255)
                                ->rows(2)
                                ->placeholder('Ej: el cliente pidió más producto en la tarde'),
                        ])
                        ->action(function (Cliente $record, array $data) {
                            $servicio = app(\App\Services\Viaje\LimiteVentaDiariaService::class);

                            // Que no se apilen autorizaciones sin usar: si ya hay una
                            // viva, el chofer todavía la tiene disponible.
                            if ($servicio->autorizacionesDisponibles($record) > 0) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Ya hay una autorización sin usar')
                                    ->body($record->nombre.' ya tiene una autorización vigente para hoy. El chofer puede facturar con esa.')
                                    ->warning()
                                    ->duration(8000)
                                    ->send();

                                return;
                            }

                            \App\Models\AutorizacionSegundaVenta::create([
                                'cliente_id' => $record->id,
                                'fecha' => today(),
                                'autorizado_por' => Auth::id(),
                                'motivo' => $data['motivo'],
                            ]);

                            \Filament\Notifications\Notification::make()
                                ->title('Autorización creada')
                                ->body('El chofer ya puede hacerle una segunda venta a '.$record->nombre.' hoy. Vale por una sola.')
                                ->success()
                                ->duration(8000)
                                ->send();
                        }),

                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (Cliente $record) => $record->saldo_pendiente <= 0),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Asignar zona en bloque.
                    //
                    // Existe porque los clientes creados antes del catálogo
                    // geográfico no tienen departamento/municipio, y mientras no
                    // lo tengan aparecen en TODOS los viajes (son "comodines",
                    // ver Cliente::scopeEnZonaDe). Editarlos de a uno para
                    // cientos de clientes no es viable.
                    Tables\Actions\BulkAction::make('asignar_zona')
                        ->label('Asignar zona')
                        ->icon('heroicon-o-map-pin')
                        ->color('warning')
                        ->modalHeading('Asignar departamento y municipio')
                        ->modalDescription('Se aplica a TODOS los clientes seleccionados y reemplaza la ubicación que tuvieran. Mientras un cliente no tenga zona, aparece en todos los viajes.')
                        ->modalSubmitActionLabel('Asignar')
                        ->visible(fn () => Auth::user()?->can('update_cliente') ?? false)
                        ->form([
                            Forms\Components\Select::make('departamento_id')
                                ->label('Departamento')
                                ->required()
                                ->options(fn () => \App\Models\Departamento::orderBy('nombre')->pluck('nombre', 'id'))
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->live()
                                ->afterStateUpdated(fn (Forms\Set $set) => $set('municipio_id', null)),

                            Forms\Components\Select::make('municipio_id')
                                ->label('Municipio')
                                ->required()
                                ->options(fn (Forms\Get $get) => filled($get('departamento_id'))
                                    ? \App\Models\Municipio::where('departamento_id', $get('departamento_id'))
                                        ->orderBy('nombre')
                                        ->pluck('nombre', 'id')
                                    : [])
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->disabled(fn (Forms\Get $get) => blank($get('departamento_id')))
                                ->placeholder('Seleccione primero un departamento')
                                // Misma defensa que en el formulario del cliente.
                                ->rule(fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    if (filled($value) && ! \App\Models\Municipio::where('id', $value)
                                        ->where('departamento_id', $get('departamento_id'))
                                        ->exists()) {
                                        $fail('El municipio no pertenece al departamento seleccionado.');
                                    }
                                }),
                        ])
                        ->action(function (\Illuminate\Support\Collection $records, array $data): void {
                            // update() masivo: no dispara eventos de modelo, por eso
                            // updated_at y updated_by se ponen a mano.
                            $actualizados = Cliente::whereKey($records->pluck('id'))->update([
                                'departamento_id' => $data['departamento_id'],
                                'municipio_id' => $data['municipio_id'],
                                'updated_by' => Auth::id(),
                                'updated_at' => now(),
                            ]);

                            \Filament\Notifications\Notification::make()
                                ->title('Zona asignada')
                                ->body($actualizados.' '.($actualizados === 1 ? 'cliente actualizado' : 'clientes actualizados').'. Ya solo aparecerán en los viajes que cubran esa zona.')
                                ->success()
                                ->duration(6000)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            foreach ($records as $record) {
                                if ($record->saldo_pendiente > 0) {
                                    throw new \Exception("No se puede eliminar el cliente {$record->nombre} porque tiene saldo pendiente.");
                                }
                            }
                        }),
                ]),
            ])
            ->defaultSort('nombre')
            ->persistFiltersInSession();
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Información del Cliente')
                    ->icon('heroicon-o-user')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('nombre')
                                    ->weight('bold')
                                    ->size('lg'),

                                Infolists\Components\TextEntry::make('tipo')
                                    ->badge()
                                    ->formatStateUsing(fn ($state) => match ($state) {
                                        'mayorista' => 'Mayorista',
                                        'minorista' => 'Minorista',
                                        'distribuidor' => 'Distribuidor',
                                        'ruta' => 'Ruta',
                                        default => $state,
                                    })
                                    ->color(fn ($state) => match ($state) {
                                        'mayorista' => 'success',
                                        'minorista' => 'info',
                                        'distribuidor' => 'warning',
                                        'ruta' => 'primary',
                                        default => 'gray',
                                    }),

                                Infolists\Components\TextEntry::make('estado')
                                    ->badge()
                                    ->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Inactivo')
                                    ->color(fn ($state) => $state ? 'success' : 'danger'),

                                Infolists\Components\TextEntry::make('rtn')
                                    ->label('RTN')
                                    ->placeholder('No registrado'),

                                Infolists\Components\TextEntry::make('telefono')
                                    ->icon('heroicon-o-phone')
                                    ->placeholder('No registrado'),

                                Infolists\Components\TextEntry::make('email')
                                    ->icon('heroicon-o-envelope')
                                    ->placeholder('No registrado'),

                                Infolists\Components\TextEntry::make('departamento.nombre')
                                    ->label('Departamento')
                                    ->icon('heroicon-o-map-pin')
                                    ->placeholder('Sin asignar'),

                                Infolists\Components\TextEntry::make('municipio.nombre')
                                    ->label('Municipio')
                                    ->icon('heroicon-o-map-pin')
                                    ->placeholder('Sin asignar'),

                                Infolists\Components\TextEntry::make('direccion')
                                    ->columnSpanFull()
                                    ->placeholder('No registrada'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Estado de Crédito')
                    ->icon('heroicon-o-credit-card')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('limite_credito')
                                    ->label('Límite de Crédito')
                                    ->money('HNL')
                                    ->placeholder('Sin límite'),

                                Infolists\Components\TextEntry::make('saldo_pendiente')
                                    ->label('Saldo Pendiente')
                                    ->money('HNL')
                                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('credito_disponible')
                                    ->label('Crédito Disponible')
                                    ->getStateUsing(fn (Cliente $record) => $record->getCreditoDisponible())
                                    ->formatStateUsing(function ($state) {
                                        if ($state === PHP_FLOAT_MAX) {
                                            return 'Sin límite';
                                        }

                                        return 'L '.number_format($state, 2);
                                    })
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('dias_credito')
                                    ->label('Plazo de Crédito')
                                    ->formatStateUsing(fn ($state) => $state > 0 ? "{$state} días" : 'Solo contado'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Política de Devoluciones')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\IconEntry::make('acepta_devolucion')
                                    ->label('Acepta Devoluciones')
                                    ->boolean(),

                                Infolists\Components\TextEntry::make('dias_devolucion')
                                    ->label('Plazo Devolución')
                                    ->formatStateUsing(fn ($state) => $state > 0 ? "{$state} días" : 'N/A')
                                    ->visible(fn (Cliente $record) => $record->acepta_devolucion),

                                Infolists\Components\TextEntry::make('porcentaje_devolucion_max')
                                    ->label('% Máximo')
                                    ->formatStateUsing(fn ($state) => $state > 0 ? "{$state}%" : 'N/A')
                                    ->visible(fn (Cliente $record) => $record->acepta_devolucion),

                                Infolists\Components\TextEntry::make('notas_acuerdo')
                                    ->label('Notas del Acuerdo')
                                    ->columnSpanFull()
                                    ->placeholder('Sin notas')
                                    ->visible(fn (Cliente $record) => $record->acepta_devolucion),
                            ]),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\VentasRelationManager::class,
            RelationManagers\PreciosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClientes::route('/'),
            'create' => Pages\CreateCliente::route('/create'),
            'view' => Pages\ViewCliente::route('/{record}'),
            'edit' => Pages\EditCliente::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('estado', true)->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['nombre', 'rtn', 'telefono', 'email'];
    }
}
