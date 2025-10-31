<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductoResource\Pages;
use App\Models\Producto;
use App\Models\Unidad;
use App\Models\Bodega;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductoResource extends Resource
{
    protected static ?string $model = Producto::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationLabel = 'Productos';
    protected static ?string $pluralModelLabel = 'Productos';
    protected static ?string $modelLabel = 'Producto';
    protected static ?string $navigationGroup = 'Inventario';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Select::make('tipo')
                    ->label('Tipo')
                    ->options([
                        'huevo' => 'Huevo',
                        'lacteo' => 'Lácteo',
                        'abarroteria' => 'Abarrotería',
                    ])
                    ->required(),

                Forms\Components\Select::make('unidad_base_id')
                    ->label('Unidad base')
                    ->options(Unidad::query()->pluck('nombre', 'id'))
                    ->searchable()
                    ->required(),

                Forms\Components\TextInput::make('sku')
                    ->label('SKU')
                    ->maxLength(100)
                    ->nullable(),

                Forms\Components\Toggle::make('activo')
                    ->label('Activo')
                    ->default(true),

                // Solo al crear: bodega inicial para registrar el pivote bodega_producto
                Forms\Components\Select::make('bodega_inicial_id')
                    ->label('Bodega inicial')
                    ->helperText('¿En qué bodega quedará registrado este producto?')
                    ->options(Bodega::query()->pluck('nombre', 'id'))
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->visibleOn('create'),

                Forms\Components\Section::make('Presentaciones')
                    ->description('Distintas presentaciones del producto')
                    ->schema([
                        Forms\Components\Repeater::make('presentaciones')
                            ->relationship('presentaciones')
                            ->schema([
                                Forms\Components\Select::make('unidad_id')
                                    ->label('Unidad')
                                    ->options(Unidad::query()->pluck('nombre', 'id'))
                                    ->searchable()
                                    ->required(),

                                Forms\Components\TextInput::make('factor_a_base')
                                    ->label('Factor a base')
                                    ->numeric()
                                    ->minValue(0.000001)
                                    ->step('0.000001')
                                    ->default(1)
                                    ->required(),

                                Forms\Components\TextInput::make('precio_referencia')
                                    ->label('Precio referencia')
                                    ->numeric()
                                    ->step('0.01')
                                    ->prefix('L')
                                    ->nullable(),

                                Forms\Components\Toggle::make('activo')
                                    ->label('Activo')
                                    ->default(true),
                            ])
                            ->columns(4)
                            ->addActionLabel('Agregar presentación'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('nombre')->searchable()->sortable(),

                // Reemplazo de BadgeColumn deprecado
                Tables\Columns\TextColumn::make('tipo')
                    ->badge()
                    ->colors([
                        'primary' => 'huevo',
                        'success' => 'lacteo',
                        'warning' => 'abarroteria',
                    ])
                    ->sortable(),

                Tables\Columns\TextColumn::make('unidadBase.nombre')
                    ->label('Unidad base')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('activo')
                    ->boolean()
                    ->label('Activo'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->label('Creado')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->options([
                        'huevo' => 'Huevo',
                        'lacteo' => 'Lácteo',
                        'abarroteria' => 'Abarrotería',
                    ]),
                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Activo'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // Puedes agregar un RelationManager para bodegas más adelante
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProductos::route('/'),
            'create' => Pages\CreateProducto::route('/create'),
            'edit'   => Pages\EditProducto::route('/{record}/edit'),
        ];
    }
}
