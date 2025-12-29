<?php

namespace App\Filament\Resources\ProductoResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\TextColumn;

class ProductoImagenRelationManager extends RelationManager
{
    protected static string $relationship = 'imagenes';

    public function form(Form $form): Form
    {
        return $form->schema([
            FileUpload::make('path')
                ->label('Imagen')
                ->image()
                ->directory('productos')
                ->imageEditor()
                ->imageResizeMode('cover')
                ->imageCropAspectRatio('1:1')
                ->imageResizeTargetWidth('1024')
                ->imageResizeTargetHeight('1024')
                ->required()
                ->maxSize(2048), // 2MB max
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('path')
                ->label('Imagen')
                ->circular(),

            ToggleColumn::make('activo'),

            TextColumn::make('created_at')
                ->label('Fecha')
                ->dateTime('d/m/Y H:i'),
        ])
        ->headerActions([
            Tables\Actions\CreateAction::make(),
        ])
        ->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ])
        ->defaultSort('orden');
    }
}
