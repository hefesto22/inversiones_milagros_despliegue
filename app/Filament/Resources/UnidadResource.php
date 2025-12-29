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
    protected static ?string $navigationGroup = 'Configuración';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'Unidad de Medida';
    protected static ?string $pluralModelLabel = 'Unidades de Medida';
    protected static ?string $navigationLabel = 'Unidades';

    // =======================================================
    // FORMULARIO
    // =======================================================
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
                                    ->maxLength(100)
                                    ->unique(ignoreRecord: true)
                                    ->placeholder('Ej: Pieza, Cartón 30, Libra'),

                                Forms\Components\TextInput::make('simbolo')
                                    ->maxLength(20)
                                    ->placeholder('Ej: pz, ct30, lb'),

                                Forms\Components\Toggle::make('es_decimal')
                                    ->label('¿Acepta decimales?')
                                    ->helperText('Activa si la unidad puede tener valores decimales (ej: 2.5 libras)')
                                    ->default(false),

                                Forms\Components\Toggle::make('activo')
                                    ->label('Activo')
                                    ->default(true),
                            ]),
                    ]),
            ]);
    }

    // =======================================================
    // TABLA
    // =======================================================
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('simbolo')
                    ->searchable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\IconColumn::make('es_decimal')
                    ->label('Decimales')
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
                    ->label('Fecha de creación')
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

                Tables\Filters\TernaryFilter::make('es_decimal')
                    ->label('Tipo')
                    ->placeholder('Todos')
                    ->trueLabel('Con decimales')
                    ->falseLabel('Enteros'),
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

    // =======================================================
    // RELACIONES
    // =======================================================
    public static function getRelations(): array
    {
        return [];
    }

    // =======================================================
    // PÁGINAS
    // =======================================================
    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUnidads::route('/'),
            'create' => Pages\CreateUnidad::route('/create'),
            'edit'   => Pages\EditUnidad::route('/{record}/edit'),
        ];
    }
}
