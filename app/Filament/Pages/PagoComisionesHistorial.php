<?php

namespace App\Filament\Pages;

use App\Models\Liquidacion;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;

class PagoComisionesHistorial extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Logística';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Historial de Pagos';

    protected static ?string $title = 'Historial de Pagos de Comisiones';

    protected static string $view = 'filament.pages.pago-comisiones-historial';

    protected static bool $shouldRegisterNavigation = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Liquidacion::query()
                    ->where('estado', Liquidacion::ESTADO_PAGADA)
                    ->with(['chofer'])
            )
            ->columns([
                TextColumn::make('numero_liquidacion')
                    ->label('No. Liquidación')
                    ->searchable()
                    ->weight('bold'),

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
                    ->color('success')
                    ->summarize(Sum::make()->money('HNL')->label('Total')),

                TextColumn::make('total_cobros')
                    ->label('Cobros')
                    ->money('HNL')
                    ->color('danger')
                    ->summarize(Sum::make()->money('HNL')->label('Total')),

                TextColumn::make('total_pagar')
                    ->label('Neto Pagado')
                    ->money('HNL')
                    ->weight('bold')
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger')
                    ->summarize(Sum::make()->money('HNL')->label('Total')),

                TextColumn::make('fecha_pago')
                    ->label('Fecha Pago')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('metodo_pago')
                    ->label('Método')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'efectivo' => 'Efectivo',
                        'transferencia' => 'Transferencia',
                        default => $state ? ucfirst($state) : '-',
                    }),

                TextColumn::make('referencia_pago')
                    ->label('Referencia')
                    ->limit(30)
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

                Filter::make('fecha_pago')
                    ->form([
                        DatePicker::make('desde')
                            ->label('Pagado desde'),
                        DatePicker::make('hasta')
                            ->label('Pagado hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'], fn ($q, $date) => $q->where('fecha_pago', '>=', $date))
                            ->when($data['hasta'], fn ($q, $date) => $q->where('fecha_pago', '<=', $date));
                    }),
            ])
            ->groups([
                Group::make('chofer.name')
                    ->label('Chofer')
                    ->collapsible(),
            ])
            ->actions([
                TableAction::make('ver_viajes')
                    ->label('Detalle')
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
            ->defaultSort('fecha_pago', 'desc')
            ->emptyStateHeading('Sin pagos registrados')
            ->emptyStateDescription('Aún no se han registrado pagos de comisiones.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->striped()
            ->paginated([10, 25, 50]);
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('volver')
                ->label('Volver a Liquidaciones')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(PagoComisiones::getUrl()),
        ];
    }
}
