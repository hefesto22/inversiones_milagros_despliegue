<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChoferCamionAsignacionResource\Pages;
use App\Models\ChoferCamionAsignacion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ChoferCamionAsignacionResource extends Resource
{
    protected static ?string $model = ChoferCamionAsignacion::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Logística';
    protected static ?string $navigationLabel = 'Asignaciones';
    protected static ?string $pluralModelLabel = 'Asignaciones';
    protected static ?string $modelLabel = 'Asignación Chofer–Camión';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Asignación')
                ->columns(12)
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('Chofer')
                        ->relationship('chofer', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(6),

                    Forms\Components\Select::make('camion_id')
                        ->label('Camión')
                        ->relationship('camion', 'placa')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(6),

                    Forms\Components\DatePicker::make('vigente_desde')
                        ->label('Vigente desde')
                        ->required()
                        ->columnSpan(4),

                    Forms\Components\DatePicker::make('vigente_hasta')
                        ->label('Vigente hasta')
                        ->rule('after_or_equal:vigente_desde')
                        ->helperText('Déjalo vacío si sigue vigente.')
                        ->columnSpan(4),

                    Forms\Components\Toggle::make('activo')
                        ->label('Activo')
                        ->default(true)
                        ->inline(false)
                        ->columnSpan(4),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('chofer.name')
                    ->label('Chofer')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('camion.placa')
                    ->label('Camión')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('vigente_desde')
                    ->label('Desde')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('vigente_hasta')
                    ->label('Hasta')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),

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

                Tables\Filters\SelectFilter::make('camion_id')
                    ->label('Camión')
                    ->relationship('camion', 'placa'),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Chofer')
                    ->relationship('chofer', 'name'),
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
            ->defaultSort('vigente_desde', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListChoferCamionAsignacions::route('/'),
            'create' => Pages\CreateChoferCamionAsignacion::route('/create'),
            'edit'   => Pages\EditChoferCamionAsignacion::route('/{record}/edit'),
        ];
    }
}
