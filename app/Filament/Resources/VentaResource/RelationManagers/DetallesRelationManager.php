<?php

namespace App\Filament\Resources\VentaResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class DetallesRelationManager extends RelationManager
{
    protected static string $relationship = 'detalles';

    protected static ?string $title = 'Productos';

    protected static ?string $modelLabel = 'producto';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('producto.nombre')
            ->columns([
                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->weight('bold')
                    ->searchable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('unidad.abreviatura')
                    ->label('Unidad')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('cantidad')
                    ->numeric(decimalPlaces: 2)
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('precio_unitario')
                    ->label('Precio Unit.')
                    ->money('HNL')
                    ->description(function ($record): ?string {
                        if ($record->precio_anterior && $record->precio_anterior != $record->precio_unitario) {
                            $diff = $record->precio_unitario - $record->precio_anterior;
                            $signo = $diff > 0 ? '+' : '';
                            return "Anterior: L " . number_format($record->precio_anterior, 2) . " ({$signo}" . number_format($diff, 2) . ")";
                        }
                        return null;
                    }),

                Tables\Columns\TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('HNL')
                    ->color('gray'),

                Tables\Columns\IconColumn::make('aplica_isv')
                    ->label('ISV')
                    ->boolean()
                    ->trueIcon('heroicon-o-check')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('total_isv')
                    ->label('ISV')
                    ->money('HNL')
                    ->color('warning')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_linea')
                    ->label('Total')
                    ->money('HNL')
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('costo_unitario')
                    ->label('Costo')
                    ->money('HNL')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('ganancia')
                    ->label('Ganancia')
                    ->getStateUsing(fn ($record) => $record->calcularGanancia())
                    ->money('HNL')
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id')
            ->paginated(false)
            ->emptyStateHeading('Sin productos')
            ->emptyStateDescription('Agrega productos a esta venta.')
            ->emptyStateIcon('heroicon-o-cube');
    }
}
