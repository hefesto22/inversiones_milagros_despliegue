<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CamionGastoResource\Pages;
use App\Models\CamionGasto;
use App\Models\Camion;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CamionGastoResource extends Resource
{
    protected static ?string $model = CamionGasto::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Logística';
    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Gasto de Camión';
    protected static ?string $pluralModelLabel = 'Gastos de Camión';
    protected static ?string $navigationLabel = 'Gastos de Camión';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Gasto')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('camion_id')
                                    ->label('Camión')
                                    ->relationship('camion', 'codigo')
                                    ->getOptionLabelFromRecordUsing(fn(Camion $record) => "{$record->codigo} - {$record->placa}")
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->live(),

                                Forms\Components\Select::make('chofer_id')
                                    ->label('Chofer')
                                    ->options(function () {
                                        return User::whereHas('roles', function ($query) {
                                            $query->where('name', 'Chofer');
                                        })->pluck('name', 'id');
                                    })
                                    ->required()
                                    ->searchable()
                                    ->preload(),

                                Forms\Components\DatePicker::make('fecha')
                                    ->label('Fecha')
                                    ->required()
                                    ->default(now())
                                    ->maxDate(now())
                                    ->native(false),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('tipo_gasto')
                                    ->label('Tipo de Gasto')
                                    ->options(CamionGasto::TIPOS_GASTO)
                                    ->required()
                                    ->native(false)
                                    ->live(),

                                Forms\Components\TextInput::make('monto')
                                    ->label('Monto')
                                    ->required()
                                    ->numeric()
                                    ->prefix('L')
                                    ->minValue(0.01)
                                    ->step(0.01),

                                Forms\Components\TextInput::make('proveedor')
                                    ->label('Proveedor')
                                    ->placeholder('Gasolinera, taller, etc.')
                                    ->maxLength(255),
                            ]),
                    ]),

                // Campos específicos para Gasolina
                Forms\Components\Section::make('Detalles de Combustible')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('litros')
                                    ->label('Litros')
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->step(0.001)
                                    ->suffix('L'),

                                Forms\Components\TextInput::make('precio_por_litro')
                                    ->label('Precio por Litro')
                                    ->numeric()
                                    ->prefix('L')
                                    ->minValue(0.01)
                                    ->step(0.01),

                                Forms\Components\TextInput::make('kilometraje')
                                    ->label('Kilometraje Actual')
                                    ->numeric()
                                    ->suffix('km')
                                    ->minValue(0),
                            ]),
                    ])
                    ->visible(fn(Forms\Get $get) => $get('tipo_gasto') === 'gasolina')
                    ->collapsible(),

                Forms\Components\Section::make('Comprobante')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Toggle::make('tiene_factura')
                                    ->label('¿Tiene Factura?')
                                    ->default(false)
                                    ->inline(false),

                                Forms\Components\Toggle::make('enviado_whatsapp')
                                    ->label('¿Enviado por WhatsApp?')
                                    ->default(false)
                                    ->inline(false)
                                    ->disabled()
                                    ->dehydrated()
                                    ->helperText(function ($record) {
                                        if ($record && $record->enviado_whatsapp_at) {
                                            return 'Enviado: ' . $record->enviado_whatsapp_at->format('d/m/Y h:i a');
                                        }
                                        return 'Se marca automáticamente al enviar';
                                    }),
                            ]),

                        Forms\Components\Textarea::make('descripcion')
                            ->label('Descripción / Notas')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),

                // Sección de Aprobación (solo visible para Jefe/Encargado en edición)
                Forms\Components\Section::make('Aprobación')
                    ->schema([
                        Forms\Components\Select::make('estado')
                            ->label('Estado')
                            ->options(CamionGasto::ESTADOS)
                            ->required()
                            ->native(false)
                            ->live(),

                        Forms\Components\Textarea::make('motivo_rechazo')
                            ->label('Motivo de Rechazo')
                            ->rows(2)
                            ->maxLength(500)
                            ->visible(fn(Forms\Get $get) => $get('estado') === 'rechazado')
                            ->required(fn(Forms\Get $get) => $get('estado') === 'rechazado'),
                    ])
                    ->visible(fn($livewire) => $livewire instanceof Pages\EditCamionGasto)
                    ->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('camion.codigo')
                    ->label('Camión')
                    ->description(fn($record) => $record->camion?->placa)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('chofer.name')
                    ->label('Chofer')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('tipo_gasto')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn($state) => CamionGasto::TIPOS_GASTO[$state] ?? $state)
                    ->color(fn($state) => match ($state) {
                        'gasolina' => 'warning',
                        'mantenimiento' => 'info',
                        'reparacion' => 'danger',
                        'peaje' => 'gray',
                        'viaticos' => 'success',
                        'lavado' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('monto')
                    ->label('Monto')
                    ->money('HNL')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('HNL')),

                Tables\Columns\TextColumn::make('litros')
                    ->label('Litros')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' L')
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('tiene_factura')
                    ->label('Factura')
                    ->boolean()
                    ->trueIcon('heroicon-o-document-check')
                    ->falseIcon('heroicon-o-document')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\IconColumn::make('enviado_whatsapp')
                    ->label('WhatsApp')
                    ->boolean()
                    ->trueIcon('heroicon-o-chat-bubble-left-ellipsis')
                    ->falseIcon('heroicon-o-chat-bubble-left')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn($state) => CamionGasto::ESTADOS[$state] ?? $state)
                    ->color(fn($state) => match ($state) {
                        'pendiente' => 'warning',
                        'aprobado' => 'success',
                        'rechazado' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('proveedor')
                    ->label('Proveedor')
                    ->limit(20)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('camion_id')
                    ->label('Camión')
                    ->relationship('camion', 'codigo')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('chofer_id')
                    ->label('Chofer')
                    ->relationship('chofer', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('tipo_gasto')
                    ->label('Tipo de Gasto')
                    ->options(CamionGasto::TIPOS_GASTO),

                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(CamionGasto::ESTADOS),

                Tables\Filters\TernaryFilter::make('tiene_factura')
                    ->label('Con Factura'),

                Tables\Filters\TernaryFilter::make('enviado_whatsapp')
                    ->label('Enviado por WhatsApp'),

                Tables\Filters\Filter::make('fecha')
                    ->form([
                        Forms\Components\DatePicker::make('desde')
                            ->label('Desde')
                            ->native(false),
                        Forms\Components\DatePicker::make('hasta')
                            ->label('Hasta')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'], fn($q, $date) => $q->whereDate('fecha', '>=', $date))
                            ->when($data['hasta'], fn($q, $date) => $q->whereDate('fecha', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('aprobar')
                    ->label('Aprobar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Aprobar Gasto')
                    ->modalDescription('¿Confirmar que este gasto es válido?')
                    ->visible(fn($record) => $record->estado === 'pendiente')
                    ->action(function ($record) {
                        $record->aprobar(Auth::id());

                        \Filament\Notifications\Notification::make()
                            ->title('Gasto aprobado')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('rechazar')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Rechazar Gasto')
                    ->form([
                        Forms\Components\Textarea::make('motivo_rechazo')
                            ->label('Motivo del Rechazo')
                            ->required()
                            ->rows(3)
                            ->placeholder('Explica por qué se rechaza este gasto...'),
                    ])
                    ->visible(fn($record) => $record->estado === 'pendiente')
                    ->action(function ($record, array $data) {
                        $record->rechazar(Auth::id(), $data['motivo_rechazo']);

                        \Filament\Notifications\Notification::make()
                            ->title('Gasto rechazado')
                            ->warning()
                            ->send();
                    }),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn($record) => $record->estado === 'pendiente'),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn($record) => $record->estado === 'pendiente'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('aprobar_masivo')
                        ->label('Aprobar Seleccionados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->estado === 'pendiente') {
                                    $record->aprobar(Auth::id());
                                    $count++;
                                }
                            }

                            \Filament\Notifications\Notification::make()
                                ->title("{$count} gastos aprobados")
                                ->success()
                                ->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            // Solo permitir eliminar pendientes
                            foreach ($records as $record) {
                                if ($record->estado !== 'pendiente') {
                                    \Filament\Notifications\Notification::make()
                                        ->title('No se pueden eliminar gastos aprobados o rechazados')
                                        ->danger()
                                        ->send();

                                    return false;
                                }
                            }
                        }),
                ]),
            ])
            ->defaultSort('fecha', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCamionGastos::route('/'),
            'create' => Pages\CreateCamionGasto::route('/create'),
            'view' => Pages\ViewCamionGasto::route('/{record}'),
            'edit' => Pages\EditCamionGasto::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('estado', 'pendiente')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}