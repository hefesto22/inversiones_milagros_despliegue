<?php

namespace App\Filament\Pages;

use App\Models\Liquidacion;
use App\Models\User;
use App\Models\Viaje;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

class PagoComisiones extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Logística';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Pago de Comisiones';

    protected static ?string $title = 'Liquidaciones de Comisiones';

    protected static string $view = 'filament.pages.pago-comisiones';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Liquidacion::query()
                    ->with(['chofer'])
                    ->latest('fecha_fin')
            )
            ->columns([
                TextColumn::make('numero_liquidacion')
                    ->label('No. Liquidación')
                    ->searchable()
                    ->weight('bold')
                    ->copyable(),

                TextColumn::make('chofer.name')
                    ->label('Chofer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fecha_inicio')
                    ->label('Período')
                    ->formatStateUsing(fn ($record) => $record->fecha_inicio->format('d/m/Y') . ' - ' . $record->fecha_fin->format('d/m/Y'))
                    ->sortable(),

                TextColumn::make('total_viajes')
                    ->label('Viajes')
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('total_comisiones')
                    ->label('Comisiones')
                    ->money('HNL')
                    ->color('success'),

                TextColumn::make('total_cobros')
                    ->label('Cobros')
                    ->money('HNL')
                    ->color('danger')
                    ->visible(fn () => Liquidacion::where('total_cobros', '>', 0)->exists()),

                TextColumn::make('total_pagar')
                    ->label('Neto Pagado')
                    ->money('HNL')
                    ->weight('bold')
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger'),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($record) => $record->getEstadoLabel())
                    ->color(fn ($record) => $record->getEstadoColor()),

                TextColumn::make('metodo_pago')
                    ->label('Método')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'efectivo' => 'Efectivo',
                        'transferencia' => 'Transferencia',
                        default => $state ? ucfirst($state) : '-',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('fecha_pago')
                    ->label('Fecha Pago')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('chofer_id')
                    ->label('Chofer')
                    ->options(function () {
                        return User::whereHas('roles', fn($q) => $q->where('name', 'Chofer'))
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->preload(),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'borrador' => 'Borrador',
                        'aprobada' => 'Aprobada',
                        'pagada' => 'Pagada',
                        'anulada' => 'Anulada',
                    ]),
            ])
            ->actions([
                TableAction::make('ver_viajes')
                    ->label('Ver viajes')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (Liquidacion $record) => "Viajes - {$record->numero_liquidacion}")
                    ->modalContent(function (Liquidacion $record) {
                        $viajes = $record->viajes()
                            ->with('viaje')
                            ->get()
                            ->map(fn ($lv) => $lv->viaje);

                        return view('filament.pages.partials.liquidacion-viajes', [
                            'viajes' => $viajes,
                            'liquidacion' => $record,
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Sin liquidaciones')
            ->emptyStateDescription('Las liquidaciones se generan automáticamente el día 1 de cada mes.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->striped()
            ->paginated([10, 25, 50]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('liquidar_manual')
                ->label('Liquidar Manualmente')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->form([
                    Select::make('chofer_id')
                        ->label('Chofer')
                        ->options(function () {
                            return User::whereHas('roles', fn($q) => $q->where('name', 'Chofer'))
                                ->pluck('name', 'id');
                        })
                        ->required()
                        ->searchable()
                        ->preload(),

                    DatePicker::make('fecha_inicio')
                        ->label('Desde')
                        ->required()
                        ->default(now()->startOfMonth()),

                    DatePicker::make('fecha_fin')
                        ->label('Hasta')
                        ->required()
                        ->default(now()->endOfMonth()),
                ])
                ->action(function (array $data) {
                    $this->liquidarManual($data);
                })
                ->modalHeading('Liquidar Comisiones Manualmente')
                ->modalDescription('Genera y paga una liquidación en efectivo para el chofer y período seleccionado.')
                ->modalSubmitActionLabel('Liquidar y marcar como pagado'),

            Action::make('ver_historial')
                ->label('Historial Detallado')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn () => PagoComisionesHistorial::getUrl()),
        ];
    }

    /**
     * Liquidar manualmente un chofer para un período dado
     */
    private function liquidarManual(array $data): void
    {
        $choferId = $data['chofer_id'];
        $fechaInicio = Carbon::parse($data['fecha_inicio'])->startOfDay();
        $fechaFin = Carbon::parse($data['fecha_fin'])->endOfDay();
        $chofer = User::find($choferId);

        // Buscar viajes cerrados no pagados en el período
        $viajes = Viaje::where('chofer_id', $choferId)
            ->where('estado', Viaje::ESTADO_CERRADO)
            ->where('comision_pagada', false)
            ->whereBetween('fecha_salida', [$fechaInicio, $fechaFin])
            ->orderBy('fecha_salida')
            ->get();

        if ($viajes->isEmpty()) {
            Notification::make()
                ->title('Sin viajes pendientes')
                ->body("No hay viajes con comisiones pendientes para {$chofer->name} en el período seleccionado.")
                ->warning()
                ->send();
            return;
        }

        try {
            DB::transaction(function () use ($chofer, $choferId, $viajes, $fechaInicio, $fechaFin) {
                // 1. Crear liquidación
                $liquidacion = Liquidacion::create([
                    'chofer_id' => $choferId,
                    'tipo_periodo' => Liquidacion::PERIODO_MENSUAL,
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                    'created_by' => auth()->id(),
                ]);

                // 2. Agregar viajes
                foreach ($viajes as $viaje) {
                    $liquidacion->agregarViaje($viaje);
                }

                // 3. Pagar usando el método formal del modelo
                $liquidacion->aprobar();
                $liquidacion->pagar('efectivo', 'Liquidación manual');

                Notification::make()
                    ->title('Liquidación registrada')
                    ->body("{$liquidacion->numero_liquidacion}: {$viajes->count()} viajes para {$chofer->name}. Neto: L " . number_format($liquidacion->total_pagar, 2))
                    ->success()
                    ->duration(10000)
                    ->send();
            });
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Error al liquidar')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
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
