<?php

namespace App\Filament\Resources\ViajeResource\Pages;

use App\Filament\Resources\ViajeResource;
use App\Models\Viaje;
use App\Models\CamionGasto;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Grid;
use Filament\Support\Enums\FontWeight;

class ViewViaje extends ViewRecord
{
    protected static string $resource = ViajeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                // ==========================================
                // ESTADÍSTICAS DEL VIAJE
                // ==========================================
                Section::make('Resumen del Viaje')
                    ->icon('heroicon-o-chart-bar')
                    ->schema([
                        Grid::make(5)
                            ->schema([
                                $this->statEntry('total_cargado_costo', 'Cargado', 'heroicon-o-archive-box-arrow-down', 'primary'),
                                $this->statEntry('total_vendido', 'Vendido', 'heroicon-o-banknotes', 'success'),
                                $this->statEntry('total_merma_costo', 'Mermas', 'heroicon-o-exclamation-triangle', 'danger'),
                                $this->statEntryCustom('gastos_viaje', 'Gastos', 'heroicon-o-credit-card', 'warning'),
                                $this->statEntry('neto_chofer', 'Neto Chofer', 'heroicon-o-user', 'info'),
                            ]),
                    ])
                    ->collapsible(),

                // ==========================================
                // INFORMACIÓN DEL VIAJE
                // ==========================================
                Section::make('Información del Viaje')
                    ->icon('heroicon-o-truck')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('numero_viaje')
                                    ->label('No. Viaje')
                                    ->weight(FontWeight::Bold)
                                    ->copyable()
                                    ->size(TextEntry\TextEntrySize::Large),

                                TextEntry::make('estado')
                                    ->label('Estado')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => match ($state) {
                                        Viaje::ESTADO_PLANIFICADO => 'Planificado',
                                        Viaje::ESTADO_CARGANDO => 'Cargando',
                                        Viaje::ESTADO_EN_RUTA => 'En Ruta',
                                        Viaje::ESTADO_REGRESANDO => 'Regresando',
                                        Viaje::ESTADO_DESCARGANDO => 'Descargando',
                                        Viaje::ESTADO_LIQUIDANDO => 'Liquidando',
                                        Viaje::ESTADO_CERRADO => 'Cerrado',
                                        Viaje::ESTADO_CANCELADO => 'Cancelado',
                                        default => $state,
                                    })
                                    ->color(fn($state) => match ($state) {
                                        Viaje::ESTADO_PLANIFICADO => 'gray',
                                        Viaje::ESTADO_CARGANDO => 'info',
                                        Viaje::ESTADO_EN_RUTA => 'warning',
                                        Viaje::ESTADO_REGRESANDO => 'primary',
                                        Viaje::ESTADO_DESCARGANDO => 'info',
                                        Viaje::ESTADO_LIQUIDANDO => 'warning',
                                        Viaje::ESTADO_CERRADO => 'success',
                                        Viaje::ESTADO_CANCELADO => 'danger',
                                        default => 'gray',
                                    }),

                                TextEntry::make('camion.placa')
                                    ->label('Camión')
                                    ->badge()
                                    ->color('info'),
                            ]),

                        Grid::make(3)
                            ->schema([
                                TextEntry::make('chofer.name')
                                    ->label('Chofer')
                                    ->icon('heroicon-o-user'),

                                TextEntry::make('bodegaOrigen.nombre')
                                    ->label('Bodega Origen')
                                    ->icon('heroicon-o-building-storefront'),

                                TextEntry::make('fecha_salida')
                                    ->label('Fecha Salida')
                                    ->dateTime('d/m/Y H:i')
                                    ->icon('heroicon-o-calendar')
                                    ->placeholder('Sin iniciar'),
                            ]),

                        Grid::make(3)
                            ->schema([
                                TextEntry::make('fecha_regreso')
                                    ->label('Fecha Regreso')
                                    ->dateTime('d/m/Y H:i')
                                    ->icon('heroicon-o-calendar')
                                    ->placeholder('En curso')
                                    ->visible(fn($record) => $record->fecha_regreso !== null),

                                TextEntry::make('km_salida')
                                    ->label('Km Salida')
                                    ->suffix(' km')
                                    ->placeholder('-')
                                    ->visible(fn($record) => $record->km_salida !== null),

                                TextEntry::make('km_regreso')
                                    ->label('Km Regreso')
                                    ->suffix(' km')
                                    ->placeholder('-')
                                    ->visible(fn($record) => $record->km_regreso !== null),
                            ]),

                        TextEntry::make('observaciones')
                            ->label('Observaciones')
                            ->placeholder('Sin observaciones')
                            ->columnSpanFull()
                            ->visible(fn($record) => !empty($record->observaciones)),
                    ])
                    ->collapsible(),

                // ==========================================
                // EFECTIVO (solo si aplica)
                // ==========================================
                Section::make('Control de Efectivo')
                    ->icon('heroicon-o-currency-dollar')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('efectivo_inicial')
                                    ->label('Efectivo Inicial')
                                    ->money('HNL')
                                    ->placeholder('L 0.00'),

                                TextEntry::make('efectivo_esperado')
                                    ->label('Efectivo Esperado')
                                    ->money('HNL')
                                    ->placeholder('L 0.00'),

                                TextEntry::make('efectivo_entregado')
                                    ->label('Efectivo Entregado')
                                    ->money('HNL')
                                    ->placeholder('L 0.00'),

                                TextEntry::make('diferencia_efectivo')
                                    ->label('Diferencia')
                                    ->money('HNL')
                                    ->color(fn($state) => $state < 0 ? 'danger' : ($state > 0 ? 'warning' : 'success'))
                                    ->placeholder('L 0.00'),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn($record) => in_array($record->estado, [
                        Viaje::ESTADO_LIQUIDANDO,
                        Viaje::ESTADO_CERRADO,
                    ])),

                // ==========================================
                // COMISIONES (solo si cerrado)
                // ==========================================
                Section::make('Liquidación del Chofer')
                    ->icon('heroicon-o-calculator')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('comision_ganada')
                                    ->label('Comisión Ganada')
                                    ->money('HNL')
                                    ->color('success')
                                    ->weight(FontWeight::Bold),

                                TextEntry::make('cobros_devoluciones')
                                    ->label('Cobros/Descuentos')
                                    ->money('HNL')
                                    ->color('danger'),

                                TextEntry::make('neto_chofer')
                                    ->label('Neto a Pagar')
                                    ->money('HNL')
                                    ->color('info')
                                    ->weight(FontWeight::Bold)
                                    ->size(TextEntry\TextEntrySize::Large),
                            ]),
                    ])
                    ->collapsible()
                    ->visible(fn($record) => $record->estado === Viaje::ESTADO_CERRADO),
            ]);
    }

    /**
     * Helper para crear un stat entry con formato de moneda
     */
    protected function statEntry(string $field, string $label, string $icon, string $color): TextEntry
    {
        return TextEntry::make($field)
            ->label($label)
            ->money('HNL')
            ->icon($icon)
            ->color($color)
            ->weight(FontWeight::Bold)
            ->placeholder('L 0.00');
    }

    /**
     * Helper para crear un stat entry con valor calculado (gastos del viaje)
     */
    protected function statEntryCustom(string $name, string $label, string $icon, string $color): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->weight(FontWeight::Bold)
            ->state(function ($record) {
                // Calcular total de gastos del viaje
                $totalGastos = CamionGasto::where('viaje_id', $record->id)
                    ->where('estado', 'aprobado')
                    ->sum('monto');
                
                return 'L ' . number_format($totalGastos, 2);
            });
    }
}