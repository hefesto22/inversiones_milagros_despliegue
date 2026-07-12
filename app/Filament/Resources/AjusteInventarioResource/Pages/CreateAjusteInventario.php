<?php

declare(strict_types=1);

namespace App\Filament\Resources\AjusteInventarioResource\Pages;

use App\Application\Services\AjusteInventarioService;
use App\Enums\AjusteMotivo;
use App\Enums\AjusteTipoMovimiento;
use App\Filament\Resources\AjusteInventarioResource;
use App\Models\Lote;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAjusteInventario extends CreateRecord
{
    protected static string $resource = AjusteInventarioResource::class;

    /**
     * Intercepta la creación: en vez de crear el modelo directamente,
     * delegamos al AjusteInventarioService que aplica reglas de negocio
     * (lock, validaciones, vinculación pareja, etc.).
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $tipo = AjusteTipoMovimiento::from($data['tipo_movimiento']);
        $motivo = AjusteMotivo::from($data['motivo']);
        $svc = app(AjusteInventarioService::class);
        $user = Auth::user();

        $loteOrigen = Lote::findOrFail($data['lote_id']);

        if ($tipo === AjusteTipoMovimiento::SalidaReclasificacion) {
            $loteDestino = Lote::findOrFail($data['lote_destino_id']);
            // Costo nullable: si no se envía, el Service usa el costo del lote ORIGEN (Opción B)
            $costoAplicado = isset($data['costo_unitario_aplicado']) && $data['costo_unitario_aplicado'] !== ''
                ? (float) $data['costo_unitario_aplicado']
                : null;
            $result = $svc->crearReclasificacion(
                loteOrigen:            $loteOrigen,
                loteDestino:           $loteDestino,
                huevosAMover:          (float) $data['huevos_a_mover'],
                costoUnitarioAplicado: $costoAplicado,
                motivo:                $motivo,
                descripcion:           $data['descripcion'],
                evidenciaPath:         $data['evidencia_path'] ?? null,
                solicitante:           $user,
            );
            // Retornamos la salida (el resource lo muestra en la lista; la entrada está vinculada)
            Notification::make()
                ->title('Reclasificación creada')
                ->body("Salida #{$result['salida']->id} ↔ Entrada #{$result['entrada']->id}")
                ->success()->send();
            return $result['salida'];
        }

        if ($tipo === AjusteTipoMovimiento::MermaResidual) {
            $ajuste = $svc->crearMermaResidual(
                lote:           $loteOrigen,
                huevosAMermar:  (float) $data['huevos_a_mover'],
                motivo:         $motivo,
                descripcion:    $data['descripcion'],
                evidenciaPath:  $data['evidencia_path'] ?? null,
                solicitante:    $user,
            );
            Notification::make()->title('Merma residual creada')->success()->send();
            return $ajuste;
        }

        // EntradaReclasificacion no se crea sola desde el form (es pareja de salida)
        // AjusteCorreccion se crea desde acción específica sobre un ajuste aplicado
        throw new \DomainException("Tipo {$tipo->value} no se crea desde este formulario.");
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
