<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $navigationIcon = 'heroicon-o-home';
    
    protected static string $routePath = '/';
    
    protected static ?int $navigationSort = -2;

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()
                    ->schema([
                        Select::make('periodo')
                            ->label('Período')
                            ->options([
                                'hoy' => 'Hoy',
                                'ayer' => 'Ayer',
                                'ultimos_7_dias' => 'Últimos 7 días',
                                'ultimos_30_dias' => 'Últimos 30 días',
                                'este_mes' => 'Este mes',
                                'mes_anterior' => 'Mes anterior',
                                'este_año' => 'Este año',
                                'personalizado' => 'Personalizado',
                            ])
                            ->default('este_mes')
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $dates = $this->getDateRangeFromPeriod($state);
                                $set('fecha_inicio', $dates['inicio']);
                                $set('fecha_fin', $dates['fin']);
                            }),

                        DatePicker::make('fecha_inicio')
                            ->label('Fecha Inicio')
                            ->default(now()->startOfMonth())
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->visible(fn (Get $get) => $get('periodo') === 'personalizado'),

                        DatePicker::make('fecha_fin')
                            ->label('Fecha Fin')
                            ->default(now()->endOfMonth())
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->visible(fn (Get $get) => $get('periodo') === 'personalizado'),
                    ])
                    ->columns(3),
            ]);
    }

    protected function getDateRangeFromPeriod(string $periodo): array
    {
        return match ($periodo) {
            'hoy' => [
                'inicio' => now()->startOfDay()->format('Y-m-d'),
                'fin' => now()->endOfDay()->format('Y-m-d'),
            ],
            'ayer' => [
                'inicio' => now()->subDay()->startOfDay()->format('Y-m-d'),
                'fin' => now()->subDay()->endOfDay()->format('Y-m-d'),
            ],
            'ultimos_7_dias' => [
                'inicio' => now()->subDays(6)->startOfDay()->format('Y-m-d'),
                'fin' => now()->endOfDay()->format('Y-m-d'),
            ],
            'ultimos_30_dias' => [
                'inicio' => now()->subDays(29)->startOfDay()->format('Y-m-d'),
                'fin' => now()->endOfDay()->format('Y-m-d'),
            ],
            'este_mes' => [
                'inicio' => now()->startOfMonth()->format('Y-m-d'),
                'fin' => now()->endOfMonth()->format('Y-m-d'),
            ],
            'mes_anterior' => [
                'inicio' => now()->subMonth()->startOfMonth()->format('Y-m-d'),
                'fin' => now()->subMonth()->endOfMonth()->format('Y-m-d'),
            ],
            'este_año' => [
                'inicio' => now()->startOfYear()->format('Y-m-d'),
                'fin' => now()->endOfYear()->format('Y-m-d'),
            ],
            default => [
                'inicio' => now()->startOfMonth()->format('Y-m-d'),
                'fin' => now()->endOfMonth()->format('Y-m-d'),
            ],
        };
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\EstadoResultados::class,  // NUEVO - primero
            \App\Filament\Widgets\StatsOverview::class,
            \App\Filament\Widgets\RegaloAprovechadoOverview::class,
            \App\Filament\Widgets\GastosCamionOverview::class,
            \App\Filament\Widgets\GastosBodegaOverview::class,
            \App\Filament\Widgets\VentasChart::class,
            \App\Filament\Widgets\VentasRecientes::class,
        ];
    }
}