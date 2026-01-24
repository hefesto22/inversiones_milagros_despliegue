<?php

namespace App\Filament\Resources\VentaResource\Pages;

use App\Filament\Resources\VentaResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class ViewVenta extends ViewRecord
{
    protected static string $resource = VentaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // =====================================================
            // IMPRIMIR COTIZACIÓN - Solo en borrador
            // =====================================================
            Actions\ActionGroup::make([
                Actions\Action::make('ver_cotizacion')
                    ->label('Ver Cotización')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn () => route('pdf.cotizacion', $this->record))
                    ->openUrlInNewTab(),

                Actions\Action::make('descargar_cotizacion')
                    ->label('Descargar PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->url(fn () => route('pdf.cotizacion.download', $this->record))
                    ->openUrlInNewTab(),
            ])
            ->label('Imprimir Cotización')
            ->icon('heroicon-o-printer')
            ->color('info')
            ->button()
            ->visible(fn () => $this->record->estado === 'borrador'),

            // =====================================================
            // EDITAR - Solo en borrador sin pagos
            // =====================================================
            Actions\EditAction::make()
                ->visible(fn () =>
                    $this->record->estado === 'borrador' &&
                    $this->record->monto_pagado <= 0
                ),

            // =====================================================
            // PROCESAR VENTA / REGISTRAR PAGO
            // =====================================================
            Actions\Action::make('registrar_pago')
                ->label(fn () =>
                    $this->record->estado === 'borrador' ? 'Procesar Venta' : 'Registrar Pago'
                )
                ->icon(fn () =>
                    $this->record->estado === 'borrador' ? 'heroicon-o-check-circle' : 'heroicon-o-banknotes'
                )
                ->color('success')
                ->visible(fn () => 
                    $this->record->estado !== 'cancelada' && 
                    $this->record->saldo_pendiente > 0
                )
                ->form([
                    // Advertencia si es primer pago (borrador)
                    Forms\Components\Placeholder::make('info_venta')
                        ->label('')
                        ->content(function () {
                            if ($this->record->estado === 'borrador') {
                                return new HtmlString("
                                    <div class='rounded-lg bg-blue-50 dark:bg-blue-900/20 p-4 mb-2'>
                                        <p class='text-sm font-semibold text-blue-800 dark:text-blue-200'>
                                            📦 Al procesar esta venta:
                                        </p>
                                        <ul class='text-sm text-blue-700 dark:text-blue-300 mt-2 list-disc list-inside'>
                                            <li>Se generará el número de venta oficial</li>
                                            <li>Se descontará el stock de los productos</li>
                                            <li>Ya no podrá editarse la venta</li>
                                            <li>La cotización se convertirá en venta confirmada</li>
                                        </ul>
                                    </div>
                                ");
                            }
                            return '';
                        }),

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Placeholder::make('total_venta')
                            ->label('Total Venta')
                            ->content(fn () => 'L ' . number_format($this->record->total, 2)),

                        Forms\Components\Placeholder::make('saldo_pendiente')
                            ->label('Saldo Pendiente')
                            ->content(fn () => new HtmlString(
                                '<span class="text-lg font-bold text-danger-600">L ' .
                                number_format($this->record->saldo_pendiente, 2) .
                                '</span>'
                            )),
                    ]),

                    Forms\Components\TextInput::make('monto')
                        ->label('Monto a Pagar')
                        ->required()
                        ->numeric()
                        ->prefix('L')
                        ->minValue(0.01)
                        ->maxValue(fn () => $this->record->saldo_pendiente)
                        ->default(fn () => $this->record->saldo_pendiente)
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            $saldo = $this->record->saldo_pendiente;
                            $monto = floatval($state ?? 0);

                            if ($monto < $saldo) {
                                $restante = $saldo - $monto;
                                $set('info_pago', new HtmlString(
                                    '<span class="text-warning-600">⚠️ Pago parcial - Quedará saldo de L ' .
                                    number_format($restante, 2) . '</span>'
                                ));
                            } else {
                                $set('info_pago', new HtmlString(
                                    '<span class="text-success-600">✅ Pago completo - Saldo quedará en L 0.00</span>'
                                ));
                            }
                        }),

                    Forms\Components\Placeholder::make('info_pago')
                        ->label('')
                        ->content(fn ($get) => $get('info_pago') ?? new HtmlString(
                            '<span class="text-success-600">✅ Pago completo - Saldo quedará en L 0.00</span>'
                        )),

                    Forms\Components\Select::make('metodo_pago')
                        ->label('Método de Pago')
                        ->options([
                            'efectivo' => 'Efectivo',
                            'transferencia' => 'Transferencia',
                            'tarjeta' => 'Tarjeta',
                            'cheque' => 'Cheque',
                        ])
                        ->default('efectivo')
                        ->required(),

                    Forms\Components\TextInput::make('referencia')
                        ->label('Referencia')
                        ->placeholder('No. transferencia, cheque, etc.')
                        ->maxLength(100),

                    Forms\Components\Textarea::make('nota')
                        ->label('Nota')
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    $esPrimerPago = $this->record->estado === 'borrador';

                    // Si es el primer pago, completar la venta primero
                    if ($esPrimerPago) {
                        try {
                            $this->record->completar();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Error al procesar venta')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                            return;
                        }
                    }

                    // Registrar el pago
                    $this->record->registrarPago(
                        $data['monto'],
                        $data['metodo_pago'],
                        $data['referencia'] ?? null,
                        $data['nota'] ?? null
                    );

                    // Refrescar datos
                    $this->refreshFormData([
                        'numero_venta',
                        'estado',
                        'estado_pago',
                        'saldo_pendiente',
                        'monto_pagado'
                    ]);

                    $mensaje = $esPrimerPago
                        ? 'Venta procesada - No. ' . $this->record->numero_venta
                        : 'Pago registrado';

                    \Filament\Notifications\Notification::make()
                        ->title($mensaje)
                        ->body('Pago: L ' . number_format($data['monto'], 2) .
                            ($this->record->saldo_pendiente > 0
                                ? ' | Saldo restante: L ' . number_format($this->record->saldo_pendiente, 2)
                                : ' | ✅ Completamente pagado'))
                        ->success()
                        ->actions([
                            \Filament\Notifications\Actions\Action::make('imprimir')
                                ->label('Imprimir Factura')
                                ->url(route('venta.imprimir', $this->record))
                                ->openUrlInNewTab(),
                        ])
                        ->persistent()
                        ->send();
                }),

            // =====================================================
            // IMPRIMIR FACTURA - Para ventas procesadas
            // =====================================================
            Actions\Action::make('imprimir_factura')
                ->label('Imprimir Factura')
                ->icon('heroicon-o-printer')
                ->color('success')
                ->url(fn () => route('venta.imprimir', $this->record))
                ->openUrlInNewTab()
                ->visible(fn () => in_array($this->record->estado, ['completada', 'pendiente_pago', 'pagada'])),

            // =====================================================
            // CANCELAR - Solo en borrador sin pagos
            // =====================================================
            Actions\Action::make('cancelar')
                ->label('Cancelar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancelar Cotización')
                ->modalDescription('¿Estás seguro de cancelar esta cotización? Esta acción no se puede deshacer.')
                ->visible(fn () =>
                    $this->record->estado === 'borrador' &&
                    $this->record->monto_pagado <= 0
                )
                ->form([
                    Forms\Components\Textarea::make('motivo')
                        ->label('Motivo de cancelación')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    $this->record->cancelar($data['motivo']);
                    $this->refreshFormData(['estado']);

                    \Filament\Notifications\Notification::make()
                        ->title('Cotización cancelada')
                        ->warning()
                        ->send();
                }),

            // =====================================================
            // ELIMINAR - Solo en borrador sin pagos
            // =====================================================
            Actions\DeleteAction::make()
                ->visible(fn () =>
                    $this->record->estado === 'borrador' &&
                    $this->record->monto_pagado <= 0
                ),
        ];
    }
}