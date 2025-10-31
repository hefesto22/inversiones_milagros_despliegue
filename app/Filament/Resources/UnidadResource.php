<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UnidadResource\Pages;
use App\Models\Unidad;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UnidadResource extends Resource
{
    protected static ?string $model = Unidad::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';
    protected static ?string $navigationLabel = 'Unidades';
    protected static ?string $pluralLabel = 'Unidades';
    protected static ?string $modelLabel = 'Unidad';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Ej: pieza, libra, litro'),

                Forms\Components\TextInput::make('simbolo')
                    ->label('Símbolo')
                    ->maxLength(50)
                    ->placeholder('Ej: pz, lb, L'),

                Forms\Components\Toggle::make('es_decimal')
                    ->label('¿Acepta decimales?')
                    ->default(false),

                // Mostrar solo lectura de quién creó / actualizó
                Forms\Components\Placeholder::make('creado_por')
                    ->label('Creado por')
                    ->content(fn (?Unidad $record) => $record?->user?->name ?? '—')
                    ->hidden(fn (string $operation) => $operation === 'create'),

                Forms\Components\Placeholder::make('actualizado_por')
                    ->label('Última actualización por')
                    ->content(fn (?Unidad $record) => $record?->userUpdate?->name ?? '—')
                    ->hidden(fn (string $operation) => $operation === 'create'),

                Forms\Components\Placeholder::make('created_at')
                    ->label('Creado')
                    ->content(fn (?Unidad $record) => optional($record?->created_at)->format('d/m/Y H:i'))
                    ->hidden(fn (string $operation) => $operation === 'create'),

                Forms\Components\Placeholder::make('updated_at')
                    ->label('Actualizado')
                    ->content(fn (?Unidad $record) => optional($record?->updated_at)->format('d/m/Y H:i'))
                    ->hidden(fn (string $operation) => $operation === 'create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),

                Tables\Columns\TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('simbolo')
                    ->label('Símbolo')
                    ->sortable(),

                Tables\Columns\IconColumn::make('es_decimal')
                    ->label('Decimales')
                    ->boolean(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Creado por')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('userUpdate.name')
                    ->label('Actualizado por')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnidads::route('/'),
            'create' => Pages\CreateUnidad::route('/create'),
            'edit' => Pages\EditUnidad::route('/{record}/edit'),
        ];
    }
}
