<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoriaResource\Pages;
use App\Filament\Resources\CategoriaResource\RelationManagers\UnidadesRelationManager;
use App\Models\Categoria;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CategoriaResource extends Resource
{
    protected static ?string $model = Categoria::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationGroup = 'Configuración';
    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Categoría';
    protected static ?string $pluralModelLabel = 'Categorías';

    // ========================================
    // FORMULARIO
    // ========================================
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos de la categoria')
                    ->schema([
                        Forms\Components\TextInput::make('nombre')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true)
                            ->placeholder('Ej: Huevo Mediano, Lacteos'),

                        Forms\Components\Toggle::make('activo')
                            ->label('Activo')
                            ->default(true),

                        Forms\Components\Toggle::make('aplica_isv')
                            ->label('Aplica ISV (15%)')
                            ->default(false)
                            ->helperText('Activar si los productos de esta categoria incluyen ISV en el precio de compra')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Configuracion de Reempaque')
                    ->description('Para categorias de productos reempacados (ej: Opoa), seleccione la categoria origen de donde se tomaran los huevos')
                    ->schema([
                        Forms\Components\Select::make('categoria_origen_id')
                            ->label('Categoria Origen (Lote)')
                            ->options(function ($record) {
                                // Mostrar todas las categorias base activas
                                $query = Categoria::where('activo', true)
                                    ->where(function ($q) {
                                        $q->whereNull('categoria_origen_id')
                                          ->orWhereColumn('categoria_origen_id', 'id'); // Incluir las que se referencian a si mismas
                                    });
                                
                                return $query->pluck('nombre', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->placeholder('Ninguna (no usa lotes)')
                            ->helperText('Seleccione de que lote salen los productos. Para categorias como "Huevo Marron" seleccione la misma categoria.')
                            ->columnSpanFull(),

                        Forms\Components\Placeholder::make('info_reempaque')
                            ->label('')
                            ->content(function ($record) {
                                if (!$record) {
                                    return 'Al seleccionar una categoria origen, los productos de esta categoria restaran del lote correspondiente al cargar viajes.';
                                }
                                
                                if ($record->categoria_origen_id) {
                                    $origen = $record->categoriaOrigen;
                                    if ($origen && $origen->id === $record->id) {
                                        return "Los productos de esta categoria restaran de su propio lote de \"{$record->nombre}\" al cargar viajes.";
                                    }
                                    return "Los productos de esta categoria restaran del lote de \"{$origen->nombre}\" al cargar viajes.";
                                }
                                
                                return 'Esta categoria no usa lotes. Los productos se manejan por stock directo en bodega.';
                            })
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(fn ($record) => $record && !$record->categoria_origen_id),
            ]);
    }

    // ========================================
    // TABLA
    // ========================================
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->label('Nombre'),

                Tables\Columns\TextColumn::make('categoriaOrigen.nombre')
                    ->label('Origen (Lote)')
                    ->placeholder('Base')
                    ->badge()
                    ->color(fn ($state) => $state ? 'info' : 'gray'),

                Tables\Columns\IconColumn::make('aplica_isv')
                    ->label('ISV')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\ToggleColumn::make('activo')
                    ->label('Activo'),

                Tables\Columns\TextColumn::make('creador.name')
                    ->label('Creado por')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha de creacion')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),

                Tables\Filters\TernaryFilter::make('aplica_isv')
                    ->label('ISV')
                    ->placeholder('Todos')
                    ->trueLabel('Con ISV')
                    ->falseLabel('Sin ISV'),

                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options([
                        'base' => 'Categorias Base',
                        'derivada' => 'Categorias Derivadas (Reempaque)',
                    ])
                    ->query(function ($query, array $data) {
                        if ($data['value'] === 'base') {
                            return $query->whereNull('categoria_origen_id');
                        }
                        if ($data['value'] === 'derivada') {
                            return $query->whereNotNull('categoria_origen_id');
                        }
                        return $query;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (Categoria $record) {
                        // Prevenir eliminacion si tiene categorias derivadas
                        if ($record->categoriasDerivadas()->count() > 0) {
                            throw new \Exception('No se puede eliminar una categoria que tiene categorias derivadas.');
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('nombre');
    }

    // ========================================
    // RELACIONES
    // ========================================
    public static function getRelations(): array
    {
        return [
            UnidadesRelationManager::class,
        ];
    }

    // ========================================
    // PAGINAS
    // ========================================
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategorias::route('/'),
            'create' => Pages\CreateCategoria::route('/create'),
            'edit' => Pages\EditCategoria::route('/{record}/edit'),
        ];
    }
}