<?php

namespace App\Filament\Resources\ReempaqueResource\Pages;

use App\Filament\Resources\ReempaqueResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewReempaque extends ViewRecord
{
    protected static string $resource = ReempaqueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cancelar')
                ->label('Cancelar Reempaque')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancelar Reempaque')
                ->modalDescription('¿Estás seguro de cancelar este reempaque? Los huevos volverán a los lotes originales.')
                ->form([
                    \Filament\Forms\Components\Textarea::make('motivo')
                        ->label('Motivo de Cancelación')
                        ->required()
                        ->placeholder('Explica por qué se cancela este reempaque...')
                        ->rows(3),
                ])
                ->visible(fn() => $this->record->estado === 'en_proceso')
                ->action(function (array $data) {
                    $this->record->cancelar($data['motivo']);

                    $this->refreshFormData(['estado', 'nota']);

                    \Filament\Notifications\Notification::make()
                        ->title('Reempaque cancelado')
                        ->body('El reempaque ha sido cancelado y los huevos devueltos a los lotes.')
                        ->warning()
                        ->send();
                }),

            Actions\Action::make('ver_lotes')
                ->label('Ver Lotes Usados')
                ->icon('heroicon-o-archive-box')
                ->color('info')
                ->url(fn() => route('filament.admin.resources.lotes.index'))
                ->openUrlInNewTab(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Información General')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('numero_reempaque')
                                    ->label('No. Reempaque')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->copyable(),

                                Infolists\Components\TextEntry::make('bodega.nombre')
                                    ->label('Bodega')
                                    ->badge()
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('tipo')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => $state === 'individual' ? 'Individual' : 'Mezclado')
                                    ->color(fn($state) => $state === 'individual' ? 'success' : 'warning'),

                                Infolists\Components\TextEntry::make('estado')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => match($state) {
                                        'en_proceso' => 'En Proceso',
                                        'completado' => 'Completado',
                                        'cancelado' => 'Cancelado',
                                        'revertido' => 'Revertido',
                                        default => $state,
                                    })
                                    ->color(fn($state) => match($state) {
                                        'en_proceso' => 'warning',
                                        'completado' => 'success',
                                        'cancelado' => 'danger',
                                        'revertido' => 'info',
                                        default => 'gray',
                                    }),

                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Fecha de Creación')
                                    ->dateTime('d/m/Y H:i'),

                                Infolists\Components\TextEntry::make('creador.name')
                                    ->label('Creado por'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Lotes Utilizados')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('reempaqueLotes')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('lote.numero_lote')
                                    ->label('No. Lote')
                                    ->weight('bold')
                                    ->url(fn($record) => route('filament.admin.resources.lotes.view', ['record' => $record->lote_id]))
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('lote.producto.nombre')
                                    ->label('Producto'),

                                Infolists\Components\TextEntry::make('lote.proveedor.nombre')
                                    ->label('Proveedor'),

                                Infolists\Components\TextEntry::make('cantidad_cartones_usados')
                                    ->label('Cartones Usados')
                                    ->numeric(decimalPlaces: 2)
                                    ->suffix(' cartones'),

                                Infolists\Components\TextEntry::make('cantidad_huevos_usados')
                                    ->label('Huevos Usados')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos'),

                                Infolists\Components\TextEntry::make('costo_parcial')
                                    ->label('Costo')
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('porcentaje_participacion')
                                    ->label('% Participación')
                                    ->getStateUsing(fn($record) => $record->getPorcentajeParticipacion())
                                    ->suffix('%')
                                    ->color('info'),
                            ])
                            ->columns(7),
                    ]),

                Infolists\Components\Section::make('Proceso de Reempaque')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('total_huevos_usados')
                                    ->label('Total Huevos Usados')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos')
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('merma')
                                    ->label('Merma')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos')
                                    ->color('danger'),

                                Infolists\Components\TextEntry::make('huevos_utiles')
                                    ->label('Huevos Útiles')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos')
                                    ->color('success')
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('porcentaje_merma')
                                    ->label('% Merma')
                                    ->getStateUsing(fn($record) => $record->getPorcentajeMerma())
                                    ->suffix('%')
                                    ->color(fn($state) => match(true) {
                                        $state < 2 => 'success',
                                        $state < 5 => 'warning',
                                        default => 'danger',
                                    }),
                            ]),
                    ]),

                Infolists\Components\Section::make('Productos Generados')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('cartones_30')
                                    ->label('Cartones de 30')
                                    ->suffix(' cartones')
                                    ->badge()
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('cartones_15')
                                    ->label('Cartones de 15')
                                    ->suffix(' cartones')
                                    ->badge()
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('huevos_sueltos')
                                    ->label('Huevos Sueltos')
                                    ->suffix(' huevos')
                                    ->badge()
                                    ->color('warning'),

                                Infolists\Components\TextEntry::make('total_empacado')
                                    ->label('Total Empacado')
                                    ->getStateUsing(fn($record) => $record->getTotalHuevosEmpacados())
                                    ->suffix(' huevos')
                                    ->weight('bold')
                                    ->color('success'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Costos')
                    ->schema([
                        Infolists\Components\Grid::make(5)
                            ->schema([
                                Infolists\Components\TextEntry::make('costo_total')
                                    ->label('Costo Total')
                                    ->money('HNL')
                                    ->weight('bold')
                                    ->size('lg'),

                                Infolists\Components\TextEntry::make('costo_unitario_promedio')
                                    ->label('Costo por Huevo')
                                    ->getStateUsing(fn($record) => 'L ' . number_format(floatval($record->costo_unitario_promedio), 4))
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('costo_por_carton_30')
                                    ->label('Costo por Cartón (30)')
                                    ->getStateUsing(function ($record) {
                                        $total = floatval($record->costo_total);
                                        $huevos = intval($record->total_huevos_usados);
                                        if ($huevos <= 0) return 'L 0.0000';
                                        return 'L ' . number_format(($total / $huevos) * 30, 4);
                                    })
                                    ->weight('bold')
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('costo_por_medio_15')
                                    ->label('Costo por Medio (15)')
                                    ->getStateUsing(function ($record) {
                                        $total = floatval($record->costo_total);
                                        $huevos = intval($record->total_huevos_usados);
                                        if ($huevos <= 0) return 'L 0.0000';
                                        return 'L ' . number_format(($total / $huevos) * 15, 4);
                                    })
                                    ->weight('bold')
                                    ->color('warning'),

                                Infolists\Components\TextEntry::make('proveedores')
                                    ->label('Proveedores')
                                    ->getStateUsing(function ($record) {
                                        return $record->getProveedores()
                                            ->pluck('nombre')
                                            ->join(', ');
                                    })
                                    ->badge()
                                    ->separator(','),
                            ]),
                    ]),

                Infolists\Components\Section::make('Productos en Stock')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('reempaqueProductos')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('producto.nombre')
                                    ->label('Producto')
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('cantidad')
                                    ->label('Cantidad')
                                    ->numeric(decimalPlaces: 2),

                                Infolists\Components\TextEntry::make('costo_unitario')
                                    ->label('Costo Unitario')
                                    ->getStateUsing(fn($record) => 'L ' . number_format(floatval($record->costo_unitario), 4)),

                                Infolists\Components\TextEntry::make('costo_total')
                                    ->label('Costo Total')
                                    ->getStateUsing(fn($record) => 'L ' . number_format(floatval($record->costo_total), 4)),

                                Infolists\Components\IconEntry::make('agregado_a_stock')
                                    ->label('En Stock')
                                    ->boolean()
                                    ->trueIcon('heroicon-o-check-circle')
                                    ->falseIcon('heroicon-o-x-circle')
                                    ->trueColor('success')
                                    ->falseColor('danger'),

                                Infolists\Components\TextEntry::make('fecha_agregado_stock')
                                    ->label('Fecha Agregado')
                                    ->dateTime('d/m/Y H:i')
                                    ->placeholder('No agregado'),
                            ])
                            ->columns(6),
                    ])
                    ->visible(fn($record) => $record->reempaqueProductos()->count() > 0),

                Infolists\Components\Section::make('Notas')
                    ->schema([
                        Infolists\Components\TextEntry::make('nota')
                            ->label('')
                            ->markdown()
                            ->placeholder('Sin notas'),
                    ])
                    ->visible(fn($record) => !empty($record->nota))
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}