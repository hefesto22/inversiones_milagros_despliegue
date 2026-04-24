<?php

namespace App\Filament\Widgets;

use App\Models\Lote;
use App\Models\ReempaqueLote;
use App\Filament\Widgets\Concerns\HasDateFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

class RegaloAprovechadoOverview extends BaseWidget
{
    use HasWidgetShield;
    use HasDateFilters;

    protected static ?int $sort = 6;

    protected function getStats(): array
    {
        $dateRange = $this->getFilteredDateRange();
        $previousRange = $this->getPreviousPeriodDateRange();
        $periodoLabel = $this->getPeriodLabel();

        // ============================================
        // CARTONES DE REGALO APROVECHADOS
        // ============================================

        // Periodo actual
        $datosActual = $this->getRegaloData($dateRange['inicio'], $dateRange['fin']);
        
        // Periodo anterior
        $datosAnterior = $this->getRegaloData($previousRange['inicio'], $previousRange['fin']);

        // Calcular cambio porcentual
        $cambioCartones = $this->calculatePercentageChange(
            $datosActual['cartones'], 
            $datosAnterior['cartones']
        );

        $cambioGanancia = $this->calculatePercentageChange(
            $datosActual['ganancia'], 
            $datosAnterior['ganancia']
        );

        return [
            Stat::make("Cartones Regalo Aprovechados ({$periodoLabel})", number_format($datosActual['cartones'], 1))
                ->description($this->getDescripcionCartones($cambioCartones, $datosActual['huevos']))
                ->descriptionIcon($cambioCartones >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color('success')
                ->chart($this->getChartData()),

            Stat::make("Ganancia por Regalo ({$periodoLabel})", 'L ' . number_format($datosActual['ganancia'], 2))
                ->description($this->getDescripcionGanancia($cambioGanancia, $datosActual['reempaques']))
                ->descriptionIcon($cambioGanancia >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color('success'),
        ];
    }

    /**
     * Obtener datos de regalo para un rango de fechas
     */
    protected function getRegaloData($fechaInicio, $fechaFin): array
    {
        // Fase 5: columna de costo dinámica según inventario.wac.read_source.
        // Lote::columnaSqlCostoPorHuevo() retorna uno de dos identificadores
        // hardcoded (`lotes.costo_por_huevo` | `lotes.wac_costo_por_huevo`),
        // blindado por tests — interpolar en selectRaw es seguro.
        $columnaCosto = Lote::columnaSqlCostoPorHuevo();

        $resultado = ReempaqueLote::whereBetween('reempaque_lotes.created_at', [$fechaInicio, $fechaFin])
            ->where('cartones_regalo_usados', '>', 0)
            ->join('lotes', 'reempaque_lotes.lote_id', '=', 'lotes.id')
            ->selectRaw("
                SUM(reempaque_lotes.cartones_regalo_usados) as total_cartones,
                SUM(reempaque_lotes.cartones_regalo_usados * lotes.huevos_por_carton) as total_huevos,
                SUM(reempaque_lotes.cartones_regalo_usados * lotes.huevos_por_carton * {$columnaCosto}) as ganancia_estimada,
                COUNT(DISTINCT reempaque_lotes.reempaque_id) as total_reempaques
            ")
            ->first();

        return [
            'cartones' => (float) ($resultado->total_cartones ?? 0),
            'huevos' => (float) ($resultado->total_huevos ?? 0),
            'ganancia' => (float) ($resultado->ganancia_estimada ?? 0),
            'reempaques' => (int) ($resultado->total_reempaques ?? 0),
        ];
    }

    /**
     * Descripcion para el stat de cartones
     */
    protected function getDescripcionCartones(float $cambio, float $huevos): string
    {
        $huevosTexto = number_format($huevos, 0) . ' huevos';
        
        if ($cambio == 0) {
            return $huevosTexto . ' | Sin cambio';
        }

        $direccion = $cambio >= 0 ? 'mas' : 'menos';
        return $huevosTexto . ' | ' . number_format(abs($cambio), 1) . '% ' . $direccion . ' que antes';
    }

    /**
     * Descripcion para el stat de ganancia
     */
    protected function getDescripcionGanancia(float $cambio, int $reempaques): string
    {
        $reempaquesTexto = $reempaques . ' reempaque' . ($reempaques != 1 ? 's' : '') . ' con regalo';
        
        if ($cambio == 0) {
            return $reempaquesTexto;
        }

        $direccion = $cambio >= 0 ? 'mas' : 'menos';
        return $reempaquesTexto . ' | ' . number_format(abs($cambio), 1) . '% ' . $direccion;
    }

    /**
     * Datos para el mini chart
     */
    protected function getChartData(): array
    {
        $dateRange = $this->getFilteredDateRange();
        $datos = [];
        
        $diffDays = min($dateRange['inicio']->diffInDays($dateRange['fin']), 6);
        
        for ($i = $diffDays; $i >= 0; $i--) {
            $fecha = $dateRange['fin']->copy()->subDays($i)->toDateString();
            
            $cartones = ReempaqueLote::whereDate('created_at', $fecha)
                ->where('cartones_regalo_usados', '>', 0)
                ->sum('cartones_regalo_usados');
            
            $datos[] = (float) $cartones;
        }

        return $datos;
    }
}