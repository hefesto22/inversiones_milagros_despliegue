<?php

namespace App\Filament\Resources\ProveedorResource\RelationManagers;

use App\Models\Producto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ProveedorProductoRelationManager extends RelationManager
{
    protected static string $relationship = 'preciosProveedor';

    protected static ?string $title = 'Precios de Compra';
    protected static ?string $modelLabel = 'Precio de Compra';
    protected static ?string $pluralModelLabel = 'Precios de Compra';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('producto_id')
                ->label('Producto')
                ->options(
                    Producto::query()
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                )
                ->searchable()
                ->required()
                ->columnSpanFull(),

            Forms\Components\TextInput::make('ultimo_precio_compra')
                ->label('Último Precio Compra')
                ->required()
                ->numeric()
                ->minValue(0)
                ->step('0.0001'),

            Forms\Components\DateTimePicker::make('actualizado_en')
                ->label('Actualizado en')
                ->default(now()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('producto.nombre')
                ->label('Producto')
                ->sortable()
                ->searchable()
                ->weight('bold'),

            Tables\Columns\TextColumn::make('ultimo_precio_compra')
                ->label('Último Precio')
                ->money('HNL', true)
                ->sortable(),

            Tables\Columns\TextColumn::make('actualizado_en')
                ->label('Actualizado')
                ->dateTime('d/m/Y H:i')
                ->sortable(),
        ])
        ->filters([
            Tables\Filters\Filter::make('recientes')
                ->label('Actualizados Recientes')
                ->query(fn ($query) =>
                    $query->where('actualizado_en', '>=', now()->subDays(7))
                ),
        ])
        ->headerActions([
            Tables\Actions\CreateAction::make()
                ->label('Añadir precio'),
        ])
        ->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ])
        ->bulkActions([
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(),
            ]),
        ]);
    }
}
