<?php

namespace App\Filament\Resources\CamionResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AsignacionesRelationManager extends RelationManager
{
    protected static string $relationship = 'asignaciones'; // Camion::asignaciones()

    protected static ?string $title = 'Asignaciones de chofer';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')
                ->label('Chofer')
                ->relationship('chofer', 'name')
                ->searchable()
                ->preload()
                ->required(),

            Forms\Components\DatePicker::make('vigente_desde')
                ->label('Vigente desde')
                ->required(),

            Forms\Components\DatePicker::make('vigente_hasta')
                ->label('Vigente hasta')
                ->rule('after_or_equal:vigente_desde')
                ->helperText('Déjalo vacío si sigue vigente.'),

            Forms\Components\Toggle::make('activo')
                ->label('Activo')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('chofer.name')
                    ->label('Chofer')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('vigente_desde')
                    ->label('Desde')
                    ->date(),

                Tables\Columns\TextColumn::make('vigente_hasta')
                    ->label('Hasta')
                    ->date()
                    ->placeholder('—'),

                Tables\Columns\ToggleColumn::make('activo')
                    ->label('Activo'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Nueva asignación'),
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
}
