<?php

namespace App\Filament\Resources\ClienteResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class VentasRelationManager extends RelationManager
{
    protected static string $relationship = 'ventas';

    protected static ?string $title = 'Historial de Ventas';

    protected static ?string $modelLabel = 'venta';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('numero_venta')
            ->columns([
                Tables\Columns\TextColumn::make('numero_venta')
                    ->label('No. Venta')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('Número copiado'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn ($record) => $record->created_at->diffForHumans()),

                Tables\Columns\TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->badge()
                    ->color('info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('tipo_pago')
                    ->label('Tipo Pago')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'efectivo' => 'Efectivo',
                        'transferencia' => 'Transferencia',
                        'tarjeta' => 'Tarjeta',
                        'credito' => 'Crédito',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'efectivo' => 'success',
                        'transferencia' => 'info',
                        'tarjeta' => 'primary',
                        'credito' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total_isv')
                    ->label('ISV')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('monto_pagado')
                    ->label('Pagado')
                    ->money('HNL')
                    ->sortable()
                    ->color('info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('saldo_pendiente')
                    ->label('Saldo')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->color(fn ($state) => match (true) {
                        $state <= 0 => 'success',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('fecha_vencimiento')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(function ($record) {
                        if (!$record->fecha_vencimiento) return 'gray';
                        if ($record->saldo_pendiente <= 0) return 'success';
                        if ($record->fecha_vencimiento->isPast()) return 'danger';
                        if ($record->fecha_vencimiento->diffInDays(now()) <= 3) return 'warning';
                        return 'gray';
                    })
                    ->description(function ($record) {
                        if (!$record->fecha_vencimiento || $record->saldo_pendiente <= 0) return null;
                        if ($record->fecha_vencimiento->isPast()) {
                            return '⚠️ Vencida hace ' . $record->fecha_vencimiento->diffForHumans();
                        }
                        return 'En ' . $record->fecha_vencimiento->diffForHumans();
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'borrador' => 'Borrador',
                        'completada' => 'Completada',
                        'pendiente_pago' => 'Pend. Pago',
                        'pagada' => 'Pagada',
                        'cancelada' => 'Cancelada',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'borrador' => 'gray',
                        'completada' => 'success',
                        'pendiente_pago' => 'warning',
                        'pagada' => 'success',
                        'cancelada' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('estado_pago')
                    ->label('Pago')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pendiente' => 'Pendiente',
                        'parcial' => 'Parcial',
                        'pagado' => 'Pagado',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'pendiente' => 'danger',
                        'parcial' => 'warning',
                        'pagado' => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options([
                        'borrador' => 'Borrador',
                        'completada' => 'Completada',
                        'pendiente_pago' => 'Pendiente de Pago',
                        'pagada' => 'Pagada',
                        'cancelada' => 'Cancelada',
                    ]),

                Tables\Filters\SelectFilter::make('estado_pago')
                    ->label('Estado de Pago')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'parcial' => 'Parcial',
                        'pagado' => 'Pagado',
                    ]),

                Tables\Filters\SelectFilter::make('tipo_pago')
                    ->label('Tipo de Pago')
                    ->options([
                        'efectivo' => 'Efectivo',
                        'transferencia' => 'Transferencia',
                        'tarjeta' => 'Tarjeta',
                        'credito' => 'Crédito',
                    ]),

                Tables\Filters\Filter::make('con_saldo')
                    ->label('Con saldo pendiente')
                    ->query(fn ($query) => $query->where('saldo_pendiente', '>', 0))
                    ->toggle(),

                Tables\Filters\Filter::make('vencidas')
                    ->label('Vencidas')
                    ->query(fn ($query) => $query
                        ->where('saldo_pendiente', '>', 0)
                        ->whereNotNull('fecha_vencimiento')
                        ->where('fecha_vencimiento', '<', now())
                    )
                    ->toggle(),

                Tables\Filters\Filter::make('este_mes')
                    ->label('Este mes')
                    ->query(fn ($query) => $query->whereMonth('created_at', now()->month)
                        ->whereYear('created_at', now()->year)
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('ver')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn ($record) => route('filament.admin.resources.ventas.view', ['record' => $record])),

                Tables\Actions\Action::make('registrar_pago')
                    ->label('Pago')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn ($record) => $record->saldo_pendiente > 0)
                    ->url(fn ($record) => route('filament.admin.resources.ventas.edit', ['record' => $record])),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->emptyStateHeading('Sin ventas registradas')
            ->emptyStateDescription('Las ventas realizadas a este cliente aparecerán aquí.')
            ->emptyStateIcon('heroicon-o-shopping-cart');
    }
}
