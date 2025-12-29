<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BodegaResource\Pages;
use App\Filament\Resources\BodegaResource\RelationManagers\UsuariosRelationManager;
use App\Models\Bodega;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BodegaResource extends Resource
{
    protected static ?string $model = Bodega::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationGroup = 'Configuración';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = 'Bodega';
    protected static ?string $pluralModelLabel = 'Bodegas';
    protected static ?string $navigationLabel = 'Bodegas';

    // ======================================================
    // FORMULARIO
    // ======================================================
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('nombre')
                                    ->required()
                                    ->maxLength(150)
                                    ->placeholder('Ej: Bodega Central, Norte, Sucursal #1'),

                                Forms\Components\TextInput::make('codigo')
                                    ->maxLength(20)
                                    ->unique(ignoreRecord: true)
                                    ->placeholder('Ej: BOD-001'),

                                Forms\Components\TextInput::make('ubicacion')
                                    ->maxLength(255)
                                    ->placeholder('Ubicación física o dirección'),

                                Forms\Components\Toggle::make('activo')
                                    ->label('Activo')
                                    ->default(true),
                            ]),
                    ]),
            ]);
    }

    // ======================================================
    // TABLA
    // ======================================================
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('codigo')
                    ->searchable()
                    ->label('Código')
                    ->color('info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ubicacion')
                    ->toggleable()
                    ->limit(30),

                Tables\Columns\ToggleColumn::make('activo')
                    ->label('Activo'),

                Tables\Columns\TextColumn::make('creador.name')
                    ->label('Creado por')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha de creación')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('nombre');
    }

    // ======================================================
    // RELACIONES (AQUÍ SE AGREGA EL RELATION MANAGER)
    // ======================================================
    public static function getRelations(): array
    {
        return [
            UsuariosRelationManager::class,
        ];
    }

    // ======================================================
    // PÁGINAS
    // ======================================================
    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBodegas::route('/'),
            'create' => Pages\CreateBodega::route('/create'),
            'edit'   => Pages\EditBodega::route('/{record}/edit'),
        ];
    }
}
