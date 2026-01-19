<?php

namespace App\Filament\Widgets;

use App\Models\ViajeVenta;
use App\Models\Compra;
use App\Filament\Widgets\Concerns\HasDateFilters;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

class VentasChart extends ChartWidget
{
    use HasWidgetShield;
    use HasDateFilters;

    protected static ?string $heading = 'Ventas vs Compras';
    
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        $periodoLabel = $this->getPeriodLabel();
        return "Ventas vs Compras ({$periodoLabel})";
    }

    protected function getData(): array
    {
        $dateRange = $this->getFilteredDateRange();
        
        $ventas = [];
        $compras = [];
        $labels = [];

        // Calcular días entre las fechas
        $diffDays = $dateRange['inicio']->diffInDays($dateRange['fin']);
        
        // Si el rango es mayor a 60 días, agrupar por semanas
        // Si es mayor a 365 días, agrupar por meses
        if ($diffDays > 365) {
            return $this->getDataByMonth($dateRange);
        } elseif ($diffDays > 60) {
            return $this->getDataByWeek($dateRange);
        }

        // Datos diarios
        $currentDate = $dateRange['inicio']->copy();
        
        while ($currentDate <= $dateRange['fin']) {
            $labels[] = $currentDate->format('d/m');

            $totalVentas = ViajeVenta::whereDate('fecha_venta', $currentDate->toDateString())
                ->where('estado', 'completada')
                ->sum('total');
            $ventas[] = (float) $totalVentas;

            $totalCompras = Compra::whereDate('created_at', $currentDate->toDateString())
                ->sum('total');
            $compras[] = (float) $totalCompras;

            $currentDate->addDay();
        }

        return $this->formatChartData($labels, $ventas, $compras);
    }

    protected function getDataByWeek(array $dateRange): array
    {
        $ventas = [];
        $compras = [];
        $labels = [];

        $currentDate = $dateRange['inicio']->copy()->startOfWeek();
        
        while ($currentDate <= $dateRange['fin']) {
            $weekEnd = $currentDate->copy()->endOfWeek();
            
            // Ajustar si el fin de semana excede el rango
            if ($weekEnd > $dateRange['fin']) {
                $weekEnd = $dateRange['fin']->copy();
            }

            $labels[] = 'Sem ' . $currentDate->format('d/m');

            $totalVentas = ViajeVenta::whereBetween('fecha_venta', [$currentDate, $weekEnd])
                ->where('estado', 'completada')
                ->sum('total');
            $ventas[] = (float) $totalVentas;

            $totalCompras = Compra::whereBetween('created_at', [$currentDate, $weekEnd])
                ->sum('total');
            $compras[] = (float) $totalCompras;

            $currentDate->addWeek();
        }

        return $this->formatChartData($labels, $ventas, $compras);
    }

    protected function getDataByMonth(array $dateRange): array
    {
        $ventas = [];
        $compras = [];
        $labels = [];

        $currentDate = $dateRange['inicio']->copy()->startOfMonth();
        
        while ($currentDate <= $dateRange['fin']) {
            $monthEnd = $currentDate->copy()->endOfMonth();
            
            if ($monthEnd > $dateRange['fin']) {
                $monthEnd = $dateRange['fin']->copy();
            }

            $labels[] = $currentDate->format('M Y');

            $totalVentas = ViajeVenta::whereBetween('fecha_venta', [$currentDate, $monthEnd])
                ->where('estado', 'completada')
                ->sum('total');
            $ventas[] = (float) $totalVentas;

            $totalCompras = Compra::whereBetween('created_at', [$currentDate, $monthEnd])
                ->sum('total');
            $compras[] = (float) $totalCompras;

            $currentDate->addMonth();
        }

        return $this->formatChartData($labels, $ventas, $compras);
    }

    protected function formatChartData(array $labels, array $ventas, array $compras): array
    {
        return [
            'datasets' => [
                [
                    'label' => 'Ventas',
                    'data' => $ventas,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
                [
                    'label' => 'Compras',
                    'data' => $compras,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'callback' => "function(value) { return 'L ' + value.toLocaleString(); }",
                    ],
                ],
            ],
        ];
    }
}