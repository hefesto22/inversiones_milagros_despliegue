<?php

namespace App\Filament\Resources\LoteResource\Pages;

use App\Filament\Resources\LoteResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;

class ViewLote extends ViewRecord
{
    protected static string $resource = LoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Accion de registrar merma
            Actions\Action::make('registrar_merma')
                ->label('Registrar Merma')
                ->icon('heroicon-o-minus-circle')
                ->color('danger')
                ->form([
                    \Filament\Forms\Components\Placeholder::make('info_lote')
                        ->label('')
                        ->content(function () {
                            $record = $this->record;
                            $buffer = $record->getBufferRegaloDisponible();
                            $bufferColor = $buffer > 0 ? 'green' : 'red';

                            return new \Illuminate\Support\HtmlString("
                                <div class='rounded-lg bg-gray-50 dark:bg-gray-900 p-4 space-y-2'>
                                    <div class='grid grid-cols-2 gap-4'>
                                        <div>
                                            <p class='text-sm text-gray-500'>Huevos Disponibles</p>
                                            <p class='text-xl font-bold text-blue-600'>" . number_format($record->cantidad_huevos_remanente, 0) . "</p>
                                        </div>
                                        <div>
                                            <p class='text-sm text-gray-500'>Buffer de Regalos</p>
                                            <p class='text-xl font-bold text-{$bufferColor}-600'>" . number_format($buffer, 0) . "</p>
                                        </div>
                                    </div>
                                    <div class='border-t pt-2 mt-2'>
                                        <p class='text-sm text-gray-500'>Costo por Huevo</p>
                                        <p class='text-lg font-bold'>L " . number_format($record->costo_por_huevo, 2) . "</p>
                                    </div>
                                </div>
                            ");
                        }),

                    \Filament\Forms\Components\TextInput::make('cantidad_huevos')
                        ->label('Cantidad de Huevos Daniados')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(fn() => $this->record->cantidad_huevos_remanente)
                        ->suffix('huevos')
                        ->live()
                        ->helperText(fn() => 'Maximo: ' . number_format($this->record->cantidad_huevos_remanente, 0) . ' huevos'),

                    \Filament\Forms\Components\Select::make('motivo')
                        ->label('Motivo')
                        ->required()
                        ->options([
                            'rotos' => 'Rotos',
                            'podridos' => 'Podridos',
                            'vencidos' => 'Vencidos',
                            'dañados_transporte' => 'Daniados en Transporte',
                            'otros' => 'Otros',
                        ])
                        ->native(false)
                        ->default('rotos'),

                    \Filament\Forms\Components\Textarea::make('descripcion')
                        ->label('Descripcion (opcional)')
                        ->placeholder('Detalles adicionales sobre la merma...')
                        ->rows(2),

                    \Filament\Forms\Components\Placeholder::make('resumen_impacto')
                        ->label('Impacto de esta merma')
                        ->content(function (\Filament\Forms\Get $get) {
                            $record = $this->record;
                            $cantidad = (int) ($get('cantidad_huevos') ?? 0);

                            if ($cantidad <= 0) {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='text-gray-500 text-sm'>
                                        Ingresa la cantidad de huevos para ver el impacto
                                    </div>
                                ");
                            }

                            $buffer = $record->getBufferRegaloDisponible();
                            $costoPorHuevo = $record->costo_por_huevo ?? 0;

                            $cubierto = min($cantidad, $buffer);
                            $perdidaHuevos = max(0, $cantidad - $buffer);
                            $perdidaLempiras = $perdidaHuevos * $costoPorHuevo;

                            if ($perdidaHuevos == 0) {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-green-50 dark:bg-green-900/20 p-4'>
                                        <p class='text-green-800 dark:text-green-200 font-bold'>
                                            Sin perdida economica
                                        </p>
                                        <p class='text-sm text-green-700 dark:text-green-300 mt-1'>
                                            Los {$cantidad} huevos seran cubiertos por el buffer de regalos.<br>
                                            Buffer restante despues: " . number_format($buffer - $cubierto, 0) . " huevos
                                        </p>
                                    </div>
                                ");
                            } else {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-red-50 dark:bg-red-900/20 p-4'>
                                        <p class='text-red-800 dark:text-red-200 font-bold'>
                                            Perdida economica
                                        </p>
                                        <div class='text-sm text-red-700 dark:text-red-300 mt-2 space-y-1'>
                                            <p>Cubierto por buffer: <strong>{$cubierto} huevos</strong></p>
                                            <p>Perdida real: <strong>{$perdidaHuevos} huevos</strong></p>
                                            <p>Perdida en dinero: <strong>L " . number_format($perdidaLempiras, 2) . "</strong></p>
                                        </div>
                                    </div>
                                ");
                            }
                        }),
                ])
                ->action(function (array $data) {
                    try {
                        // Refrescar el lote para obtener datos actuales
                        $this->record->refresh();

                        // Validar que aun hay stock suficiente
                        $cantidadSolicitada = (float) $data['cantidad_huevos'];
                        
                        if ($cantidadSolicitada > $this->record->cantidad_huevos_remanente) {
                            Notification::make()
                                ->title('Stock insuficiente')
                                ->body("Solo hay " . number_format($this->record->cantidad_huevos_remanente, 0) . " huevos disponibles.")
                                ->danger()
                                ->send();
                            return;
                        }

                        $merma = $this->record->registrarMerma(
                            $cantidadSolicitada,
                            $data['motivo'],
                            $data['descripcion'] ?? null,
                            Auth::id()
                        );

                        $mensaje = "Merma #{$merma->numero_merma} registrada. ";

                        if ($merma->tuvoPerdidaEconomica()) {
                            $mensaje .= "Perdida: L " . number_format($merma->perdida_real_lempiras, 2);
                        } else {
                            $mensaje .= "Sin perdida economica (cubierto por buffer).";
                        }

                        Notification::make()
                            ->title('Merma registrada')
                            ->body($mensaje)
                            ->color($merma->tuvoPerdidaEconomica() ? 'warning' : 'success')
                            ->duration(5000)
                            ->send();

                        $this->refreshFormData([
                            'cantidad_huevos_remanente',
                            'merma_total_acumulada',
                            'costo_por_huevo',
                        ]);

                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Error al registrar merma')
                            ->body($e->getMessage())
                            ->danger()
                            ->duration(8000)
                            ->send();
                    }
                })
                ->visible(fn() => $this->record->estado === 'disponible' && $this->record->cantidad_huevos_remanente > 0)
                ->modalWidth('lg'),

            Actions\Action::make('reempacar')
                ->label('Reempacar')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->url(fn() => route('filament.admin.resources.reempaques.create'))
                ->visible(fn() => $this->record->estado === 'disponible' && $this->record->cantidad_huevos_remanente > 0),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                // Informacion Principal
                Infolists\Components\Section::make('Informacion del Lote')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('numero_lote')
                                    ->label('No. Lote')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->copyable(),

                                Infolists\Components\TextEntry::make('producto.nombre')
                                    ->label('Producto')
                                    ->size('lg'),

                                Infolists\Components\TextEntry::make('bodega.nombre')
                                    ->label('Bodega')
                                    ->badge()
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('estado')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => $state === 'disponible' ? 'Disponible' : 'Agotado')
                                    ->color(fn($state) => $state === 'disponible' ? 'success' : 'gray'),
                            ]),
                    ]),

                // Cantidades
                Infolists\Components\Section::make('Inventario Actual')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('cantidad_huevos_remanente')
                                    ->label('Huevos Disponibles')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('equivalente_cartones')
                                    ->label('Equivalente en Cartones')
                                    ->getStateUsing(function ($record) {
                                        $c30 = floor($record->cantidad_huevos_remanente / 30);
                                        $resto = $record->cantidad_huevos_remanente % 30;
                                        return "{$c30} cartones + {$resto} sueltos";
                                    }),

                                Infolists\Components\TextEntry::make('buffer_disponible')
                                    ->label('Buffer de Regalos')
                                    ->getStateUsing(fn($record) => $record->getBufferRegaloDisponible())
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos')
                                    ->color(fn($record) => $record->getBufferRegaloDisponible() > 0 ? 'success' : 'warning'),

                                Infolists\Components\TextEntry::make('merma_total_acumulada')
                                    ->label('Mermas Acumuladas')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos')
                                    ->color('danger'),
                            ]),
                    ]),

                // Costos
                Infolists\Components\Section::make('Costos')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('costo_total_acumulado')
                                    ->label('Costo Total Acumulado')
                                    ->money('HNL')
                                    ->size('lg')
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('costo_por_huevo')
                                    ->label('Costo por Huevo')
                                    ->formatStateUsing(fn($state) => 'L ' . number_format($state, 4))
                                    ->size('lg')
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('costo_por_carton_facturado')
                                    ->label('Costo por Carton')
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('valor_inventario')
                                    ->label('Valor del Inventario')
                                    ->getStateUsing(fn($record) => $record->calcularCostoDeHuevos($record->cantidad_huevos_remanente))
                                    ->money('HNL')
                                    ->color('success'),
                            ]),
                    ]),

                // Acumulados historicos
                Infolists\Components\Section::make('Historial Acumulado')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('huevos_facturados_acumulados')
                                    ->label('Huevos Facturados (Total Historico)')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos')
                                    ->helperText('Total comprado y pagado'),

                                Infolists\Components\TextEntry::make('huevos_regalo_acumulados')
                                    ->label('Huevos Regalo (Total Historico)')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos')
                                    ->helperText('Total recibido gratis'),

                                Infolists\Components\TextEntry::make('cantidad_huevos_original')
                                    ->label('Huevos Ingresados (Total)')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos'),

                                Infolists\Components\TextEntry::make('historial_compras_count')
                                    ->label('Compras Registradas')
                                    ->getStateUsing(fn($record) => $record->historialCompras()->count())
                                    ->badge()
                                    ->color('info'),
                            ]),
                    ])
                    ->visible(fn($record) => $record->esLoteUnico())
                    ->collapsible(),

                // Historial de Compras
                Infolists\Components\Section::make('Historial de Compras')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('historialCompras')
                            ->schema([
                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Fecha')
                                    ->dateTime('d/m/Y H:i'),

                                Infolists\Components\TextEntry::make('compra.numero_compra')
                                    ->label('No. Compra')
                                    ->url(fn($record) => route('filament.admin.resources.compras.view', ['record' => $record->compra_id]))
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('proveedor.nombre')
                                    ->label('Proveedor'),

                                Infolists\Components\TextEntry::make('cartones_facturados')
                                    ->label('Facturados')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' cart.'),

                                Infolists\Components\TextEntry::make('cartones_regalo')
                                    ->label('Regalo')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' cart.')
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('costo_compra')
                                    ->label('Costo')
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('costo_por_huevo_compra')
                                    ->label('$/Huevo')
                                    ->formatStateUsing(fn($state) => 'L ' . number_format($state, 2)),

                                Infolists\Components\TextEntry::make('costo_promedio_resultante')
                                    ->label('Promedio Resultante')
                                    ->formatStateUsing(fn($state) => 'L ' . number_format($state, 2))
                                    ->color('info'),
                            ])
                            ->columns(8),
                    ])
                    ->visible(fn($record) => $record->esLoteUnico() && $record->historialCompras()->count() > 0)
                    ->collapsible()
                    ->collapsed(fn($record) => $record->historialCompras()->count() > 5),

                // Historial de Mermas
                Infolists\Components\Section::make('Historial de Mermas')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('mermas')
                            ->schema([
                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Fecha')
                                    ->dateTime('d/m/Y H:i'),

                                Infolists\Components\TextEntry::make('numero_merma')
                                    ->label('No. Merma')
                                    ->copyable(),

                                Infolists\Components\TextEntry::make('cantidad_huevos')
                                    ->label('Cantidad')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos'),

                                Infolists\Components\TextEntry::make('motivo')
                                    ->label('Motivo')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => match ($state) {
                                        'rotos' => 'Rotos',
                                        'podridos' => 'Podridos',
                                        'vencidos' => 'Vencidos',
                                        'dañados_transporte' => 'Transporte',
                                        'otros' => 'Otros',
                                        default => $state,
                                    })
                                    ->color(fn($state) => match ($state) {
                                        'rotos' => 'warning',
                                        'podridos' => 'danger',
                                        'vencidos' => 'gray',
                                        'dañados_transporte' => 'info',
                                        default => 'gray',
                                    }),

                                Infolists\Components\TextEntry::make('cubierto_por_regalo')
                                    ->label('Cubierto')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos')
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('perdida_real_huevos')
                                    ->label('Perdida')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos')
                                    ->color('danger'),

                                Infolists\Components\TextEntry::make('perdida_real_lempiras')
                                    ->label('Perdida L')
                                    ->money('HNL')
                                    ->color('danger'),

                                Infolists\Components\TextEntry::make('creador.name')
                                    ->label('Registrado por'),
                            ])
                            ->columns(8),
                    ])
                    ->visible(fn($record) => $record->mermas()->count() > 0)
                    ->collapsible()
                    ->collapsed(fn($record) => $record->mermas()->count() > 5),
            ]);
    }
}