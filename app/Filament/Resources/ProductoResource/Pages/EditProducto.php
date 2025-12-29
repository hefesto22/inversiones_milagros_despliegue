<?php

namespace App\Filament\Resources\ProductoResource\Pages;

use App\Filament\Resources\ProductoResource;
use App\Models\BodegaProducto;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EditProducto extends EditRecord
{
    protected static string $resource = ProductoResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Verificar si el usuario actual puede editar stock
     */
    protected function usuarioPuedeEditarStock(): bool
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return false;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['super_admin', 'jefe'])
            ->exists();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Cargar los valores de cada bodega
        $bodegasProducto = BodegaProducto::where('producto_id', $this->record->id)
            ->with('bodega')
            ->get();

        // 🎯 CARGAR COSTO PROMEDIO desde la primera bodega como referencia
        if ($bodegasProducto->isNotEmpty()) {
            $primeraBodega = $bodegasProducto->first();

            // Si existe costo_promedio_actual, usarlo como precio_sugerido de referencia
            if ($primeraBodega->costo_promedio_actual > 0) {
                $data['precio_sugerido'] = $primeraBodega->costo_promedio_actual;
            }
        }

        foreach ($bodegasProducto as $bodegaProducto) {
            // Cargar nombre de bodega
            $data["bodega_nombre_{$bodegaProducto->id}"] = $bodegaProducto->bodega
                ? $bodegaProducto->bodega->nombre
                : 'Sin bodega';

            // Cargar stock actual formateado a 2 decimales
            $data["stock_actual_{$bodegaProducto->id}"] = number_format($bodegaProducto->stock, 2, '.', '');

            // Cargar stock mínimo formateado a 2 decimales
            $data["stock_minimo_{$bodegaProducto->id}"] = number_format($bodegaProducto->stock_minimo, 2, '.', '');

            // 🎯 CARGAR COSTO PROMEDIO ACTUAL (sin decimales, ya está redondeado)
            $data["costo_promedio_{$bodegaProducto->id}"] = number_format($bodegaProducto->costo_promedio_actual ?? 0, 0, '.', '');

            // 🎯 CARGAR PRECIO DE VENTA SUGERIDO (sin decimales, ya está redondeado)
            $data["precio_venta_{$bodegaProducto->id}"] = number_format($bodegaProducto->precio_venta_sugerido ?? 0, 0, '.', '');

            // 🆕 CARGAR PRECIO CON ISV (calculado)
            $precioBase = $bodegaProducto->precio_venta_sugerido ?? 0;
            $aplicaIsv = $this->record->aplica_isv ?? true;
            $precioConIsv = $aplicaIsv && $precioBase > 0 ? ceil($precioBase * 1.15) : $precioBase;
            $data["precio_isv_{$bodegaProducto->id}"] = number_format($precioConIsv, 0, '.', '');
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $currentUser = Auth::user();
        $data['updated_by'] = $currentUser->id;

        // Verificar si puede editar stock
        $puedeEditarStock = $this->usuarioPuedeEditarStock();

        // Guardar los cambios de stock_minimo y stock_actual (si tiene permiso)
        $bodegasProducto = BodegaProducto::where('producto_id', $this->record->id)->get();

        foreach ($bodegasProducto as $bodegaProducto) {
            $updateData = [];

            // 🎯 GUARDAR STOCK MÍNIMO (siempre permitido)
            $keyMinimo = "stock_minimo_{$bodegaProducto->id}";
            if (isset($data[$keyMinimo])) {
                $updateData['stock_minimo'] = round((float)$data[$keyMinimo], 2);
                unset($data[$keyMinimo]);
            }

            // 🎯 GUARDAR STOCK ACTUAL (solo si tiene permiso: jefe o super_admin)
            $keyActual = "stock_actual_{$bodegaProducto->id}";
            if (isset($data[$keyActual]) && $puedeEditarStock) {
                $nuevoStock = round((float)$data[$keyActual], 2);
                $stockAnterior = $bodegaProducto->stock;

                // Solo actualizar si cambió
                if ($nuevoStock != $stockAnterior) {
                    $updateData['stock'] = $nuevoStock;

                    // 📝 Registrar el ajuste manual en logs
                    Log::info("Ajuste manual de stock", [
                        'producto_id' => $this->record->id,
                        'bodega_id' => $bodegaProducto->bodega_id,
                        'stock_anterior' => $stockAnterior,
                        'stock_nuevo' => $nuevoStock,
                        'usuario_id' => $currentUser->id,
                        'usuario_nombre' => $currentUser->name ?? 'N/A',
                    ]);
                }

                unset($data[$keyActual]);
            } elseif (isset($data[$keyActual])) {
                // Si no tiene permiso, solo remover del array sin guardar
                unset($data[$keyActual]);
            }

            // Aplicar actualizaciones si hay cambios
            if (!empty($updateData)) {
                $bodegaProducto->update($updateData);
            }
        }

        // 🎯 Limpiar todos los campos temporales
        foreach ($data as $key => $value) {
            if (
                str_starts_with($key, 'bodega_nombre_') ||
                str_starts_with($key, 'stock_actual_') ||
                str_starts_with($key, 'stock_minimo_') ||
                str_starts_with($key, 'costo_promedio_') ||
                str_starts_with($key, 'precio_venta_') ||
                str_starts_with($key, 'precio_isv_')  // 🆕 LIMPIAR CAMPO ISV
            ) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
