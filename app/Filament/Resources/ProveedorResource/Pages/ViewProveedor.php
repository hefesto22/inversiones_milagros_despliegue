<?php

namespace App\Filament\Resources\ProveedorResource\Pages;

use App\Filament\Resources\ProveedorResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Support\Facades\Auth;

class ViewProveedor extends ViewRecord
{
    protected static string $resource = ProveedorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('ver_estado_cuenta')
                ->label('Estado de Cuenta')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->action(function () {
                    $stats = $this->record->getEstadisticas();

                    $mensaje = "📊 ESTADO DE CUENTA DEL PROVEEDOR\n\n";
                    $mensaje .= "💰 FINANCIERO:\n";
                    $mensaje .= "Total de compras: L " . number_format($stats['total_compras'], 2) . "\n";
                    $mensaje .= "Total pagado: L " . number_format($stats['total_pagado'], 2) . "\n";
                    $mensaje .= "Saldo pendiente (recibidas): L " . number_format($stats['saldo_pendiente'], 2) . "\n";
                    $mensaje .= "Deuda total (incluye no recibidas): L " . number_format($stats['total_deuda'], 2) . "\n\n";

                    $mensaje .= "📦 OPERACIONES:\n";
                    $mensaje .= "Compras completadas: " . $stats['compras_completadas'] . "\n";
                    $mensaje .= "Compras activas: " . $stats['compras_activas'] . "\n";
                    $mensaje .= "Pendiente de recibir: L " . number_format($stats['pendiente_recibir'], 2);

                    \Filament\Notifications\Notification::make()
                        ->title('Estado de Cuenta')
                        ->body($mensaje)
                        ->icon('heroicon-o-document-text')
                        ->color('info')
                        ->duration(15000)
                        ->send();
                }),

            Actions\Action::make('nueva_compra')
                ->label('Nueva Compra')
                ->icon('heroicon-o-shopping-cart')
                ->color('success')
                ->url(fn() => \App\Filament\Resources\CompraResource::getUrl('create')),
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
                                Infolists\Components\TextEntry::make('nombre')
                                    ->label('Nombre')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->columnSpan(2),

                                Infolists\Components\TextEntry::make('rtn')
                                    ->label('RTN')
                                    ->default('Sin RTN')
                                    ->copyable(),

                                Infolists\Components\TextEntry::make('telefono')
                                    ->label('Teléfono')
                                    ->icon('heroicon-o-phone')
                                    ->default('Sin teléfono')
                                    ->copyable(),

                                Infolists\Components\TextEntry::make('email')
                                    ->label('Email')
                                    ->icon('heroicon-o-envelope')
                                    ->default('Sin email')
                                    ->copyable(),

                                Infolists\Components\IconEntry::make('estado')
                                    ->label('Estado')
                                    ->boolean()
                                    ->trueIcon('heroicon-o-check-circle')
                                    ->falseIcon('heroicon-o-x-circle')
                                    ->trueColor('success')
                                    ->falseColor('danger'),

                                Infolists\Components\TextEntry::make('direccion')
                                    ->label('Dirección')
                                    ->default('Sin dirección')
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columns(1),

                Infolists\Components\Section::make('Resumen Financiero')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('saldo_pendiente')
                                    ->label('Saldo Pendiente (Recibidas)')
                                    ->getStateUsing(fn($record) => $record->getSaldoPendiente())
                                    ->money('HNL')
                                    ->color(fn($state) => $state > 0 ? 'danger' : 'success')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->helperText('Mercancía recibida pendiente de pago'),

                                Infolists\Components\TextEntry::make('deuda_total')
                                    ->label('Deuda Total')
                                    ->getStateUsing(fn($record) => $record->getTotalDeuda())
                                    ->money('HNL')
                                    ->color(fn($state) => $state > 0 ? 'warning' : 'success')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->helperText('Incluye compras no recibidas'),

                                Infolists\Components\TextEntry::make('pendiente_recibir')
                                    ->label('Pendiente de Recibir')
                                    ->getStateUsing(fn($record) => $record->getTotalPendienteRecibir())
                                    ->money('HNL')
                                    ->color('info')
                                    ->helperText('Mercancía ordenada sin recibir'),
                            ]),
                    ])
                    ->columns(1),

                Infolists\Components\Section::make('Estadísticas')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('total_compras')
                                    ->label('Total de Compras')
                                    ->getStateUsing(
                                        fn($record) => $record->compras()
                                            ->whereNotIn('estado', ['borrador', 'cancelada'])
                                            ->count()
                                    ),

                                Infolists\Components\TextEntry::make('total_monto_compras')
                                    ->label('Monto Total de Compras')
                                    ->getStateUsing(fn($record) => $record->getTotalCompras())
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('total_pagado')
                                    ->label('Total Pagado')
                                    ->getStateUsing(fn($record) => $record->getTotalPagado())
                                    ->money('HNL')
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('compras_completadas')
                                    ->label('Compras Completadas')
                                    ->getStateUsing(
                                        fn($record) => $record->compras()
                                            ->where('estado', 'recibida_pagada')
                                            ->count()
                                    )
                                    ->badge()
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('compras_activas')
                                    ->label('Compras Activas')
                                    ->getStateUsing(
                                        fn($record) => $record->compras()
                                            ->activas()
                                            ->count()
                                    )
                                    ->badge()
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('compras_credito')
                                    ->label('Compras a Crédito')
                                    ->getStateUsing(
                                        fn($record) => $record->compras()
                                            ->where('tipo_pago', 'credito')
                                            ->whereNotIn('estado', ['borrador', 'cancelada'])
                                            ->count()
                                    ),

                                Infolists\Components\TextEntry::make('interes_total')
                                    ->label('Interés Total Acumulado')
                                    ->getStateUsing(function ($record) {
                                        return $record->compras()
                                            ->whereIn('estado', ['recibida_pendiente_pago', 'por_recibir_pendiente_pago'])
                                            ->where('tipo_pago', 'credito')
                                            ->get()
                                            ->sum(fn($compra) => $compra->getInteresAcumulado());
                                    })
                                    ->money('HNL')
                                    ->color('warning')
                                    ->visible(fn($state) => $state > 0),

                                Infolists\Components\TextEntry::make('ultima_compra')
                                    ->label('Última Compra')
                                    ->getStateUsing(function ($record) {
                                        $ultimaCompra = $record->compras()
                                            ->whereNotIn('estado', ['borrador', 'cancelada'])
                                            ->latest('created_at')
                                            ->first();
                                        return $ultimaCompra?->created_at;
                                    })
                                    ->dateTime('d/m/Y H:i')
                                    ->placeholder('Sin compras')
                                    ->default(null),
                            ]),
                    ])
                    ->columns(1)
                    ->collapsible(),

                Infolists\Components\Section::make('Información del Sistema')
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
                    ->columns(1)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    protected function getFooterWidgets(): array
    {
        return [
            // ProveedorResource\Widgets\ProveedorStatsWidget::class,
        ];
    }
}
