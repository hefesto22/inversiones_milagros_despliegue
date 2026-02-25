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

    protected function getHeading(): ?string
    {
        return 'Gastos de Bodega';
    }

    protected function getStats(): array
    {
        // Obtener rango de fechas del filtro
        $dateRange = $this->getFilteredDateRange();
        $previousRange = $this->getPreviousPeriodDateRange();

        // Gastos pendientes de aprobar (siempre muestra todos los pendientes)
        $gastosPendientes = BodegaGasto::where('estado', 'pendiente')->count();

        // Total gastado en el período (solo aprobados, SIN inversiones)
        $totalPeriodo = BodegaGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$dateRange['inicio'], $dateRange['fin']])
            ->where(function ($q) {
                $q->where('categoria_contable', '!=', 'inversion')
                  ->orWhereNull('categoria_contable');
            })
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

        // Otros gastos operativos (limpieza, papelería, herramientas, etc.)
        // Excluye inversiones
        $otrosGastos = BodegaGasto::where('estado', 'aprobado')
            ->whereNotIn('tipo_gasto', ['cartones', 'empaque'])
            ->whereBetween('fecha', [$dateRange['inicio'], $dateRange['fin']])
            ->where(function ($q) {
                $q->where('categoria_contable', '!=', 'inversion')
                  ->orWhereNull('categoria_contable');
            })
            ->sum('monto');

        // Comparación con período anterior (sin inversiones)
        $totalPeriodoAnterior = BodegaGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$previousRange['inicio'], $previousRange['fin']])
            ->where(function ($q) {
                $q->where('categoria_contable', '!=', 'inversion')
                  ->orWhereNull('categoria_contable');
            })
            ->sum('monto');

        $diferenciaPeriodo = $this->calculatePercentageChange($totalPeriodo, $totalPeriodoAnterior);

        $periodoLabel = $this->getPeriodLabel();

        return [
            Stat::make('Bodega: Pendientes', $gastosPendientes)
                ->description('Por aprobar')
                ->descriptionIcon('heroicon-m-clock')
                ->color($gastosPendientes > 0 ? 'warning' : 'success')
                ->chart([0, $gastosPendientes])
                ->url(route('filament.admin.resources.bodega-gastos.index', ['tableFilters[estado][value]' => 'pendiente'])),

            Stat::make("Bodega: Total ({$periodoLabel})", 'L ' . number_format($totalPeriodo, 2))
                ->description($diferenciaPeriodo >= 0 ? "+{$diferenciaPeriodo}% vs período anterior" : "{$diferenciaPeriodo}% vs período anterior")
                ->descriptionIcon($diferenciaPeriodo >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($diferenciaPeriodo > 10 ? 'danger' : 'success'),

            Stat::make("Bodega: Cartones ({$periodoLabel})", 'L ' . number_format($totalCartones, 2))
                ->description('Cartones para reempaque')
                ->descriptionIcon('heroicon-m-cube')
                ->color('warning'),

            Stat::make("Bodega: Otros ({$periodoLabel})", 'L ' . number_format($otrosGastos, 2))
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