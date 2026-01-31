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
use Filament\Infolists\Components\Fieldset;
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
                // INFORMACIÓN DEL VIAJE (Colapsado por defecto)
                // ==========================================
                Section::make('Información del Viaje')
                    ->icon('heroicon-o-truck')
                    ->schema([
                        Grid::make(4)
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

                                TextEntry::make('chofer.name')
                                    ->label('Chofer')
                                    ->icon('heroicon-o-user'),
                            ]),

                        Grid::make(4)
                            ->schema([
                                TextEntry::make('bodegaOrigen.nombre')
                                    ->label('Bodega Origen')
                                    ->icon('heroicon-o-building-storefront'),

                                TextEntry::make('fecha_salida')
                                    ->label('Salida')
                                    ->dateTime('d/m/Y H:i')
                                    ->icon('heroicon-o-calendar')
                                    ->placeholder('Sin iniciar'),

                                TextEntry::make('fecha_regreso')
                                    ->label('Regreso')
                                    ->dateTime('d/m/Y H:i')
                                    ->icon('heroicon-o-calendar')
                                    ->placeholder('En curso'),

                                TextEntry::make('km_recorridos')
                                    ->label('Km Recorridos')
                                    ->state(fn($record) => $record->getKilometrosRecorridos() 
                                        ? number_format($record->getKilometrosRecorridos()) . ' km'
                                        : '-'),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),

                // ==========================================
                // 📦 CARGA INICIAL
                // ==========================================
                Section::make('📦 Carga Inicial')
                    ->description('Producto que salió en el camión')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('total_cargado_costo')
                                    ->label('Costo de la Carga')
                                    ->money('HNL')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight(FontWeight::Bold)
                                    ->color('gray')
                                    ->helperText('Lo que costó la mercadería'),

                                TextEntry::make('total_cargado_venta')
                                    ->label('Venta Esperada')
                                    ->money('HNL')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight(FontWeight::Bold)
                                    ->color('info')
                                    ->helperText('Si todo se vendiera al precio sugerido'),

                                TextEntry::make('ganancia_esperada')
                                    ->label('Ganancia Esperada')
                                    ->state(fn($record) => 'L ' . number_format(
                                        ($record->total_cargado_venta ?? 0) - ($record->total_cargado_costo ?? 0), 
                                        2
                                    ))
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight(FontWeight::Bold)
                                    ->color('success')
                                    ->helperText('Venta Esperada - Costo'),
                            ]),
                    ])
                    ->collapsible(),

                // ==========================================
                // 💰 RESULTADO DE VENTAS
                // ==========================================
                Section::make('💰 Resultado de Ventas')
                    ->description('Lo que realmente se vendió')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('venta_realizada')
                                    ->label('Total Vendido')
                                    ->state(fn($record) => 'L ' . number_format($this->calcularVentaRealizada($record), 2))
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight(FontWeight::Bold)
                                    ->color('success')
                                    ->helperText('Contado + Crédito'),

                                TextEntry::make('total_devuelto_costo')
                                    ->label('No Vendido (Devuelto)')
                                    ->money('HNL')
                                    ->color('warning')
                                    ->helperText('Producto que regresó'),

                                TextEntry::make('descuentos_dados')
                                    ->label('Descuentos Otorgados')
                                    ->state(fn($record) => 'L ' . number_format($this->calcularDescuentosOtorgados($record), 2))
                                    ->color('danger')
                                    ->helperText('Vendió más barato del precio sugerido'),

                                TextEntry::make('total_merma_costo')
                                    ->label('Mermas')
                                    ->money('HNL')
                                    ->color('danger')
                                    ->helperText('Producto dañado/perdido'),
                            ]),

                        // Desglose: Contado vs Crédito
                        Fieldset::make('Desglose de Ventas')
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        TextEntry::make('ventas_contado')
                                            ->label('💵 Ventas de Contado')
                                            ->state(fn($record) => 'L ' . number_format($this->calcularVentasContado($record), 2))
                                            ->weight(FontWeight::Bold)
                                            ->color('success')
                                            ->helperText('Efectivo a entregar'),

                                        TextEntry::make('ventas_credito')
                                            ->label('📋 Ventas a Crédito')
                                            ->state(fn($record) => 'L ' . number_format($this->calcularVentasCredito($record), 2))
                                            ->weight(FontWeight::Bold)
                                            ->color('info')
                                            ->helperText('Pendiente de cobro'),

                                        TextEntry::make('efectividad_precio')
                                            ->label('Efectividad de Precio')
                                            ->state(fn($record) => $this->calcularEfectividadPrecio($record))
                                            ->helperText('100% = vendió al precio sugerido'),

                                        TextEntry::make('porcentaje_vendido')
                                            ->label('% de Carga Vendida')
                                            ->state(fn($record) => $this->calcularPorcentajeVendido($record))
                                            ->helperText('Del total cargado'),
                                    ]),
                            ]),
                    ])
                    ->collapsible(),

                // ==========================================
                // 💵 ENTREGA DE EFECTIVO
                // ==========================================
                Section::make('💵 Entrega de Efectivo')
                    ->description('Dinero que debe entregar el chofer')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('efectivo_debe_entregar')
                                    ->label('Debe Entregar')
                                    ->state(fn($record) => 'L ' . number_format($this->calcularVentasContado($record), 2))
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight(FontWeight::Bold)
                                    ->color('info')
                                    ->helperText('Total de ventas de contado'),

                                TextEntry::make('efectivo_entregado')
                                    ->label('Entregó')
                                    ->money('HNL')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight(FontWeight::Bold)
                                    ->helperText('Lo que entregó el chofer'),

                                TextEntry::make('diferencia_efectivo')
                                    ->label('Diferencia')
                                    ->money('HNL')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight(FontWeight::Bold)
                                    ->color(fn($state) => match(true) {
                                        $state > 0 => 'success',
                                        $state < 0 => 'danger',
                                        default => 'success',
                                    })
                                    ->helperText(fn($record) => match(true) {
                                        ($record->diferencia_efectivo ?? 0) > 0 => 'Sobrante',
                                        ($record->diferencia_efectivo ?? 0) < 0 => 'Faltante',
                                        default => 'Cuadrado',
                                    }),

                                TextEntry::make('estado_efectivo')
                                    ->label('Estado')
                                    ->state(fn($record) => $this->getEstadoEfectivo($record))
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->color(fn($record) => $this->getColorEstadoEfectivo($record)),
                            ]),
                    ])
                    ->collapsible()
                    ->visible(fn($record) => in_array($record->estado, [
                        Viaje::ESTADO_LIQUIDANDO,
                        Viaje::ESTADO_CERRADO,
                    ])),

                // ==========================================
                // 🧮 RENTABILIDAD DEL VIAJE
                // ==========================================
                Section::make('🧮 Rentabilidad del Viaje')
                    ->description('Análisis de ganancias')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('margen_bruto')
                                    ->label('Margen Bruto')
                                    ->state(fn($record) => 'L ' . number_format($this->calcularMargenBruto($record), 2))
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight(FontWeight::Bold)
                                    ->color(fn($record) => $this->calcularMargenBruto($record) >= 0 ? 'success' : 'danger')
                                    ->helperText('Venta - Costo de lo vendido'),

                                TextEntry::make('gastos_operativos')
                                    ->label('(-) Gastos y Mermas')
                                    ->state(fn($record) => 'L ' . number_format(
                                        $this->calcularGastosViaje($record) + ($record->total_merma_costo ?? 0),
                                        2
                                    ))
                                    ->color('warning')
                                    ->helperText('Gastos aprobados + Mermas'),

                                TextEntry::make('comision_viaje')
                                    ->label('(-) Comisión Chofer')
                                    ->money('HNL', true, 'comision_ganada')
                                    ->state(fn($record) => $record->comision_ganada ?? 0)
                                    ->formatStateUsing(fn($state) => 'L ' . number_format($state, 2))
                                    ->color('warning')
                                    ->helperText('Pago por comisiones'),

                                TextEntry::make('utilidad_neta')
                                    ->label('= Utilidad Neta')
                                    ->state(fn($record) => 'L ' . number_format($this->calcularUtilidadNeta($record), 2))
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight(FontWeight::Bold)
                                    ->color(fn($record) => $this->calcularUtilidadNeta($record) >= 0 ? 'success' : 'danger')
                                    ->helperText('Ganancia final del viaje'),
                            ]),
                    ])
                    ->collapsible(),

                // ==========================================
                // 📊 GASTOS DEL VIAJE (Detalle colapsado)
                // ==========================================
                Section::make('📊 Detalle de Gastos')
                    ->description('Gastos operativos del viaje')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('gastos_aprobados')
                                    ->label('Gastos Aprobados')
                                    ->state(fn($record) => 'L ' . number_format($this->calcularGastosViaje($record), 2))
                                    ->color('warning')
                                    ->weight(FontWeight::Bold)
                                    ->helperText('Combustible, viáticos, etc.'),

                                TextEntry::make('total_merma_costo')
                                    ->label('Mermas')
                                    ->money('HNL')
                                    ->color('danger')
                                    ->helperText('Producto perdido/dañado'),

                                TextEntry::make('total_costos')
                                    ->label('Total Costos')
                                    ->state(fn($record) => 'L ' . number_format(
                                        $this->calcularGastosViaje($record) + ($record->total_merma_costo ?? 0),
                                        2
                                    ))
                                    ->color('danger')
                                    ->weight(FontWeight::Bold),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),

                // ==========================================
                // OBSERVACIONES
                // ==========================================
                Section::make('Observaciones')
                    ->schema([
                        TextEntry::make('observaciones')
                            ->label('')
                            ->placeholder('Sin observaciones')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn($record) => !empty($record->observaciones)),
            ]);
    }

    // ============================================================
    // MÉTODOS DE CÁLCULO
    // ============================================================

    /**
     * Calcular venta realizada total (contado + crédito)
     */
    protected function calcularVentaRealizada(Viaje $viaje): float
    {
        return (float) $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->sum('total');
    }

    /**
     * Calcular ventas de contado (efectivo a entregar)
     */
    protected function calcularVentasContado(Viaje $viaje): float
    {
        return (float) $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->where('tipo_pago', '!=', 'credito')
            ->sum('total');
    }

    /**
     * Calcular ventas a crédito
     */
    protected function calcularVentasCredito(Viaje $viaje): float
    {
        return (float) $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->where('tipo_pago', 'credito')
            ->sum('total');
    }

    /**
     * Calcular descuentos otorgados
     */
    protected function calcularDescuentosOtorgados(Viaje $viaje): float
    {
        $descuentoTotal = 0;

        $ventas = $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->with(['detalles.viajeCarga'])
            ->get();

        foreach ($ventas as $venta) {
            foreach ($venta->detalles as $detalle) {
                $precioSugerido = $detalle->viajeCarga?->precio_venta_sugerido ?? $detalle->precio_base;
                $precioVendido = $detalle->precio_base;
                
                if ($precioVendido < $precioSugerido) {
                    $descuento = ($precioSugerido - $precioVendido) * $detalle->cantidad;
                    $descuentoTotal += $descuento;
                }
            }
        }

        return $descuentoTotal;
    }

    /**
     * Calcular total de gastos del viaje (aprobados)
     */
    protected function calcularGastosViaje(Viaje $viaje): float
    {
        return (float) CamionGasto::where('viaje_id', $viaje->id)
            ->where('estado', 'aprobado')
            ->sum('monto');
    }

    /**
     * Calcular margen bruto (Venta Realizada - Costo de lo vendido)
     */
    protected function calcularMargenBruto(Viaje $viaje): float
    {
        $ventaRealizada = $this->calcularVentaRealizada($viaje);
        
        $costoVendido = 0;
        $ventas = $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->with('detalles')
            ->get();

        foreach ($ventas as $venta) {
            foreach ($venta->detalles as $detalle) {
                $costoVendido += $detalle->costo_unitario * $detalle->cantidad;
            }
        }

        return $ventaRealizada - $costoVendido;
    }

    /**
     * Calcular utilidad neta
     */
    protected function calcularUtilidadNeta(Viaje $viaje): float
    {
        $margenBruto = $this->calcularMargenBruto($viaje);
        $gastos = $this->calcularGastosViaje($viaje);
        $comisiones = (float) ($viaje->comision_ganada ?? 0);
        $mermas = (float) ($viaje->total_merma_costo ?? 0);

        return $margenBruto - $gastos - $comisiones - $mermas;
    }

    /**
     * Calcular porcentaje vendido
     */
    protected function calcularPorcentajeVendido(Viaje $viaje): string
    {
        $totalCargado = (float) $viaje->cargas()->sum('cantidad');
        $totalVendido = (float) $viaje->cargas()->sum('cantidad_vendida');

        if ($totalCargado <= 0) {
            return '0%';
        }

        $porcentaje = ($totalVendido / $totalCargado) * 100;
        return number_format($porcentaje, 1) . '%';
    }

    /**
     * Calcular efectividad del precio
     */
    protected function calcularEfectividadPrecio(Viaje $viaje): string
    {
        $totalEsperado = 0;
        $totalRealizado = 0;

        $ventas = $viaje->ventasRuta()
            ->whereIn('estado', ['confirmada', 'completada'])
            ->with(['detalles.viajeCarga'])
            ->get();

        foreach ($ventas as $venta) {
            foreach ($venta->detalles as $detalle) {
                $precioSugerido = $detalle->viajeCarga?->precio_venta_sugerido ?? $detalle->precio_base;
                
                $totalEsperado += $precioSugerido * $detalle->cantidad;
                $totalRealizado += $detalle->precio_base * $detalle->cantidad;
            }
        }

        if ($totalEsperado <= 0) {
            return 'N/A';
        }

        $efectividad = ($totalRealizado / $totalEsperado) * 100;
        return number_format($efectividad, 1) . '%';
    }

    /**
     * Obtener estado del efectivo
     */
    protected function getEstadoEfectivo(Viaje $viaje): string
    {
        $debeEntregar = $this->calcularVentasContado($viaje);
        $entregado = (float) ($viaje->efectivo_entregado ?? 0);
        
        if ($entregado == 0 && $debeEntregar > 0) {
            return '⏳ Pendiente';
        }
        
        $diferencia = $entregado - $debeEntregar;
        
        if (abs($diferencia) < 0.01) {
            return '✅ Cuadrado';
        } elseif ($diferencia > 0) {
            return '💰 Sobrante';
        } else {
            return '⚠️ Faltante';
        }
    }

    /**
     * Obtener color del estado del efectivo
     */
    protected function getColorEstadoEfectivo(Viaje $viaje): string
    {
        $debeEntregar = $this->calcularVentasContado($viaje);
        $entregado = (float) ($viaje->efectivo_entregado ?? 0);
        
        if ($entregado == 0 && $debeEntregar > 0) {
            return 'warning';
        }
        
        $diferencia = $entregado - $debeEntregar;
        
        if (abs($diferencia) < 0.01) {
            return 'success';
        } elseif ($diferencia > 0) {
            return 'success';
        } else {
            return 'danger';
        }
    }
}