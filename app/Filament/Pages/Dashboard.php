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
                                'este_mes' => 'Este mes',
                                'mes_anterior' => 'Mes anterior',
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
            'este_mes' => [
                'inicio' => now()->startOfMonth()->format('Y-m-d'),
                'fin' => now()->endOfMonth()->format('Y-m-d'),
            ],
            'mes_anterior' => [
                'inicio' => now()->subMonth()->startOfMonth()->format('Y-m-d'),
                'fin' => now()->subMonth()->endOfMonth()->format('Y-m-d'),
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
            \App\Filament\Widgets\EstadoResultados::class,
            \App\Filament\Widgets\StatsOverview::class,
            \App\Filament\Widgets\VentasChart::class,
            \App\Filament\Widgets\VentasRecientes::class,
        ];
    }
}