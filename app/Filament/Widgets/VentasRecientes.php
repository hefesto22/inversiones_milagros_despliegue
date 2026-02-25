<?php

namespace App\Filament\Widgets;

use App\Models\ViajeVenta;
use App\Filament\Widgets\Concerns\HasDateFilters;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

class VentasRecientes extends BaseWidget
{
    use HasWidgetShield;
    use HasDateFilters;

    protected static ?string $heading = 'Ventas del Período';
    
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';
    protected static bool $isLazy = false;

    public function getTableHeading(): ?string
    {
        $periodoLabel = $this->getPeriodLabel();
        return "Ventas Recientes ({$periodoLabel})";
    }

    public function table(Table $table): Table
    {
        $dateRange = $this->getFilteredDateRange();

        return $table
            ->query(
                ViajeVenta::query()
                    ->where('estado', 'completada')
                    ->whereBetween('fecha_venta', [$dateRange['inicio'], $dateRange['fin']])
                    ->latest('fecha_venta')
            )
            ->columns([
                Tables\Columns\TextColumn::make('numero_venta')
                    ->label('No. Venta')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('fecha_venta')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->limit(25),

                Tables\Columns\TextColumn::make('tipo_pago')
                    ->label('Pago')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'contado' => 'success',
                        'credito' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('userCreador.name')
                    ->label('Vendedor')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('fecha_venta', 'desc')
            ->striped()
            ->paginated([10, 25, 50]);
    }
}