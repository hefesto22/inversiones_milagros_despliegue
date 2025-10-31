<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClienteResource\Pages;
use App\Models\Cliente;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ClienteResource extends Resource
{
    protected static ?string $model = Cliente::class;

    protected static ?string $navigationIcon = 'heroicon-o-user';
    protected static ?string $navigationGroup = 'Gestión';
    protected static ?string $navigationLabel = 'Clientes';
    protected static ?string $pluralModelLabel = 'Clientes';
    protected static ?string $modelLabel = 'Cliente';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos del cliente')
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
                    ->toggleable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
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
            ClienteResource\RelationManagers\PreciosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListClientes::route('/'),
            'create' => Pages\CreateCliente::route('/create'),
            'edit'   => Pages\EditCliente::route('/{record}/edit'),
        ];
    }
}
