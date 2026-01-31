<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClienteResource\Pages;
use App\Filament\Resources\ClienteResource\RelationManagers;
use App\Models\Cliente;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class ClienteResource extends Resource
{
    protected static ?string $model = Cliente::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

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
                                        if (!$record) {
                                            return 'L 0.00';
                                        }
                                        $saldo = $record->saldo_pendiente ?? 0;
                                        $color = $saldo > 0 ? 'text-red-600 font-bold' : 'text-green-600';
                                        return "<span class='{$color}'>L " . number_format($saldo, 2) . "</span>";
                                    })
                                    ->extraAttributes(['class' => 'text-lg'])
                                    ->visible(fn ($livewire) => $livewire instanceof Pages\EditCliente),
                            ]),

                        // Información de crédito (solo en edición)
                        Forms\Components\Placeholder::make('info_credito')
                            ->label('')
                            ->content(function (?Cliente $record): string {
                                if (!$record || !$record->limite_credito || $record->limite_credito <= 0) {
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
                                            <span class='font-semibold'>L " . number_format($disponible, 2) . "</span>
                                        </div>
                                        <div class='w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700'>
                                            <div class='{$colorBarra} h-2.5 rounded-full' style='width: " . min($porcentajeUsado, 100) . "%'></div>
                                        </div>
                                        <div class='text-xs text-gray-500 text-right'>" . number_format($porcentajeUsado, 1) . "% utilizado</div>
                                    </div>
                                ";
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

                Tables\Columns\TextColumn::make('limite_credito')
                    ->label('Límite')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable()
                    ->description(fn (Cliente $record): string =>
                        $record->dias_credito > 0 ? "{$record->dias_credito} días" : 'Contado'
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
                        return 'L ' . number_format($state, 2);
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

                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (Cliente $record) => $record->saldo_pendiente <= 0),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
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
                                        return 'L ' . number_format($state, 2);
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
