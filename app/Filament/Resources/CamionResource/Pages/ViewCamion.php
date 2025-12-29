<?php

namespace App\Filament\Resources\CamionResource\Pages;

use App\Filament\Resources\CamionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCamion extends ViewRecord
{
    protected static string $resource = CamionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => $this->record->activo),

            Actions\Action::make('asignar_chofer')
                ->label('Asignar Chofer')
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->visible(fn () => !$this->record->getChoferActual() && $this->record->activo)
                ->form([
                    \Filament\Forms\Components\Select::make('user_id')
                        ->label('Chofer')
                        ->options(\App\Models\User::whereHas('roles', function ($q) {
                            $q->where('name', 'chofer');
                        })->pluck('name', 'id'))
                        ->required()
                        ->searchable(),

                    \Filament\Forms\Components\DatePicker::make('vigente_desde')
                        ->label('Fecha de Asignación')
                        ->default(now())
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data) {
                    \App\Models\ChoferCamionAsignacion::create([
                        'camion_id' => $this->record->id,
                        'user_id' => $data['user_id'],
                        'vigente_desde' => $data['vigente_desde'],
                        'activo' => true,
                    ]);

                    \Filament\Notifications\Notification::make()
                        ->title('Chofer asignado')
                        ->success()
                        ->send();

                    $this->refreshFormData(['choferActual']);
                }),

            Actions\Action::make('desasignar_chofer')
                ->label('Desasignar Chofer')
                ->icon('heroicon-o-user-minus')
                ->color('warning')
                ->visible(fn () => $this->record->getChoferActual())
                ->requiresConfirmation()
                ->action(function () {
                    $asignacionActual = \App\Models\ChoferCamionAsignacion::where('camion_id', $this->record->id)
                        ->where('activo', true)
                        ->first();

                    if ($asignacionActual) {
                        $asignacionActual->update([
                            'vigente_hasta' => now(),
                            'activo' => false,
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->title('Chofer desasignado')
                            ->success()
                            ->send();

                        $this->refreshFormData(['choferActual']);
                    }
                }),

            Actions\Action::make('registrar_mantenimiento')
                ->label('Registrar Mantenimiento')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('info')
                ->form([
                    \Filament\Forms\Components\DatePicker::make('ultimo_mantenimiento')
                        ->label('Fecha de Mantenimiento')
                        ->default(now())
                        ->required()
                        ->native(false),

                    \Filament\Forms\Components\Textarea::make('observaciones_mantenimiento')
                        ->label('Observaciones')
                        ->rows(3)
                        ->placeholder('Describe los trabajos realizados'),
                ])
                ->action(function (array $data) {
                    $observaciones = $this->record->observaciones
                        ? $this->record->observaciones . "\n\n--- Mantenimiento " . now()->format('d/m/Y') . " ---\n" . $data['observaciones_mantenimiento']
                        : "--- Mantenimiento " . now()->format('d/m/Y') . " ---\n" . $data['observaciones_mantenimiento'];

                    $this->record->update([
                        'ultimo_mantenimiento' => $data['ultimo_mantenimiento'],
                        'observaciones' => $observaciones,
                    ]);

                    \Filament\Notifications\Notification::make()
                        ->title('Mantenimiento registrado')
                        ->success()
                        ->send();

                    $this->refreshFormData(['ultimo_mantenimiento', 'observaciones']);
                }),

            Actions\Action::make('ver_disponibilidad')
                ->label('Verificar Disponibilidad')
                ->icon('heroicon-o-check-circle')
                ->color('info')
                ->action(function () {
                    $disponible = $this->record->estaDisponible();
                    $chofer = $this->record->getChoferActual();

                    $mensaje = $disponible
                        ? "✅ El camión está DISPONIBLE\n"
                        : "❌ El camión NO está disponible\n";

                    if ($chofer) {
                        $mensaje .= "Chofer asignado: {$chofer->name}\n";
                    } else {
                        $mensaje .= "Sin chofer asignado\n";
                    }

                    $viajesActivos = $this->record->viajes()
                        ->whereIn('estado', ['en_preparacion', 'en_ruta'])
                        ->count();

                    if ($viajesActivos > 0) {
                        $mensaje .= "Viajes activos: {$viajesActivos}";
                    }

                    \Filament\Notifications\Notification::make()
                        ->title('Estado de Disponibilidad')
                        ->body($mensaje)
                        ->icon($disponible ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                        ->color($disponible ? 'success' : 'danger')
                        ->duration(8000)
                        ->send();
                }),

            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->viajes()->count() === 0),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            // Aquí podrías agregar widgets personalizados si los creas
            // CamionResource\Widgets\CamionStatsWidget::class,
        ];
    }
}
