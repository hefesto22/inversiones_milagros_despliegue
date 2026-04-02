<?php

namespace App\Filament\Resources\CamionGastoResource\Pages;

use App\Filament\Resources\CamionGastoResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewCamionGasto extends ViewRecord
{
    protected static string $resource = CamionGastoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn() => $this->record->estado === 'pendiente'),

            Actions\Action::make('aprobar')
                ->label('Aprobar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn() => $this->record->estado === 'pendiente')
                ->action(function () {
                    $this->record->aprobar(auth()->id());

                    \Filament\Notifications\Notification::make()
                        ->title('Gasto aprobado')
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),

            Actions\Action::make('rechazar')
                ->label('Rechazar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->form([
                    \Filament\Forms\Components\Textarea::make('motivo_rechazo')
                        ->label('Motivo del Rechazo')
                        ->required()
                        ->rows(3),
                ])
                ->visible(fn() => $this->record->estado === 'pendiente')
                ->action(function (array $data) {
                    $this->record->rechazar(auth()->id(), $data['motivo_rechazo']);

                    \Filament\Notifications\Notification::make()
                        ->title('Gasto rechazado')
                        ->warning()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Información del Gasto')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('fecha')
                                    ->label('Fecha')
                                    ->date('d/m/Y'),

                                Infolists\Components\TextEntry::make('camion.codigo')
                                    ->label('Camión')
                                    ->formatStateUsing(fn($record) => "{$record->camion->codigo} - {$record->camion->placa}"),

                                Infolists\Components\TextEntry::make('chofer.name')
                                    ->label('Chofer'),

                                Infolists\Components\TextEntry::make('tipo_gasto')
                                    ->label('Tipo')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => \App\Models\CamionGasto::TIPOS_GASTO[$state] ?? $state)
                                    ->color(fn($state) => match ($state) {
                                        'gasolina', 'diesel' => 'warning',
                                        'mantenimiento' => 'info',
                                        'reparacion' => 'danger',
                                        default => 'gray',
                                    }),

                                Infolists\Components\TextEntry::make('monto')
                                    ->label('Monto')
                                    ->money('HNL')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('proveedor')
                                    ->label('Proveedor')
                                    ->placeholder('No especificado'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Detalles de Combustible')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('litros')
                                    ->label('Litros')
                                    ->suffix(' L')
                                    ->placeholder('-'),

                                Infolists\Components\TextEntry::make('precio_por_litro')
                                    ->label('Precio por Litro')
                                    ->money('HNL')
                                    ->placeholder('-'),

                                Infolists\Components\TextEntry::make('kilometraje')
                                    ->label('Kilometraje')
                                    ->suffix(' km')
                                    ->placeholder('-'),
                            ]),
                    ])
                    ->visible(fn($record) => in_array($record->tipo_gasto, \App\Models\CamionGasto::TIPOS_COMBUSTIBLE)),

                Infolists\Components\Section::make('Comprobante')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\IconEntry::make('tiene_factura')
                                    ->label('¿Tiene Factura?')
                                    ->boolean(),

                                Infolists\Components\IconEntry::make('enviado_whatsapp')
                                    ->label('¿Enviado por WhatsApp?')
                                    ->boolean(),

                                Infolists\Components\TextEntry::make('enviado_whatsapp_at')
                                    ->label('Enviado el')
                                    ->dateTime('d/m/Y h:i a')
                                    ->visible(fn($record) => $record->enviado_whatsapp)
                                    ->columnSpanFull(),
                            ]),

                        Infolists\Components\TextEntry::make('descripcion')
                            ->label('Descripción')
                            ->placeholder('Sin descripción')
                            ->columnSpanFull(),
                    ]),

                Infolists\Components\Section::make('Estado')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('estado')
                                    ->label('Estado')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => \App\Models\CamionGasto::ESTADOS[$state] ?? $state)
                                    ->color(fn($state) => match ($state) {
                                        'pendiente' => 'warning',
                                        'aprobado' => 'success',
                                        'rechazado' => 'danger',
                                        default => 'gray',
                                    }),

                                Infolists\Components\TextEntry::make('aprobador.name')
                                    ->label('Revisado por')
                                    ->visible(fn($record) => $record->estado !== 'pendiente'),

                                Infolists\Components\TextEntry::make('aprobado_at')
                                    ->label('Fecha de Revisión')
                                    ->dateTime('d/m/Y h:i a')
                                    ->visible(fn($record) => $record->estado !== 'pendiente'),

                                Infolists\Components\TextEntry::make('motivo_rechazo')
                                    ->label('Motivo de Rechazo')
                                    ->visible(fn($record) => $record->estado === 'rechazado')
                                    ->color('danger')
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Infolists\Components\Section::make('Auditoría')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('creador.name')
                                    ->label('Creado por'),

                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Fecha de Creación')
                                    ->dateTime('d/m/Y h:i a'),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}