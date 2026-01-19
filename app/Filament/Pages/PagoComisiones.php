<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\Viaje;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

class PagoComisiones extends Page implements HasForms, HasTable
{
    use HasPageShield;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Logística';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Pago de Comisiones';

    protected static ?string $title = 'Pago de Comisiones';

    protected static string $view = 'filament.pages.pago-comisiones';

    // Propiedades del formulario
    public ?int $chofer_id = null;
    public ?string $tipo_periodo = 'quincenal_primera';
    public ?int $mes = null;
    public ?int $anio = null;

    // Datos calculados
    public Collection $viajesPendientes;
    public float $totalComisiones = 0;
    public float $totalCobros = 0;
    public float $netoAPagar = 0;

    public function mount(): void
    {
        $this->mes = now()->month;
        $this->anio = now()->year;
        $this->viajesPendientes = collect();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Seleccionar Período de Pago')
                    ->schema([
                        Select::make('chofer_id')
                            ->label('Chofer')
                            ->options(function () {
                                return User::whereHas('roles', fn($q) => $q->where('name', 'Chofer'))
                                    ->pluck('name', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn () => $this->calcularViajes()),

                        Radio::make('tipo_periodo')
                            ->label('Período')
                            ->options([
                                'quincenal_primera' => 'Quincena 1-15',
                                'quincenal_segunda' => 'Quincena 16-fin de mes',
                                'mensual' => 'Todo el mes',
                            ])
                            ->default('quincenal_primera')
                            ->inline()
                            ->live()
                            ->afterStateUpdated(fn () => $this->calcularViajes()),

                        Select::make('mes')
                            ->label('Mes')
                            ->options([
                                1 => 'Enero',
                                2 => 'Febrero',
                                3 => 'Marzo',
                                4 => 'Abril',
                                5 => 'Mayo',
                                6 => 'Junio',
                                7 => 'Julio',
                                8 => 'Agosto',
                                9 => 'Septiembre',
                                10 => 'Octubre',
                                11 => 'Noviembre',
                                12 => 'Diciembre',
                            ])
                            ->default(now()->month)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->calcularViajes()),

                        Select::make('anio')
                            ->label('Año')
                            ->options(function () {
                                $currentYear = now()->year;
                                return [
                                    $currentYear - 1 => $currentYear - 1,
                                    $currentYear => $currentYear,
                                    $currentYear + 1 => $currentYear + 1,
                                ];
                            })
                            ->default(now()->year)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->calcularViajes()),
                    ])
                    ->columns(4),
            ]);
    }

    public function calcularViajes(): void
    {
        if (!$this->chofer_id || !$this->mes || !$this->anio) {
            $this->viajesPendientes = collect();
            $this->totalComisiones = 0;
            $this->totalCobros = 0;
            $this->netoAPagar = 0;
            return;
        }

        // Calcular fechas según período
        $fechas = $this->getFechasPeriodo();

        // Obtener viajes cerrados, no pagados, en el período
        $this->viajesPendientes = Viaje::where('chofer_id', $this->chofer_id)
            ->where('estado', 'cerrado')
            ->where('comision_pagada', false)
            ->whereBetween('fecha_salida', [$fechas['inicio'], $fechas['fin']])
            ->orderBy('fecha_salida')
            ->get();

        // Calcular totales
        $this->totalComisiones = $this->viajesPendientes->sum('comision_ganada');
        $this->totalCobros = $this->viajesPendientes->sum('cobros_devoluciones');
        $this->netoAPagar = $this->totalComisiones - $this->totalCobros;
    }

    protected function getFechasPeriodo(): array
    {
        $inicio = Carbon::create($this->anio, $this->mes, 1);
        $finMes = $inicio->copy()->endOfMonth();

        return match ($this->tipo_periodo) {
            'quincenal_primera' => [
                'inicio' => $inicio->startOfDay(),
                'fin' => Carbon::create($this->anio, $this->mes, 15)->endOfDay(),
            ],
            'quincenal_segunda' => [
                'inicio' => Carbon::create($this->anio, $this->mes, 16)->startOfDay(),
                'fin' => $finMes->endOfDay(),
            ],
            'mensual' => [
                'inicio' => $inicio->startOfDay(),
                'fin' => $finMes->endOfDay(),
            ],
            default => [
                'inicio' => $inicio->startOfDay(),
                'fin' => $finMes->endOfDay(),
            ],
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                if (!$this->chofer_id) {
                    return Viaje::query()->whereRaw('1 = 0');
                }

                $fechas = $this->getFechasPeriodo();

                return Viaje::query()
                    ->where('chofer_id', $this->chofer_id)
                    ->where('estado', 'cerrado')
                    ->where('comision_pagada', false)
                    ->whereBetween('fecha_salida', [$fechas['inicio'], $fechas['fin']]);
            })
            ->columns([
                TextColumn::make('numero_viaje')
                    ->label('No. Viaje')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('fecha_salida')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('camion.placa')
                    ->label('Camión')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('total_vendido')
                    ->label('Ventas')
                    ->money('HNL')
                    ->sortable(),

                TextColumn::make('comision_ganada')
                    ->label('Comisión')
                    ->money('HNL')
                    ->color('success')
                    ->weight('bold'),

                TextColumn::make('cobros_devoluciones')
                    ->label('Cobros')
                    ->money('HNL')
                    ->color('danger'),

                TextColumn::make('neto_chofer')
                    ->label('Neto')
                    ->money('HNL')
                    ->weight('bold')
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger'),
            ])
            ->defaultSort('fecha_salida', 'asc')
            ->emptyStateHeading('Sin viajes pendientes')
            ->emptyStateDescription('No hay viajes pendientes de pago en el período seleccionado.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    public function registrarPago(): void
    {
        if (!$this->chofer_id) {
            Notification::make()
                ->title('Seleccione un chofer')
                ->warning()
                ->send();
            return;
        }

        if ($this->viajesPendientes->isEmpty()) {
            Notification::make()
                ->title('No hay viajes pendientes')
                ->warning()
                ->send();
            return;
        }

        $fechaPago = now();

        // Marcar todos los viajes como pagados
        Viaje::whereIn('id', $this->viajesPendientes->pluck('id'))
            ->update([
                'comision_pagada' => true,
                'fecha_pago_comision' => $fechaPago,
            ]);

        $chofer = User::find($this->chofer_id);
        $cantidadViajes = $this->viajesPendientes->count();

        Notification::make()
            ->title('Pago registrado')
            ->body("Se marcaron {$cantidadViajes} viajes como pagados para {$chofer->name}. Neto: L " . number_format($this->netoAPagar, 2))
            ->success()
            ->duration(10000)
            ->send();

        // Resetear
        $this->calcularViajes();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('registrar_pago')
                ->label('Registrar Pago')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->size('lg')
                ->requiresConfirmation()
                ->modalHeading('Confirmar Pago')
                ->modalDescription(fn () => $this->viajesPendientes->isNotEmpty() 
                    ? "¿Registrar pago de {$this->viajesPendientes->count()} viajes por L " . number_format($this->netoAPagar, 2) . "?"
                    : "No hay viajes para pagar")
                ->modalSubmitActionLabel('Sí, registrar pago')
                ->disabled(fn () => $this->viajesPendientes->isEmpty())
                ->action(fn () => $this->registrarPago()),

            Action::make('ver_historial')
                ->label('Historial de Pagos')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn () => PagoComisionesHistorial::getUrl()),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $pendientes = Viaje::where('estado', 'cerrado')
            ->where('comision_pagada', false)
            ->count();

        return $pendientes > 0 ? (string) $pendientes : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}