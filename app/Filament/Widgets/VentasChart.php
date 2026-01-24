<?php

namespace App\Filament\Widgets;

use App\Models\Venta;
use App\Models\ViajeVenta;
use App\Models\Compra;
use App\Models\CamionGasto;
use App\Models\BodegaGasto;
use App\Filament\Widgets\Concerns\HasDateFilters;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

class VentasChart extends ChartWidget
{
    use HasWidgetShield;
    use HasDateFilters;

    protected static ?string $heading = 'Resumen Financiero';
    
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        $periodoLabel = $this->getPeriodLabel();
        return "Resumen Financiero ({$periodoLabel})";
    }

    protected function getData(): array
    {
        $dateRange = $this->getFilteredDateRange();
        
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
        return $this->getDataByDay($dateRange);
    }

    protected function getDataByDay(array $dateRange): array
    {
        $ventasRuta = [];
        $ventasBodega = [];
        $compras = [];
        $gastosCamion = [];
        $gastosBodega = [];
        $labels = [];

        $currentDate = $dateRange['inicio']->copy();
        
        while ($currentDate <= $dateRange['fin']) {
            $fecha = $currentDate->toDateString();
            $labels[] = $currentDate->format('d/m');

            // Ventas en Ruta (ViajeVenta)
            $ventasRuta[] = (float) ViajeVenta::whereDate('fecha_venta', $fecha)
                ->whereIn('estado', ['confirmada', 'completada'])
                ->sum('total');

            // Ventas en Bodega (Venta)
            $ventasBodega[] = (float) Venta::whereDate('created_at', $fecha)
                ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
                ->sum('total');

            // Compras
            $compras[] = (float) Compra::whereDate('created_at', $fecha)
                ->sum('total');

            // Gastos de Camión (aprobados)
            $gastosCamion[] = (float) CamionGasto::whereDate('fecha', $fecha)
                ->where('estado', 'aprobado')
                ->sum('monto');

            // Gastos de Bodega (aprobados)
            $gastosBodega[] = (float) BodegaGasto::whereDate('fecha', $fecha)
                ->where('estado', 'aprobado')
                ->sum('monto');

            $currentDate->addDay();
        }

        return $this->formatChartData($labels, $ventasRuta, $ventasBodega, $compras, $gastosCamion, $gastosBodega);
    }

    protected function getDataByWeek(array $dateRange): array
    {
        $ventasRuta = [];
        $ventasBodega = [];
        $compras = [];
        $gastosCamion = [];
        $gastosBodega = [];
        $labels = [];

        $currentDate = $dateRange['inicio']->copy()->startOfWeek();
        
        while ($currentDate <= $dateRange['fin']) {
            $weekEnd = $currentDate->copy()->endOfWeek();
            
            if ($weekEnd > $dateRange['fin']) {
                $weekEnd = $dateRange['fin']->copy();
            }

            $labels[] = 'Sem ' . $currentDate->format('d/m');

            // Ventas en Ruta
            $ventasRuta[] = (float) ViajeVenta::whereBetween('fecha_venta', [$currentDate, $weekEnd])
                ->whereIn('estado', ['confirmada', 'completada'])
                ->sum('total');

            // Ventas en Bodega
            $ventasBodega[] = (float) Venta::whereBetween('created_at', [$currentDate, $weekEnd])
                ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
                ->sum('total');

            // Compras
            $compras[] = (float) Compra::whereBetween('created_at', [$currentDate, $weekEnd])
                ->sum('total');

            // Gastos de Camión
            $gastosCamion[] = (float) CamionGasto::whereBetween('fecha', [$currentDate, $weekEnd])
                ->where('estado', 'aprobado')
                ->sum('monto');

            // Gastos de Bodega
            $gastosBodega[] = (float) BodegaGasto::whereBetween('fecha', [$currentDate, $weekEnd])
                ->where('estado', 'aprobado')
                ->sum('monto');

            $currentDate->addWeek();
        }

        return $this->formatChartData($labels, $ventasRuta, $ventasBodega, $compras, $gastosCamion, $gastosBodega);
    }

    protected function getDataByMonth(array $dateRange): array
    {
        $ventasRuta = [];
        $ventasBodega = [];
        $compras = [];
        $gastosCamion = [];
        $gastosBodega = [];
        $labels = [];

        $currentDate = $dateRange['inicio']->copy()->startOfMonth();
        
        while ($currentDate <= $dateRange['fin']) {
            $monthEnd = $currentDate->copy()->endOfMonth();
            
            if ($monthEnd > $dateRange['fin']) {
                $monthEnd = $dateRange['fin']->copy();
            }

            $labels[] = $currentDate->format('M Y');

            // Ventas en Ruta
            $ventasRuta[] = (float) ViajeVenta::whereBetween('fecha_venta', [$currentDate, $monthEnd])
                ->whereIn('estado', ['confirmada', 'completada'])
                ->sum('total');

            // Ventas en Bodega
            $ventasBodega[] = (float) Venta::whereBetween('created_at', [$currentDate, $monthEnd])
                ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
                ->sum('total');

            // Compras
            $compras[] = (float) Compra::whereBetween('created_at', [$currentDate, $monthEnd])
                ->sum('total');

            // Gastos de Camión
            $gastosCamion[] = (float) CamionGasto::whereBetween('fecha', [$currentDate, $monthEnd])
                ->where('estado', 'aprobado')
                ->sum('monto');

            // Gastos de Bodega
            $gastosBodega[] = (float) BodegaGasto::whereBetween('fecha', [$currentDate, $monthEnd])
                ->where('estado', 'aprobado')
                ->sum('monto');

            $currentDate->addMonth();
        }

        return $this->formatChartData($labels, $ventasRuta, $ventasBodega, $compras, $gastosCamion, $gastosBodega);
    }

    protected function formatChartData(
        array $labels, 
        array $ventasRuta, 
        array $ventasBodega,
        array $compras,
        array $gastosCamion, 
        array $gastosBodega
    ): array {
        return [
            'datasets' => [
                [
                    'label' => 'Ventas Ruta',
                    'data' => $ventasRuta,
                    'borderColor' => '#10b981', // Verde
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => false,
                    'tension' => 0.4,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Ventas Bodega',
                    'data' => $ventasBodega,
                    'borderColor' => '#3b82f6', // Azul
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => false,
                    'tension' => 0.4,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Compras',
                    'data' => $compras,
                    'borderColor' => '#8b5cf6', // Morado
                    'backgroundColor' => 'rgba(139, 92, 246, 0.1)',
                    'fill' => false,
                    'tension' => 0.4,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Gastos Camión',
                    'data' => $gastosCamion,
                    'borderColor' => '#f59e0b', // Naranja
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => false,
                    'tension' => 0.4,
                    'borderWidth' => 2,
                    'borderDash' => [5, 5],
                ],
                [
                    'label' => 'Gastos Bodega',
                    'data' => $gastosBodega,
                    'borderColor' => '#ef4444', // Rojo
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'fill' => false,
                    'tension' => 0.4,
                    'borderWidth' => 2,
                    'borderDash' => [5, 5],
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
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
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
            'interaction' => [
                'mode' => 'nearest',
                'axis' => 'x',
                'intersect' => false,
            ],
        ];
    }
}