<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CamionResource\Pages;
use App\Filament\Resources\CamionResource\RelationManagers\AsignacionesRelationManager;
use App\Models\Camion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CamionResource extends Resource
{
    protected static ?string $model = Camion::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Logística';
    protected static ?string $navigationLabel = 'Camiones';
    protected static ?string $pluralModelLabel = 'Camiones';
    protected static ?string $modelLabel = 'Camión';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos del camión')
                ->columns(12)
                ->schema([
                    Forms\Components\TextInput::make('placa')
                        ->label('Placa')
                        ->required()
                        ->maxLength(20)
                        ->unique(ignoreRecord: true)
                        ->helperText('Ej.: HAA-1234')
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('capacidad_cartones_30')
                        ->label('Capacidad cartones 30')
                        ->numeric()
                        ->minValue(0)
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('capacidad_cartones_15')
                        ->label('Capacidad cartones 15')
                        ->numeric()
                        ->minValue(0)
                        ->columnSpan(4),

                    Forms\Components\Toggle::make('activo')
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
                Tables\Columns\TextColumn::make('placa')
                    ->label('Placa')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('capacidad_cartones_30')
                    ->label('Cap. C30')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('capacidad_cartones_15')
                    ->label('Cap. C15')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('capacidad_total')
                    ->label('Capacidad total')
                    ->state(fn (Camion $r) => (int)($r->capacidad_cartones_30 ?? 0) + (int)($r->capacidad_cartones_15 ?? 0))
                    ->toggleable(),

                Tables\Columns\ToggleColumn::make('activo')
                    ->label('Activo')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Solo activos')
                    ->trueLabel('Activos')->falseLabel('Inactivos')->placeholder('Todos')
                    ->queries(
                        true: fn ($q) => $q->where('activo', true),
                        false: fn ($q) => $q->where('activo', false),
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
            ->defaultSort('placa', 'asc');
    }

    public static function getRelations(): array
    {
        return [
            AsignacionesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCamions::route('/'),
            'create' => Pages\CreateCamion::route('/create'),
            'edit'   => Pages\EditCamion::route('/{record}/edit'),
        ];
    }
}
