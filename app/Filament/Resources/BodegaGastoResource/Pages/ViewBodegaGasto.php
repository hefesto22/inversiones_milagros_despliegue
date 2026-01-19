<?php

namespace App\Filament\Resources\BodegaGastoResource\Pages;

use App\Filament\Resources\BodegaGastoResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ViewBodegaGasto extends ViewRecord
{
    protected static string $resource = BodegaGastoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Botón Enviar WhatsApp
            Actions\Action::make('enviar_whatsapp')
                ->label('Enviar por WhatsApp')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('success')
                ->url(fn() => $this->record->generarUrlWhatsapp())
                ->openUrlInNewTab()
                ->after(function () {
                    $this->record->marcarEnviadoWhatsapp();
                    
                    Notification::make()
                        ->success()
                        ->title('Enviado')
                        ->body('Se abrió WhatsApp. No olvides adjuntar la factura si aplica.')
                        ->send();
                })
                ->visible(fn() => !$this->record->enviado_whatsapp)
                ->requiresConfirmation()
                ->modalHeading('Enviar por WhatsApp')
                ->modalDescription(fn() => $this->record->tiene_factura 
                    ? 'Se abrirá WhatsApp con los datos del gasto. Recuerda adjuntar la foto de la factura.'
                    : 'Se abrirá WhatsApp con los datos del gasto.')
                ->modalSubmitActionLabel('Abrir WhatsApp'),

            // Botón Aprobar
            Actions\Action::make('aprobar')
                ->label('Aprobar')
                ->icon('heroicon-o-check')
                ->color('success')
                ->action(function () {
                    $this->record->aprobar(Auth::id());

                    Notification::make()
                        ->success()
                        ->title('Gasto Aprobado')
                        ->body('El gasto ha sido aprobado correctamente.')
                        ->send();
                    
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(function () {
                    if ($this->record->estado !== 'pendiente') return false;

                    $currentUser = Auth::user();
                    return DB::table('model_has_roles')
                        ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                        ->where('model_has_roles.model_type', '=', get_class($currentUser))
                        ->where('model_has_roles.model_id', '=', $currentUser->id)
                        ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                        ->exists();
                })
                ->requiresConfirmation()
                ->modalHeading('Aprobar Gasto')
                ->modalDescription('¿Estás seguro de aprobar este gasto?'),

            // Botón Rechazar
            Actions\Action::make('rechazar')
                ->label('Rechazar')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->action(function () {
                    $this->record->delete();

                    Notification::make()
                        ->warning()
                        ->title('Gasto Rechazado')
                        ->body('El gasto ha sido rechazado y eliminado.')
                        ->send();
                    
                    $this->redirect($this->getResource()::getUrl('index'));
                })
                ->visible(function () {
                    if ($this->record->estado !== 'pendiente') return false;

                    $currentUser = Auth::user();
                    return DB::table('model_has_roles')
                        ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                        ->where('model_has_roles.model_type', '=', get_class($currentUser))
                        ->where('model_has_roles.model_id', '=', $currentUser->id)
                        ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                        ->exists();
                })
                ->requiresConfirmation()
                ->modalHeading('Rechazar Gasto')
                ->modalDescription('¿Estás seguro de rechazar este gasto? Se eliminará del sistema.'),

            Actions\EditAction::make()
                ->visible(fn() => $this->record->estado === 'pendiente'),
        ];
    }
}