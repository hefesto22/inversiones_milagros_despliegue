<?php

namespace App\Filament\Resources\ViajeResource\RelationManagers;

use App\Models\ViajeComisionDetalle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\FontWeight;

class ComisionesRelationManager extends RelationManager
{
    protected static string $relationship = 'comisionesDetalle';

    protected static ?string $title = 'Comisiones del Viaje';

    protected static ?string $modelLabel = 'Comisión';

    protected static ?string $pluralModelLabel = 'Comisiones';

    protected static ?string $icon = 'heroicon-o-currency-dollar';

    public function isReadOnly(): bool
    {
        return true; // Las comisiones se calculan automáticamente
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('producto.nombre')
            ->columns([
                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold),

                Tables\Columns\TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('precio_vendido')
                    ->label('Precio Vendido')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('precio_sugerido')
                    ->label('Precio Sugerido')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('costo')
                    ->label('Costo')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('tipo_comision')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'normal' => 'Normal',
                        'reducida' => 'Reducida',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'normal' => 'success',
                        'reducida' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('comision_unitaria')
                    ->label('Comisión/U')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('comision_total')
                    ->label('Comisión Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->color('success'),

                Tables\Columns\TextColumn::make('venta.numero_venta')
                    ->label('No. Venta')
                    ->badge()
                    ->color('info')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo_comision')
                    ->label('Tipo Comisión')
                    ->options([
                        'normal' => 'Normal',
                        'reducida' => 'Reducida',
                    ]),

                Tables\Filters\SelectFilter::make('producto_id')
                    ->label('Producto')
                    ->relationship('producto', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([
                // Sin acciones - las comisiones se calculan automáticamente
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // Sin acciones bulk
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Sin comisiones calculadas')
            ->emptyStateDescription('Las comisiones se calculan al liquidar el viaje.')
            ->emptyStateIcon('heroicon-o-calculator')
            ->poll('30s'); // Actualizar cada 30 segundos
    }
}