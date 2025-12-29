<?php

namespace App\Filament\Resources\ClienteResource\Pages;

use App\Filament\Resources\ClienteResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewCliente extends ViewRecord
{
    protected static string $resource = ClienteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => $this->record->estado),

            Actions\Action::make('registrar_pago')
                ->label('Registrar Pago')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn () => $this->record->saldo_pendiente > 0)
                ->form([
                    \Filament\Forms\Components\Grid::make(2)->schema([
                        \Filament\Forms\Components\Placeholder::make('saldo_actual')
                            ->label('Saldo Pendiente Actual')
                            ->content(fn () => 'L ' . number_format($this->record->saldo_pendiente, 2)),

                        \Filament\Forms\Components\Select::make('venta_id')
                            ->label('Venta a Abonar')
                            ->options(function () {
                                return $this->record->ventas()
                                    ->where('tipo_pago', 'credito')
                                    ->where('saldo_pendiente', '>', 0)
                                    ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
                                    ->get()
                                    ->mapWithKeys(fn ($venta) => [
                                        $venta->id => "{$venta->numero_venta} - Saldo: L " . number_format($venta->saldo_pendiente, 2)
                                    ]);
                            })
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, \Filament\Forms\Set $set) {
                                if ($state) {
                                    $venta = \App\Models\Venta::find($state);
                                    if ($venta) {
                                        $set('monto_maximo', $venta->saldo_pendiente);
                                        $set('monto', $venta->saldo_pendiente);
                                    }
                                }
                            }),

                        \Filament\Forms\Components\Hidden::make('monto_maximo'),

                        \Filament\Forms\Components\TextInput::make('monto')
                            ->label('Monto a Pagar')
                            ->required()
                            ->numeric()
                            ->prefix('L')
                            ->minValue(0.01)
                            ->maxValue(fn (\Filament\Forms\Get $get) => $get('monto_maximo') ?? 999999)
                            ->helperText(fn (\Filament\Forms\Get $get) =>
                                $get('monto_maximo')
                                    ? 'Máximo: L ' . number_format($get('monto_maximo'), 2)
                                    : 'Selecciona una venta primero'
                            ),

                        \Filament\Forms\Components\Select::make('metodo_pago')
                            ->label('Método de Pago')
                            ->options([
                                'efectivo' => 'Efectivo',
                                'transferencia' => 'Transferencia',
                                'tarjeta' => 'Tarjeta',
                                'cheque' => 'Cheque',
                            ])
                            ->default('efectivo')
                            ->required(),

                        \Filament\Forms\Components\TextInput::make('referencia')
                            ->label('Referencia')
                            ->placeholder('No. transferencia, cheque, etc.')
                            ->maxLength(100),

                        \Filament\Forms\Components\Textarea::make('nota')
                            ->label('Nota')
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Observaciones del pago'),
                    ]),
                ])
                ->action(function (array $data) {
                    $venta = \App\Models\Venta::find($data['venta_id']);

                    if ($venta) {
                        $venta->registrarPago(
                            $data['monto'],
                            $data['metodo_pago'] ?? 'efectivo',
                            $data['referencia'] ?? null,
                            $data['nota'] ?? null
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('Pago registrado')
                            ->body('Saldo restante: L ' . number_format($venta->fresh()->saldo_pendiente, 2))
                            ->success()
                            ->duration(8000)
                            ->send();

                        $this->refreshFormData(['saldo_pendiente']);
                    }
                }),

            Actions\Action::make('ver_estado_cuenta')
                ->label('Estado de Cuenta')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->action(function () {
                    $creditoDisponible = $this->record->getCreditoDisponible();
                    $limiteCredito = $this->record->limite_credito ?? 0;
                    $creditoUtilizado = $limiteCredito > 0 ? ($limiteCredito - min($creditoDisponible, $limiteCredito)) : 0;
                    $saldoPendiente = $this->record->saldo_pendiente;
                    $ventasCredito = $this->record->ventas()
                        ->where('tipo_pago', 'credito')
                        ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
                        ->count();

                    $mensaje = "💳 ESTADO DE CUENTA\n\n";
                    $mensaje .= "Límite de crédito: L " . number_format($limiteCredito, 2) . "\n";
                    $mensaje .= "Crédito utilizado: L " . number_format($creditoUtilizado, 2) . "\n";

                    if ($creditoDisponible === PHP_FLOAT_MAX) {
                        $mensaje .= "Crédito disponible: Sin límite\n\n";
                    } else {
                        $mensaje .= "Crédito disponible: L " . number_format($creditoDisponible, 2) . "\n\n";
                    }

                    $mensaje .= "Saldo pendiente: L " . number_format($saldoPendiente, 2) . "\n";
                    $mensaje .= "Ventas a crédito: {$ventasCredito}";

                    \Filament\Notifications\Notification::make()
                        ->title('Estado de Cuenta')
                        ->body($mensaje)
                        ->icon('heroicon-o-document-text')
                        ->color('info')
                        ->duration(10000)
                        ->send();
                }),

            Actions\Action::make('ajustar_limite_credito')
                ->label('Ajustar Límite')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('warning')
                ->form([
                    \Filament\Forms\Components\Grid::make(2)->schema([
                        \Filament\Forms\Components\Placeholder::make('limite_actual')
                            ->label('Límite Actual')
                            ->content(fn () => 'L ' . number_format($this->record->limite_credito ?? 0, 2)),

                        \Filament\Forms\Components\Placeholder::make('credito_disponible')
                            ->label('Crédito Disponible')
                            ->content(function () {
                                $disponible = $this->record->getCreditoDisponible();
                                if ($disponible === PHP_FLOAT_MAX) {
                                    return 'Sin límite';
                                }
                                return 'L ' . number_format($disponible, 2);
                            }),

                        \Filament\Forms\Components\TextInput::make('nuevo_limite')
                            ->label('Nuevo Límite de Crédito')
                            ->required()
                            ->numeric()
                            ->prefix('L')
                            ->minValue(0)
                            ->step(0.01)
                            ->default(fn () => $this->record->limite_credito ?? 0)
                            ->columnSpanFull(),

                        \Filament\Forms\Components\TextInput::make('dias_credito')
                            ->label('Días de Crédito')
                            ->numeric()
                            ->suffix('días')
                            ->minValue(0)
                            ->default(fn () => $this->record->dias_credito ?? 0),

                        \Filament\Forms\Components\Textarea::make('motivo')
                            ->label('Motivo del Ajuste')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Describe el motivo del cambio de límite'),
                    ]),
                ])
                ->action(function (array $data) {
                    $limiteAnterior = $this->record->limite_credito ?? 0;

                    $this->record->update([
                        'limite_credito' => $data['nuevo_limite'],
                        'dias_credito' => $data['dias_credito'] ?? $this->record->dias_credito,
                    ]);

                    \Filament\Notifications\Notification::make()
                        ->title('Límite de crédito actualizado')
                        ->body("Anterior: L " . number_format($limiteAnterior, 2) . " → Nuevo: L " . number_format($data['nuevo_limite'], 2))
                        ->success()
                        ->send();

                    $this->refreshFormData(['limite_credito', 'dias_credito']);
                }),

            Actions\Action::make('nueva_venta')
                ->label('Nueva Venta')
                ->icon('heroicon-o-shopping-cart')
                ->color('success')
                ->url(fn () => \App\Filament\Resources\VentaResource::getUrl('create', ['cliente_id' => $this->record->id])),

            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->ventas()->count() === 0 && $this->record->saldo_pendiente <= 0),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Información General')
                    ->icon('heroicon-o-user')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('nombre')
                                    ->label('Nombre')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->columnSpan(2),

                                Infolists\Components\TextEntry::make('tipo')
                                    ->label('Tipo')
                                    ->badge()
                                    ->formatStateUsing(fn ($state) => match ($state) {
                                        'mayorista' => 'Mayorista',
                                        'minorista' => 'Minorista',
                                        'distribuidor' => 'Distribuidor',
                                        'ruta' => 'Ruta',
                                        default => $state,
                                    })
                                    ->color(fn ($state) => match ($state) {
                                        'mayorista' => 'success',
                                        'minorista' => 'info',
                                        'distribuidor' => 'warning',
                                        'ruta' => 'primary',
                                        default => 'gray',
                                    }),

                                Infolists\Components\TextEntry::make('rtn')
                                    ->label('RTN')
                                    ->placeholder('Sin RTN'),

                                Infolists\Components\TextEntry::make('telefono')
                                    ->label('Teléfono')
                                    ->icon('heroicon-o-phone')
                                    ->placeholder('Sin teléfono'),

                                Infolists\Components\TextEntry::make('email')
                                    ->label('Email')
                                    ->icon('heroicon-o-envelope')
                                    ->placeholder('Sin email')
                                    ->copyable(),

                                Infolists\Components\TextEntry::make('direccion')
                                    ->label('Dirección')
                                    ->placeholder('Sin dirección')
                                    ->columnSpanFull(),

                                Infolists\Components\IconEntry::make('estado')
                                    ->label('Estado')
                                    ->boolean()
                                    ->trueIcon('heroicon-o-check-circle')
                                    ->falseIcon('heroicon-o-x-circle')
                                    ->trueColor('success')
                                    ->falseColor('danger'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Estado de Crédito')
                    ->icon('heroicon-o-credit-card')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('limite_credito')
                                    ->label('Límite de Crédito')
                                    ->money('HNL')
                                    ->size('lg')
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('credito_utilizado')
                                    ->label('Crédito Utilizado')
                                    ->getStateUsing(function ($record) {
                                        $disponible = $record->getCreditoDisponible();
                                        if ($disponible === PHP_FLOAT_MAX || $record->limite_credito <= 0) {
                                            return 0;
                                        }
                                        return $record->limite_credito - $disponible;
                                    })
                                    ->money('HNL')
                                    ->color('warning'),

                                Infolists\Components\TextEntry::make('credito_disponible')
                                    ->label('Crédito Disponible')
                                    ->getStateUsing(fn ($record) => $record->getCreditoDisponible())
                                    ->formatStateUsing(function ($state) {
                                        if ($state === PHP_FLOAT_MAX) {
                                            return 'Sin límite';
                                        }
                                        return 'L ' . number_format($state, 2);
                                    })
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('saldo_pendiente')
                                    ->label('Saldo Pendiente')
                                    ->money('HNL')
                                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                                    ->size('lg')
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('dias_credito')
                                    ->label('Plazo de Crédito')
                                    ->formatStateUsing(fn ($state) => $state > 0 ? "{$state} días" : 'Solo contado'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Política de Devoluciones')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\IconEntry::make('acepta_devolucion')
                                    ->label('Acepta Devoluciones')
                                    ->boolean(),

                                Infolists\Components\TextEntry::make('dias_devolucion')
                                    ->label('Plazo Devolución')
                                    ->formatStateUsing(fn ($state) => $state > 0 ? "{$state} días" : 'N/A')
                                    ->visible(fn ($record) => $record->acepta_devolucion),

                                Infolists\Components\TextEntry::make('porcentaje_devolucion_max')
                                    ->label('% Máximo')
                                    ->formatStateUsing(fn ($state) => $state > 0 ? "{$state}%" : 'N/A')
                                    ->visible(fn ($record) => $record->acepta_devolucion),

                                Infolists\Components\TextEntry::make('notas_acuerdo')
                                    ->label('Notas del Acuerdo')
                                    ->columnSpanFull()
                                    ->placeholder('Sin notas')
                                    ->visible(fn ($record) => $record->acepta_devolucion),
                            ]),
                    ])
                    ->collapsed()
                    ->visible(fn ($record) => $record->acepta_devolucion),

                Infolists\Components\Section::make('Estadísticas')
                    ->icon('heroicon-o-chart-bar')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('total_ventas')
                                    ->label('Total de Ventas')
                                    ->getStateUsing(fn ($record) => $record->ventas()
                                        ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
                                        ->count()
                                    ),

                                Infolists\Components\TextEntry::make('total_monto_ventas')
                                    ->label('Monto Total')
                                    ->getStateUsing(fn ($record) => $record->ventas()
                                        ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
                                        ->sum('total')
                                    )
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('ventas_credito')
                                    ->label('Ventas a Crédito')
                                    ->getStateUsing(fn ($record) => $record->ventas()
                                        ->where('tipo_pago', 'credito')
                                        ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
                                        ->count()
                                    ),

                                Infolists\Components\TextEntry::make('ultima_venta')
                                    ->label('Última Venta')
                                    ->getStateUsing(fn ($record) => $record->ventas()
                                        ->latest('created_at')
                                        ->first()?->created_at
                                    )
                                    ->dateTime('d/m/Y')
                                    ->placeholder('Sin ventas'),
                            ]),
                    ])
                    ->collapsible(),

                Infolists\Components\Section::make('Información del Sistema')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Fecha de Creación')
                                    ->dateTime('d/m/Y H:i'),

                                Infolists\Components\TextEntry::make('updated_at')
                                    ->label('Última Actualización')
                                    ->dateTime('d/m/Y H:i')
                                    ->since(),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}
