<?php

declare(strict_types=1);

namespace App\Filament\Resources\AjusteInventarioResource\Pages;

use App\Application\Services\AjusteInventarioService;
use App\Enums\AjusteEstado;
use App\Filament\Resources\AjusteInventarioResource;
use App\Models\AjusteInventario;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewAjusteInventario extends ViewRecord
{
    protected static string $resource = AjusteInventarioResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Resumen del ajuste')->columns(3)->schema([
                TextEntry::make('id')->label('# Ajuste'),
                TextEntry::make('created_at')->label('Creado')->dateTime('d/m/Y H:i'),
                TextEntry::make('estado')->badge(),
                TextEntry::make('tipo_movimiento')->badge(),
                TextEntry::make('motivo')->badge(),
                TextEntry::make('pareja.id')->label('Pareja vinculada')->placeholder('—'),
            ]),

            Section::make('Lote y producto')->columns(3)->schema([
                TextEntry::make('producto.nombre')->label('Producto'),
                TextEntry::make('lote.numero_lote')->label('Lote'),
                TextEntry::make('bodega.nombre')->label('Bodega'),
            ]),

            Section::make('Movimiento')->columns(4)->schema([
                TextEntry::make('huevos_antes')->label('Antes (huevos)')->numeric(decimalPlaces: 2),
                TextEntry::make('delta_huevos')->label('Delta')->numeric(decimalPlaces: 2),
                TextEntry::make('huevos_despues')->label('Después (huevos)')->numeric(decimalPlaces: 2),
                TextEntry::make('cartones_equiv')->label('Equiv. cart 1x30'),
                TextEntry::make('costo_unitario_aplicado')->label('Costo unit. aplicado (L/huevo)')->numeric(decimalPlaces: 6),
                TextEntry::make('valor_contable_afectado')->label('Valor contable (L)')->money('HNL', 2),
            ]),

            Section::make('Justificación')->schema([
                TextEntry::make('descripcion')->label('Descripción'),
                TextEntry::make('evidencia_path')->label('Evidencia')->placeholder('Sin foto'),
            ]),

            Section::make('Bitácora de aprobación')->columns(3)->schema([
                TextEntry::make('creador.name')->label('Creado por'),
                TextEntry::make('aprobador.name')->label('Aprobado por')->placeholder('—'),
                TextEntry::make('aprobado_en')->label('Aprobado en')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('rechazador.name')->label('Rechazado por')->placeholder('—'),
                TextEntry::make('rechazado_en')->label('Rechazado en')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('motivo_rechazo')->label('Motivo rechazo')->placeholder('—')->columnSpanFull(),
                TextEntry::make('aplicador.name')->label('Aplicado por')->placeholder('—'),
                TextEntry::make('aplicado_en')->label('Aplicado en')->dateTime('d/m/Y H:i')->placeholder('—'),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('aprobar')
                ->label('Aprobar')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->visible(fn () => Auth::user()?->can('aprobar', $this->record) ?? false)
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        app(AjusteInventarioService::class)->aprobar($this->record, Auth::user());
                        Notification::make()->title('Aprobado')->success()->send();
                        $this->record->refresh();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),

            Actions\Action::make('rechazar')
                ->label('Rechazar')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->visible(fn () => Auth::user()?->can('rechazar', $this->record) ?? false)
                ->form([
                    Forms\Components\Textarea::make('motivo_rechazo')->label('Motivo del rechazo')->required()->rows(3),
                ])
                ->action(function (array $data) {
                    try {
                        app(AjusteInventarioService::class)->rechazar($this->record, Auth::user(), $data['motivo_rechazo']);
                        Notification::make()->title('Rechazado')->success()->send();
                        $this->record->refresh();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),

            Actions\Action::make('aplicar')
                ->label('Aplicar al lote')
                ->color('primary')
                ->icon('heroicon-o-check-badge')
                ->visible(fn () => Auth::user()?->can('aplicar', $this->record) ?? false)
                ->requiresConfirmation()
                ->modalDescription('Esta acción modifica el saldo del lote y dispara el WAC. Es irreversible — para corregir, se crea un nuevo ajuste de tipo "AjusteCorreccion".')
                ->action(function () {
                    try {
                        app(AjusteInventarioService::class)->aplicar($this->record, Auth::user());
                        Notification::make()->title('Ajuste aplicado al lote')->success()->send();
                        $this->record->refresh();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error al aplicar')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
