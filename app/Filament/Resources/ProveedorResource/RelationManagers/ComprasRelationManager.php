<?php

namespace App\Filament\Resources\ProveedorResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ComprasRelationManager extends RelationManager
{
    protected static string $relationship = 'compras';

    protected static ?string $title = 'Historial de Compras';

    protected static ?string $modelLabel = 'compra';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('No. Compra')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state) => '#' . str_pad($state, 6, '0', STR_PAD_LEFT)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('tipo_pago')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'contado' => 'Contado',
                        'credito' => 'Crédito',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'contado' => 'success',
                        'credito' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('interes_credito')
                    ->label('Interés')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'borrador' => 'Borrador',
                        'ordenada' => 'Ordenada',
                        'recibida_pagada' => 'Recibida y Pagada ✅',
                        'recibida_pendiente_pago' => 'Recibida - Debo Pagar 📦',
                        'por_recibir_pagada' => 'Pagada - Falta Recibir 💰',
                        'por_recibir_pendiente_pago' => 'Pendiente Todo ⏳',
                        'cancelada' => 'Cancelada ❌',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'borrador' => 'gray',
                        'ordenada' => 'info',
                        'recibida_pagada' => 'success',
                        'recibida_pendiente_pago' => 'warning',
                        'por_recibir_pagada' => 'info',
                        'por_recibir_pendiente_pago' => 'danger',
                        'cancelada' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options([
                        'borrador' => 'Borrador',
                        'ordenada' => 'Ordenada',
                        'recibida_pagada' => 'Recibida y Pagada ✅',
                        'recibida_pendiente_pago' => 'Recibida - Debo Pagar 📦',
                        'por_recibir_pagada' => 'Pagada - Falta Recibir 💰',
                        'por_recibir_pendiente_pago' => 'Pendiente Todo ⏳',
                        'cancelada' => 'Cancelada ❌',
                    ]),

                Tables\Filters\SelectFilter::make('tipo_pago')
                    ->label('Tipo de Pago')
                    ->options([
                        'contado' => 'Contado',
                        'credito' => 'Crédito',
                    ]),

                Tables\Filters\Filter::make('pendiente_pago')
                    ->label('Pendiente de Pago')
                    ->query(fn ($query) => $query->whereIn('estado', [
                        'recibida_pendiente_pago',
                        'por_recibir_pendiente_pago'
                    ])),

                Tables\Filters\Filter::make('pendiente_recibir')
                    ->label('Pendiente de Recibir')
                    ->query(fn ($query) => $query->whereIn('estado', [
                        'ordenada',
                        'por_recibir_pagada',
                        'por_recibir_pendiente_pago'
                    ])),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }
}
