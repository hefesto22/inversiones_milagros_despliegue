<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProveedorPrecioResource\Pages;
use App\Models\ProveedorPrecio;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProveedorPrecioResource extends \Filament\Resources\Resource
{
    protected static ?string $model = ProveedorPrecio::class;
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Precios';
    protected static ?string $navigationLabel = 'Precios de Compra';
    protected static ?string $modelLabel = 'Precio de compra';
    protected static ?string $pluralModelLabel = 'Precios de compra';
    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\Select::make('proveedor_id')
                        ->label('Proveedor')
                        ->relationship('proveedor', 'nombre')
                        ->searchable()->preload()->required(),

                    Forms\Components\Select::make('producto_id')
                        ->label('Producto')
                        ->relationship('producto', 'nombre')
                        ->searchable()->preload()->required(),

                    Forms\Components\Select::make('unidad_id')
                        ->label('Presentación / Unidad')
                        ->relationship('unidad', 'nombre')
                        ->searchable()->preload()->required(),

                    Forms\Components\TextInput::make('precio_compra')
                        ->label('Precio compra (HNL)')
                        ->numeric()
                        ->rules(['decimal:0,4'])
                        ->required(),

                    Forms\Components\DatePicker::make('vigente_desde')
                        ->label('Vigente desde')
                        ->default(now())
                        ->required(),

                    Forms\Components\DatePicker::make('vigente_hasta')
                        ->label('Vigente hasta')
                        ->afterOrEqual('vigente_desde')
                        ->helperText('Déjalo vacío para mantenerlo ACTIVO hasta que registres uno nuevo.'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('proveedor.nombre')->label('Proveedor')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('producto.nombre')->label('Producto')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('unidad.nombre')->label('Unidad')->sortable(),
                Tables\Columns\TextColumn::make('precio_compra')->label('Precio')
                    ->money('HNL')->sortable(),
                Tables\Columns\TextColumn::make('vigente_desde')->date()->sortable(),
                Tables\Columns\TextColumn::make('vigente_hasta')
                    ->date()
                    ->label('Hasta')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->colors([
                        'success' => fn ($record) => is_null($record->vigente_hasta),
                        'warning' => fn ($record) => ! is_null($record->vigente_hasta),
                    ])
                    ->formatStateUsing(fn ($record) => is_null($record->vigente_hasta) ? 'Activa' : 'Histórico'),
            ])
            ->defaultSort('vigente_desde', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('vigente')
                    ->label('Solo vigentes')
                    ->boolean()
                    ->queries(
                        true:  fn (Builder $q) => $q->whereNull('vigente_hasta'),
                        false: fn (Builder $q) => $q->whereNotNull('vigente_hasta'),
                        blank: fn (Builder $q) => $q
                    ),
                Tables\Filters\SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable(),
                Tables\Filters\SelectFilter::make('producto_id')
                    ->label('Producto')
                    ->relationship('producto', 'nombre')
                    ->searchable(),
                Tables\Filters\SelectFilter::make('unidad_id')
                    ->label('Unidad')
                    ->relationship('unidad', 'nombre')
                    ->searchable(),
                Tables\Filters\Filter::make('rango_vigencia')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when($data['desde'] ?? null, fn ($qq, $v) => $qq->whereDate('vigente_desde', '>=', $v))
                            ->when($data['hasta'] ?? null, fn ($qq, $v) => $qq->whereDate('vigente_desde', '<=', $v));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProveedorPrecios::route('/'),
            'create' => Pages\CreateProveedorPrecio::route('/create'),
            'edit'   => Pages\EditProveedorPrecio::route('/{record}/edit'),
        ];
    }
}
