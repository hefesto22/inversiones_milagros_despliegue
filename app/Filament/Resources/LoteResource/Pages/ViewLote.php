<?php

namespace App\Filament\Resources\LoteResource\Pages;

use App\Filament\Resources\LoteResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewLote extends ViewRecord
{
    protected static string $resource = LoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reempacar')
                ->label('Reempacar este Lote')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->url(fn() => route('filament.admin.resources.reempaques.create'))
                ->visible(fn() => $this->record->estado === 'disponible' && $this->record->cantidad_huevos_remanente > 0),

            Actions\Action::make('ver_compra')
                ->label('Ver Compra')
                ->icon('heroicon-o-shopping-cart')
                ->color('info')
                ->url(fn() => route('filament.admin.resources.compras.view', ['record' => $this->record->compra_id]))
                ->visible(fn() => $this->record->compra_id),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Información del Lote')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('numero_lote')
                                    ->label('No. Lote')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->copyable(),

                                Infolists\Components\TextEntry::make('estado')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => $state === 'disponible' ? 'Disponible' : 'Agotado')
                                    ->color(fn($state) => $state === 'disponible' ? 'success' : 'gray'),

                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Fecha de Creación')
                                    ->dateTime('d/m/Y H:i'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Origen')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('compra.numero_compra')
                                    ->label('Compra')
                                    ->url(fn($record) => $record->compra_id
                                        ? route('filament.admin.resources.compras.view', ['record' => $record->compra_id])
                                        : null)
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('proveedor.nombre')
                                    ->label('Proveedor')
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('bodega.nombre')
                                    ->label('Bodega')
                                    ->badge()
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('producto.nombre')
                                    ->label('Producto')
                                    ->columnSpanFull()
                                    ->size('lg')
                                    ->weight('bold'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Cantidades')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('cantidad_cartones_original')
                                    ->label('Cartones Originales')
                                    ->numeric(decimalPlaces: 2)
                                    ->suffix(' cartones'),

                                Infolists\Components\TextEntry::make('huevos_por_carton')
                                    ->label('Huevos por Cartón')
                                    ->numeric(decimalPlaces: 0),

                                Infolists\Components\TextEntry::make('cantidad_huevos_original')
                                    ->label('Huevos Originales')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos'),

                                Infolists\Components\TextEntry::make('cantidad_huevos_remanente')
                                    ->label('Huevos Disponibles')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos')
                                    ->color(fn($state) => $state > 0 ? 'success' : 'gray')
                                    ->weight('bold')
                                    ->size('lg'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Uso del Lote')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('huevos_usados')
                                    ->label('Huevos Usados')
                                    ->getStateUsing(fn($record) => $record->cantidad_huevos_original - $record->cantidad_huevos_remanente)
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos'),

                                Infolists\Components\TextEntry::make('porcentaje_usado')
                                    ->label('Porcentaje Usado')
                                    ->getStateUsing(fn($record) => $record->getPorcentajeUsado())
                                    ->suffix('%')
                                    ->color(fn($state) => match(true) {
                                        $state < 25 => 'success',
                                        $state < 75 => 'warning',
                                        default => 'danger',
                                    }),

                                Infolists\Components\TextEntry::make('porcentaje_disponible')
                                    ->label('Porcentaje Disponible')
                                    ->getStateUsing(fn($record) => 100 - $record->getPorcentajeUsado())
                                    ->suffix('%')
                                    ->color('info'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Costos')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('costo_por_carton')
                                    ->label('Costo por Cartón')
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('costo_por_huevo')
                                    ->label('Costo por Huevo')
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('costo_remanente')
                                    ->label('Costo Total Remanente')
                                    ->getStateUsing(fn($record) => $record->getCostoRemanente())
                                    ->money('HNL')
                                    ->weight('bold')
                                    ->size('lg')
                                    ->color('success'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Reempaques Realizados')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('reempaqueLotes')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('reempaque.numero_reempaque')
                                    ->label('No. Reempaque')
                                    ->url(fn($record) => route('filament.admin.resources.reempaques.view', ['record' => $record->reempaque_id]))
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('cantidad_huevos_usados')
                                    ->label('Huevos Usados')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' huevos'),

                                Infolists\Components\TextEntry::make('costo_parcial')
                                    ->label('Costo')
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('reempaque.created_at')
                                    ->label('Fecha')
                                    ->dateTime('d/m/Y H:i'),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn($record) => $record->reempaqueLotes()->count() > 0)
                    ->collapsible(),
            ]);
    }
}
