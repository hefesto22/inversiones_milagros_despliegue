<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BodegaResource\Pages;
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
    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Bodega';
    protected static ?string $pluralModelLabel = 'Bodegas';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Información de la bodega')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('nombre')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(150),

                    Forms\Components\TextInput::make('ubicacion')
                        ->label('Ubicación')
                        ->maxLength(255),

                    Forms\Components\Toggle::make('activo')
                        ->label('Activa')
                        ->default(true)
                        ->inline(false)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Usuarios asignados')
                ->description('Selecciona los usuarios que trabajan en esta bodega (sin importar el rol).')
                ->schema([
                    Forms\Components\Select::make('usuarios')
                        ->label('Usuarios')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->relationship('usuarios', 'name') // usa el campo "name" en users
                        ->helperText('Estos usuarios tendrán esta bodega como su “casa de trabajo”.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('ubicacion')
                    ->label('Ubicación')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('activo')
                    ->label('Activa')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('usuarios_count')
                    ->counts('usuarios')
                    ->label('Usuarios asignados')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizada')
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(), // Filament Shield controlará permisos
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('nombre');
    }

    public static function getRelations(): array
    {
        return [
            // Si más adelante deseas gestionar usuarios desde la bodega:
            // BodegaResource\RelationManagers\UsuariosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBodegas::route('/'),
            'create' => Pages\CreateBodega::route('/create'),
            'edit'   => Pages\EditBodega::route('/{record}/edit'),
        ];
    }
}
