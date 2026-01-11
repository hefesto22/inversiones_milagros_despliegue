<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\Viaje;
use Filament\Pages\Page;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Illuminate\Database\Eloquent\Builder;

class PagoComisionesHistorial extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Logística';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Historial de Pagos';

    protected static ?string $title = 'Historial de Pagos de Comisiones';

    protected static string $view = 'filament.pages.pago-comisiones-historial';

    protected static bool $shouldRegisterNavigation = false; // No mostrar en menú principal

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Viaje::query()
                    ->where('estado', 'cerrado')
                    ->where('comision_pagada', true)
                    ->whereNotNull('fecha_pago_comision')
            )
            ->columns([
                TextColumn::make('fecha_pago_comision')
                    ->label('Fecha Pago')
                    ->date('d/m/Y')
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('chofer.name')
                    ->label('Chofer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('numero_viaje')
                    ->label('No. Viaje')
                    ->searchable(),

                TextColumn::make('fecha_salida')
                    ->label('Fecha Viaje')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('camion.placa')
                    ->label('Camión')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('comision_ganada')
                    ->label('Comisión')
                    ->money('HNL')
                    ->color('success'),

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
            ->filters([
                SelectFilter::make('chofer_id')
                    ->label('Chofer')
                    ->options(function () {
                        return User::whereHas('roles', fn($q) => $q->where('name', 'Chofer'))
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->preload(),

                Filter::make('fecha_pago')
                    ->form([
                        DatePicker::make('desde')
                            ->label('Pagado desde'),
                        DatePicker::make('hasta')
                            ->label('Pagado hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'], fn ($q, $date) => $q->where('fecha_pago_comision', '>=', $date))
                            ->when($data['hasta'], fn ($q, $date) => $q->where('fecha_pago_comision', '<=', $date));
                    }),
            ])
            ->groups([
                Group::make('fecha_pago_comision')
                    ->label('Fecha de Pago')
                    ->date()
                    ->collapsible(),

                Group::make('chofer.name')
                    ->label('Chofer')
                    ->collapsible(),
            ])
            ->defaultSort('fecha_pago_comision', 'desc')
            ->emptyStateHeading('Sin pagos registrados')
            ->emptyStateDescription('Aún no se han registrado pagos de comisiones.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('volver')
                ->label('Volver a Pagos')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(PagoComisiones::getUrl()),
        ];
    }
}