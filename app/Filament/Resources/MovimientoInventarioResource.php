<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MovimientoInventarioResource\Pages;
use App\Models\MovimientoInventario;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MovimientoInventarioResource extends Resource
{
    protected static ?string $model = MovimientoInventario::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $navigationGroup = 'Inventario';
    protected static ?string $navigationLabel = 'Movimientos (Kardex)';
    protected static ?string $modelLabel = 'Movimiento';
    protected static ?string $pluralModelLabel = 'Movimientos de Inventario';
    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Información del Movimiento')
                ->schema([
                    Forms\Components\Select::make('bodega_id')
                        ->label('Bodega')
                        ->relationship('bodega', 'nombre')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->reactive()
                        ->columnSpan(1),

                    Forms\Components\Select::make('producto_id')
                        ->label('Producto')
                        ->relationship('producto', 'nombre')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->reactive()
                        ->afterStateUpdated(function ($state, callable $set) {
                            // Aquí podrías cargar la unidad base del producto
                            $set('cantidad_base', null);
                        })
                        ->columnSpan(1),

                    Forms\Components\Select::make('tipo')
                        ->label('Tipo de Movimiento')
                        ->options([
                            'entrada' => 'Entrada',
                            'salida' => 'Salida',
                            'merma' => 'Merma',
                            'ajuste' => 'Ajuste',
                        ])
                        ->required()
                        ->native(false)
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('cantidad_base')
                        ->label('Cantidad (Unidad Base)')
                        ->numeric()
                        ->rules(['decimal:0,3'])
                        ->required()
                        ->minValue(0.001)
                        ->step(0.001)
                        ->helperText('Cantidad en la unidad base del producto (pieza, mL, lb)')
                        ->suffix(fn ($get) => static::getUnidadBaseSuffix($get('producto_id')))
                        ->columnSpan(1),

                    Forms\Components\DateTimePicker::make('fecha')
                        ->label('Fecha y Hora')
                        ->default(now())
                        ->required()
                        ->native(false)
                        ->seconds(false)
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('referencia_tipo')
                        ->label('Tipo de Referencia')
                        ->placeholder('compra, venta, viaje_carga, ajuste...')
                        ->helperText('Opcional: tipo de documento que origina el movimiento')
                        ->maxLength(255)
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('referencia_id')
                        ->label('ID de Referencia')
                        ->numeric()
                        ->helperText('Opcional: ID del documento origen')
                        ->columnSpan(1),

                    Forms\Components\Textarea::make('nota')
                        ->label('Nota / Observaciones')
                        ->rows(3)
                        ->maxLength(1000)
                        ->columnSpan(2),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('tipo')
                    ->label('Tipo')
                    ->colors([
                        'success' => 'entrada',
                        'danger' => 'salida',
                        'warning' => 'merma',
                        'primary' => 'ajuste',
                    ])
                    ->icons([
                        'heroicon-o-arrow-down-tray' => 'entrada',
                        'heroicon-o-arrow-up-tray' => 'salida',
                        'heroicon-o-exclamation-triangle' => 'merma',
                        'heroicon-o-wrench' => 'ajuste',
                    ])
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('cantidad_base')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->weight('bold')
                    ->color(fn ($record) => match($record->tipo) {
                        'entrada', 'ajuste' => 'success',
                        'salida' => 'danger',
                        'merma' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state, $record) =>
                        number_format($state, 3) . ' ' . ($record->producto->unidadBase->simbolo ?? '')
                    ),

                Tables\Columns\TextColumn::make('referencia_tipo')
                    ->label('Referencia')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state, $record) =>
                        $state ? ucfirst($state) . ($record->referencia_id ? " #{$record->referencia_id}" : '') : '—'
                    )
                    ->toggleable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuario')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                Tables\Columns\TextColumn::make('nota')
                    ->label('Nota')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->nota)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('producto_id')
                    ->label('Producto')
                    ->relationship('producto', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo de Movimiento')
                    ->options([
                        'entrada' => 'Entrada',
                        'salida' => 'Salida',
                        'merma' => 'Merma',
                        'ajuste' => 'Ajuste',
                    ])
                    ->native(false),

                Tables\Filters\SelectFilter::make('referencia_tipo')
                    ->label('Tipo de Referencia')
                    ->options([
                        'compra' => 'Compra',
                        'venta' => 'Venta',
                        'viaje_carga' => 'Viaje - Carga',
                        'viaje_merma' => 'Viaje - Merma',
                        'ajuste' => 'Ajuste',
                        'traspaso' => 'Traspaso',
                        'devolucion' => 'Devolución',
                    ]),

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
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->emptyStateHeading('Sin movimientos registrados')
            ->emptyStateDescription('Comienza registrando el primer movimiento de inventario.')
            ->emptyStateIcon('heroicon-o-arrow-path');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMovimientoInventarios::route('/'),
            'create' => Pages\CreateMovimientoInventario::route('/create'),
            'edit' => Pages\EditMovimientoInventario::route('/{record}/edit'),
            // 'view' key removed due to missing class Pages\ViewMovimientoInventario
        ];
    }

    /**
     * Helper para obtener el sufijo de unidad base
     */
    protected static function getUnidadBaseSuffix(?int $productoId): string
    {
        if (!$productoId) {
            return '';
        }

        $producto = \App\Models\Producto::find($productoId);
        return $producto?->unidadBase?->simbolo ?? '';
    }

    /**
     * Navegación con badge de conteo
     */
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::whereDate('fecha', today())->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }
}
