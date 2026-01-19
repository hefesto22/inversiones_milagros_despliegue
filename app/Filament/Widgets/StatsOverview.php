<?php

namespace App\Filament\Widgets;

use App\Models\Venta;
use App\Models\ViajeVenta;
use App\Models\Compra;
use App\Filament\Widgets\Concerns\HasDateFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

class StatsOverview extends BaseWidget
{
    use HasWidgetShield;
    use HasDateFilters;

    protected static ?int $sort = 1;
    
    protected function getStats(): array
    {
        // Obtener rango de fechas del filtro
        $dateRange = $this->getFilteredDateRange();
        $previousRange = $this->getPreviousPeriodDateRange();

        // Ventas del período seleccionado
        $ventasPeriodo = ViajeVenta::whereBetween('fecha_venta', [$dateRange['inicio'], $dateRange['fin']])
            ->where('estado', 'completada')
            ->sum('total');

        // Ventas del período anterior para comparación
        $ventasPeriodoAnterior = ViajeVenta::whereBetween('fecha_venta', [$previousRange['inicio'], $previousRange['fin']])
            ->where('estado', 'completada')
            ->sum('total');

        // Calcular porcentaje de cambio en ventas
        $cambioVentas = $this->calculatePercentageChange($ventasPeriodo, $ventasPeriodoAnterior);

        // Compras del período seleccionado
        $comprasPeriodo = Compra::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('total');

        // Compras del período anterior
        $comprasPeriodoAnterior = Compra::whereBetween('created_at', [$previousRange['inicio'], $previousRange['fin']])
            ->sum('total');

        // Calcular porcentaje de cambio en compras
        $cambioCompras = $this->calculatePercentageChange($comprasPeriodo, $comprasPeriodoAnterior);

        // Ganancias del período (ventas - costo de ventas)
        $costoVentasPeriodo = ViajeVenta::whereBetween('fecha_venta', [$dateRange['inicio'], $dateRange['fin']])
            ->where('estado', 'completada')
            ->with('detalles')
            ->get()
            ->sum(function ($venta) {
                return $venta->detalles->sum(function ($detalle) {
                    return $detalle->costo_unitario * $detalle->cantidad;
                });
            });

        $gananciasPeriodo = $ventasPeriodo - $costoVentasPeriodo;

        // Ganancias del período anterior
        $costoVentasPeriodoAnterior = ViajeVenta::whereBetween('fecha_venta', [$previousRange['inicio'], $previousRange['fin']])
            ->where('estado', 'completada')
            ->with('detalles')
            ->get()
            ->sum(function ($venta) {
                return $venta->detalles->sum(function ($detalle) {
                    return $detalle->costo_unitario * $detalle->cantidad;
                });
            });

        $gananciasPeriodoAnterior = $ventasPeriodoAnterior - $costoVentasPeriodoAnterior;

        // Calcular porcentaje de cambio en ganancias
        $cambioGanancias = $this->calculatePercentageChange($gananciasPeriodo, $gananciasPeriodoAnterior);

        // Ventas de hoy (siempre muestra hoy, independiente del filtro)
        $ventasHoy = ViajeVenta::whereDate('fecha_venta', today())
            ->where('estado', 'completada')
            ->sum('total');

        // Cantidad de ventas del período
        $cantidadVentasPeriodo = ViajeVenta::whereBetween('fecha_venta', [$dateRange['inicio'], $dateRange['fin']])
            ->where('estado', 'completada')
            ->count();

        $periodoLabel = $this->getPeriodLabel();

        return [
            Stat::make("Ventas ({$periodoLabel})", 'L ' . number_format($ventasPeriodo, 2))
                ->description($cambioVentas >= 0 
                    ? number_format(abs($cambioVentas), 1) . '% más que período anterior' 
                    : number_format(abs($cambioVentas), 1) . '% menos que período anterior')
                ->descriptionIcon($cambioVentas >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($cambioVentas >= 0 ? 'success' : 'danger')
                ->chart($this->getVentasChartData()),

            Stat::make("Compras ({$periodoLabel})", 'L ' . number_format($comprasPeriodo, 2))
                ->description($cambioCompras >= 0 
                    ? number_format(abs($cambioCompras), 1) . '% más que período anterior' 
                    : number_format(abs($cambioCompras), 1) . '% menos que período anterior')
                ->descriptionIcon($cambioCompras >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($cambioCompras <= 0 ? 'success' : 'warning')
                ->chart($this->getComprasChartData()),

            Stat::make("Ganancias ({$periodoLabel})", 'L ' . number_format($gananciasPeriodo, 2))
                ->description($cambioGanancias >= 0 
                    ? number_format(abs($cambioGanancias), 1) . '% más que período anterior' 
                    : number_format(abs($cambioGanancias), 1) . '% menos que período anterior')
                ->descriptionIcon($cambioGanancias >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($gananciasPeriodo >= 0 ? 'success' : 'danger'),

            Stat::make('Ventas Hoy', 'L ' . number_format($ventasHoy, 2))
                ->description($cantidadVentasPeriodo . ' ventas en el período')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('info'),
        ];
    }

    /**
     * Obtener datos para el mini chart de ventas
     */
    protected function getVentasChartData(): array
    {
        $dateRange = $this->getFilteredDateRange();
        $ventas = [];
        
        // Limitar a máximo 7 puntos para el mini chart
        $diffDays = min($dateRange['inicio']->diffInDays($dateRange['fin']), 6);
        
        for ($i = $diffDays; $i >= 0; $i--) {
            $fecha = $dateRange['fin']->copy()->subDays($i)->toDateString();
            $total = ViajeVenta::whereDate('fecha_venta', $fecha)
                ->where('estado', 'completada')
                ->sum('total');
            $ventas[] = (float) $total;
        }

        return $ventas;
    }

    /**
     * Obtener datos para el mini chart de compras
     */
    protected function getComprasChartData(): array
    {
        $dateRange = $this->getFilteredDateRange();
        $compras = [];
        
        $diffDays = min($dateRange['inicio']->diffInDays($dateRange['fin']), 6);
        
        for ($i = $diffDays; $i >= 0; $i--) {
            $fecha = $dateRange['fin']->copy()->subDays($i)->toDateString();
            $total = Compra::whereDate('created_at', $fecha)->sum('total');
            $compras[] = (float) $total;
        }

        return $compras;
    }
}