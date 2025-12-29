<?php

namespace App\Filament\Resources\VentaResource\RelationManagers;

use App\Models\VentaPago;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PagosRelationManager extends RelationManager
{
    protected static string $relationship = 'pagos';

    protected static ?string $title = 'Historial de Pagos';

    protected static ?string $modelLabel = 'pago';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\TextInput::make('monto')
                            ->label('Monto')
                            ->required()
                            ->numeric()
                            ->prefix('L')
                            ->minValue(0.01)
                            ->maxValue(fn () => $this->getOwnerRecord()->saldo_pendiente)
                            ->default(fn () => $this->getOwnerRecord()->saldo_pendiente)
                            ->helperText(fn () => 'Saldo pendiente: L ' . number_format($this->getOwnerRecord()->saldo_pendiente, 2)),

                        Forms\Components\Select::make('metodo_pago')
                            ->label('Método de Pago')
                            ->options([
                                'efectivo' => 'Efectivo',
                                'transferencia' => 'Transferencia',
                                'tarjeta' => 'Tarjeta',
                                'cheque' => 'Cheque',
                            ])
                            ->default('efectivo')
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('referencia')
                            ->label('Referencia')
                            ->maxLength(100)
                            ->placeholder('No. de transferencia, cheque, etc.')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('nota')
                            ->label('Nota')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn ($record) => $record->created_at->diffForHumans()),

                Tables\Columns\TextColumn::make('monto')
                    ->label('Monto')
                    ->money('HNL')
                    ->weight('bold')
                    ->color('success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('metodo_pago')
                    ->label('Método')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'efectivo' => 'Efectivo',
                        'transferencia' => 'Transferencia',
                        'tarjeta' => 'Tarjeta',
                        'cheque' => 'Cheque',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'efectivo' => 'success',
                        'transferencia' => 'info',
                        'tarjeta' => 'primary',
                        'cheque' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('referencia')
                    ->label('Referencia')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('nota')
                    ->label('Nota')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('creador.name')
                    ->label('Registrado por')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('metodo_pago')
                    ->label('Método')
                    ->options([
                        'efectivo' => 'Efectivo',
                        'transferencia' => 'Transferencia',
                        'tarjeta' => 'Tarjeta',
                        'cheque' => 'Cheque',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Registrar Pago')
                    ->icon('heroicon-o-banknotes')
                    ->visible(fn () => $this->getOwnerRecord()->saldo_pendiente > 0)
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = Auth::id();
                        return $data;
                    })
                    ->after(function () {
                        $venta = $this->getOwnerRecord();

                        // Recalcular saldo
                        $totalPagado = $venta->pagos()->sum('monto');
                        $venta->monto_pagado = $totalPagado;
                        $venta->saldo_pendiente = $venta->total - $totalPagado;

                        // Actualizar estado de pago
                        if ($venta->saldo_pendiente <= 0) {
                            $venta->estado_pago = 'pagado';
                            $venta->saldo_pendiente = 0;

                            // Si estaba pendiente_pago, cambiar a pagada
                            if ($venta->estado === 'pendiente_pago') {
                                $venta->estado = 'pagada';
                            }
                        } else {
                            $venta->estado_pago = 'parcial';
                        }

                        $venta->save();

                        // Actualizar saldo del cliente si es crédito
                        if ($venta->tipo_pago === 'credito') {
                            $venta->cliente->saldo_pendiente = $venta->cliente->ventas()
                                ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
                                ->where('tipo_pago', 'credito')
                                ->sum('saldo_pendiente');
                            $venta->cliente->save();
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Pago registrado')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\DeleteAction::make()
                    ->before(function ($record) {
                        $venta = $this->getOwnerRecord();

                        // Revertir el pago
                        $venta->monto_pagado -= $record->monto;
                        $venta->saldo_pendiente += $record->monto;

                        // Actualizar estado
                        if ($venta->monto_pagado <= 0) {
                            $venta->estado_pago = 'pendiente';
                            if ($venta->estado === 'pagada') {
                                $venta->estado = 'pendiente_pago';
                            }
                        } else {
                            $venta->estado_pago = 'parcial';
                        }

                        $venta->save();

                        // Actualizar saldo del cliente
                        if ($venta->tipo_pago === 'credito') {
                            $venta->cliente->saldo_pendiente += $record->monto;
                            $venta->cliente->save();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Sin pagos registrados')
            ->emptyStateDescription(fn () =>
                $this->getOwnerRecord()->saldo_pendiente > 0
                    ? 'Registra el primer pago para esta venta.'
                    : 'Esta venta ya está pagada.'
            )
            ->emptyStateIcon('heroicon-o-banknotes');
    }
}
