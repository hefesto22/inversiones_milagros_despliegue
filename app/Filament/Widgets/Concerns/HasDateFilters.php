<?php

namespace App\Filament\Widgets\Concerns;

use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

trait HasDateFilters
{
    use InteractsWithPageFilters;

    protected function getFilteredDateRange(): array
    {
        $periodo = $this->filters['periodo'] ?? 'este_mes';
        
        // Si es personalizado, usar las fechas del filtro
        if ($periodo === 'personalizado') {
            $fechaInicio = $this->filters['fecha_inicio'] ?? now()->startOfMonth()->format('Y-m-d');
            $fechaFin = $this->filters['fecha_fin'] ?? now()->endOfMonth()->format('Y-m-d');
            
            return [
                'inicio' => Carbon::parse($fechaInicio)->startOfDay(),
                'fin' => Carbon::parse($fechaFin)->endOfDay(),
            ];
        }

        // Calcular fechas basado en el período seleccionado
        return match ($periodo) {
            'hoy' => [
                'inicio' => now()->startOfDay(),
                'fin' => now()->endOfDay(),
            ],
            'ayer' => [
                'inicio' => now()->subDay()->startOfDay(),
                'fin' => now()->subDay()->endOfDay(),
            ],
            'ultimos_7_dias' => [
                'inicio' => now()->subDays(6)->startOfDay(),
                'fin' => now()->endOfDay(),
            ],
            'ultimos_30_dias' => [
                'inicio' => now()->subDays(29)->startOfDay(),
                'fin' => now()->endOfDay(),
            ],
            'este_mes' => [
                'inicio' => now()->startOfMonth(),
                'fin' => now()->endOfMonth(),
            ],
            'mes_anterior' => [
                'inicio' => now()->subMonth()->startOfMonth(),
                'fin' => now()->subMonth()->endOfMonth(),
            ],
            'este_año' => [
                'inicio' => now()->startOfYear(),
                'fin' => now()->endOfYear(),
            ],
            default => [
                'inicio' => now()->startOfMonth(),
                'fin' => now()->endOfMonth(),
            ],
        };
    }

    /**
     * Obtiene el rango de fechas del período anterior para comparación
     */
    protected function getPreviousPeriodDateRange(): array
    {
        $currentRange = $this->getFilteredDateRange();
        $diffDays = $currentRange['inicio']->diffInDays($currentRange['fin']) + 1;

        return [
            'inicio' => $currentRange['inicio']->copy()->subDays($diffDays),
            'fin' => $currentRange['fin']->copy()->subDays($diffDays),
        ];
    }

    /**
     * Obtiene una etiqueta legible del período seleccionado
     */
    protected function getPeriodLabel(): string
    {
        $periodo = $this->filters['periodo'] ?? 'este_mes';
        
        return match ($periodo) {
            'hoy' => 'Hoy',
            'ayer' => 'Ayer',
            'ultimos_7_dias' => 'Últimos 7 días',
            'ultimos_30_dias' => 'Últimos 30 días',
            'este_mes' => 'Este mes',
            'mes_anterior' => 'Mes anterior',
            'este_año' => 'Este año',
            'personalizado' => $this->getCustomPeriodLabel(),
            default => 'Este mes',
        };
    }

    protected function getCustomPeriodLabel(): string
    {
        $range = $this->getFilteredDateRange();
        return $range['inicio']->format('d/m/Y') . ' - ' . $range['fin']->format('d/m/Y');
    }

    /**
     * Calcula el porcentaje de cambio entre dos valores
     */
    protected function calculatePercentageChange(float $current, float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        
        return (($current - $previous) / $previous) * 100;
    }
}