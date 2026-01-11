<?php

namespace App\Filament\Resources\ViajeResource\RelationManagers;

use App\Models\ViajeVenta;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Builder;

class VentasRelationManager extends RelationManager
{
    protected static string $relationship = 'ventasRuta';

    protected static ?string $title = 'Ventas del Viaje';

    protected static ?string $modelLabel = 'Venta';

    protected static ?string $pluralModelLabel = 'Ventas';

    protected static ?string $icon = 'heroicon-o-shopping-cart';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('numero_venta')
            ->columns([
                Tables\Columns\TextColumn::make('numero_venta')
                    ->label('No. Venta')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->copyable(),

                Tables\Columns\TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->limit(25)
                    ->tooltip(fn ($record) => $record->cliente?->nombre),

                Tables\Columns\TextColumn::make('fecha_venta')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('tipo_pago')
                    ->label('Pago')
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

                Tables\Columns\TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('impuesto')
                    ->label('ISV')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('descuento')
                    ->label('Descuento')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->color('success'),

                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'borrador' => 'Borrador',
                        'confirmada' => 'Confirmada',
                        'completada' => 'Completada',
                        'cancelada' => 'Cancelada',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'borrador' => 'gray',
                        'confirmada' => 'info',
                        'completada' => 'success',
                        'cancelada' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('detalles_count')
                    ->label('Items')
                    ->counts('detalles')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('userCreador.name')
                    ->label('Vendedor')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options([
                        'borrador' => 'Borrador',
                        'confirmada' => 'Confirmada',
                        'completada' => 'Completada',
                        'cancelada' => 'Cancelada',
                    ]),

                Tables\Filters\SelectFilter::make('tipo_pago')
                    ->options([
                        'contado' => 'Contado',
                        'credito' => 'Crédito',
                    ]),

                Tables\Filters\SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([
                // No permitir crear ventas desde aquí, se hacen desde Punto de Venta
            ])
            ->actions([
                Tables\Actions\Action::make('ver_detalle')
                    ->label('Ver Detalle')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading(fn ($record) => 'Venta: ' . $record->numero_venta)
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn ($record) => view('filament.resources.viaje.ventas-detalle-modal', [
                        'venta' => $record->load(['cliente', 'detalles.producto', 'userCreador']),
                    ])),

                Tables\Actions\Action::make('imprimir')
                    ->label('Imprimir')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn ($record) => route('viaje-venta.imprimir', $record->id))
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('¿Está seguro de cancelar esta venta? Se devolverá el stock al viaje.')
                    ->visible(fn ($record) => in_array($record->estado, ['borrador', 'confirmada', 'completada']))
                    ->action(function ($record) {
                        // Devolver stock a las cargas
                        foreach ($record->detalles as $detalle) {
                            if ($detalle->viaje_carga_id) {
                                $detalle->viajeCarga?->decrement('cantidad_vendida', $detalle->cantidad);
                            }
                        }

                        // Restar del total del viaje
                        $record->viaje->decrement('total_vendido', $record->total);

                        // Cancelar la venta
                        $record->update(['estado' => 'cancelada']);

                        \Filament\Notifications\Notification::make()
                            ->title('Venta cancelada')
                            ->body('El stock ha sido devuelto al viaje.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                // No permitir acciones masivas por seguridad
            ])
            ->defaultSort('fecha_venta', 'desc')
            ->emptyStateHeading('Sin ventas')
            ->emptyStateDescription('No se han registrado ventas en este viaje.')
            ->emptyStateIcon('heroicon-o-shopping-cart');
    }
}