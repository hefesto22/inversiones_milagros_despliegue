<?php

namespace App\Filament\Widgets;

use App\Models\CamionGasto;
use App\Filament\Widgets\Concerns\HasDateFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

class GastosCamionOverview extends BaseWidget
{
    use HasWidgetShield;
    use HasDateFilters;

    protected static ?string $pollingInterval = '30s';

    protected static ?int $sort = 2;

    protected function getHeading(): ?string
    {
        return 'Gastos de Camiones';
    }

    protected function getStats(): array
    {
        // Obtener rango de fechas del filtro
        $dateRange = $this->getFilteredDateRange();
        $previousRange = $this->getPreviousPeriodDateRange();

        // Gastos pendientes de aprobar (siempre muestra todos los pendientes)
        $gastosPendientes = CamionGasto::where('estado', 'pendiente')->count();

        // Total gastado en el período (solo aprobados)
        $totalPeriodo = CamionGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('monto');

        // Total en combustible del período (gasolina + diésel)
        $totalGasolina = CamionGasto::where('estado', 'aprobado')
            ->whereIn('tipo_gasto', CamionGasto::TIPOS_COMBUSTIBLE)
            ->whereBetween('fecha', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('monto');

        // Total litros del período (gasolina + diésel)
        $totalLitros = CamionGasto::where('estado', 'aprobado')
            ->whereIn('tipo_gasto', CamionGasto::TIPOS_COMBUSTIBLE)
            ->whereBetween('fecha', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('litros');

        // Otros gastos (mantenimiento, reparación, etc.)
        $otrosGastos = CamionGasto::where('estado', 'aprobado')
            ->whereNotIn('tipo_gasto', CamionGasto::TIPOS_COMBUSTIBLE)
            ->whereBetween('fecha', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('monto');

        // Comparación con período anterior
        $totalPeriodoAnterior = CamionGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$previousRange['inicio'], $previousRange['fin']])
            ->sum('monto');

        $diferenciaPeriodo = $this->calculatePercentageChange($totalPeriodo, $totalPeriodoAnterior);

        $periodoLabel = $this->getPeriodLabel();

        return [
            Stat::make('Camión: Pendientes', $gastosPendientes)
                ->description('Por aprobar')
                ->descriptionIcon('heroicon-m-clock')
                ->color($gastosPendientes > 0 ? 'warning' : 'success')
                ->chart([0, $gastosPendientes])
                ->url(route('filament.admin.resources.camion-gastos.index', ['tableFilters[estado][value]' => 'pendiente'])),

            Stat::make("Camión: Total ({$periodoLabel})", 'L ' . number_format($totalPeriodo, 2))
                ->description($diferenciaPeriodo >= 0 ? "+{$diferenciaPeriodo}% vs período anterior" : "{$diferenciaPeriodo}% vs período anterior")
                ->descriptionIcon($diferenciaPeriodo >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($diferenciaPeriodo > 10 ? 'danger' : 'success'),

            Stat::make("Camión: Combustible ({$periodoLabel})", 'L ' . number_format($totalGasolina, 2))
                ->description(number_format($totalLitros, 1) . ' litros')
                ->descriptionIcon('heroicon-m-fire')
                ->color('warning'),

            Stat::make("Camión: Otros ({$periodoLabel})", 'L ' . number_format($otrosGastos, 2))
                ->description('Mantenimiento, reparaciones, etc.')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('info'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}