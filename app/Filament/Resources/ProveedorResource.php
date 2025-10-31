<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProveedorResource\Pages;
use App\Models\Proveedor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProveedorResource extends Resource
{
    protected static ?string $model = Proveedor::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationGroup = 'Gestión';
    protected static ?string $navigationLabel = 'Proveedores';
    protected static ?string $pluralModelLabel = 'Proveedores';
    protected static ?string $modelLabel = 'Proveedor';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos del proveedor')
                ->columns(12)
                ->schema([
                    Forms\Components\TextInput::make('nombre')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(120)
                        ->unique(ignoreRecord: true)
                        ->columnSpan(6),

                    Forms\Components\TextInput::make('rtn')
                        ->label('RTN')
                        ->placeholder('Opcional')
                        ->maxLength(20)
                        ->columnSpan(3),

                    Forms\Components\TextInput::make('telefono')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(20)
                        ->columnSpan(3),

                    Forms\Components\Textarea::make('direccion')
                        ->label('Dirección')
                        ->rows(3)
                        ->maxLength(255)
                        ->columnSpan(9),

                    Forms\Components\Toggle::make('estado')
                        ->label('Activo')
                        ->default(true)
                        ->inline(false)
                        ->columnSpan(3),
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
                Tables\Columns\TextColumn::make('rtn')
                    ->label('RTN')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('direccion')
                    ->label('Dirección')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ToggleColumn::make('estado')
                    ->label('Activo')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('estado')
                    ->label('Solo activos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos')
                    ->placeholder('Todos')
                    ->queries(
                        true: fn ($q) => $q->where('estado', true),
                        false: fn ($q) => $q->where('estado', false),
                        blank: fn ($q) => $q
                    ),
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
            ->defaultSort('nombre', 'asc');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['nombre', 'rtn', 'telefono', 'direccion'];
    }

    public static function getRelations(): array
    {
        return [
            ProveedorResource\RelationManagers\PreciosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProveedors::route('/'),
            'create' => Pages\CreateProveedor::route('/create'),
            'edit'   => Pages\EditProveedor::route('/{record}/edit'),
        ];
    }
}
