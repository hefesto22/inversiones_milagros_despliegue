<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasDateFilters;
use Filament\Widgets\Widget;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

class EstadoResultados extends Widget
{
    use HasWidgetShield;
    use HasDateFilters;
    

    protected static string $view = 'filament.widgets.estado-resultados';

    protected static ?int $sort = 0;

    protected int | string | array $columnSpan = 'full';

    public function getPdfUrl(): string
    {
        return route('estado-resultados.pdf', $this->buildUrlParams());
    }

    public function getDownloadUrl(): string
    {
        return route('estado-resultados.download', $this->buildUrlParams());
    }

    private function buildUrlParams(): array
    {
        $periodo = $this->filters['periodo'] ?? 'este_mes';
        $params = ['periodo' => $periodo];

        if ($periodo === 'personalizado') {
            $params['fecha_inicio'] = $this->filters['fecha_inicio']
                ?? now()->startOfMonth()->format('Y-m-d');
            $params['fecha_fin'] = $this->filters['fecha_fin']
                ?? now()->endOfMonth()->format('Y-m-d');
        }

        return $params;
    }
}