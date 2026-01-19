<?php

namespace App\Filament\Widgets;

use App\Models\BodegaGasto;
use App\Filament\Widgets\Concerns\HasDateFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

class GastosBodegaOverview extends BaseWidget
{
    use HasWidgetShield;
    use HasDateFilters;

    protected static ?string $pollingInterval = '30s';

    protected static ?int $sort = 3;

    /**
     * Solo visible para Jefe, Encargado y Super Admin
     */

    protected function getStats(): array
    {
        // Obtener rango de fechas del filtro
        $dateRange = $this->getFilteredDateRange();
        $previousRange = $this->getPreviousPeriodDateRange();

        // Gastos pendientes de aprobar (siempre muestra todos los pendientes)
        $gastosPendientes = BodegaGasto::where('estado', 'pendiente')->count();

        // Total gastado en el período (solo aprobados)
        $totalPeriodo = BodegaGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('monto');

        // Total en cartones del período
        $totalCartones = BodegaGasto::where('estado', 'aprobado')
            ->where('tipo_gasto', 'cartones')
            ->whereBetween('fecha', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('monto');

        // Total en empaque del período
        $totalEmpaque = BodegaGasto::where('estado', 'aprobado')
            ->where('tipo_gasto', 'empaque')
            ->whereBetween('fecha', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('monto');

        // Otros gastos (limpieza, papelería, herramientas, etc.)
        $otrosGastos = BodegaGasto::where('estado', 'aprobado')
            ->whereNotIn('tipo_gasto', ['cartones', 'empaque'])
            ->whereBetween('fecha', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('monto');

        // Comparación con período anterior
        $totalPeriodoAnterior = BodegaGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$previousRange['inicio'], $previousRange['fin']])
            ->sum('monto');

        $diferenciaPeriodo = $this->calculatePercentageChange($totalPeriodo, $totalPeriodoAnterior);

        $periodoLabel = $this->getPeriodLabel();

        return [
            Stat::make('Gastos Pendientes', $gastosPendientes)
                ->description('Por aprobar')
                ->descriptionIcon('heroicon-m-clock')
                ->color($gastosPendientes > 0 ? 'warning' : 'success')
                ->chart([0, $gastosPendientes])
                ->url(route('filament.admin.resources.bodega-gastos.index', ['tableFilters[estado][value]' => 'pendiente'])),

            Stat::make("Total Gastos Bodega ({$periodoLabel})", 'L ' . number_format($totalPeriodo, 2))
                ->description($diferenciaPeriodo >= 0 ? "+{$diferenciaPeriodo}% vs período anterior" : "{$diferenciaPeriodo}% vs período anterior")
                ->descriptionIcon($diferenciaPeriodo >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($diferenciaPeriodo > 10 ? 'danger' : 'success'),

            Stat::make("Cartones ({$periodoLabel})", 'L ' . number_format($totalCartones, 2))
                ->description('Cartones para reempaque')
                ->descriptionIcon('heroicon-m-cube')
                ->color('warning'),

            Stat::make("Otros Gastos ({$periodoLabel})", 'L ' . number_format($otrosGastos, 2))
                ->description('Limpieza, papelería, etc.')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('info'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}