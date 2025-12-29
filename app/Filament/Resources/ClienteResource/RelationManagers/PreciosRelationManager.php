<?php

namespace App\Filament\Resources\ClienteResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PreciosRelationManager extends RelationManager
{
    protected static string $relationship = 'preciosCliente';

    protected static ?string $title = 'Historial de Precios';

    protected static ?string $modelLabel = 'precio';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('producto.nombre')
            ->columns([
                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->sortable()
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('producto.unidad.nombre')
                    ->label('Unidad')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('ultimo_precio_venta')
                    ->label('Último Precio')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('ultimo_precio_con_isv')
                    ->label('Precio + ISV')
                    ->money('HNL')
                    ->sortable()
                    ->color('warning')
                    ->description('Con 15% ISV'),

                Tables\Columns\TextColumn::make('cantidad_ultima_venta')
                    ->label('Últ. Cantidad')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('fecha_ultima_venta')
                    ->label('Última Venta')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn ($record) => $record->fecha_ultima_venta
                        ? $record->fecha_ultima_venta->diffForHumans()
                        : null
                    ),

                Tables\Columns\TextColumn::make('total_ventas')
                    ->label('Veces Vendido')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('cantidad_total_vendida')
                    ->label('Total Vendido')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('con_ventas_recientes')
                    ->label('Ventas últimos 30 días')
                    ->query(fn ($query) => $query->where('fecha_ultima_venta', '>=', now()->subDays(30))),

                Tables\Filters\Filter::make('productos_frecuentes')
                    ->label('Productos frecuentes (5+ ventas)')
                    ->query(fn ($query) => $query->where('total_ventas', '>=', 5)),
            ])
            ->actions([
                Tables\Actions\Action::make('ver_producto')
                    ->label('Ver Producto')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn ($record) => route('filament.admin.resources.productos.edit', ['record' => $record->producto_id])),
            ])
            ->defaultSort('fecha_ultima_venta', 'desc')
            ->emptyStateHeading('Sin historial de precios')
            ->emptyStateDescription('Cuando se realicen ventas a este cliente, aquí aparecerá el historial de precios por producto.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }
}
